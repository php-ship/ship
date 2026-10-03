<?php

declare(strict_types=1);

namespace Ship\Console\Commands;

use Ship\Config\ShipConfig;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\ComposeCommand;
use Ship\Docker\ComposeFileBuilder;
use Ship\Docker\EnvFile;
use Ship\Docker\HostUser;
use Ship\Docker\MySqlUsernameGuard;
use Ship\Extensions\ExtensionLoader;
use Ship\Runtime\ProcessRunner;
use Ship\Services\ServiceRegistry;
use Ship\Support\ShipVersion;
use Ship\Sync\MutagenSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Development only -- see `ship build`/`ship release --tag` for production, a separate workflow
 * entirely (ProductionBuildRunner), not a mode of this command. Keeping the two apart means this
 * command never again grows a production-only branch (deploy commands, image export, ...) the way
 * it briefly did before `ship build`/`ship release` existed.
 */
#[AsCommand(name: 'up', description: 'Build and start the development environment')]
final class UpCommand extends Command
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly ProcessRunner $runner,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = ShipConfig::fromFile($this->projectRoot . '/ship.json');

        $registry = new ServiceRegistry(ServiceRegistry::defaults());

        // Same extension loading Application does at boot — needed here
        // too, independently, because a project whose ship.json selects an
        // extension-provided service would otherwise pass `ship init`
        // (which used Application's already-loaded registry) and then
        // fail on `ship up` with an OutOfBoundsException, since this
        // registry starts fresh with only the built-ins. Not re-printing
        // the warnings here too — Application's own constructor already
        // surfaced them once; a real bug found via an independent audit
        // had every extension class built once there and a second time
        // here, printing the same warning twice on every `ship up`.
        (new ExtensionLoader())->load($config->extensions, $registry);

        $this->warnAboutStubVersionMismatch($output);

        $usernameProblems = MySqlUsernameGuard::problems($config, EnvFile::parse($this->projectRoot . '/.env'));
        if ($usernameProblems !== []) {
            $output->writeln(sprintf(
                '<error>ship: %s is set to "root" in .env -- the official mysql image\'s own '
                    . 'entrypoint refuses to start at all with that value (it\'s reserved for '
                    . 'MYSQL_ROOT_PASSWORD, not MYSQL_USER). Pick a different username.</error>',
                implode(', ', $usernameProblems),
            ));

            return Command::FAILURE;
        }

        $mutagenSync = MutagenSync::isEnabled();

        $hostUser = $this->resolveHostUser($config, $mutagenSync, $output);

        $compose = (new ComposeFileBuilder($registry))->build($config, ShipEnvironment::Development, $mutagenSync, $hostUser);

        $composeDir = $this->projectRoot . '/ship';
        if (!is_dir($composeDir)) {
            mkdir($composeDir, recursive: true);
        }

        $composePath = $composeDir . '/docker-compose.generated.yml';
        file_put_contents($composePath, $compose);

        $result = $this->runner->runInteractive(
            [...ComposeCommand::baseArgs($this->projectRoot), 'up', '--build', '-d'],
            $this->projectRoot,
        );

        if ($result !== Command::SUCCESS) {
            return $result;
        }

        $result = $this->ensureEveryServiceStarted($compose, $output);

        if ($result !== Command::SUCCESS) {
            return $result;
        }

        $result = $this->ensureHealthchecksPass($compose, $output);

        if ($result !== Command::SUCCESS) {
            return $result;
        }

        $result = $this->ensurePublishedPortsAreBound($output);

        if ($result !== Command::SUCCESS) {
            return $result;
        }

        // Last, not first: needs the app container already running and healthy to sync into --
        // see MutagenSync::start()'s own docblock for why this blocks until the initial sync
        // actually finishes rather than just firing off session creation.
        return $mutagenSync
            ? (new MutagenSync($this->runner, $this->projectRoot, $config->serviceNames['app'] ?? 'app'))->start($output)
            : Command::SUCCESS;
    }

    /**
     * ship.json's hostUser (see ShipConfig::$hostUser), or null -- and when the user asked for it but
     * it can't apply, says why instead of quietly doing nothing, since the visible result of that
     * (a root-owned vendor/ they turned the option on to avoid) would otherwise be a mystery.
     *
     * @return array{uid: int, gid: int}|null
     */
    private function resolveHostUser(ShipConfig $config, bool $mutagenSync, OutputInterface $output): ?array
    {
        if (!$config->hostUser) {
            return null;
        }

        $reason = match (true) {
            $mutagenSync => 'SHIP_MUTAGEN syncs into a volume as root, so the two cannot be combined',
            HostUser::detect() === null => 'there is no non-root POSIX user to match here (native Windows, or already root)',
            default => null,
        };

        if ($reason !== null) {
            $output->writeln("<comment>ship: hostUser is set in ship.json but was not applied -- {$reason}. Running as root.</comment>");

            return null;
        }

        return HostUser::detect();
    }

    /**
     * `docker compose up --build -d` exiting 0 doesn't guarantee every service actually started -- under
     * several simultaneous image builds, Compose can leave a service sitting at "Created" without ever
     * starting it (observed with webserver/reverb; not something the generated compose file causes, see
     * docs/roadmap.md). One retry self-heals that. A service still not running after the retry is a real
     * failure the caller needs to see, not something to paper over.
     */
    private function ensureEveryServiceStarted(string $composeYaml, OutputInterface $output): int
    {
        /** @var array{services?: array<string, mixed>} $parsed */
        $parsed = Yaml::parse($composeYaml);
        $expected = array_keys($parsed['services'] ?? []);

        $notRunning = array_diff($expected, $this->runningServices());

        if ($notRunning === []) {
            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<comment>ship: %s did not start with the rest of the stack -- retrying...</comment>',
            implode(', ', $notRunning),
        ));

        $this->runner->runInteractive(
            [...ComposeCommand::baseArgs($this->projectRoot), 'up', '-d', ...$notRunning],
            $this->projectRoot,
        );

        $stillNotRunning = array_diff($notRunning, $this->runningServices());

        if ($stillNotRunning === []) {
            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<error>ship: %s still did not start. Check `ship logs %s`.</error>',
            implode(', ', $stillNotRunning),
            array_values($stillNotRunning)[0],
        ));

        return Command::FAILURE;
    }

    /**
     * `ship up` doesn't republish stub files itself -- only `ship init` does (see its own
     * publishStubs()) -- so a project that upgrades the `php-ship/ship` package and runs `ship up`
     * directly just keeps whatever `ship/Dockerfile` etc. the *previous* version wrote, silently.
     * This only warns, never re-publishes on its own: a project may have
     * hand-edited those files (see README's "Customizing the stack"), and silently overwriting
     * that would be worse than an outdated stub. Stays quiet for a project whose `ship init` never
     * recorded a version (older `ship`, or the version genuinely couldn't be determined) -- no
     * baseline to compare against means no mismatch to report.
     */
    private function warnAboutStubVersionMismatch(OutputInterface $output): void
    {
        $recordedVersionFile = $this->projectRoot . '/ship/.ship-version';
        $currentVersion = ShipVersion::current();

        if (!is_file($recordedVersionFile) || $currentVersion === null) {
            return;
        }

        $recordedVersion = trim((string) file_get_contents($recordedVersionFile));

        if ($recordedVersion === '' || $recordedVersion === $currentVersion) {
            return;
        }

        $output->writeln(sprintf(
            '<comment>ship: the published ship/ directory was generated by %s, but %s is now '
                . 'installed. Run `ship init` again to pick up any stub changes -- it re-asks every '
                . 'prompt, so have your current selections (see ship.json) ready to re-pick.</comment>',
            $recordedVersion,
            $currentVersion,
        ));
    }

    /**
     * @return list<string>
     */
    private function runningServices(): array
    {
        $output = $this->runner->runQuiet(
            [...ComposeCommand::baseArgs($this->projectRoot), 'ps', '--services', '--status', 'running'],
            $this->projectRoot,
        );

        return $output === '' ? [] : explode("\n", $output);
    }

    /**
     * A service reporting "running" doesn't mean whatever's inside it is actually ready --
     * MySQL/Postgres/Redis's own images take a few seconds beyond process start before they'll
     * accept real connections (longest on a fresh volume's first boot), which is exactly why their
     * ServiceDefinitions declare a `healthcheck` in the first place. Nothing before this ever
     * consulted it, so `ship up` immediately followed by `ship exec app php artisan migrate` --
     * a completely normal thing to do -- could race ahead of the database and fail with
     * "connection refused" even though `ship up` itself had already reported success. Only
     * services that declare a healthcheck are waited on; anything without one has no health
     * status to check and stays covered by ensureEveryServiceStarted() alone.
     */
    private function ensureHealthchecksPass(string $composeYaml, OutputInterface $output): int
    {
        /** @var array{services?: array<string, array{healthcheck?: array{interval?: string, retries?: int, start_period?: string}}>} $parsed */
        $parsed = Yaml::parse($composeYaml);

        $unhealthy = [];

        foreach ($parsed['services'] ?? [] as $name => $service) {
            if (isset($service['healthcheck']) && !$this->serviceBecomesHealthy($name, $service['healthcheck'])) {
                $unhealthy[] = $name;
            }
        }

        if ($unhealthy === []) {
            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<error>ship: %s never became healthy -- check `ship logs %s`.</error>',
            implode(', ', $unhealthy),
            $unhealthy[0],
        ));

        return Command::FAILURE;
    }

    /**
     * Polls until the service's *own* healthcheck would have given up -- not a fixed count. A
     * real bug found via an independent audit: a flat "15 attempts x 2s = ~30s" budget was well
     * past MySQL/Postgres/Redis's own interval x retries (5s x 5 = 25s) when that comment was
     * written, but Garage/RustFS/Silo's own healthcheck (10s start_period + 5s x 10 retries = 60s)
     * and SeaweedFS's (10s x 5 = 50s) can both legitimately still be "starting" well after this
     * gave up and reported a false "never became healthy" -- the exact failure a slow first boot
     * (a fresh volume's own initialization) produces, not a real problem. Computed from the
     * service's own generated `healthcheck:` block instead, so it's never shorter than Docker's
     * own patience for it, with a small buffer on top rather than trusting a single reading right
     * at the edge, since a container can sit at "starting" for several polls before Docker marks
     * it "healthy".
     *
     * @param array{interval?: string, retries?: int, start_period?: string} $healthcheck
     */
    private function serviceBecomesHealthy(string $service, array $healthcheck): bool
    {
        $containerId = trim($this->runner->runQuiet(
            [...ComposeCommand::baseArgs($this->projectRoot), 'ps', '-q', $service],
            $this->projectRoot,
        ));

        if ($containerId === '') {
            return false;
        }

        $pollIntervalSeconds = 2;
        $maxAttempts = (int) ceil($this->healthcheckBudgetSeconds($healthcheck) / $pollIntervalSeconds) + 1;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $status = trim($this->runner->runQuiet(
                ['docker', 'inspect', $containerId, '--format', '{{.State.Health.Status}}'],
                $this->projectRoot,
            ));

            if ($status === 'healthy') {
                return true;
            }

            if ($attempt < $maxAttempts) {
                sleep($pollIntervalSeconds);
            }
        }

        return false;
    }

    /**
     * Docker's own worst-case time before it gives up and marks a container "unhealthy":
     * start_period, then interval x retries. A 5s buffer on top for this process's own polling
     * overhead -- never meant to be exact, just never shorter than Docker's own patience.
     *
     * @param array{interval?: string, retries?: int, start_period?: string} $healthcheck
     */
    private function healthcheckBudgetSeconds(array $healthcheck): int
    {
        $interval = $this->parseSeconds($healthcheck['interval'] ?? '30s');
        $retries = $healthcheck['retries'] ?? 3;
        $startPeriod = $this->parseSeconds($healthcheck['start_period'] ?? '0s');

        return $startPeriod + ($interval * $retries) + 5;
    }

    /**
     * Every healthcheck ship itself generates uses a plain "<N>s" duration -- not Docker's full
     * duration syntax (which also allows "1m30s", "1h", ...) -- so this only ever needs to parse
     * that one shape. Falls back to a conservative 30s for anything else (a hand-edited
     * docker-compose.override.yml's own healthcheck, say) rather than failing outright.
     */
    private function parseSeconds(string $duration): int
    {
        return preg_match('/^(\d+)s$/', $duration, $matches) === 1 ? (int) $matches[1] : 30;
    }

    /**
     * A container reporting "running" doesn't mean its published ports actually bound -- Docker
     * can silently drop one if another process already owns that host port (observed with
     * Reverb's default 8080 colliding with an unrelated container), leaving the service reachable
     * over the internal Docker network but not from the host. Nothing to retry here, unlike
     * ensureEveryServiceStarted() -- another process squatting on the port won't free it up on its
     * own, so this only needs to turn an otherwise-silent failure into a visible one. Checked right
     * after `up` returns, Docker's own async network setup can still be mid-flight either way --
     * `docker compose port` observed reporting a port as bound moments before the bind actually
     * failed, not just the reverse (slow-but-fine). portIsBound() waits out that settling window
     * instead of trusting whatever the first read says.
     *
     * Reads `docker compose config` (Compose's own fully-resolved view), not the raw generated
     * YAML the other ensure*() methods parse -- found from real use: Vite's own port mapping
     * (see ComposeFileBuilder) has "${VITE_PORT:-5173}" on *both* sides, not just the host side
     * every other mapping here has, so the container side is no longer always a bare literal a
     * simple string split could pull out safely. `docker compose config` already resolves every
     * "${VAR:-default}" the same way `docker compose up` itself would (env var, then .env, then
     * the inline default), handing back a real "target" port number directly instead of text this
     * class would otherwise have to re-parse.
     */
    private function ensurePublishedPortsAreBound(OutputInterface $output): int
    {
        /** @var array{services?: array<string, array{ports?: list<array{target?: int}>}>} $resolved */
        $resolved = Yaml::parse($this->runner->runQuiet(
            [...ComposeCommand::baseArgs($this->projectRoot), 'config'],
            $this->projectRoot,
        ));

        $unbound = [];

        foreach ($resolved['services'] ?? [] as $name => $service) {
            foreach ($service['ports'] ?? [] as $mapping) {
                $containerPort = isset($mapping['target']) ? (string) $mapping['target'] : null;

                if ($containerPort !== null && !$this->portIsBound($name, $containerPort)) {
                    $unbound[] = "{$name} ({$containerPort})";
                }
            }
        }

        if ($unbound === []) {
            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<error>ship: %s did not actually bind to the host, even though the container is '
                . 'running -- something else on this machine is already using that port. Free it '
                . 'up, or override it (see README\'s "Running more than one project at once" '
                . 'section), then retry.</error>',
            implode(', ', $unbound),
        ));

        return Command::FAILURE;
    }

    /**
     * The *last* of 3 readings, 1 second apart, is the answer -- not the first. See
     * ensurePublishedPortsAreBound()'s docblock: an early reading can go either way while Docker's
     * async network setup is still mid-flight, so only a reading taken after that settles is
     * trustworthy.
     */
    private function portIsBound(string $service, string $containerPort): bool
    {
        $bound = false;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $raw = $this->runner->runQuiet(
                [...ComposeCommand::baseArgs($this->projectRoot), 'port', $service, $containerPort],
                $this->projectRoot,
            );
            $bound = $this->looksActuallyBound($raw);

            if ($attempt < 3) {
                sleep(1);
            }
        }

        return $bound;
    }

    /**
     * Empty output isn't the only failure shape -- `docker compose port` prints the literal
     * "invalid IP:0", not an error and not empty, when a service's port mapping exists in its
     * metadata but the actual host bind never succeeded. Only a real "host:port" with a non-zero
     * numeric port counts as genuinely bound.
     */
    private function looksActuallyBound(string $output): bool
    {
        if ($output === '') {
            return false;
        }

        $port = substr($output, (int) strrpos($output, ':') + 1);

        return $port !== '' && ctype_digit($port) && $port !== '0';
    }
}

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
use Ship\Docker\NginxUpstreamMismatch;
use Ship\Docker\ProjectName;
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
 * Development only. Production is a separate workflow: `ship build`/`ship release --tag`
 * (ProductionBuildRunner).
 */
#[AsCommand(name: 'up', description: 'Build and start the development environment')]
final class UpCommand extends Command
{
    /**
     * $registry is the one Application already populated with extensions. Optional so the command
     * can be constructed directly (tests), in which case it builds its own.
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly ProcessRunner $runner,
        private readonly ?ServiceRegistry $registry = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = ShipConfig::fromFile($this->projectRoot . '/ship.json');

        if ($this->registry !== null) {
            $registry = $this->registry;
        } else {
            $registry = new ServiceRegistry(ServiceRegistry::defaults());
            (new ExtensionLoader())->load($config->extensions, $registry);
        }

        $this->warnAboutStubVersionMismatch($output);

        $nginxWarning = NginxUpstreamMismatch::warning($this->projectRoot, $config);
        if ($nginxWarning !== null) {
            $output->writeln($nginxWarning);
        }

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

        $compose = (new ComposeFileBuilder($registry))->build(
            $config,
            ShipEnvironment::Development,
            $mutagenSync,
            $hostUser,
            ProjectName::resolve($config, $this->projectRoot),
        );

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

        // Before the ensure*() checks: Reverb and Octane runtimes mount the synced volume, which
        // is empty until the first sync pass finishes, so they can't be running before it does.
        if ($mutagenSync) {
            $result = (new MutagenSync($this->runner, $this->projectRoot, $config->serviceNames['app'] ?? 'app'))->start($output);

            if ($result !== Command::SUCCESS) {
                return $result;
            }
        }

        $result = $this->ensureEveryServiceStarted($compose, $output);

        if ($result !== Command::SUCCESS) {
            return $result;
        }

        $result = $this->ensureHealthchecksPass($compose, $output);

        if ($result !== Command::SUCCESS) {
            return $result;
        }

        return $this->ensurePublishedPortsAreBound($output);
    }

    /**
     * ship.json's hostUser (see ShipConfig::$hostUser), or null. When it was asked for but can't
     * apply, says why instead of quietly running as root.
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
     * `docker compose up --build -d` exiting 0 doesn't guarantee every service started: under
     * several simultaneous image builds, Compose can leave one at "Created". One retry fixes that;
     * a service still not running afterwards is reported as a failure.
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
     * `ship up` never republishes stub files (only `ship init` does), so after upgrading the
     * package a project keeps the previous version's ship/Dockerfile etc. Only warns: the files
     * may be hand-edited. Silent when no version was recorded.
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
                . 'installed. Run `ship init` again to pick up any stub changes. Each prompt defaults '
                . 'to your current selection, so you can press Enter through them. It overwrites the '
                . 'files in ship/, so commit or back up any you edited by hand first.</comment>',
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
     * Waits for every service that declares a healthcheck to report healthy. "Running" isn't
     * "ready": a database takes a few seconds before it accepts connections, and a migration run
     * straight after `ship up` would otherwise race it.
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
     * Polls for as long as the service's own healthcheck would take to give up (see
     * healthcheckBudgetSeconds()) rather than a fixed count, since budgets differ widely between
     * services.
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
     * Docker's worst-case time before marking a container "unhealthy" (start_period, then
     * interval x retries), plus a 5s buffer for polling overhead.
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
     * Parses the plain "<N>s" durations ship generates. Anything else (a hand-written override's
     * "1m30s") falls back to 30s.
     */
    private function parseSeconds(string $duration): int
    {
        return preg_match('/^(\d+)s$/', $duration, $matches) === 1 ? (int) $matches[1] : 30;
    }

    /**
     * A running container doesn't mean its published ports bound: Docker can silently drop one
     * when another process owns the host port. Nothing to retry, so this only reports it.
     *
     * Reads `docker compose config` rather than the generated YAML, since it resolves every
     * "${VAR:-default}" the way `docker compose up` does and returns a numeric "target" port.
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
     * The last of 3 readings, 1 second apart, is the answer: an early reading can go either way
     * while Docker's network setup is still settling.
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
     * `docker compose port` prints the literal "invalid IP:0" when a mapping exists but the host
     * bind failed, so only a "host:port" with a non-zero numeric port counts as bound.
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

<?php

declare(strict_types=1);

namespace Ship\Console\Commands;

use Ship\Config\ShipConfig;
use Ship\Contracts\FrameworkAdapter;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\ComposeCommand;
use Ship\Docker\ComposeFileBuilder;
use Ship\Docker\EntrypointScriptBuilder;
use Ship\Extensions\ExtensionLoader;
use Ship\Frameworks\LaravelAdapter;
use Ship\Frameworks\SymfonyAdapter;
use Ship\Runtime\ProcessRunner;
use Ship\Services\ServiceRegistry;
use Ship\Support\ShipVersion;
use Ship\Sync\MutagenSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'up', description: 'Build and start the environment')]
final class UpCommand extends Command
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly ProcessRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('prod', null, InputOption::VALUE_NONE, 'Start in production mode');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = ShipConfig::fromFile($this->projectRoot . '/ship.json');
        $environment = (bool) $input->getOption('prod')
            ? ShipEnvironment::Production
            : ShipEnvironment::Development;

        $registry = new ServiceRegistry(ServiceRegistry::defaults());

        // Same extension loading Application does at boot — needed here
        // too, independently, because a project whose ship.json selects an
        // extension-provided service would otherwise pass `ship init`
        // (which used Application's already-loaded registry) and then
        // fail on `ship up` with an OutOfBoundsException, since this
        // registry starts fresh with only the built-ins.
        $warnings = (new ExtensionLoader())->load($config->extensions, $registry);
        foreach ($warnings as $warning) {
            $output->writeln("<comment>ship: warning: {$warning}</comment>");
        }

        $this->warnAboutStubVersionMismatch($output);

        // Never on in production regardless of SHIP_MUTAGEN -- there's no bind mount there to
        // begin with (source is baked into the image at build time), so nothing to sync.
        $mutagenSync = $environment->isDevelopment() && MutagenSync::isEnabled();

        $compose = (new ComposeFileBuilder($registry))->build($config, $environment, $mutagenSync);

        $composeDir = $this->projectRoot . '/ship';
        if (!is_dir($composeDir)) {
            mkdir($composeDir, recursive: true);
        }

        $composePath = $composeDir . '/docker-compose.generated.yml';
        file_put_contents($composePath, $compose);

        // Generated on every `ship up` (not just --prod) so it's cheap
        // and always current; the "dev" build target simply never
        // references it. Content depends on which FrameworkAdapter
        // matches the project, which is exactly why this can't be a
        // static stub InitCommand publishes once — see
        // EntrypointScriptBuilder's docblock.
        $releaseCommands = array_merge(
            [],
            ...array_map(
                static fn (FrameworkAdapter $adapter): array => $adapter->releaseCommands(),
                $this->detectFrameworkAdapters($config->extensions),
            ),
        );
        $entrypointDir = $composeDir . '/prod';
        if (!is_dir($entrypointDir)) {
            mkdir($entrypointDir, recursive: true);
        }
        $entrypointPath = $entrypointDir . '/entrypoint.sh';
        file_put_contents($entrypointPath, (new EntrypointScriptBuilder())->build($releaseCommands));
        chmod($entrypointPath, 0o755);

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

        $result = $this->ensurePublishedPortsAreBound($compose, $output);

        if ($result !== Command::SUCCESS) {
            return $result;
        }

        // Last, not first: needs the "app" container already running and healthy to sync into --
        // see MutagenSync::start()'s own docblock for why this blocks until the initial sync
        // actually finishes rather than just firing off session creation.
        return $mutagenSync
            ? (new MutagenSync($this->runner, $this->projectRoot))->start($output)
            : Command::SUCCESS;
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
     * publishStubs()) -- so a project that upgrades the `php-ship/ship` package and runs `ship
     * up`/`ship up --prod` directly just keeps whatever `ship/Dockerfile` etc. the *previous*
     * version wrote, silently. This only warns, never re-publishes on its own: a project may have
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
        /** @var array{services?: array<string, array{healthcheck?: mixed}>} $parsed */
        $parsed = Yaml::parse($composeYaml);

        $unhealthy = [];

        foreach ($parsed['services'] ?? [] as $name => $service) {
            if (isset($service['healthcheck']) && !$this->serviceBecomesHealthy($name)) {
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
     * Polls for up to 30s -- well past any built-in healthcheck's own interval x retries (5s x 5 =
     * 25s), which already covers a fresh volume's first-boot initialization -- rather than trusting
     * a single reading, since a container can sit at "starting" for several polls before Docker
     * marks it "healthy".
     */
    private function serviceBecomesHealthy(string $service): bool
    {
        $containerId = trim($this->runner->runQuiet(
            [...ComposeCommand::baseArgs($this->projectRoot), 'ps', '-q', $service],
            $this->projectRoot,
        ));

        if ($containerId === '') {
            return false;
        }

        for ($attempt = 1; $attempt <= 15; $attempt++) {
            $status = trim($this->runner->runQuiet(
                ['docker', 'inspect', $containerId, '--format', '{{.State.Health.Status}}'],
                $this->projectRoot,
            ));

            if ($status === 'healthy') {
                return true;
            }

            if ($attempt < 15) {
                sleep(2);
            }
        }

        return false;
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
     */
    private function ensurePublishedPortsAreBound(string $composeYaml, OutputInterface $output): int
    {
        /** @var array{services?: array<string, array{ports?: list<string>}>} $parsed */
        $parsed = Yaml::parse($composeYaml);

        $unbound = [];

        foreach ($parsed['services'] ?? [] as $name => $service) {
            foreach ($service['ports'] ?? [] as $mapping) {
                $containerPort = $this->containerPortFrom($mapping);

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

    /**
     * Compose port mappings look like "80:80" or "${APP_PORT:-80}:80" -- only the container-side
     * port (the part after the last ":") matters for `docker compose port`, so the host side's
     * env-var syntax never needs resolving here.
     */
    private function containerPortFrom(string $mapping): ?string
    {
        $parts = explode(':', $mapping);
        $containerPort = end($parts);

        return $containerPort !== '' ? $containerPort : null;
    }

    /**
     * Same detection Application does at boot -- duplicated since this command runs standalone.
     *
     * @param list<string> $extensionClasses
     * @return list<FrameworkAdapter>
     */
    private function detectFrameworkAdapters(array $extensionClasses): array
    {
        $candidates = [
            new LaravelAdapter(),
            new SymfonyAdapter(),
            ...(new ExtensionLoader())->loadFrameworkAdapters($extensionClasses),
        ];

        return array_values(array_filter(
            $candidates,
            fn (FrameworkAdapter $adapter): bool => $adapter->detect($this->projectRoot),
        ));
    }
}

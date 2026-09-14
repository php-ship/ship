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

        $compose = (new ComposeFileBuilder($registry))->build($config, $environment);

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
        chmod($entrypointPath, 0755);

        $result = $this->runner->runInteractive(
            [...ComposeCommand::baseArgs($this->projectRoot), 'up', '--build', '-d'],
            $this->projectRoot,
        );

        if ($result !== Command::SUCCESS) {
            return $result;
        }

        return $this->ensureEveryServiceStarted($compose, $output);
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

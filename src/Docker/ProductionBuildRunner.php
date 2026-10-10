<?php

declare(strict_types=1);

namespace Ship\Docker;

use Ship\Config\ShipConfig;
use Ship\Contracts\FrameworkAdapter;
use Ship\Contracts\ShipEnvironment;
use Ship\Frameworks\LaravelAdapter;
use Ship\Frameworks\SymfonyAdapter;
use Ship\Runtime\ProcessRunner;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * The implementation behind `ship build` and `ship release --tag`: generates the prod entrypoint
 * (see EntrypointScriptBuilder), writes the production compose file, works out which services are
 * project-owned images (ProductionImagePlan) and runs `docker compose build` for them. Only the
 * tag suffix differs between the two commands.
 */
final class ProductionBuildRunner
{
    public function __construct(
        private readonly ProcessRunner $runner,
    ) {
    }

    /**
     * @param list<FrameworkAdapter> $extensionFrameworkAdapters extension FrameworkAdapters the
     *     caller already instantiated (see ExtensionLoader::load())
     * @return array{
     *     composeServices: array<string, array<string, mixed>>,
     *     images: list<array{tag: string, canonicalService: string, members: list<string>}>,
     * }|null null on a build failure, already reported to $output
     */
    public function build(
        ShipConfig $config,
        ServiceRegistry $registry,
        array $extensionFrameworkAdapters,
        string $projectRoot,
        string $projectName,
        string $tagSuffix,
        OutputInterface $output,
    ): ?array {
        // Re-applied at build time, when `COPY . .` runs, in case .dockerignore was edited or
        // removed since `ship init`.
        DockerignoreGuard::ensure($projectRoot);

        $this->generateEntrypoint($config, $projectRoot, $extensionFrameworkAdapters);

        $composeYaml = (new ComposeFileBuilder($registry))->build($config, ShipEnvironment::Production, projectName: $projectName);
        /** @var array{services: array<string, array<string, mixed>>} $parsed */
        $parsed = Yaml::parse($composeYaml);

        $appServiceName = $config->serviceNames['app'] ?? 'app';
        $plan = ProductionImagePlan::plan($parsed['services'], $projectName, $appServiceName, $tagSuffix);
        $parsed['services'] = $plan['services'];

        $composeDir = $projectRoot . '/ship';
        if (!is_dir($composeDir)) {
            mkdir($composeDir, recursive: true);
        }
        // See ComposeCommand::PRODUCTION_COMPOSE_FILE for why this isn't the dev file.
        file_put_contents(
            $projectRoot . '/' . ComposeCommand::PRODUCTION_COMPOSE_FILE,
            Yaml::dump($parsed, inline: 6, indent: 2, flags: Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE),
        );

        $canonicalServices = array_map(static fn (array $image): string => $image['canonicalService'], $plan['images']);

        if ($canonicalServices === []) {
            $output->writeln('<comment>ship: no project-owned service has a build: step -- nothing to build.</comment>');

            return ['composeServices' => $plan['services'], 'images' => []];
        }

        // Passed to `docker compose build` for Compose's own `${VAR}` substitution; nothing here
        // is baked into the image automatically.
        $buildEnv = EnvFile::parse($projectRoot . '/.env.production');

        $result = $this->runner->runInteractive(
            // No override file and the production compose file: see ComposeCommand::baseArgs().
            [
                ...ComposeCommand::baseArgs($projectRoot, includeOverride: false, composeFile: ComposeCommand::PRODUCTION_COMPOSE_FILE),
                'build',
                ...$canonicalServices,
            ],
            $projectRoot,
            $buildEnv === [] ? null : $buildEnv,
        );

        if ($result !== Command::SUCCESS) {
            $output->writeln('<error>ship: building one or more production images failed -- see the output above.</error>');

            return null;
        }

        foreach ($plan['images'] as $image) {
            $output->writeln("<info>ship: built {$image['tag']}</info>");
        }

        return ['composeServices' => $plan['services'], 'images' => $plan['images']];
    }

    /**
     * The entrypoint's content depends on which FrameworkAdapter matches the project. It is a
     * build input, copied into the image by the Dockerfile's `prod` stage.
     *
     * @param list<FrameworkAdapter> $extensionFrameworkAdapters
     */
    private function generateEntrypoint(ShipConfig $config, string $projectRoot, array $extensionFrameworkAdapters): void
    {
        $releaseCommands = array_merge(
            [],
            ...array_map(
                static fn (FrameworkAdapter $adapter): array => $adapter->releaseCommands(),
                $this->detectFrameworkAdapters($projectRoot, $extensionFrameworkAdapters),
            ),
        );

        $entrypointDir = $projectRoot . '/ship/prod';
        if (!is_dir($entrypointDir)) {
            mkdir($entrypointDir, recursive: true);
        }

        $entrypointPath = $entrypointDir . '/entrypoint.sh';
        file_put_contents($entrypointPath, (new EntrypointScriptBuilder())->build($releaseCommands));
        chmod($entrypointPath, 0o755);
    }

    /**
     * The same detection Application does at boot, against the adapters the caller handed in.
     *
     * @param list<FrameworkAdapter> $extensionFrameworkAdapters
     * @return list<FrameworkAdapter>
     */
    private function detectFrameworkAdapters(string $projectRoot, array $extensionFrameworkAdapters): array
    {
        $candidates = [
            new LaravelAdapter(),
            new SymfonyAdapter(),
            ...$extensionFrameworkAdapters,
        ];

        return array_values(array_filter(
            $candidates,
            fn (FrameworkAdapter $adapter): bool => $adapter->detect($projectRoot),
        ));
    }
}

<?php

declare(strict_types=1);

namespace Ship\Docker;

use Ship\Config\ShipConfig;
use Ship\Contracts\FrameworkAdapter;
use Ship\Contracts\ShipEnvironment;
use Ship\Extensions\ExtensionLoader;
use Ship\Frameworks\LaravelAdapter;
use Ship\Frameworks\SymfonyAdapter;
use Ship\Runtime\ProcessRunner;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * The one shared implementation `ship build` and `ship release --tag` both build on: generate the
 * prod entrypoint (a build input, see EntrypointScriptBuilder), build the production compose file,
 * work out which services are project-owned images (ProductionImagePlan), and actually run `docker
 * compose build` for them. `ship build` calls this with $tagSuffix "local"; `ship release` calls it
 * with the real `--tag` value -- same code path either way, so there's exactly one place that
 * decides what a production image is and how it gets built.
 */
final class ProductionBuildRunner
{
    public function __construct(
        private readonly ProcessRunner $runner,
    ) {
    }

    /**
     * @return array{
     *     composeServices: array<string, array<string, mixed>>,
     *     images: list<array{tag: string, canonicalService: string, members: list<string>}>,
     * }|null null on a build failure, already reported to $output
     */
    public function build(
        ShipConfig $config,
        ServiceRegistry $registry,
        string $projectRoot,
        string $projectName,
        string $tagSuffix,
        OutputInterface $output,
    ): ?array {
        $this->generateEntrypoint($config, $projectRoot);

        $composeYaml = (new ComposeFileBuilder($registry))->build($config, ShipEnvironment::Production);
        /** @var array{services: array<string, array<string, mixed>>} $parsed */
        $parsed = Yaml::parse($composeYaml);

        $appServiceName = $config->serviceNames['app'] ?? 'app';
        $plan = ProductionImagePlan::plan($parsed['services'], $projectName, $appServiceName, $tagSuffix);
        $parsed['services'] = $plan['services'];

        $composeDir = $projectRoot . '/ship';
        if (!is_dir($composeDir)) {
            mkdir($composeDir, recursive: true);
        }
        // ComposeCommand::PRODUCTION_COMPOSE_FILE, never docker-compose.generated.yml -- a real
        // bug found via an independent audit: sharing the dev file meant ship build/ship release
        // silently overwrote it with a production compose until the next ship up regenerated it,
        // during which ship exec/shell/composer/npm would all read the wrong target.
        file_put_contents(
            $projectRoot . '/' . ComposeCommand::PRODUCTION_COMPOSE_FILE,
            Yaml::dump($parsed, inline: 6, indent: 2, flags: Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE),
        );

        $canonicalServices = array_map(static fn (array $image): string => $image['canonicalService'], $plan['images']);

        if ($canonicalServices === []) {
            $output->writeln('<comment>ship: no project-owned service has a build: step -- nothing to build.</comment>');

            return ['composeServices' => $plan['services'], 'images' => []];
        }

        // Only ever non-null when the project's own .env.production sets something -- see
        // EnvFile's own docblock for why this is Compose's `${VAR}` build-arg substitution, not
        // anything baked into the image automatically.
        $buildEnv = EnvFile::parse($projectRoot . '/.env.production');

        $result = $this->runner->runInteractive(
            // includeOverride: false -- never docker-compose.override.yml here, see
            // ComposeCommand::baseArgs()'s own docblock. composeFile: PRODUCTION_COMPOSE_FILE --
            // never the dev file, see that constant's own docblock.
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
     * Generated fresh on every `ship build`/`ship release` (not a static stub) -- its content depends
     * on which FrameworkAdapter matches the project. A build input: baked into the image by `ship/
     * Dockerfile`'s `prod` stage's own `COPY`, not something the release artifact carries separately.
     */
    private function generateEntrypoint(ShipConfig $config, string $projectRoot): void
    {
        $releaseCommands = array_merge(
            [],
            ...array_map(
                static fn (FrameworkAdapter $adapter): array => $adapter->releaseCommands(),
                $this->detectFrameworkAdapters($projectRoot, $config->extensions),
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
     * Same detection Application/UpCommand do at boot -- duplicated since this runs standalone.
     *
     * @param list<string> $extensionClasses
     * @return list<FrameworkAdapter>
     */
    private function detectFrameworkAdapters(string $projectRoot, array $extensionClasses): array
    {
        $candidates = [
            new LaravelAdapter(),
            new SymfonyAdapter(),
            ...(new ExtensionLoader())->loadFrameworkAdapters($extensionClasses),
        ];

        return array_values(array_filter(
            $candidates,
            fn (FrameworkAdapter $adapter): bool => $adapter->detect($projectRoot),
        ));
    }
}

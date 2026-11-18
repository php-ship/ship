<?php

declare(strict_types=1);

namespace Ship\Docker;

use Ship\Config\ShipConfig;
use Ship\Contracts\DescribesRelease;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\ServiceRegistry;
use Ship\Support\ShipVersion;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Writes `dist/ship/<tag>/` once the production images are built: pins every image ship doesn't
 * build to its digest, saves the built ones, and writes the compose file, `.env`, deploy script,
 * README and release.json (see the ship-release contract in ship-ecosystem/spec).
 *
 * Everything is written into a staging folder next to the destination, release.json last, and
 * the folder is renamed into place only once all of it succeeded. A failure removes the staging
 * folder, so `dist/ship/<tag>` is either complete or absent.
 */
final class ReleaseWriter
{
    /**
     * The user every production process of the app runs as (php-fpm's workers, Octane, Reverb and
     * `processes` drop to it). Commands run in the app as anyone else, root included, would leave
     * files under storage/ the app can no longer write.
     */
    public const CONSOLE_USER = 'www-data';

    private readonly Filesystem $filesystem;

    public function __construct(
        private readonly ReleaseDocker $docker,
        private readonly ServiceRegistry $registry,
    ) {
        $this->filesystem = new Filesystem();
    }

    /**
     * The release folder for $tag, refusing a symlink, and an existing release unless $force.
     *
     * @throws ReleaseFailed
     */
    public static function destination(string $projectRoot, string $tag, bool $force): string
    {
        $destination = $projectRoot . '/dist/ship/' . $tag;

        if (is_link($destination)) {
            throw new ReleaseFailed("dist/ship/{$tag} is a symlink -- refusing to write a release through it. Remove it first.");
        }

        if (file_exists($destination) && !$force) {
            throw new ReleaseFailed("dist/ship/{$tag} already exists -- pick a new tag, or pass --force to replace that release.");
        }

        return $destination;
    }

    /**
     * @param list<array{tag: string, canonicalService: string, members: list<string>}> $images the
     *        project-owned images ProductionBuildRunner built
     * @param array{gitCommit: ?string, dirty: ?bool} $source captured before the build, which
     *        writes generated files into the project
     * @param array<string, string> $consoleCommands name => command line, from the matching
     *        framework adapters (FrameworkAdapter::consoleCommands())
     * @throws ReleaseFailed
     */
    public function write(
        string $projectRoot,
        string $tag,
        ShipConfig $config,
        string $projectName,
        string $productionComposeYaml,
        array $images,
        array $source,
        ?DescribesRelease $framework,
        array $consoleCommands,
        bool $force,
        bool $withEnv,
        OutputInterface $output,
    ): string {
        $destination = self::destination($projectRoot, $tag, $force);

        /** @var array{services: array<string, array<string, mixed>>} $production */
        $production = Yaml::parse($productionComposeYaml);

        if ($images === []) {
            throw new ReleaseFailed('no project-owned image was built -- there is nothing to release.');
        }

        $pinned = $this->pinPulledImages($production['services'], $output);
        $builtFacts = $this->inspectBuiltImages($images);

        $staging = $projectRoot . '/dist/ship/.release-' . bin2hex(random_bytes(6));
        $this->filesystem->mkdir($staging . '/images', 0o700);
        $this->filesystem->chmod([$staging, $staging . '/images'], 0o700);

        try {
            $releaseCompose = $this->releaseCompose($production, $pinned['refs']);
            $releaseComposeYaml = Yaml::dump($releaseCompose, inline: 6, indent: 2, flags: Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
            file_put_contents($staging . '/' . ReleaseManifest::COMPOSE_FILE, $releaseComposeYaml);

            $envProduction = $projectRoot . '/.env.production';
            if ($withEnv) {
                $this->filesystem->copy($envProduction, $staging . '/.env');
                $this->filesystem->chmod($staging . '/.env', 0o600);
            }

            $archives = $this->saveImages($images, $staging, $output);

            $deploy = $this->deploy($config, $productionComposeYaml);

            $manifest = ReleaseManifest::build(
                app: $projectName,
                tag: $tag,
                createdAt: new \DateTimeImmutable(),
                builderVersion: ShipVersion::current() ?? 'unknown',
                source: $source,
                platform: $builtFacts['platform'],
                images: array_map(
                    static fn (array $image): array => [
                        'ref' => $image['tag'],
                        'id' => $builtFacts['ids'][$image['tag']],
                        'file' => $archives[$image['tag']]['file'],
                        'sha256' => $archives[$image['tag']]['sha256'],
                        'services' => $image['members'],
                    ],
                    $images,
                ),
                pulledImages: $pinned['images'],
                releaseServices: $releaseCompose['services'],
                roles: $this->roles($config, $production['services'], $productionComposeYaml),
                http: $this->http($releaseCompose['services'], $config, $framework, $projectRoot),
                framework: $framework?->frameworkName(),
                runtime: $config->services['runtime'] ?? 'php-fpm',
                envFile: $withEnv ? '.env' : null,
                envKeys: array_keys(EnvFile::parse($envProduction)),
                envReferenced: ReleaseManifest::referencedVariables($releaseComposeYaml),
                envRequired: [...RequiredEnv::requiredIn($releaseComposeYaml), ...($framework?->requiredEnv($projectRoot) ?? [])],
                deploy: $deploy,
                networks: $config->externalNetwork === null ? [] : [$config->externalNetwork],
                console: [
                    'service' => $config->serviceNames['app'] ?? 'app',
                    'user' => self::CONSOLE_USER,
                    'shell' => ['sh'],
                    'commands' => array_map(
                        // FrameworkAdapter::consoleCommands() gives a command line such as "php artisan".
                        static fn (string $command): array => explode(' ', (string) preg_replace('/\s+/', ' ', trim($command))),
                        $consoleCommands,
                    ),
                ],
            );

            if ($deploy !== null) {
                file_put_contents($staging . '/deploy-commands.sh', self::deployScript($deploy));
                $this->filesystem->chmod($staging . '/deploy-commands.sh', 0o755);
            }

            file_put_contents($staging . '/README.md', self::readme($manifest));

            // Last: a release folder without release.json is incomplete.
            file_put_contents(
                $staging . '/release.json',
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            );

            $this->moveIntoPlace($staging, $destination, $tag);
        } catch (\Throwable $e) {
            $this->filesystem->remove($staging);

            throw $e instanceof ReleaseFailed ? $e : new ReleaseFailed($e->getMessage(), previous: $e);
        }

        return $destination;
    }

    /**
     * deploy-commands.sh, generated from release.json's `deploy` so the two can't disagree: the
     * services it needs come up healthy first, then each command runs in a one-off container of
     * `deploy.service` with nothing else started.
     *
     * @param array{service: string, needs: list<string>, commands: list<list<string>>} $deploy
     */
    public static function deployScript(array $deploy): string
    {
        $lines = [
            '#!/bin/sh',
            '',
            '# Generated by `ship release` from release.json\'s "deploy". Run it once per deploy from this',
            '# folder, after loading images/*.tar and before `docker compose up -d`.',
            'set -e',
            '',
        ];

        if ($deploy['needs'] !== []) {
            $lines[] = 'docker compose up -d --wait ' . implode(' ', array_map(self::shellQuote(...), $deploy['needs']));
            $lines[] = '';
        }

        foreach ($deploy['commands'] as $argv) {
            $lines[] = 'docker compose run --rm --no-deps -T ' . self::shellQuote($deploy['service']) . ' '
                . implode(' ', array_map(self::shellQuote(...), $argv));
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * The manual deploy steps for this release, from its manifest.
     *
     * @param array<string, mixed> $manifest
     */
    public static function readme(array $manifest): string
    {
        /** @var list<array{file: string}> $images */
        $images = $manifest['images'];
        /** @var array{file: ?string} $env */
        $env = $manifest['env'];
        /** @var array{networks: list<string>} $requires */
        $requires = $manifest['requires'];

        $steps = array_map(static fn (array $image): string => 'docker load -i ' . self::shellQuote($image['file']), $images);

        foreach ($requires['networks'] as $network) {
            array_unshift($steps, 'docker network create ' . self::shellQuote($network) . '  # only if it does not exist yet');
        }

        if ($manifest['deploy'] !== null) {
            $steps[] = 'sh deploy-commands.sh';
        }

        $steps[] = 'docker compose up -d';

        $envNote = $env['file'] !== null
            ? "`.env` holds this release's production secrets (a copy of `.env.production`). Keep it private\n"
                . "and don't commit or share it.\n"
            : "This release has no `.env` (`ship release --no-env`). Before running the steps, provide every\n"
                . "variable in release.json's `env.referenced` to Compose and every one in `env.keys` to the app,\n"
                . "e.g. by writing a `.env` file here.\n";

        return "# {$manifest['app']} {$manifest['tag']}\n\n"
            . "Built by {$manifest['builder']['package']} {$manifest['builder']['version']} for {$manifest['platform']}.\n"
            . "release.json describes this release; see the ship-release contract.\n\n"
            . "## Deploy\n\nOn a server with Docker, from this folder:\n\n```sh\n"
            . implode("\n", $steps) . "\n```\n\n"
            . "## Secrets\n\n" . $envNote;
    }

    /**
     * POSIX shell quoting. Plain words stay readable; anything else is single-quoted.
     */
    public static function shellQuote(string $argument): string
    {
        if ($argument !== '' && preg_match('/^[A-Za-z0-9_.\/:=@%+-]+$/', $argument) === 1) {
            return $argument;
        }

        return "'" . str_replace("'", "'\\''", $argument) . "'";
    }

    /**
     * Pulls every image ship doesn't build and resolves it to `name:tag@sha256:...`, the reference
     * the release compose file then uses.
     *
     * @param array<string, array<string, mixed>> $services the production compose services
     * @return array{refs: array<string, string>, images: list<array{ref: string, services: list<string>}>}
     * @throws ReleaseFailed
     */
    private function pinPulledImages(array $services, OutputInterface $output): array
    {
        $byRef = [];

        foreach ($services as $name => $service) {
            if (!isset($service['build']) && is_string($service['image'] ?? null)) {
                $byRef[$service['image']][] = $name;
            }
        }

        ksort($byRef, SORT_STRING);
        $refs = [];
        $images = [];

        foreach ($byRef as $ref => $serviceNames) {
            $output->writeln("<info>ship: pulling {$ref}</info>");

            if (!$this->docker->pull($ref)) {
                throw new ReleaseFailed("pulling {$ref} failed -- see the output above.");
            }

            $digest = $this->docker->repoDigest($ref);

            if ($digest === null) {
                throw new ReleaseFailed(
                    "{$ref} has no repository digest, so the release can't pin it -- it must come from a registry, "
                        . 'not a local build (used by ' . implode(', ', $serviceNames) . ').',
                );
            }

            $pinned = explode('@', $ref, 2)[0] . '@' . $digest;
            $refs[$ref] = $pinned;
            $images[] = ['ref' => $pinned, 'services' => $serviceNames];
        }

        return ['refs' => $refs, 'images' => $images];
    }

    /**
     * @param list<array{tag: string, canonicalService: string, members: list<string>}> $images
     * @return array{platform: string, ids: array<string, string>}
     * @throws ReleaseFailed
     */
    private function inspectBuiltImages(array $images): array
    {
        $ids = [];
        $platforms = [];

        foreach ($images as $image) {
            $id = $this->docker->imageId($image['tag']);
            $platform = $this->docker->platform($image['tag']);

            if ($id === null || $platform === null) {
                throw new ReleaseFailed("the built image {$image['tag']} can't be inspected.");
            }

            $ids[$image['tag']] = $id;
            $platforms[$platform][] = $image['tag'];
        }

        if (count($platforms) > 1) {
            $details = [];
            foreach ($platforms as $platform => $tags) {
                $details[] = "{$platform} (" . implode(', ', $tags) . ')';
            }

            throw new ReleaseFailed('the built images are for different platforms: ' . implode('; ', $details) . '.');
        }

        return ['platform' => (string) array_key_first($platforms), 'ids' => $ids];
    }

    /**
     * The production compose file without `build:`, with every pulled image pinned.
     *
     * @param array{services: array<string, array<string, mixed>>} $production
     * @param array<string, string> $pinnedRefs
     * @return array{services: array<string, array<string, mixed>>}
     */
    private function releaseCompose(array $production, array $pinnedRefs): array
    {
        foreach ($production['services'] as $name => $service) {
            unset($production['services'][$name]['build']);

            if (is_string($service['image'] ?? null) && isset($pinnedRefs[$service['image']])) {
                $production['services'][$name]['image'] = $pinnedRefs[$service['image']];
            }
        }

        return $production;
    }

    /**
     * One `docker save` per project-owned image.
     *
     * @param list<array{tag: string, canonicalService: string, members: list<string>}> $images
     * @return array<string, array{file: string, sha256: string}>
     * @throws ReleaseFailed
     */
    private function saveImages(array $images, string $staging, OutputInterface $output): array
    {
        $archives = [];

        foreach ($images as $image) {
            $file = "images/{$image['canonicalService']}.tar";
            $output->writeln("<info>ship: exporting {$image['tag']} -> {$file}</info>");

            if (!$this->docker->save($image['tag'], $staging . '/' . $file) || !is_file($staging . '/' . $file)) {
                throw new ReleaseFailed("exporting {$image['tag']} failed -- see the output above.");
            }

            $archives[$image['tag']] = ['file' => $file, 'sha256' => (string) hash_file('sha256', $staging . '/' . $file)];
        }

        return $archives;
    }

    /**
     * @return ?array{service: string, needs: list<string>, commands: list<list<string>>}
     */
    private function deploy(ShipConfig $config, string $productionComposeYaml): ?array
    {
        if ($config->deployCommands === []) {
            return null;
        }

        return [
            'service' => $config->serviceNames['app'] ?? 'app',
            // From the production compose file, which still has `build:` (see
            // DeployPlan::infrastructureServices()).
            'needs' => DeployPlan::infrastructureServices($productionComposeYaml),
            'commands' => array_map(static fn (string $command): array => ['sh', '-c', $command], $config->deployCommands),
        ];
    }

    /**
     * "app" and "webserver" by name; everything else built from ship's own Dockerfiles (processes,
     * Reverb) is a process; everything else (pulled, or built from its own Dockerfile like Garage)
     * is infrastructure.
     *
     * @param array<string, array<string, mixed>> $productionServices
     * @return array<string, string>
     */
    private function roles(ShipConfig $config, array $productionServices, string $productionComposeYaml): array
    {
        $app = $config->serviceNames['app'] ?? 'app';
        $web = $config->serviceNames['webserver'] ?? 'webserver';
        $infrastructure = DeployPlan::infrastructureServices($productionComposeYaml);
        $roles = [];

        foreach (array_keys($productionServices) as $name) {
            $roles[$name] = match (true) {
                $name === $app => ReleaseManifest::ROLE_APP,
                $name === $web => ReleaseManifest::ROLE_WEB,
                in_array($name, $infrastructure, true) => ReleaseManifest::ROLE_INFRASTRUCTURE,
                default => ReleaseManifest::ROLE_PROCESS,
            };
        }

        return $roles;
    }

    /**
     * @param array<string, array<string, mixed>> $releaseServices
     * @return list<array{service: string, port: int, role: string, health: ?string, urlSetting: ?string}>
     */
    private function http(array $releaseServices, ShipConfig $config, ?DescribesRelease $framework, string $projectRoot): array
    {
        $health = $framework?->healthPath($projectRoot);
        $urlSetting = $framework?->siteUrlSetting();

        return array_map(
            static fn (array $entry): array => $entry['role'] === 'site'
                ? [...$entry, 'health' => $health, 'urlSetting' => $urlSetting]
                : $entry,
            HttpEntryPoints::resolve($releaseServices, $config, $this->registry, ShipEnvironment::Production),
        );
    }

    /**
     * Renames the finished staging folder to $destination. An existing release (--force) is moved
     * aside first and deleted only once the new one is in place.
     *
     * @throws ReleaseFailed
     */
    private function moveIntoPlace(string $staging, string $destination, string $tag): void
    {
        // Re-checked: the destination may have appeared while the images were exported.
        if (is_link($destination)) {
            throw new ReleaseFailed("dist/ship/{$tag} is a symlink -- refusing to write a release through it. Remove it first.");
        }

        $old = null;

        if (file_exists($destination)) {
            $old = dirname($staging) . '/.release-old-' . bin2hex(random_bytes(6));

            if (!@rename($destination, $old)) {
                throw new ReleaseFailed("couldn't move the existing dist/ship/{$tag} aside to replace it.");
            }
        }

        if (!@rename($staging, $destination)) {
            if ($old !== null) {
                @rename($old, $destination);
            }

            throw new ReleaseFailed("couldn't move the finished release into dist/ship/{$tag}.");
        }

        if ($old !== null) {
            $this->filesystem->remove($old);
        }
    }
}

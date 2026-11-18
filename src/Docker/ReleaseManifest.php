<?php

declare(strict_types=1);

namespace Ship\Docker;

/**
 * release.json, the "ship-release" contract (docs/release-contract.md in ship-ecosystem/spec; its
 * schema is copied to schema/ship-release.schema.json).
 * Pure: ReleaseWriter collects the facts, this shapes them. No `.env.production` value is passed
 * in, so none can end up in the file.
 */
final class ReleaseManifest
{
    public const FORMAT = 'ship-release';
    public const BUILDER_PACKAGE = 'php-ship/ship';
    public const COMPOSE_FILE = 'docker-compose.yml';

    public const ROLE_APP = 'app';
    public const ROLE_WEB = 'web';
    public const ROLE_PROCESS = 'process';
    public const ROLE_INFRASTRUCTURE = 'infrastructure';

    /**
     * @param array{gitCommit: ?string, dirty: ?bool} $source
     * @param list<array{ref: string, id: string, file: string, sha256: string, services: list<string>}> $images
     * @param list<array{ref: string, services: list<string>}> $pulledImages
     * @param array<string, array<string, mixed>> $releaseServices the release compose file's services
     * @param array<string, string> $roles service name => role
     * @param list<array{service: string, port: int, role: string, health: ?string, urlSetting: ?string}> $http
     * @param list<string> $envKeys
     * @param list<string> $envReferenced
     * @param list<string> $envRequired
     * @param ?array{service: string, needs: list<string>, commands: list<list<string>>} $deploy
     * @param list<string> $networks
     * @param array{service: string, user: string, shell: list<string>, commands: array<string, list<string>>} $console
     * @return array<string, mixed>
     */
    public static function build(
        string $app,
        string $tag,
        \DateTimeImmutable $createdAt,
        string $builderVersion,
        array $source,
        string $platform,
        array $images,
        array $pulledImages,
        array $releaseServices,
        array $roles,
        array $http,
        ?string $framework,
        string $runtime,
        ?string $envFile,
        array $envKeys,
        array $envReferenced,
        array $envRequired,
        ?array $deploy,
        array $networks,
        array $console,
    ): array {
        return [
            'format' => self::FORMAT,
            'app' => $app,
            'tag' => $tag,
            'createdAt' => $createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'builder' => ['package' => self::BUILDER_PACKAGE, 'version' => $builderVersion],
            'source' => $source,
            'platform' => $platform,
            'compose' => self::COMPOSE_FILE,
            'images' => array_map(
                static fn (array $image): array => [...$image, 'services' => self::sorted($image['services'])],
                $images,
            ),
            'pulledImages' => array_map(
                static fn (array $image): array => [...$image, 'services' => self::sorted($image['services'])],
                $pulledImages,
            ),
            'http' => $http,
            'services' => self::services($releaseServices, $roles),
            'framework' => $framework,
            'runtime' => $runtime,
            'env' => [
                'file' => $envFile,
                'keys' => self::sorted($envKeys),
                'referenced' => self::sorted($envReferenced),
                'required' => self::sorted($envRequired),
            ],
            'deploy' => $deploy === null ? null : [...$deploy, 'needs' => self::sorted($deploy['needs'])],
            'requires' => ['networks' => self::sorted($networks)],
            'console' => [...$console, 'commands' => self::sortedByKey($console['commands'])],
        ];
    }

    /**
     * Every variable Compose interpolates in $composeYaml, in `$VAR` or `${VAR...}` form. `$$` is
     * an escaped literal `$` (a `processes` command's own shell variable), not a variable.
     *
     * @return list<string>
     */
    public static function referencedVariables(string $composeYaml): array
    {
        $unescaped = str_replace('$$', '', $composeYaml);

        if (preg_match_all('/\$\{?([A-Za-z_][A-Za-z0-9_]*)/', $unescaped, $matches) === 0) {
            return [];
        }

        return self::sorted($matches[1]);
    }

    /**
     * The named volumes a service mounts, as Compose declares them (no project prefix). Bind mounts
     * (paths) are not volumes.
     *
     * @param array<string, mixed> $service
     * @return list<string>
     */
    public static function namedVolumes(array $service): array
    {
        $names = [];

        foreach (is_array($service['volumes'] ?? null) ? $service['volumes'] : [] as $mount) {
            $source = is_string($mount) ? explode(':', $mount, 2)[0] : (is_array($mount) ? (string) ($mount['source'] ?? '') : '');
            $isNamed = is_string($mount) ? str_contains($mount, ':') : ($mount['type'] ?? 'volume') === 'volume';

            if ($isNamed && $source !== '' && !str_starts_with($source, '.') && !str_starts_with($source, '/') && !str_starts_with($source, '~')) {
                $names[] = $source;
            }
        }

        return self::sorted($names);
    }

    /**
     * @param array<string, array<string, mixed>> $releaseServices
     * @param array<string, string> $roles
     * @return array<string, array{role: string, image: string, healthcheck: bool, volumes: list<string>}>
     */
    private static function services(array $releaseServices, array $roles): array
    {
        $services = [];

        foreach ($releaseServices as $name => $service) {
            $healthcheck = $service['healthcheck'] ?? null;

            $services[$name] = [
                'role' => $roles[$name] ?? self::ROLE_INFRASTRUCTURE,
                'image' => (string) ($service['image'] ?? ''),
                'healthcheck' => is_array($healthcheck) && ($healthcheck['disable'] ?? false) !== true,
                'volumes' => self::namedVolumes($service),
            ];
        }

        ksort($services, SORT_STRING);

        return $services;
    }

    /**
     * @template T
     * @param array<string, T> $map
     * @return array<string, T>
     */
    private static function sortedByKey(array $map): array
    {
        ksort($map, SORT_STRING);

        return $map;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }
}

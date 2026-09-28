<?php

declare(strict_types=1);

namespace Ship\Docker;

use Symfony\Component\Yaml\Yaml;

/**
 * The docker commands behind ship.json's deployCommands (see ShipConfig::$deployCommands), kept as
 * plain argv builders -- not tied to UpCommand -- so anything that needs the same "bring the
 * infrastructure up, then run each one-off step against it" sequence can reuse it.
 */
final class DeployPlan
{
    /**
     * Services with no `build:` -- the databases, caches and the like an image pulled straight from
     * a registry provides. These have to be up (and healthy, where they declare a healthcheck)
     * before a deploy command runs, since nothing else starts them: the app service has no
     * depends_on for them, and a one-off `docker compose run` only starts its own dependencies.
     * Anything that builds from ship/Dockerfile (the app, its nginx, Reverb, extra processes) is
     * excluded -- those are exactly what must *not* start until the deploy commands succeed.
     *
     * @return list<string>
     */
    public static function infrastructureServices(string $composeYaml): array
    {
        /** @var array{services?: array<string, array<string, mixed>>} $parsed */
        $parsed = Yaml::parse($composeYaml);

        $names = [];

        foreach ($parsed['services'] ?? [] as $name => $service) {
            if (!isset($service['build'])) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * `-T` because this can run somewhere with no terminal at all (CI); `--no-deps` so it never
     * starts anything as a side effect; `sh -c` so a command can be any shell line (`php artisan
     * migrate --force && php artisan db:seed`), not just a single executable.
     *
     * @return list<string>
     */
    public static function runArgs(string $projectRoot, string $appService, string $command): array
    {
        return [
            ...ComposeCommand::baseArgs($projectRoot),
            'run', '--rm', '--no-deps', '-T',
            $appService, 'sh', '-c', $command,
        ];
    }

    /**
     * @param list<string> $services
     * @return list<string>
     */
    public static function startInfrastructureArgs(string $projectRoot, array $services): array
    {
        return [...ComposeCommand::baseArgs($projectRoot), 'up', '-d', '--wait', ...$services];
    }
}

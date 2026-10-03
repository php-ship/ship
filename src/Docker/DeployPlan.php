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
     * Everything except the project's own application code (the app, its nginx, Reverb, extra
     * processes -- anything building from `ship/Dockerfile` or `ship/Dockerfile.frankenphp`,
     * whichever target). Databases, caches, and the like an image pulled straight from a registry
     * provides are the obvious case, but a project-owned service with its own small packaging
     * Dockerfile for unrelated reasons (Garage's own tiny from-scratch image, say) is
     * infrastructure here too, not application code -- a real bug found via an independent audit:
     * the original `!isset($service['build'])` check used "has a build: at all" as a proxy for
     * "is this the app's own code," which happened to also catch Garage, leaving a "create the
     * bucket" deploy command to run against a Garage that was never started. Everything this
     * returns has to be up (and healthy, where it declares a healthcheck) before a deploy command
     * runs, since nothing else starts it: the app service has no `depends_on` for any of it, and a
     * one-off `docker compose run` only starts its own dependencies.
     *
     * @return list<string>
     */
    public static function infrastructureServices(string $composeYaml): array
    {
        /** @var array{services?: array<string, array<string, mixed>>} $parsed */
        $parsed = Yaml::parse($composeYaml);

        $appDockerfiles = ['ship/Dockerfile', 'ship/Dockerfile.frankenphp'];
        $names = [];

        foreach ($parsed['services'] ?? [] as $name => $service) {
            $dockerfile = $service['build']['dockerfile'] ?? null;

            if (!in_array($dockerfile, $appDockerfiles, true)) {
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

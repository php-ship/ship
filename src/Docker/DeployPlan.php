<?php

declare(strict_types=1);

namespace Ship\Docker;

use Symfony\Component\Yaml\Yaml;

/**
 * Argv builders for ship.json's deployCommands (see ShipConfig::$deployCommands): bring the
 * infrastructure up, then run each one-off step against it.
 */
final class DeployPlan
{
    /**
     * Every service that isn't the project's application code, i.e. doesn't build from
     * `ship/Dockerfile` or `ship/Dockerfile.frankenphp`. That includes a service with its own
     * packaging Dockerfile, such as Garage. These have to be up and healthy before a deploy
     * command runs, since nothing else starts them: the app has no `depends_on` for them.
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
     * `-T` because there may be no terminal (CI), `--no-deps` so nothing else starts as a side
     * effect, `sh -c` so a command can be any shell line.
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

<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;

final class OctaneRoadRunnerService implements ServiceDefinition
{
    public function key(): string
    {
        return 'octane-roadrunner';
    }

    public function label(): string
    {
        return 'Octane (RoadRunner)';
    }

    public function group(): string
    {
        return 'runtime';
    }

    // Only one application runtime ever makes sense per project, so $instanceName -- always null
    // here in practice, nothing ever offers a second one to select -- is unused.

    public function composeFragment(ShipEnvironment $environment, ?string $instanceName = null): array
    {
        // --watch (dev only) -- see OctaneSwooleService's own comment for why and what it needs
        // from the project (Node, already unconditional, plus the project's own "chokidar" npm
        // package).
        $command = ['php', 'artisan', 'octane:start', '--server=roadrunner', '--host=0.0.0.0', '--port=8000'];
        if ($environment->isDevelopment()) {
            $command[] = '--watch';
        }

        return [
            'app' => [
                'command' => $command,
                'ports' => ['${APP_PORT:-8000}:8000'],
            ],
        ];
    }

    public function environmentVariables(?string $instanceName = null): array
    {
        return ['OCTANE_SERVER' => 'roadrunner'];
    }

    public function removes(): array
    {
        return ['webserver'];
    }
}

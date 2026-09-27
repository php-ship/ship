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
        return [
            'app' => [
                'command' => ['sh', '-c', $this->command($environment)],
                'ports' => ['${APP_PORT:-8000}:8000'],
            ],
        ];
    }

    /**
     * --watch (dev only, checked at container boot, not decided once at compose-generation time)
     * -- see OctaneSwooleService's own equivalent for the full reasoning, including the real CI
     * failure that ruled out just always passing it.
     */
    private function command(ShipEnvironment $environment): string
    {
        $start = 'php artisan octane:start --server=roadrunner --host=0.0.0.0 --port=8000';

        if (!$environment->isDevelopment()) {
            return $start;
        }

        return "if [ -d node_modules/chokidar ]; then {$start} --watch; else {$start}; fi";
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

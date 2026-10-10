<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\DevPortBinding;

final class OctaneSwooleService implements ServiceDefinition
{
    public function key(): string
    {
        return 'octane-swoole';
    }

    public function label(): string
    {
        return 'Octane (Swoole) — default';
    }

    public function group(): string
    {
        return 'runtime';
    }

    // Only one application runtime makes sense per project, so $instanceName is unused.

    public function composeFragment(ShipEnvironment $environment, ?string $instanceName = null): array
    {
        // Merged into the existing "app" service, so its build/volumes are kept.
        return [
            'app' => [
                'command' => ['sh', '-c', $this->command($environment)],
                'ports' => [DevPortBinding::bind('${APP_PORT:-8000}:8000', $environment)],
                // An Octane server would otherwise run as root in production. See
                // EntrypointScriptBuilder.
                'environment' => $environment->isDevelopment() ? [] : ['SHIP_RUN_AS' => 'www-data'],
            ],
        ];
    }

    /**
     * --watch in dev: without it Octane keeps serving already-booted code until a manual
     * `octane:reload`. It needs the project's own "chokidar" npm package, so the check happens at
     * container boot: passing --watch without chokidar crash-loops Octane's watcher, and a
     * project that adds it later gets --watch on its next `ship up`.
     */
    private function command(ShipEnvironment $environment): string
    {
        $start = 'php artisan octane:start --server=swoole --host=0.0.0.0 --port=8000';

        if (!$environment->isDevelopment()) {
            return $start;
        }

        return "if [ -d node_modules/chokidar ]; then {$start} --watch; else {$start}; fi";
    }

    public function environmentVariables(?string $instanceName = null): array
    {
        return ['OCTANE_SERVER' => 'swoole'];
    }

    public function removes(): array
    {
        return ['webserver'];
    }
}

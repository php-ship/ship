<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;

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

    public function composeFragment(ShipEnvironment $environment): array
    {
        // Targets the already-defined "app" service — ComposeFileBuilder
        // merges this into it rather than replacing it, so build/volumes
        // from baseServices() are preserved.
        return [
            'app' => [
                'command' => ['php', 'artisan', 'octane:start', '--server=swoole', '--host=0.0.0.0', '--port=8000'],
                'ports' => ['${APP_PORT:-8000}:8000'],
            ],
        ];
    }

    public function environmentVariables(): array
    {
        return ['OCTANE_SERVER' => 'swoole'];
    }

    public function removes(): array
    {
        return ['webserver'];
    }
}

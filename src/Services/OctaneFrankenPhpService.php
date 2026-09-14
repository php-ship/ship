<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;

/**
 * Structurally different from Swoole/RoadRunner: FrankenPHP *is* the server, built atop Caddy, needing
 * `dunglas/frankenphp` as its base image, not `php:*-fpm-alpine` -- so it needs its own Dockerfile,
 * published alongside the default one by InitCommand. Overrides just app.build.dockerfile, solely
 * relying on ComposeFileBuilder's shallow merge to keep context/target/args, fully intact here.
 */
final class OctaneFrankenPhpService implements ServiceDefinition
{
    public function key(): string
    {
        return 'octane-frankenphp';
    }

    public function label(): string
    {
        return 'Octane (FrankenPHP)';
    }

    public function group(): string
    {
        return 'runtime';
    }

    public function composeFragment(ShipEnvironment $environment): array
    {
        return [
            'app' => [
                // Shallow-merged into baseServices()'s "app.build" by
                // ComposeFileBuilder::mergeServiceFragment() — this only
                // overrides "dockerfile", context/target/args from
                // baseServices() are preserved.
                'build' => ['dockerfile' => 'ship/Dockerfile.frankenphp'],
                'command' => ['php', 'artisan', 'octane:start', '--server=frankenphp', '--host=0.0.0.0', '--port=8000'],
                'ports' => ['${APP_PORT:-8000}:8000', '${APP_HTTPS_PORT:-8443}:8443'],
            ],
        ];
    }

    public function environmentVariables(): array
    {
        return ['OCTANE_SERVER' => 'frankenphp'];
    }

    public function removes(): array
    {
        return ['webserver'];
    }
}

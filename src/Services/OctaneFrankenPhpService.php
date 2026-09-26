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

    // Only one application runtime ever makes sense per project, so $instanceName -- always null
    // here in practice, nothing ever offers a second one to select -- is unused.

    public function composeFragment(ShipEnvironment $environment, ?string $instanceName = null): array
    {
        return [
            'app' => [
                // Shallow-merged into baseServices()'s "app.build" by
                // ComposeFileBuilder::mergeServiceFragment() — this only
                // overrides "dockerfile", context/target/args from
                // baseServices() are preserved.
                'build' => ['dockerfile' => 'ship/Dockerfile.frankenphp'],
                // No --watch here, unlike Swoole/RoadRunner (see OctaneSwooleService's own
                // comment) -- FrankenPHP has its own history of --watch hanging every request
                // mid-flight in worker mode specifically inside Docker (php/frankenphp#1293),
                // reportedly fixed upstream but not verified live against this project's own
                // pinned FrankenPHP image. Revisit once that's actually confirmed working here,
                // rather than enabling it on the strength of an upstream issue being closed.
                'command' => ['php', 'artisan', 'octane:start', '--server=frankenphp', '--host=0.0.0.0', '--port=8000'],
                'ports' => ['${APP_PORT:-8000}:8000', '${APP_HTTPS_PORT:-8443}:8443'],
            ],
        ];
    }

    public function environmentVariables(?string $instanceName = null): array
    {
        return ['OCTANE_SERVER' => 'frankenphp'];
    }

    public function removes(): array
    {
        return ['webserver'];
    }
}

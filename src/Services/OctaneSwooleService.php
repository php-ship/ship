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

    // Only one application runtime ever makes sense per project, so $instanceName -- always null
    // here in practice, nothing ever offers a second one to select -- is unused.

    public function composeFragment(ShipEnvironment $environment, ?string $instanceName = null): array
    {
        // Targets the already-defined "app" service — ComposeFileBuilder
        // merges this into it rather than replacing it, so build/volumes
        // from baseServices() are preserved.
        //
        // --watch (dev only, same as Laravel Sail's own Octane setup): without it, Octane keeps
        // serving the worker process's already-booted code, so a PHP change needs a manual
        // `php artisan octane:reload` before it's actually picked up -- surprising for anyone used
        // to plain php-fpm, where every request reloads from disk. Requires Node (already
        // unconditional in the base image) and the project's own "chokidar" npm package -- an
        // app-level dependency `ship` can't install for you, same as every other package in the
        // Services table's own "Still needed in the app" column.
        $command = ['php', 'artisan', 'octane:start', '--server=swoole', '--host=0.0.0.0', '--port=8000'];
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
        return ['OCTANE_SERVER' => 'swoole'];
    }

    public function removes(): array
    {
        return ['webserver'];
    }
}

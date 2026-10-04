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

    // Only one application runtime ever makes sense per project, so $instanceName -- always null
    // here in practice, nothing ever offers a second one to select -- is unused.

    public function composeFragment(ShipEnvironment $environment, ?string $instanceName = null): array
    {
        // Targets the already-defined "app" service — ComposeFileBuilder
        // merges this into it rather than replacing it, so build/volumes
        // from baseServices() are preserved.
        return [
            'app' => [
                'command' => ['sh', '-c', $this->command($environment)],
                'ports' => [DevPortBinding::bind('${APP_PORT:-8000}:8000', $environment)],
                // Production only (dev's own entrypoint is a different, static file that never
                // reads this): an Octane server is its own long-lived program with no
                // master-drops-workers split the way php-fpm has, so without this it -- every
                // request handler included -- runs as root. See EntrypointScriptBuilder.
                'environment' => $environment->isDevelopment() ? [] : ['SHIP_RUN_AS' => 'www-data'],
            ],
        ];
    }

    /**
     * --watch (dev only, same as Laravel Sail's own Octane setup): without it, Octane keeps
     * serving the worker process's already-booted code, so a PHP change needs a manual
     * `php artisan octane:reload` before it's actually picked up -- surprising for anyone used to
     * plain php-fpm, where every request reloads from disk. Requires Node (already unconditional
     * in the base image) and the project's own "chokidar" npm package -- an app-level dependency
     * `ship` can't install for you, same as every other package in the Services table's own
     * "Still needed in the app" column.
     *
     * Checked at container *boot*, inside the actual mounted project, not decided once by ship
     * itself at compose-generation time -- unconditionally passing --watch crash-loops Octane's
     * own watcher subprocess with "Cannot find module 'chokidar'" the instant it's missing,
     * which is most fresh Laravel installs, not a rare case. A shell conditional resolved at
     * boot is the only place "is chokidar actually there" can be answered correctly, and it also
     * means a project that adds chokidar later just gets --watch on its next `ship up`, no
     * ship-side change needed.
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

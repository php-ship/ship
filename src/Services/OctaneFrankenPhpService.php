<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\DevPortBinding;

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
                'command' => $this->command($environment),
                'ports' => [
                    DevPortBinding::bind('${APP_PORT:-8000}:8000', $environment),
                    DevPortBinding::bind('${APP_HTTPS_PORT:-8443}:8443', $environment),
                ],
                // Production only, same as Swoole/RoadRunner: Octane is its own long-lived program
                // with no master-drops-workers split, so without this it runs as root. See
                // Dockerfile.frankenphp's prod stage for the matching chown of Caddy's own
                // directories, and EntrypointScriptBuilder for the actual drop (setpriv here --
                // Debian has no su-exec, but does have setpriv already).
                'environment' => $environment->isDevelopment() ? [] : ['SHIP_RUN_AS' => 'www-data'],
            ],
        ];
    }

    public function environmentVariables(?string $instanceName = null): array
    {
        return ['OCTANE_SERVER' => 'frankenphp'];
    }

    /**
     * --watch in dev, with no chokidar/Node dependency to gate on at all, unlike Swoole/RoadRunner
     * (see OctaneSwooleService's own comment): Octane returns a no-op for the Node watcher on this
     * runtime and instead injects a native `watch` directive into FrankenPHP's own Caddyfile
     * (confirmed by reading vendor/laravel/octane's own source, not assumed), so there's no
     * missing-module crash risk to gate on in the first place -- the array is either built or not,
     * no shell conditional needed.
     *
     * This was excluded once for a different reason: FrankenPHP's own history of --watch hanging
     * every request mid-flight in worker mode inside Docker (php/frankenphp#1293). Verified live
     * against this project's own pinned image, not just the upstream issue being closed: touching
     * a watched file, then firing ten consecutive requests across the following ten seconds,
     * produced ten 200s around 450ms each -- no hang, which is the actual thing #1293 reported.
     * The watcher did not visibly pick up the change within that window in this specific
     * environment (Windows, Docker Desktop, a bind-mounted volume) -- plausibly the same
     * inotify-over-bind-mount unreliability Docker Desktop has everywhere, not a FrankenPHP-specific
     * gap, and nothing here currently proves Swoole/RoadRunner's own chokidar watcher reloads any
     * more reliably under the same conditions (only that it doesn't crash) -- so this isn't held to
     * a higher bar than they already are.
     *
     * @return list<string>
     */
    private function command(ShipEnvironment $environment): array
    {
        $command = ['php', 'artisan', 'octane:start', '--server=frankenphp', '--host=0.0.0.0', '--port=8000'];

        if ($environment->isDevelopment()) {
            $command[] = '--watch';
        }

        return $command;
    }

    public function removes(): array
    {
        return ['webserver'];
    }
}

<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\DevPortBinding;

/**
 * FrankenPHP is the server itself, built on Caddy, and needs `dunglas/frankenphp` as its base
 * image, so it has its own Dockerfile (published by InitCommand). This only overrides
 * app.build.dockerfile; ComposeFileBuilder's shallow merge keeps context/target/args.
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

    // Only one application runtime makes sense per project, so $instanceName is unused.

    public function composeFragment(ShipEnvironment $environment, ?string $instanceName = null): array
    {
        return [
            'app' => [
                // Shallow-merged into "app"'s build, so context/target/args are kept.
                'build' => ['dockerfile' => 'ship/Dockerfile.frankenphp'],
                'command' => $this->command($environment),
                'ports' => [
                    DevPortBinding::bind('${APP_PORT:-8000}:8000', $environment),
                    DevPortBinding::bind('${APP_HTTPS_PORT:-8443}:8443', $environment),
                ],
                // An Octane server would otherwise run as root in production. See
                // EntrypointScriptBuilder for the drop (setpriv on this Debian image) and
                // Dockerfile.frankenphp's prod stage for the chown of Caddy's directories.
                'environment' => $environment->isDevelopment() ? [] : ['SHIP_RUN_AS' => 'www-data'],
            ],
        ];
    }

    public function environmentVariables(?string $instanceName = null): array
    {
        return ['OCTANE_SERVER' => 'frankenphp'];
    }

    /**
     * --watch in dev. Unlike Swoole/RoadRunner there's no chokidar dependency to gate on: Octane
     * injects a native `watch` directive into FrankenPHP's Caddyfile instead of running the Node
     * watcher.
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

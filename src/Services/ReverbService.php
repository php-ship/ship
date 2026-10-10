<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\DevPortBinding;

/**
 * Reverb is a long-running WebSocket server with its own published port, so it's a separate
 * compose service built from the same dev/prod targets as "app". REVERB_APP_ID/KEY/SECRET are
 * per-app credentials from `install:broadcasting` and aren't set here.
 */
final class ReverbService implements ServiceDefinition
{
    public function key(): string
    {
        return 'reverb';
    }

    public function label(): string
    {
        return 'Laravel Reverb (WebSockets)';
    }

    public function group(): string
    {
        return 'broadcasting';
    }

    // Only one broadcasting server makes sense per project, so $instanceName is unused.

    public function composeFragment(ShipEnvironment $environment, ?string $instanceName = null): array
    {
        return [
            'reverb' => [
                'build' => [
                    'context' => '.',
                    'dockerfile' => 'ship/Dockerfile',
                    // The same PHP stages as "app": it's the same Laravel app running a
                    // different artisan command.
                    'target' => $environment->isDevelopment() ? 'dev' : 'prod',
                ],
                'command' => ['php', 'artisan', 'reverb:start', '--host=0.0.0.0', '--port=8080'],
                'volumes' => $environment->isDevelopment() ? ['.:/var/www/html'] : [],
                'ports' => [DevPortBinding::bind('${REVERB_PORT:-8080}:8080', $environment)],
                'networks' => ['ship'],
                // The same optional .env as "app" (see ComposeFileBuilder::OPTIONAL_ENV_FILE).
                'env_file' => [['path' => '.env', 'required' => false]],
            ],
        ];
    }

    public function environmentVariables(?string $instanceName = null): array
    {
        return [
            'BROADCAST_CONNECTION' => 'reverb',
            // Server-to-server: "app" reaches Reverb over the Docker network by its compose
            // service name.
            'REVERB_HOST' => 'reverb',
            'REVERB_PORT' => '8080',
            'REVERB_SCHEME' => 'http',
            // Browser-to-server: Echo runs in the browser, which reaches Reverb through the
            // host-published port. Baked into the frontend bundle by Vite (VITE_-prefixed).
            'VITE_REVERB_HOST' => 'localhost',
            'VITE_REVERB_PORT' => '${REVERB_PORT:-8080}',
            'VITE_REVERB_SCHEME' => 'http',
        ];
    }

    public function removes(): array
    {
        return [];
    }
}

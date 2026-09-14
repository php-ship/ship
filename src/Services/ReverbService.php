<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;

/**
 * Reverb needs its own long process and published port -- a WebSocket server, unlike php-fpm or Octane
 * which serve per-request, so this is a genuinely separate compose service, built from the same dev
 * /prod targets as app itself. Doesn't set REVERB_APP_ID/KEY/SECRET -- those are entirely per-app
 * credentials, from `install:broadcasting`, not infrastructure, this package provisions itself.
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

    public function composeFragment(ShipEnvironment $environment): array
    {
        return [
            'reverb' => [
                'build' => [
                    'context' => '.',
                    'dockerfile' => 'ship/Dockerfile',
                    // Same PHP stages "app" uses, not a Reverb-specific
                    // image — it's the same Laravel app, just a different
                    // artisan command as the container's process.
                    'target' => $environment->isDevelopment() ? 'dev' : 'prod',
                ],
                'command' => ['php', 'artisan', 'reverb:start', '--host=0.0.0.0', '--port=8080'],
                'volumes' => $environment->isDevelopment() ? ['.:/var/www/html'] : [],
                'ports' => ['${REVERB_PORT:-8080}:8080'],
                'networks' => ['ship'],
                // Same optional .env as "app" -- see ComposeFileBuilder::OPTIONAL_ENV_FILE's
                // docblock. Reverb boots a real Laravel app too, so it needs the same secrets.
                'env_file' => [['path' => '.env', 'required' => false]],
            ],
        ];
    }

    public function environmentVariables(): array
    {
        return [
            'BROADCAST_CONNECTION' => 'reverb',
            // Server-to-server: "app"'s own PHP process (dispatching a
            // broadcast, or checking a private-channel auth callback)
            // talks to Reverb over the Docker network by its compose
            // service name — it has no route to the host-published port,
            // and "localhost" here would mean "this container itself",
            // which has no Reverb server listening.
            'REVERB_HOST' => 'reverb',
            'REVERB_PORT' => '8080',
            'REVERB_SCHEME' => 'http',
            // Browser-to-server: Laravel Echo runs in the user's browser,
            // which has no route to the "reverb" Docker DNS name either —
            // it has to go through the same host-published port the
            // WebSocket upgrade request itself uses. Baked into the
            // frontend bundle by Vite at build/dev time (VITE_-prefixed,
            // per Laravel's own broadcasting scaffold). Same
            // container-vs-browser split as the Vite dev server's own
            // port — see ComposeFileBuilder's "app" ports comment.
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

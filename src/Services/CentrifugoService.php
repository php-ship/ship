<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ExposesHttp;
use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\DevPortBinding;
use Ship\Docker\RequiredEnv;

/**
 * Centrifugo, a standalone real-time server: the framework-agnostic alternative to Reverb. It needs
 * no application code; the app publishes through its HTTP API (CENTRIFUGO_URL + CENTRIFUGO_API_KEY,
 * e.g. with centrifugal/phpcent) and signs the browser's connection tokens with
 * CENTRIFUGO_TOKEN_SECRET. Browsers connect to `/connection/websocket`.
 *
 * BROADCAST_CONNECTION is left alone: Laravel has no built-in Centrifugo driver, so setting it before
 * a driver package is installed would break every broadcast. Stateless (in-memory engine), so it
 * has no volume. One per project, like Reverb, so $instanceName is ignored.
 */
final class CentrifugoService implements ServiceDefinition, ExposesHttp
{
    public function key(): string
    {
        return 'centrifugo';
    }

    public function label(): string
    {
        return 'Centrifugo (WebSockets, any framework)';
    }

    public function group(): string
    {
        return 'broadcasting';
    }

    public function composeFragment(ShipEnvironment $environment, ?string $instanceName = null): array
    {
        return [
            'centrifugo' => [
                'image' => 'centrifugo/centrifugo:v6.10',
                'environment' => [
                    // Defaults in dev, required in production (see RequiredEnv).
                    'CENTRIFUGO_HTTP_API_KEY' => RequiredEnv::expr('CENTRIFUGO_API_KEY', 'ship-centrifugo-api-key', $environment),
                    'CENTRIFUGO_CLIENT_TOKEN_HMAC_SECRET_KEY' => RequiredEnv::expr('CENTRIFUGO_TOKEN_SECRET', 'ship-centrifugo-token-secret', $environment),
                    // The origins browsers may connect from, space-separated (`https://*.example.com`
                    // matches subdomains). With none, Centrifugo refuses every browser, same origin
                    // included, so production requires a value; development allows any.
                    'CENTRIFUGO_CLIENT_ALLOWED_ORIGINS' => RequiredEnv::expr('CENTRIFUGO_ALLOWED_ORIGINS', '*', $environment),
                    'CENTRIFUGO_HEALTH_ENABLED' => 'true',
                    // The web admin UI, on the published port, in development only.
                    ...($environment->isDevelopment() ? [
                        'CENTRIFUGO_ADMIN_ENABLED' => 'true',
                        'CENTRIFUGO_ADMIN_PASSWORD' => '${CENTRIFUGO_ADMIN_PASSWORD:-ship}',
                        'CENTRIFUGO_ADMIN_SECRET' => 'ship-centrifugo-admin-secret',
                    ] : []),
                ],
                // 8001 on the host, so it doesn't collide with an Octane app's 8000.
                'ports' => [DevPortBinding::bind('${CENTRIFUGO_PORT:-8001}:8000', $environment)],
                'healthcheck' => [
                    'test' => ['CMD', 'wget', '-qO-', 'http://127.0.0.1:8000/health'],
                    'interval' => '5s',
                    'timeout' => '5s',
                    'retries' => 5,
                ],
            ],
        ];
    }

    public function environmentVariables(?string $instanceName = null): array
    {
        return [
            // Server-to-server: the HTTP API is under /api.
            'CENTRIFUGO_URL' => 'http://centrifugo:8000',
            // The same expressions composeFragment() configures Centrifugo with.
            'CENTRIFUGO_API_KEY' => '${CENTRIFUGO_API_KEY:-ship-centrifugo-api-key}',
            'CENTRIFUGO_TOKEN_SECRET' => '${CENTRIFUGO_TOKEN_SECRET:-ship-centrifugo-token-secret}',
            // Browser-to-server in development, through the host-published port. Read by Vite.
            'VITE_CENTRIFUGO_URL' => 'ws://localhost:${CENTRIFUGO_PORT:-8001}/connection/websocket',
        ];
    }

    public function removes(): array
    {
        return [];
    }

    /**
     * Browsers connect to it directly, so a reverse proxy routes /connection/websocket to it.
     */
    public function httpEntryPoints(): array
    {
        return ['centrifugo' => ['port' => 8000, 'role' => 'websocket', 'health' => '/health']];
    }
}

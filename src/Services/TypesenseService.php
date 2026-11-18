<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\RequiredEnv;

/**
 * Typesense, the search group's alternative to Meilisearch: Laravel Scout's `typesense` driver,
 * with the TYPESENSE_* settings Scout's config reads.
 */
final class TypesenseService implements ServiceDefinition
{
    use SupportsNamedInstances;

    public function key(): string
    {
        return 'typesense';
    }

    public function label(): string
    {
        return 'Typesense';
    }

    public function group(): string
    {
        return 'search';
    }

    public function composeFragment(ShipEnvironment $environment, ?string $instanceName = null): array
    {
        $name = $this->composeServiceName($instanceName);
        $prefix = $this->envPrefix($instanceName);

        return [
            $name => [
                'image' => 'typesense/typesense:30.2',
                'environment' => [
                    // A default in dev, required in production (see RequiredEnv).
                    'TYPESENSE_API_KEY' => RequiredEnv::expr("{$prefix}TYPESENSE_API_KEY", 'shipsearchkey', $environment),
                    'TYPESENSE_DATA_DIR' => '/data',
                ],
                // Persisted in both environments, like every stateful service.
                'volumes' => ["ship-{$name}-data:/data"],
                'healthcheck' => [
                    // The image has no curl, wget or nc, but bash can speak HTTP through /dev/tcp.
                    // /health answers {"ok":true} once the server is ready.
                    'test' => [
                        'CMD',
                        'bash',
                        '-c',
                        'exec 3<>/dev/tcp/127.0.0.1/8108 && printf "GET /health HTTP/1.0\r\n\r\n" >&3 && grep -q \'"ok":true\' <&3',
                    ],
                    'interval' => '5s',
                    'timeout' => '5s',
                    'retries' => 12,
                ],
            ],
        ];
    }

    public function environmentVariables(?string $instanceName = null): array
    {
        $name = $this->composeServiceName($instanceName);
        $prefix = $this->envPrefix($instanceName);

        return [
            // Scout doesn't use Typesense unless SCOUT_DRIVER says so. Only the default instance
            // sets it; a named instance doesn't change the app's default driver.
            ...($instanceName === null ? ['SCOUT_DRIVER' => 'typesense'] : []),
            "{$prefix}TYPESENSE_HOST" => $name,
            "{$prefix}TYPESENSE_PORT" => '8108',
            "{$prefix}TYPESENSE_PROTOCOL" => 'http',
            // The same expression composeFragment() provisions Typesense with.
            "{$prefix}TYPESENSE_API_KEY" => "\${{$prefix}TYPESENSE_API_KEY:-shipsearchkey}",
        ];
    }

    public function removes(): array
    {
        return [];
    }
}

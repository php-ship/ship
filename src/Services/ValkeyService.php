<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;

/**
 * Valkey, the open-source fork of Redis: the cache group's alternative to Redis. It speaks the Redis
 * protocol, so the app uses its `redis` driver and REDIS_* settings unchanged.
 */
final class ValkeyService implements ServiceDefinition
{
    use SupportsNamedInstances;

    public function key(): string
    {
        return 'valkey';
    }

    public function label(): string
    {
        return 'Valkey (Redis-compatible)';
    }

    public function group(): string
    {
        return 'cache';
    }

    public function composeFragment(ShipEnvironment $environment, ?string $instanceName = null): array
    {
        $name = $this->composeServiceName($instanceName);

        return [
            $name => [
                'image' => 'valkey/valkey:9.0-alpine',
                // Persisted, with RDB saves on, in both environments, as with Redis: losing every
                // session on a redeploy logs out every user.
                'command' => ['valkey-server', '--save', '60 1'],
                'volumes' => ["ship-{$name}-data:/data"],
                'healthcheck' => [
                    'test' => ['CMD', 'valkey-cli', 'ping'],
                    'interval' => '5s',
                    'timeout' => '5s',
                    'retries' => 5,
                ],
            ],
        ];
    }

    public function environmentVariables(?string $instanceName = null): array
    {
        $name = $this->composeServiceName($instanceName);
        $prefix = $this->envPrefix($instanceName);

        // CACHE_STORE/SESSION_DRIVER pick the app's default store, so only the default instance
        // sets them. "redis" is Laravel's driver name, which Valkey serves.
        return [
            ...($instanceName === null ? ['CACHE_STORE' => 'redis', 'SESSION_DRIVER' => 'redis'] : []),
            "{$prefix}REDIS_HOST" => $name,
            "{$prefix}REDIS_PORT" => '6379',
        ];
    }

    public function removes(): array
    {
        return [];
    }
}

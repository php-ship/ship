<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;

final class RedisService implements ServiceDefinition
{
    use SupportsNamedInstances;

    public function key(): string
    {
        return 'redis';
    }

    public function label(): string
    {
        return 'Redis';
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
                'image' => 'redis:8-alpine',
                // Persisted, with RDB saves on, in both environments: losing every session on a
                // redeploy logs out every user.
                'command' => ['redis-server', '--save', '60 1'],
                'volumes' => ["ship-{$name}-data:/data"],
                'healthcheck' => [
                    'test' => ['CMD', 'redis-cli', 'ping'],
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
        // sets them.
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

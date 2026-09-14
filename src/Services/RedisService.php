<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;

final class RedisService implements ServiceDefinition
{
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

    public function composeFragment(ShipEnvironment $environment): array
    {
        return [
            'redis' => [
                'image' => 'redis:8-alpine',
                'command' => ['redis-server', '--save', $environment->isDevelopment() ? '60 1' : '""'],
                'volumes' => $environment->isDevelopment()
                    ? ['ship-redis-data:/data']
                    : [],
                'healthcheck' => [
                    'test' => ['CMD', 'redis-cli', 'ping'],
                    'interval' => '5s',
                    'timeout' => '5s',
                    'retries' => 5,
                ],
            ],
        ];
    }

    public function environmentVariables(): array
    {
        return [
            'CACHE_STORE' => 'redis',
            'SESSION_DRIVER' => 'redis',
            'REDIS_HOST' => 'redis',
            'REDIS_PORT' => '6379',
        ];
    }

    public function removes(): array
    {
        return [];
    }
}

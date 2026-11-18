<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\RequiresPhpExtensions;
use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;

/**
 * Memcached, a cache-only alternative to Redis. It keeps everything in memory and nothing across a
 * restart, so it has no volume, and it only becomes the app's cache store: SESSION_DRIVER is left
 * alone, because sessions held in Memcached would log every user out on each restart.
 */
final class MemcachedService implements ServiceDefinition, RequiresPhpExtensions
{
    use SupportsNamedInstances;

    public function key(): string
    {
        return 'memcached';
    }

    public function label(): string
    {
        return 'Memcached (cache only)';
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
                'image' => 'memcached:1.6-alpine',
                'healthcheck' => [
                    // The text protocol's `version` command: the server answers, not just the port.
                    'test' => ['CMD-SHELL', 'echo version | nc -w 2 127.0.0.1 11211 | grep -q "^VERSION"'],
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

        // CACHE_STORE picks the app's default store, so only the default instance sets it.
        return [
            ...($instanceName === null ? ['CACHE_STORE' => 'memcached'] : []),
            "{$prefix}MEMCACHED_HOST" => $name,
            "{$prefix}MEMCACHED_PORT" => '11211',
        ];
    }

    public function removes(): array
    {
        return [];
    }

    /**
     * Laravel's memcached cache driver.
     */
    public function requiredPhpExtensions(): array
    {
        return ['memcached'];
    }
}

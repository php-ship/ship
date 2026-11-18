<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\MemcachedService;

final class MemcachedServiceTest extends TestCase
{
    public function test_it_becomes_the_cache_store_with_the_settings_laravel_reads(): void
    {
        self::assertSame(
            ['CACHE_STORE' => 'memcached', 'MEMCACHED_HOST' => 'memcached', 'MEMCACHED_PORT' => '11211'],
            (new MemcachedService())->environmentVariables(),
        );
    }

    /**
     * Memcached keeps nothing across a restart: sessions there would log every user out.
     */
    public function test_it_leaves_the_session_driver_alone_and_keeps_no_volume(): void
    {
        $service = new MemcachedService();

        self::assertArrayNotHasKey('SESSION_DRIVER', $service->environmentVariables());
        self::assertArrayNotHasKey('volumes', $service->composeFragment(ShipEnvironment::Production)['memcached']);
    }

    /**
     * The `version` command proves the server answers, not just that the port is open.
     */
    public function test_the_healthcheck_asks_memcached_for_its_version(): void
    {
        $fragment = (new MemcachedService())->composeFragment(ShipEnvironment::Development)['memcached'];

        self::assertMatchesRegularExpression('/^memcached:\d+\.\d+-alpine$/', $fragment['image']);
        self::assertSame(['CMD-SHELL', 'echo version | nc -w 2 127.0.0.1 11211 | grep -q "^VERSION"'], $fragment['healthcheck']['test']);
    }

    public function test_it_requires_the_memcached_php_extension(): void
    {
        self::assertSame(['memcached'], (new MemcachedService())->requiredPhpExtensions());
    }

    public function test_a_named_instance_gets_prefixed_connection_settings_only(): void
    {
        self::assertSame(
            ['SESSIONS_MEMCACHED_HOST' => 'memcached-sessions', 'SESSIONS_MEMCACHED_PORT' => '11211'],
            (new MemcachedService())->environmentVariables('sessions'),
        );
    }
}

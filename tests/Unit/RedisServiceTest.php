<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\RedisService;

final class RedisServiceTest extends TestCase
{
    /**
     * A named instance adds a second reachable Redis -- it doesn't change what the app uses by
     * default, so only the default instance may set these.
     */
    public function test_only_the_default_instance_sets_cache_store_and_session_driver(): void
    {
        $service = new RedisService();
        $defaultEnv = $service->environmentVariables();
        $namedEnv = $service->environmentVariables('queue');

        self::assertSame('redis', $defaultEnv['CACHE_STORE']);
        self::assertSame('redis', $defaultEnv['SESSION_DRIVER']);
        self::assertArrayNotHasKey('CACHE_STORE', $namedEnv);
        self::assertArrayNotHasKey('SESSION_DRIVER', $namedEnv);
    }

    public function test_data_volume_only_exists_in_development(): void
    {
        $service = new RedisService();

        self::assertNotSame([], $service->composeFragment(ShipEnvironment::Development)['redis']['volumes']);
        self::assertSame([], $service->composeFragment(ShipEnvironment::Production)['redis']['volumes']);
    }

    public function test_a_named_instance_gets_its_own_host_and_env_prefix(): void
    {
        $service = new RedisService();
        $fragment = $service->composeFragment(ShipEnvironment::Development, 'queue');
        $appEnv = $service->environmentVariables('queue');

        self::assertArrayHasKey('redis-queue', $fragment);
        self::assertSame('redis-queue', $appEnv['QUEUE_REDIS_HOST']);
    }
}

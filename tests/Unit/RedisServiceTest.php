<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\RedisService;

final class RedisServiceTest extends TestCase
{
    /**
     * A named instance doesn't change the app's default cache/session store.
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

    /**
     * Losing every session on a redeploy would log out every user.
     */
    public function test_the_data_volume_persists_in_both_environments(): void
    {
        $service = new RedisService();

        self::assertNotSame([], $service->composeFragment(ShipEnvironment::Development)['redis']['volumes']);
        self::assertNotSame([], $service->composeFragment(ShipEnvironment::Production)['redis']['volumes']);
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

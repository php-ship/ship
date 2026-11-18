<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\ValkeyService;

final class ValkeyServiceTest extends TestCase
{
    /**
     * Valkey speaks the Redis protocol, so the app uses Laravel's redis driver and REDIS_* settings.
     */
    public function test_the_app_uses_its_redis_driver_against_valkey(): void
    {
        self::assertSame(
            ['CACHE_STORE' => 'redis', 'SESSION_DRIVER' => 'redis', 'REDIS_HOST' => 'valkey', 'REDIS_PORT' => '6379'],
            (new ValkeyService())->environmentVariables(),
        );
    }

    public function test_it_is_persisted_with_rdb_saves_like_redis(): void
    {
        $fragment = (new ValkeyService())->composeFragment(ShipEnvironment::Production)['valkey'];

        self::assertMatchesRegularExpression('/^valkey\/valkey:\d+\.\d+-alpine$/', $fragment['image']);
        self::assertSame(['valkey-server', '--save', '60 1'], $fragment['command']);
        self::assertSame(['ship-valkey-data:/data'], $fragment['volumes']);
        self::assertSame(['CMD', 'valkey-cli', 'ping'], $fragment['healthcheck']['test']);
    }

    /**
     * A named instance is a second reachable cache; it doesn't change the app's default store.
     */
    public function test_a_named_instance_gets_prefixed_connection_settings_only(): void
    {
        $service = new ValkeyService();

        self::assertArrayHasKey('valkey-queue', $service->composeFragment(ShipEnvironment::Development, 'queue'));
        self::assertSame(['QUEUE_REDIS_HOST' => 'valkey-queue', 'QUEUE_REDIS_PORT' => '6379'], $service->environmentVariables('queue'));
    }
}

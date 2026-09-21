<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\MeilisearchService;

final class MeilisearchServiceTest extends TestCase
{
    public function test_app_master_key_matches_what_the_container_is_provisioned_with(): void
    {
        $service = new MeilisearchService();
        $containerEnv = $service->composeFragment(ShipEnvironment::Development)['meilisearch']['environment'];
        $appEnv = $service->environmentVariables();

        self::assertSame($containerEnv['MEILI_MASTER_KEY'], $appEnv['MEILISEARCH_KEY']);
    }

    /**
     * A named instance adds a second reachable Meilisearch -- it doesn't change which one Scout
     * uses by default, so only the default instance may set this.
     */
    public function test_only_the_default_instance_sets_scout_driver(): void
    {
        $service = new MeilisearchService();

        self::assertSame('meilisearch', $service->environmentVariables()['SCOUT_DRIVER']);
        self::assertArrayNotHasKey('SCOUT_DRIVER', $service->environmentVariables('analytics'));
    }

    public function test_data_volume_only_exists_in_development(): void
    {
        $service = new MeilisearchService();

        self::assertNotSame([], $service->composeFragment(ShipEnvironment::Development)['meilisearch']['volumes']);
        self::assertSame([], $service->composeFragment(ShipEnvironment::Production)['meilisearch']['volumes']);
    }

    public function test_a_named_instance_gets_its_own_host_and_env_prefix(): void
    {
        $service = new MeilisearchService();
        $appEnv = $service->environmentVariables('analytics');

        self::assertSame('http://meilisearch-analytics:7700', $appEnv['ANALYTICS_MEILISEARCH_HOST']);
    }
}

<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\SeaweedFsService;

final class SeaweedFsServiceTest extends TestCase
{
    /**
     * Regression test for a real bug found via live Docker verification: this image's own DNS
     * resolver tries ::1 (IPv6 loopback) first, which nothing listens on, so a healthcheck against
     * "localhost" reports connection refused forever even though the master API works fine on
     * IPv4 -- the container never reports healthy despite actually being up. 127.0.0.1 sidesteps
     * the resolver entirely. Locks in the fix so a future "cleanup" back to localhost can't
     * silently reintroduce it.
     */
    public function test_the_healthcheck_targets_the_ipv4_loopback_address_explicitly(): void
    {
        $fragment = (new SeaweedFsService())->composeFragment(ShipEnvironment::Development);

        $test = implode(' ', $fragment['seaweedfs']['healthcheck']['test']);

        self::assertStringContainsString('127.0.0.1', $test);
        self::assertStringNotContainsString('localhost', $test);
    }

    public function test_app_credentials_match_what_seaweedfs_is_provisioned_with(): void
    {
        $service = new SeaweedFsService();
        $appEnv = $service->environmentVariables();

        self::assertSame('ship', $appEnv['AWS_ACCESS_KEY_ID']);
        self::assertSame('shipsecret', $appEnv['AWS_SECRET_ACCESS_KEY']);
        self::assertStringContainsString('seaweedfs', $appEnv['AWS_ENDPOINT']);
    }

    public function test_data_volume_only_exists_in_development(): void
    {
        $service = new SeaweedFsService();

        self::assertNotSame([], $service->composeFragment(ShipEnvironment::Development)['seaweedfs']['volumes']);
        self::assertSame([], $service->composeFragment(ShipEnvironment::Production)['seaweedfs']['volumes']);
    }

    public function test_a_named_instance_gets_its_own_endpoint_and_env_prefix(): void
    {
        $service = new SeaweedFsService();
        $fragment = $service->composeFragment(ShipEnvironment::Development, 'archive');
        $appEnv = $service->environmentVariables('archive');

        self::assertArrayHasKey('seaweedfs-archive', $fragment);
        self::assertSame('http://seaweedfs-archive:8333', $appEnv['ARCHIVE_AWS_ENDPOINT']);
    }
}

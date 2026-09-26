<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\SiloService;

final class SiloServiceTest extends TestCase
{
    public function test_app_credentials_match_what_silo_is_provisioned_with(): void
    {
        $service = new SiloService();
        $fragment = $service->composeFragment(ShipEnvironment::Development);
        $siloEnv = $fragment['silo']['environment'];
        $appEnv = $service->environmentVariables();

        self::assertSame($siloEnv['MINIO_ROOT_USER'], $appEnv['AWS_ACCESS_KEY_ID']);
        self::assertSame($siloEnv['MINIO_ROOT_PASSWORD'], $appEnv['AWS_SECRET_ACCESS_KEY']);
        self::assertStringContainsString('silo', $appEnv['AWS_ENDPOINT']);
    }

    public function test_data_volume_only_exists_in_development(): void
    {
        $service = new SiloService();

        self::assertNotSame([], $service->composeFragment(ShipEnvironment::Development)['silo']['volumes']);
        self::assertSame([], $service->composeFragment(ShipEnvironment::Production)['silo']['volumes']);
    }

    public function test_a_named_instance_gets_its_own_endpoint_and_env_prefix(): void
    {
        $service = new SiloService();
        $fragment = $service->composeFragment(ShipEnvironment::Development, 'archive');
        $appEnv = $service->environmentVariables('archive');

        self::assertArrayHasKey('silo-archive', $fragment);
        self::assertSame('http://silo-archive:9000', $appEnv['ARCHIVE_AWS_ENDPOINT']);
    }

    /**
     * The console is the one thing RustFS/Silo offer that SeaweedFS/Garage don't -- host-published
     * so it's actually reachable from a browser, unlike the S3 API port, which only ever needs to
     * be reachable from "app" over the internal network.
     */
    public function test_the_console_port_is_published_but_the_s3_api_port_is_not(): void
    {
        $fragment = (new SiloService())->composeFragment(ShipEnvironment::Development);

        self::assertSame(['${SILO_CONSOLE_PORT:-9001}:9001'], $fragment['silo']['ports']);
    }

    /**
     * A second named instance (storage supports additionalServices) must not collide with the
     * default one on the same host port -- the env var itself, not just the port default, has to
     * be instance-scoped.
     */
    public function test_a_named_instances_console_port_env_var_is_instance_scoped(): void
    {
        $fragment = (new SiloService())->composeFragment(ShipEnvironment::Development, 'archive');

        self::assertSame(['${ARCHIVE_SILO_CONSOLE_PORT:-9001}:9001'], $fragment['silo-archive']['ports']);
    }

    /**
     * --console-address is what actually turns the console on at all (upstream MinIO's own
     * behavior, inherited unmodified) -- silently losing this flag would still start the server,
     * making the missing console a lot less obvious than an outright startup failure.
     */
    public function test_the_console_is_explicitly_enabled_on_its_own_address(): void
    {
        $fragment = (new SiloService())->composeFragment(ShipEnvironment::Development);

        self::assertSame(['server', '/data', '--console-address', ':9001'], $fragment['silo']['command']);
    }
}

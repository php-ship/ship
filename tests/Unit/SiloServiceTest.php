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

    public function test_the_data_volume_persists_in_both_environments(): void
    {
        $service = new SiloService();

        self::assertNotSame([], $service->composeFragment(ShipEnvironment::Development)['silo']['volumes']);
        self::assertNotSame([], $service->composeFragment(ShipEnvironment::Production)['silo']['volumes']);
    }

    /**
     * "ship"/"shipsecret" are overridable defaults, not literals.
     */
    public function test_credentials_are_overridable_defaults_not_hardcoded_literals(): void
    {
        $fragment = (new SiloService())->composeFragment(ShipEnvironment::Development);

        self::assertSame('${AWS_ACCESS_KEY_ID:-ship}', $fragment['silo']['environment']['MINIO_ROOT_USER']);
        self::assertSame('${AWS_SECRET_ACCESS_KEY:-shipsecret}', $fragment['silo']['environment']['MINIO_ROOT_PASSWORD']);
    }

    /**
     * Production requires a real password, which matters here because the console can be
     * published to the host.
     */
    public function test_the_password_is_required_in_production_not_just_overridable(): void
    {
        $fragment = (new SiloService())->composeFragment(ShipEnvironment::Production);

        self::assertSame('${AWS_SECRET_ACCESS_KEY:?set a real value in .env.production}', $fragment['silo']['environment']['MINIO_ROOT_PASSWORD']);
        self::assertSame('${AWS_ACCESS_KEY_ID:-ship}', $fragment['silo']['environment']['MINIO_ROOT_USER']);
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
     * Only the console is host-published; "app" reaches the S3 API over the internal network.
     */
    public function test_the_console_port_is_published_but_the_s3_api_port_is_not(): void
    {
        $fragment = (new SiloService())->composeFragment(ShipEnvironment::Development);

        self::assertSame(['127.0.0.1:${SILO_CONSOLE_PORT:-9001}:9001'], $fragment['silo']['ports']);
    }

    /**
     * Development binds the console to 127.0.0.1 (see DevPortBinding); production mappings are
     * left alone.
     */
    public function test_the_console_port_is_not_loopback_bound_in_production(): void
    {
        $fragment = (new SiloService())->composeFragment(ShipEnvironment::Production);

        self::assertSame(['${SILO_CONSOLE_PORT:-9001}:9001'], $fragment['silo']['ports']);
    }

    /**
     * The console port's env var is instance-scoped, so a named instance can't collide with the
     * default one.
     */
    public function test_a_named_instances_console_port_env_var_is_instance_scoped(): void
    {
        $fragment = (new SiloService())->composeFragment(ShipEnvironment::Development, 'archive');

        self::assertSame(['127.0.0.1:${ARCHIVE_SILO_CONSOLE_PORT:-9001}:9001'], $fragment['silo-archive']['ports']);
    }

    /**
     * --console-address is what turns the console on; without it the server still starts.
     */
    public function test_the_console_is_explicitly_enabled_on_its_own_address(): void
    {
        $fragment = (new SiloService())->composeFragment(ShipEnvironment::Development);

        self::assertSame(['server', '/data', '--console-address', ':9001'], $fragment['silo']['command']);
    }
}

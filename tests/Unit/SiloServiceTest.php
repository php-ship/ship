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
     * "ship"/"shipsecret" must only ever be the *default* a project's own .env/.env.production
     * can override. This matters more here than for Garage/RustFS: Silo's console is published
     * to the host by default, so a hardcoded literal would mean every default `ship release`
     * deploy exposes a login page with a publicly-known credential on the open internet.
     */
    public function test_credentials_are_overridable_defaults_not_hardcoded_literals(): void
    {
        $fragment = (new SiloService())->composeFragment(ShipEnvironment::Development);

        self::assertSame('${AWS_ACCESS_KEY_ID:-ship}', $fragment['silo']['environment']['MINIO_ROOT_USER']);
        self::assertSame('${AWS_SECRET_ACCESS_KEY:-shipsecret}', $fragment['silo']['environment']['MINIO_ROOT_PASSWORD']);
    }

    /**
     * Production requires a real value instead of falling back to the same friendly default
     * development uses -- worse here than for Garage/RustFS if it didn't, since Silo's console is
     * published to the host by default. `docker compose` itself refuses to run at all when no
     * real value is set in .env.production.
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
     * The console is the one thing RustFS/Silo offer that SeaweedFS/Garage don't -- host-published
     * so it's actually reachable from a browser, unlike the S3 API port, which only ever needs to
     * be reachable from "app" over the internal network.
     */
    public function test_the_console_port_is_published_but_the_s3_api_port_is_not(): void
    {
        $fragment = (new SiloService())->composeFragment(ShipEnvironment::Development);

        self::assertSame(['127.0.0.1:${SILO_CONSOLE_PORT:-9001}:9001'], $fragment['silo']['ports']);
    }

    /**
     * A bare `HOST:CONTAINER` mapping binds every interface, not just loopback -- development
     * binds this to 127.0.0.1 instead (see DevPortBinding), for a management console that only
     * ever needs to reach the developer's own machine. Production is left unbound.
     */
    public function test_the_console_port_is_not_loopback_bound_in_production(): void
    {
        $fragment = (new SiloService())->composeFragment(ShipEnvironment::Production);

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

        self::assertSame(['127.0.0.1:${ARCHIVE_SILO_CONSOLE_PORT:-9001}:9001'], $fragment['silo-archive']['ports']);
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

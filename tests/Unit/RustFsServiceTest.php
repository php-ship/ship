<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\RustFsService;

final class RustFsServiceTest extends TestCase
{
    public function test_app_credentials_match_what_rustfs_is_provisioned_with(): void
    {
        $service = new RustFsService();
        $fragment = $service->composeFragment(ShipEnvironment::Development);
        $rustfsEnv = $fragment['rustfs']['environment'];
        $appEnv = $service->environmentVariables();

        self::assertSame($rustfsEnv['RUSTFS_ACCESS_KEY'], $appEnv['AWS_ACCESS_KEY_ID']);
        self::assertSame($rustfsEnv['RUSTFS_SECRET_KEY'], $appEnv['AWS_SECRET_ACCESS_KEY']);
        self::assertStringContainsString('rustfs', $appEnv['AWS_ENDPOINT']);
    }

    /**
     * "ship"/"shipsecret" are overridable defaults, not literals.
     */
    public function test_credentials_are_overridable_defaults_not_hardcoded_literals(): void
    {
        $fragment = (new RustFsService())->composeFragment(ShipEnvironment::Development);

        self::assertSame('${AWS_ACCESS_KEY_ID:-ship}', $fragment['rustfs']['environment']['RUSTFS_ACCESS_KEY']);
        self::assertSame('${AWS_SECRET_ACCESS_KEY:-shipsecret}', $fragment['rustfs']['environment']['RUSTFS_SECRET_KEY']);
    }

    /**
     * Production requires a real secret key instead of the development default.
     */
    public function test_the_secret_key_is_required_in_production_not_just_overridable(): void
    {
        $fragment = (new RustFsService())->composeFragment(ShipEnvironment::Production);

        self::assertSame('${AWS_SECRET_ACCESS_KEY:?set a real value in .env.production}', $fragment['rustfs']['environment']['RUSTFS_SECRET_KEY']);
        self::assertSame('${AWS_ACCESS_KEY_ID:-ship}', $fragment['rustfs']['environment']['RUSTFS_ACCESS_KEY']);
    }

    public function test_the_data_volume_persists_in_both_environments(): void
    {
        $service = new RustFsService();

        self::assertNotSame([], $service->composeFragment(ShipEnvironment::Development)['rustfs']['volumes']);
        self::assertNotSame([], $service->composeFragment(ShipEnvironment::Production)['rustfs']['volumes']);
    }

    public function test_a_named_instance_gets_its_own_endpoint_and_env_prefix(): void
    {
        $service = new RustFsService();
        $fragment = $service->composeFragment(ShipEnvironment::Development, 'archive');
        $appEnv = $service->environmentVariables('archive');

        self::assertArrayHasKey('rustfs-archive', $fragment);
        self::assertSame('http://rustfs-archive:9000', $appEnv['ARCHIVE_AWS_ENDPOINT']);
    }

    /**
     * The image's console currently doesn't render (rustfs/rustfs#8013), so no port is published.
     */
    public function test_no_port_is_published(): void
    {
        $fragment = (new RustFsService())->composeFragment(ShipEnvironment::Development);

        self::assertArrayNotHasKey('ports', $fragment['rustfs']);
    }

    /**
     * RustFS can't auto-provision a bucket; AWS_BUCKET names the expected one, which the project
     * creates.
     */
    public function test_it_does_not_claim_to_provision_a_default_bucket(): void
    {
        $fragment = (new RustFsService())->composeFragment(ShipEnvironment::Development);

        self::assertArrayNotHasKey('command', $fragment['rustfs']);
    }
}

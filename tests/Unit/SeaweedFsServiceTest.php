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

    public function test_the_data_volume_persists_in_both_environments(): void
    {
        $service = new SeaweedFsService();

        self::assertNotSame([], $service->composeFragment(ShipEnvironment::Development)['seaweedfs']['volumes']);
        self::assertNotSame([], $service->composeFragment(ShipEnvironment::Production)['seaweedfs']['volumes']);
    }

    /**
     * Regression coverage for a real bug found via an independent audit: the base image's S3
     * gateway has no authentication at all unless handed an identity config file -- verified live
     * that an unsigned request against a plain `weed server -s3` (no -s3.config) returned 200
     * with a real bucket listing. The entrypoint now overrides the image's own `weed` ENTRYPOINT
     * with a shell that generates that file from the injected credentials at boot, then execs the
     * real server against it -- verified live that the same unsigned request now gets a 403.
     */
    public function test_an_s3_identity_config_is_generated_from_the_injected_credentials(): void
    {
        $fragment = (new SeaweedFsService())->composeFragment(ShipEnvironment::Development);

        self::assertSame(['/bin/sh', '-c'], $fragment['seaweedfs']['entrypoint']);
        $script = $fragment['seaweedfs']['command'][0];
        self::assertStringContainsString('-s3.config=/etc/seaweedfs/s3_identity.json', $script);
        // $$, not $ -- Compose interpolates a bare $VAR in a command string the same as
        // ${VAR}, so this has to survive as a literal $VAR for the container's own shell.
        self::assertStringContainsString('"$$AWS_ACCESS_KEY_ID"', $script);
        self::assertStringContainsString('"$$AWS_SECRET_ACCESS_KEY"', $script);
    }

    /**
     * Regression coverage for a real bug found via an independent re-audit, confirmed live: the
     * raw credential values went straight into the printf %s placeholders with no JSON escaping
     * at all, so a secret containing a literal '"' or '\' (nothing stops a real password manager
     * or a RequiredEnv-required .env.production value from generating one) produced invalid JSON
     * -- verified live that the unescaped version of this exact command genuinely fails to parse.
     * sed now escapes both characters first.
     */
    public function test_the_identity_file_escapes_credentials_through_sed_before_embedding_them(): void
    {
        $script = (new SeaweedFsService())->composeFragment(ShipEnvironment::Development)['seaweedfs']['command'][0];

        self::assertStringContainsString("sed 's/\\\\/\\\\\\\\/g; s/\"/\\\\\"/g'", $script);
        // The escaped variables, not the raw ones, must be what actually lands in the JSON --
        // otherwise the sed step above would be dead code that never reaches the printf at all.
        self::assertStringContainsString('"$$ACCESS_KEY_ESC"', $script);
        self::assertStringContainsString('"$$SECRET_KEY_ESC"', $script);
    }

    public function test_the_secret_key_is_required_in_production_not_just_overridable(): void
    {
        $fragment = (new SeaweedFsService())->composeFragment(ShipEnvironment::Production);

        self::assertSame(
            '${AWS_SECRET_ACCESS_KEY:?set a real value in .env.production}',
            $fragment['seaweedfs']['environment']['AWS_SECRET_ACCESS_KEY'],
        );
        self::assertSame('${AWS_ACCESS_KEY_ID:-ship}', $fragment['seaweedfs']['environment']['AWS_ACCESS_KEY_ID']);
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

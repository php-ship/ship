<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\SeaweedFsService;

final class SeaweedFsServiceTest extends TestCase
{
    /**
     * The image resolves "localhost" to ::1 first, which nothing listens on, so the healthcheck
     * must target 127.0.0.1.
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
        $fragment = $service->composeFragment(ShipEnvironment::Development);
        $appEnv = $service->environmentVariables();

        self::assertSame($fragment['seaweedfs']['environment']['AWS_ACCESS_KEY_ID'], $appEnv['AWS_ACCESS_KEY_ID']);
        self::assertSame($fragment['seaweedfs']['environment']['AWS_SECRET_ACCESS_KEY'], $appEnv['AWS_SECRET_ACCESS_KEY']);
        self::assertStringContainsString('seaweedfs', $appEnv['AWS_ENDPOINT']);
    }

    /**
     * `environment:` beats `env_file:`, so the app-facing credentials must be the same Compose
     * expressions the server is provisioned with, not literals.
     */
    public function test_app_credentials_are_expressions_not_hardcoded_literals(): void
    {
        $appEnv = (new SeaweedFsService())->environmentVariables();

        self::assertSame('${AWS_ACCESS_KEY_ID:-ship}', $appEnv['AWS_ACCESS_KEY_ID']);
        self::assertSame('${AWS_SECRET_ACCESS_KEY:-shipsecret}', $appEnv['AWS_SECRET_ACCESS_KEY']);
        self::assertSame('${AWS_BUCKET:-local}', $appEnv['AWS_BUCKET']);
    }

    public function test_the_data_volume_persists_in_both_environments(): void
    {
        $service = new SeaweedFsService();

        self::assertNotSame([], $service->composeFragment(ShipEnvironment::Development)['seaweedfs']['volumes']);
        self::assertNotSame([], $service->composeFragment(ShipEnvironment::Production)['seaweedfs']['volumes']);
    }

    /**
     * The S3 gateway has no authentication without an identity file, so the entrypoint generates
     * one from the injected credentials at boot and then execs the server.
     */
    public function test_an_s3_identity_config_is_generated_from_the_injected_credentials(): void
    {
        $fragment = (new SeaweedFsService())->composeFragment(ShipEnvironment::Development);

        self::assertSame(['/bin/sh', '-c'], $fragment['seaweedfs']['entrypoint']);
        $script = $fragment['seaweedfs']['command'][0];
        self::assertStringContainsString('-s3.config=/etc/seaweedfs/s3_identity.json', $script);
        // $$ so Compose leaves a literal $VAR for the container's shell.
        self::assertStringContainsString('"$$AWS_ACCESS_KEY_ID"', $script);
        self::assertStringContainsString('"$$AWS_SECRET_ACCESS_KEY"', $script);
    }

    /**
     * A `"` or `\` in a credential would otherwise produce invalid JSON, so both are escaped
     * with sed first.
     */
    public function test_the_identity_file_escapes_credentials_through_sed_before_embedding_them(): void
    {
        $script = (new SeaweedFsService())->composeFragment(ShipEnvironment::Development)['seaweedfs']['command'][0];

        self::assertStringContainsString("sed 's/\\\\/\\\\\\\\/g; s/\"/\\\\\"/g'", $script);
        // The JSON must use the escaped variables, not the raw ones.
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

<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\GarageService;

final class GarageServiceTest extends TestCase
{
    public function test_it_provisions_credentials_via_single_node_auto_setup(): void
    {
        $fragment = (new GarageService())->composeFragment(ShipEnvironment::Development);

        self::assertSame(
            ['/garage', 'server', '--single-node', '--default-access-key', '--default-bucket'],
            $fragment['garage']['command'],
        );
    }

    public function test_app_credentials_match_what_garage_actually_provisions(): void
    {
        $service = new GarageService();
        $fragment = $service->composeFragment(ShipEnvironment::Development);
        $garageEnv = $fragment['garage']['environment'];
        $appEnv = $service->environmentVariables();

        self::assertSame($garageEnv['GARAGE_DEFAULT_ACCESS_KEY'], $appEnv['AWS_ACCESS_KEY_ID']);
        self::assertSame($garageEnv['GARAGE_DEFAULT_SECRET_KEY'], $appEnv['AWS_SECRET_ACCESS_KEY']);
        self::assertSame($garageEnv['GARAGE_DEFAULT_BUCKET'], $appEnv['AWS_BUCKET']);
    }

    /**
     * Credentials are overridable defaults, not literals. They are longer than the other storage
     * services' defaults because the image enforces minimum lengths.
     */
    public function test_credentials_are_overridable_defaults_not_hardcoded_literals(): void
    {
        $fragment = (new GarageService())->composeFragment(ShipEnvironment::Development);
        $garageEnv = $fragment['garage']['environment'];

        self::assertSame('${AWS_ACCESS_KEY_ID:-shipaccesskey}', $garageEnv['GARAGE_DEFAULT_ACCESS_KEY']);
        self::assertSame('${AWS_SECRET_ACCESS_KEY:-shipsecretplaceholder}', $garageEnv['GARAGE_DEFAULT_SECRET_KEY']);
        self::assertSame('${AWS_BUCKET:-local}', $garageEnv['GARAGE_DEFAULT_BUCKET']);
    }

    /**
     * Production requires a real secret key; the access key id keeps a plain default.
     */
    public function test_the_secret_key_is_required_in_production_not_just_overridable(): void
    {
        $fragment = (new GarageService())->composeFragment(ShipEnvironment::Production);
        $garageEnv = $fragment['garage']['environment'];

        self::assertSame('${AWS_SECRET_ACCESS_KEY:?set a real value in .env.production}', $garageEnv['GARAGE_DEFAULT_SECRET_KEY']);
        self::assertSame('${AWS_ACCESS_KEY_ID:-shipaccesskey}', $garageEnv['GARAGE_DEFAULT_ACCESS_KEY']);
    }

    /**
     * The RPC secret and admin API token are required in production. Garage reads both from env
     * vars, overriding its config file.
     */
    public function test_rpc_secret_and_admin_token_are_required_in_production_not_just_overridable(): void
    {
        $fragment = (new GarageService())->composeFragment(ShipEnvironment::Production);
        $garageEnv = $fragment['garage']['environment'];

        self::assertSame(
            '${GARAGE_RPC_SECRET:?set a real value in .env.production}',
            $garageEnv['GARAGE_RPC_SECRET'],
        );
        self::assertSame(
            '${GARAGE_ADMIN_TOKEN:?set a real value in .env.production}',
            $garageEnv['GARAGE_ADMIN_TOKEN'],
        );
    }

    public function test_rpc_secret_and_admin_token_have_friendly_defaults_in_development(): void
    {
        $fragment = (new GarageService())->composeFragment(ShipEnvironment::Development);
        $garageEnv = $fragment['garage']['environment'];

        self::assertStringContainsString(':-', $garageEnv['GARAGE_RPC_SECRET']);
        self::assertStringContainsString(':-', $garageEnv['GARAGE_ADMIN_TOKEN']);
    }

    /**
     * garage.toml's rpc_public_addr must match the instance's compose service name, which for a
     * named instance isn't "garage". The Dockerfile substitutes this build arg at build time.
     */
    public function test_the_rpc_public_addr_build_arg_matches_the_instances_own_compose_name(): void
    {
        $default = (new GarageService())->composeFragment(ShipEnvironment::Development);
        self::assertSame('garage:3901', $default['garage']['build']['args']['GARAGE_RPC_PUBLIC_ADDR']);

        $named = (new GarageService())->composeFragment(ShipEnvironment::Development, 'archive');
        self::assertSame('garage-archive:3901', $named['garage-archive']['build']['args']['GARAGE_RPC_PUBLIC_ADDR']);
    }
}

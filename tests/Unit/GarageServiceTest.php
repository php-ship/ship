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
     * These must only ever be *defaults* a project's own .env/.env.production can override -- a
     * bare literal would mean every Garage deployment everywhere shares one publicly-known,
     * hardcoded credential, unlike every other credentialed service in the registry
     * (MySqlService's DB_PASSWORD, ...). Longer than every other storage service's own
     * "ship"/"shipsecret" -- this image's own `--default-access-key` validates minimum lengths
     * and refuses to start at all otherwise, confirmed live (see GarageService's own comment).
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
     * Regression coverage for a real bug found via an independent audit: production previously
     * used the exact same `${AWS_SECRET_ACCESS_KEY:-shipsecretplaceholder}` expression as
     * development. `docker compose` itself now refuses to run at all when no real value was set
     * in .env.production. The access key id -- access-key-ID-shaped, not secret-shaped -- stays a
     * plain default, same reasoning DB_USERNAME is never required either.
     */
    public function test_the_secret_key_is_required_in_production_not_just_overridable(): void
    {
        $fragment = (new GarageService())->composeFragment(ShipEnvironment::Production);
        $garageEnv = $fragment['garage']['environment'];

        self::assertSame('${AWS_SECRET_ACCESS_KEY:?set a real value in .env.production}', $garageEnv['GARAGE_DEFAULT_SECRET_KEY']);
        self::assertSame('${AWS_ACCESS_KEY_ID:-shipaccesskey}', $garageEnv['GARAGE_DEFAULT_ACCESS_KEY']);
    }

    /**
     * Regression coverage for a real bug found via an independent audit: the published
     * garage.toml stub hardcoded an all-zero rpc_secret shared by every project that ever
     * selected Garage, and the admin API had no token at all. Garage's own CLI reads both as env
     * vars directly, overriding config.toml, so no wrapper script or generated file is needed.
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
}

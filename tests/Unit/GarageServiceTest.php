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
}

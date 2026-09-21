<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\DuskService;

final class DuskServiceTest extends TestCase
{
    public function test_driver_url_points_at_the_selenium_service_it_declares(): void
    {
        $service = new DuskService();
        $fragment = $service->composeFragment(ShipEnvironment::Development);

        self::assertArrayHasKey('selenium', $fragment);
        self::assertStringContainsString('selenium', $service->environmentVariables()['DUSK_DRIVER_URL']);
    }

    public function test_it_removes_nothing(): void
    {
        self::assertSame([], (new DuskService())->removes());
    }
}

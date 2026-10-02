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

    /**
     * Dev/test tooling, not infrastructure -- a real bug found live: with no environment check at
     * all, production built and started a Selenium container too, for a browser test suite that
     * never runs there. An empty fragment is this class's own signal to ComposeFileBuilder that it
     * contributes nothing in production -- no container, no env vars injected into "app" either.
     */
    public function test_it_contributes_nothing_in_production(): void
    {
        self::assertSame([], (new DuskService())->composeFragment(ShipEnvironment::Production));
    }
}

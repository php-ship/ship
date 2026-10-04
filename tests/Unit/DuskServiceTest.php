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
     * Pinned to a specific version, not the floating "4" major tag -- an unpinned build input
     * could silently change between two `ship up` runs with no corresponding change here.
     */
    public function test_the_selenium_image_is_pinned_to_a_specific_version(): void
    {
        $fragment = (new DuskService())->composeFragment(ShipEnvironment::Development);

        self::assertMatchesRegularExpression(
            '/^selenium\/standalone-chrome:\d+\.\d+\.\d+$/',
            $fragment['selenium']['image'],
        );
    }

    /**
     * Dev/test tooling, not infrastructure -- production has no business building and starting a
     * Selenium container for a browser test suite that never runs there. An empty fragment is
     * this class's own signal to ComposeFileBuilder that it contributes nothing in production --
     * no container, no env vars injected into "app" either.
     */
    public function test_it_contributes_nothing_in_production(): void
    {
        self::assertSame([], (new DuskService())->composeFragment(ShipEnvironment::Production));
    }
}

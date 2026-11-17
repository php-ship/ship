<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\PlaywrightService;

final class PlaywrightServiceTest extends TestCase
{
    public function test_it_is_absent_from_production(): void
    {
        self::assertSame([], (new PlaywrightService())->composeFragment(ShipEnvironment::Production));
    }

    /**
     * Client and server must be the same version, so the server's package version is read from
     * the image tag rather than written twice.
     */
    public function test_the_server_runs_the_playwright_version_of_its_image(): void
    {
        $fragment = (new PlaywrightService())->composeFragment(ShipEnvironment::Development)['playwright'];

        self::assertSame(1, preg_match('/^mcr\.microsoft\.com\/playwright:v(\d+\.\d+\.\d+)-noble$/', $fragment['image'], $match));
        self::assertSame(
            ['npx', '-y', "playwright@{$match[1]}", 'run-server', '--host', '0.0.0.0', '--port', '3000'],
            $fragment['command'],
        );
    }

    public function test_it_runs_as_the_images_unprivileged_user_with_room_for_chromium(): void
    {
        $fragment = (new PlaywrightService())->composeFragment(ShipEnvironment::Development)['playwright'];

        self::assertSame('pwuser', $fragment['user']);
        self::assertTrue($fragment['init']);
        self::assertSame('2gb', $fragment['shm_size']);
    }

    public function test_the_test_runner_in_app_connects_to_the_server(): void
    {
        self::assertSame(
            ['PW_TEST_CONNECT_WS_ENDPOINT' => 'ws://playwright:3000/'],
            (new PlaywrightService())->environmentVariables(),
        );
    }

    public function test_the_app_gets_the_internal_base_url_but_keeps_its_own_app_url(): void
    {
        self::assertSame(['app' => 'PLAYWRIGHT_BASE_URL'], (new PlaywrightService())->appUrlVariables());
    }

    public function test_the_healthcheck_waits_for_the_server_port(): void
    {
        $healthcheck = (new PlaywrightService())->composeFragment(ShipEnvironment::Development)['playwright']['healthcheck'];

        self::assertSame(['CMD', 'node', '-e'], array_slice($healthcheck['test'], 0, 3));
        self::assertStringContainsString('connect(3000', $healthcheck['test'][3]);
    }
}

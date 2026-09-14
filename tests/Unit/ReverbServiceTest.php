<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\ReverbService;

final class ReverbServiceTest extends TestCase
{
    public function test_it_contributes_a_separate_reverb_service_not_a_merge_into_app(): void
    {
        $fragment = (new ReverbService())->composeFragment(ShipEnvironment::Development);

        self::assertArrayHasKey('reverb', $fragment);
        self::assertArrayNotHasKey('app', $fragment);
        self::assertSame(['${REVERB_PORT:-8080}:8080'], $fragment['reverb']['ports']);
    }

    public function test_it_omits_the_bind_mount_in_production(): void
    {
        $fragment = (new ReverbService())->composeFragment(ShipEnvironment::Production);

        self::assertSame([], $fragment['reverb']['volumes']);
        self::assertSame('prod', $fragment['reverb']['build']['target']);
    }

    /**
     * Server-side (app -> reverb) and browser-side (Echo in the user's
     * browser -> reverb) need different hosts: the Docker service name is
     * meaningless to a browser, and "localhost" from inside the "app"
     * container means the "app" container itself, not "reverb".
     */
    public function test_server_side_and_browser_side_env_vars_point_at_different_hosts(): void
    {
        $env = (new ReverbService())->environmentVariables();

        self::assertSame('reverb', $env['REVERB_HOST']);
        self::assertSame('localhost', $env['VITE_REVERB_HOST']);
        self::assertSame('${REVERB_PORT:-8080}', $env['VITE_REVERB_PORT']);
        self::assertSame('reverb', $env['BROADCAST_CONNECTION']);
    }
}

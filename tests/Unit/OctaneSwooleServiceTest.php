<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\OctaneSwooleService;

final class OctaneSwooleServiceTest extends TestCase
{
    public function test_it_starts_octane_with_the_swoole_server(): void
    {
        $fragment = (new OctaneSwooleService())->composeFragment(ShipEnvironment::Development);

        self::assertSame(
            ['php', 'artisan', 'octane:start', '--server=swoole', '--host=0.0.0.0', '--port=8000', '--watch'],
            $fragment['app']['command'],
        );
        self::assertSame(['${APP_PORT:-8000}:8000'], $fragment['app']['ports']);
    }

    /**
     * Requested from real use: without --watch, Octane keeps serving the worker process's
     * already-booted code, so a PHP change needs a manual `php artisan octane:reload` before it's
     * picked up -- the same behavior Laravel Sail's own Octane setup avoids by passing --watch.
     * Dev only -- production never wants to restart workers on a file change it should never see
     * to begin with (the source is baked into the image, not live-mounted).
     */
    public function test_watch_is_only_added_in_development(): void
    {
        $fragment = (new OctaneSwooleService())->composeFragment(ShipEnvironment::Production);

        self::assertNotContains('--watch', $fragment['app']['command']);
    }

    public function test_octane_server_env_var_matches_the_server_flag(): void
    {
        self::assertSame('swoole', (new OctaneSwooleService())->environmentVariables()['OCTANE_SERVER']);
    }

    /**
     * Every Octane runtime serves HTTP itself, so nginx ("webserver") is never needed alongside
     * one -- see ComposeFileBuilder::baseServices()'s own docblock for the primary check this
     * backstops.
     */
    public function test_it_removes_the_webserver_service(): void
    {
        self::assertSame(['webserver'], (new OctaneSwooleService())->removes());
    }
}

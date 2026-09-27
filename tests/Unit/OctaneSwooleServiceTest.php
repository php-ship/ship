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
        $fragment = (new OctaneSwooleService())->composeFragment(ShipEnvironment::Production);

        self::assertSame(
            ['sh', '-c', 'php artisan octane:start --server=swoole --host=0.0.0.0 --port=8000'],
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
     *
     * Gated on node_modules/chokidar actually existing, checked at container *boot* -- not always
     * on -- found via a real CI failure: unconditionally passing --watch crash-loops Octane's own
     * watcher subprocess ("Cannot find module 'chokidar'") on any project that doesn't have it,
     * which is most fresh Laravel installs, not a rare case.
     */
    public function test_watch_is_conditional_on_chokidar_and_only_checked_in_development(): void
    {
        $dev = (new OctaneSwooleService())->composeFragment(ShipEnvironment::Development);
        $prod = (new OctaneSwooleService())->composeFragment(ShipEnvironment::Production);

        self::assertSame(
            ['sh', '-c', 'if [ -d node_modules/chokidar ]; then php artisan octane:start --server=swoole --host=0.0.0.0 --port=8000 --watch; else php artisan octane:start --server=swoole --host=0.0.0.0 --port=8000; fi'],
            $dev['app']['command'],
        );
        self::assertStringNotContainsString('chokidar', $prod['app']['command'][2]);
        self::assertStringNotContainsString('--watch', $prod['app']['command'][2]);
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

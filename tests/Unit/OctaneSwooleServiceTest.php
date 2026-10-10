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
     * --watch reloads workers on a PHP change, in dev only. It's gated at container boot on
     * node_modules/chokidar existing, since passing it without chokidar crash-loops Octane's
     * watcher.
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
     * Every Octane runtime serves HTTP itself, so nginx ("webserver") isn't needed.
     */
    public function test_it_removes_the_webserver_service(): void
    {
        self::assertSame(['webserver'], (new OctaneSwooleService())->removes());
    }

    /**
     * An Octane server would otherwise run as root in production (see EntrypointScriptBuilder).
     * Dev doesn't read SHIP_RUN_AS.
     */
    public function test_it_asks_the_entrypoint_to_drop_to_www_data_in_production_only(): void
    {
        $dev = (new OctaneSwooleService())->composeFragment(ShipEnvironment::Development);
        $prod = (new OctaneSwooleService())->composeFragment(ShipEnvironment::Production);

        self::assertSame(['SHIP_RUN_AS' => 'www-data'], $prod['app']['environment']);
        self::assertSame([], $dev['app']['environment']);
    }
}

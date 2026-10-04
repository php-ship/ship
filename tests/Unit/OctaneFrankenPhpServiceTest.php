<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\OctaneFrankenPhpService;

/**
 * OctaneRuntimeMergeTest already covers the dockerfile-override-without-erasing-build-context
 * behavior this service exists to exercise -- this file covers what that one doesn't: FrankenPHP
 * specifically publishing both an HTTP and HTTPS port (built-in TLS is one of its distinguishing
 * features from Swoole/RoadRunner) and its own OCTANE_SERVER value.
 */
final class OctaneFrankenPhpServiceTest extends TestCase
{
    public function test_it_publishes_both_http_and_https_ports(): void
    {
        $fragment = (new OctaneFrankenPhpService())->composeFragment(ShipEnvironment::Development);

        self::assertSame(
            ['127.0.0.1:${APP_PORT:-8000}:8000', '127.0.0.1:${APP_HTTPS_PORT:-8443}:8443'],
            $fragment['app']['ports'],
        );
    }

    /**
     * A bare `HOST:CONTAINER` mapping binds every interface, not just loopback -- development
     * binds these to 127.0.0.1 instead (see DevPortBinding), but production is left unbound: a
     * published production port often does need to be reachable from outside.
     */
    public function test_ports_are_not_loopback_bound_in_production(): void
    {
        $fragment = (new OctaneFrankenPhpService())->composeFragment(ShipEnvironment::Production);

        self::assertSame(['${APP_PORT:-8000}:8000', '${APP_HTTPS_PORT:-8443}:8443'], $fragment['app']['ports']);
    }

    public function test_it_starts_octane_with_the_frankenphp_server(): void
    {
        $fragment = (new OctaneFrankenPhpService())->composeFragment(ShipEnvironment::Production);

        self::assertSame(
            ['php', 'artisan', 'octane:start', '--server=frankenphp', '--host=0.0.0.0', '--port=8000'],
            $fragment['app']['command'],
        );
    }

    /**
     * Unlike Swoole/RoadRunner, --watch has no chokidar/Node dependency here at all -- Octane
     * injects a native watch directive into FrankenPHP's own Caddyfile instead -- so it's
     * unconditional in dev, with no shell-level presence check needed.
     */
    public function test_watch_is_added_in_development_only(): void
    {
        $dev = (new OctaneFrankenPhpService())->composeFragment(ShipEnvironment::Development);
        $prod = (new OctaneFrankenPhpService())->composeFragment(ShipEnvironment::Production);

        self::assertContains('--watch', $dev['app']['command']);
        self::assertNotContains('--watch', $prod['app']['command']);
    }

    public function test_octane_server_env_var_matches_the_server_flag(): void
    {
        self::assertSame('frankenphp', (new OctaneFrankenPhpService())->environmentVariables()['OCTANE_SERVER']);
    }

    public function test_it_removes_the_webserver_service(): void
    {
        self::assertSame(['webserver'], (new OctaneFrankenPhpService())->removes());
    }

    /**
     * Production only, same as Swoole/RoadRunner (see OctaneSwooleServiceTest's own equivalent):
     * Octane has no master-drops-workers split, so without this it runs as root. Dev never reads
     * SHIP_RUN_AS (its own entrypoint is a different, static file).
     */
    public function test_it_asks_the_entrypoint_to_drop_to_www_data_in_production_only(): void
    {
        $dev = (new OctaneFrankenPhpService())->composeFragment(ShipEnvironment::Development);
        $prod = (new OctaneFrankenPhpService())->composeFragment(ShipEnvironment::Production);

        self::assertSame(['SHIP_RUN_AS' => 'www-data'], $prod['app']['environment']);
        self::assertSame([], $dev['app']['environment']);
    }
}

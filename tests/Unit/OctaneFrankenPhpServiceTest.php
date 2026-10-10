<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\OctaneFrankenPhpService;

/**
 * OctaneRuntimeMergeTest covers the dockerfile override. This covers FrankenPHP's HTTP and HTTPS
 * ports, its OCTANE_SERVER value, --watch and SHIP_RUN_AS.
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
     * Development binds ports to 127.0.0.1 (see DevPortBinding); production mappings are left
     * alone.
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
     * --watch has no chokidar dependency on FrankenPHP, so it's unconditional in dev.
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
     * An Octane server would otherwise run as root in production. Dev doesn't read SHIP_RUN_AS.
     */
    public function test_it_asks_the_entrypoint_to_drop_to_www_data_in_production_only(): void
    {
        $dev = (new OctaneFrankenPhpService())->composeFragment(ShipEnvironment::Development);
        $prod = (new OctaneFrankenPhpService())->composeFragment(ShipEnvironment::Production);

        self::assertSame(['SHIP_RUN_AS' => 'www-data'], $prod['app']['environment']);
        self::assertSame([], $dev['app']['environment']);
    }
}

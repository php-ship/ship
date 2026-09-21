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

        self::assertSame(['${APP_PORT:-8000}:8000', '${APP_HTTPS_PORT:-8443}:8443'], $fragment['app']['ports']);
    }

    public function test_it_starts_octane_with_the_frankenphp_server(): void
    {
        $fragment = (new OctaneFrankenPhpService())->composeFragment(ShipEnvironment::Development);

        self::assertSame(
            ['php', 'artisan', 'octane:start', '--server=frankenphp', '--host=0.0.0.0', '--port=8000'],
            $fragment['app']['command'],
        );
    }

    public function test_octane_server_env_var_matches_the_server_flag(): void
    {
        self::assertSame('frankenphp', (new OctaneFrankenPhpService())->environmentVariables()['OCTANE_SERVER']);
    }

    public function test_it_removes_the_webserver_service(): void
    {
        self::assertSame(['webserver'], (new OctaneFrankenPhpService())->removes());
    }
}

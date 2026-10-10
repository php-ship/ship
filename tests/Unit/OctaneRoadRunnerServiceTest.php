<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\OctaneRoadRunnerService;

final class OctaneRoadRunnerServiceTest extends TestCase
{
    public function test_it_starts_octane_with_the_roadrunner_server(): void
    {
        $fragment = (new OctaneRoadRunnerService())->composeFragment(ShipEnvironment::Production);

        self::assertSame(
            ['sh', '-c', 'php artisan octane:start --server=roadrunner --host=0.0.0.0 --port=8000'],
            $fragment['app']['command'],
        );
        self::assertSame(['${APP_PORT:-8000}:8000'], $fragment['app']['ports']);
    }

    /**
     * Dev only, and gated at container boot on node_modules/chokidar existing (see
     * OctaneSwooleServiceTest).
     */
    public function test_watch_is_conditional_on_chokidar_and_only_checked_in_development(): void
    {
        $dev = (new OctaneRoadRunnerService())->composeFragment(ShipEnvironment::Development);
        $prod = (new OctaneRoadRunnerService())->composeFragment(ShipEnvironment::Production);

        self::assertSame(
            ['sh', '-c', 'if [ -d node_modules/chokidar ]; then php artisan octane:start --server=roadrunner --host=0.0.0.0 --port=8000 --watch; else php artisan octane:start --server=roadrunner --host=0.0.0.0 --port=8000; fi'],
            $dev['app']['command'],
        );
        self::assertStringNotContainsString('chokidar', $prod['app']['command'][2]);
        self::assertStringNotContainsString('--watch', $prod['app']['command'][2]);
    }

    public function test_octane_server_env_var_matches_the_server_flag(): void
    {
        self::assertSame('roadrunner', (new OctaneRoadRunnerService())->environmentVariables()['OCTANE_SERVER']);
    }

    /**
     * Every Octane runtime serves HTTP itself, so nginx ("webserver") isn't needed.
     */
    public function test_it_removes_the_webserver_service(): void
    {
        self::assertSame(['webserver'], (new OctaneRoadRunnerService())->removes());
    }

    /**
     * An Octane server would otherwise run as root in production (see EntrypointScriptBuilder).
     * Dev doesn't read SHIP_RUN_AS.
     */
    public function test_it_asks_the_entrypoint_to_drop_to_www_data_in_production_only(): void
    {
        $dev = (new OctaneRoadRunnerService())->composeFragment(ShipEnvironment::Development);
        $prod = (new OctaneRoadRunnerService())->composeFragment(ShipEnvironment::Production);

        self::assertSame(['SHIP_RUN_AS' => 'www-data'], $prod['app']['environment']);
        self::assertSame([], $dev['app']['environment']);
    }
}

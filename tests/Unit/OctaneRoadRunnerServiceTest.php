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
        $fragment = (new OctaneRoadRunnerService())->composeFragment(ShipEnvironment::Development);

        self::assertSame(
            ['php', 'artisan', 'octane:start', '--server=roadrunner', '--host=0.0.0.0', '--port=8000', '--watch'],
            $fragment['app']['command'],
        );
        self::assertSame(['${APP_PORT:-8000}:8000'], $fragment['app']['ports']);
    }

    /**
     * Dev only -- see OctaneSwooleServiceTest's own equivalent for why.
     */
    public function test_watch_is_only_added_in_development(): void
    {
        $fragment = (new OctaneRoadRunnerService())->composeFragment(ShipEnvironment::Production);

        self::assertNotContains('--watch', $fragment['app']['command']);
    }

    public function test_octane_server_env_var_matches_the_server_flag(): void
    {
        self::assertSame('roadrunner', (new OctaneRoadRunnerService())->environmentVariables()['OCTANE_SERVER']);
    }

    /**
     * Every Octane runtime serves HTTP itself, so nginx ("webserver") is never needed alongside
     * one -- see ComposeFileBuilder::baseServices()'s own docblock for the primary check this
     * backstops.
     */
    public function test_it_removes_the_webserver_service(): void
    {
        self::assertSame(['webserver'], (new OctaneRoadRunnerService())->removes());
    }
}

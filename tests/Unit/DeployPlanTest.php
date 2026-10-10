<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Docker\DeployPlan;

final class DeployPlanTest extends TestCase
{
    /**
     * Image-pulled services are infrastructure; services built from ship/Dockerfile must not
     * start until the deploy commands succeed.
     */
    public function test_services_without_a_build_are_infrastructure(): void
    {
        $yaml = <<<'YAML'
            services:
              app:
                build: { context: ., dockerfile: ship/Dockerfile }
              webserver:
                build: { context: ., dockerfile: ship/Dockerfile }
              mysql:
                image: 'mysql:9.7'
              redis:
                image: 'redis:8-alpine'
            YAML;

        self::assertSame(['mysql', 'redis'], DeployPlan::infrastructureServices($yaml));
    }

    public function test_a_stack_with_nothing_but_built_services_has_no_infrastructure(): void
    {
        $yaml = "services:\n  app:\n    build: { context: ., dockerfile: ship/Dockerfile }\n";

        self::assertSame([], DeployPlan::infrastructureServices($yaml));
    }

    /**
     * Garage has its own packaging Dockerfile but is still infrastructure, so "has a build:" isn't
     * the test.
     */
    public function test_a_service_building_from_its_own_dockerfile_is_infrastructure_too(): void
    {
        $yaml = <<<'YAML'
            services:
              app:
                build: { context: ., dockerfile: ship/Dockerfile }
              garage:
                build: { context: ./ship/garage, dockerfile: Dockerfile }
              mysql:
                image: 'mysql:9.7'
            YAML;

        self::assertSame(['garage', 'mysql'], DeployPlan::infrastructureServices($yaml));
    }

    public function test_frankenphps_own_dockerfile_is_not_infrastructure_either(): void
    {
        $yaml = "services:\n  app:\n    build: { context: ., dockerfile: ship/Dockerfile.frankenphp }\n";

        self::assertSame([], DeployPlan::infrastructureServices($yaml));
    }

    /**
     * -T (no terminal in CI), --no-deps (start nothing as a side effect) and sh -c (any shell
     * line) are each required.
     */
    public function test_a_command_runs_as_a_one_off_shell_line_in_the_app_service(): void
    {
        $args = DeployPlan::runArgs(sys_get_temp_dir(), 'admin-app', 'php artisan migrate --force && php artisan db:seed');

        self::assertSame(
            ['run', '--rm', '--no-deps', '-T', 'admin-app', 'sh', '-c', 'php artisan migrate --force && php artisan db:seed'],
            array_slice($args, -8),
        );
    }

    public function test_infrastructure_is_started_detached_and_waited_on(): void
    {
        $args = DeployPlan::startInfrastructureArgs(sys_get_temp_dir(), ['mysql', 'redis']);

        self::assertSame(['up', '-d', '--wait', 'mysql', 'redis'], array_slice($args, -5));
    }
}

<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Docker\DeployPlan;

final class DeployPlanTest extends TestCase
{
    /**
     * Only image-pulled services are infrastructure -- everything built from ship/Dockerfile (the
     * app, its nginx, Reverb) is exactly what must not start until the deploy commands succeed.
     */
    public function test_only_services_without_a_build_are_infrastructure(): void
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
        $yaml = "services:\n  app:\n    build: { context: . }\n";

        self::assertSame([], DeployPlan::infrastructureServices($yaml));
    }

    /**
     * -T (no terminal: this can run in CI), --no-deps (never start anything as a side effect), and
     * sh -c (a command can be any shell line, not just one executable) are each load-bearing.
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

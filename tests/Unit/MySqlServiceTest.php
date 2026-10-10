<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\MySqlService;

final class MySqlServiceTest extends TestCase
{
    public function test_app_credentials_match_what_the_mysql_container_is_provisioned_with(): void
    {
        $service = new MySqlService();
        $mysqlEnv = $service->composeFragment(ShipEnvironment::Development)['mysql']['environment'];
        $appEnv = $service->environmentVariables();

        self::assertSame($mysqlEnv['MYSQL_DATABASE'], $appEnv['DB_DATABASE']);
        self::assertSame($mysqlEnv['MYSQL_USER'], $appEnv['DB_USERNAME']);
        self::assertSame($mysqlEnv['MYSQL_PASSWORD'], $appEnv['DB_PASSWORD']);
    }

    /**
     * Production requires a real password instead of the development default.
     */
    public function test_the_password_is_required_in_production_not_just_overridable(): void
    {
        $fragment = (new MySqlService())->composeFragment(ShipEnvironment::Production);

        self::assertSame('${DB_PASSWORD:?set a real value in .env.production}', $fragment['mysql']['environment']['MYSQL_PASSWORD']);
        self::assertSame('${DB_ROOT_PASSWORD:?set a real value in .env.production}', $fragment['mysql']['environment']['MYSQL_ROOT_PASSWORD']);
    }

    /**
     * The root password is a separate secret that "app" never receives, so a leaked app
     * credential doesn't grant MySQL admin access.
     */
    public function test_the_root_password_is_a_separate_secret_from_the_app_users_own_password(): void
    {
        $service = new MySqlService();
        $mysqlEnv = $service->composeFragment(ShipEnvironment::Development)['mysql']['environment'];

        self::assertNotSame($mysqlEnv['MYSQL_PASSWORD'], $mysqlEnv['MYSQL_ROOT_PASSWORD']);
        self::assertArrayNotHasKey('DB_ROOT_PASSWORD', $service->environmentVariables());
    }

    public function test_db_shell_command_targets_the_mysql_service(): void
    {
        $shell = (new MySqlService())->databaseShellCommand();

        self::assertSame('mysql', $shell['service']);
        self::assertStringContainsString('mysql', implode(' ', $shell['command']));
    }

    /**
     * "localhost" would use the unix socket, which the image's temporary init-phase server also
     * answers before TCP connections are accepted.
     */
    public function test_the_healthcheck_pings_over_tcp_not_the_init_phase_sockets(): void
    {
        $fragment = (new MySqlService())->composeFragment(ShipEnvironment::Development);

        $test = implode(' ', $fragment['mysql']['healthcheck']['test']);

        self::assertStringContainsString('127.0.0.1', $test);
        self::assertStringNotContainsString('localhost', $test);
    }
}

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
     * Production requires a real value instead of falling back to the same friendly default
     * development uses -- `docker compose` itself refuses to run at all until one is set, rather
     * than every project that forgot silently sharing the same publicly-known default.
     */
    public function test_the_password_is_required_in_production_not_just_overridable(): void
    {
        $fragment = (new MySqlService())->composeFragment(ShipEnvironment::Production);

        self::assertSame('${DB_PASSWORD:?set a real value in .env.production}', $fragment['mysql']['environment']['MYSQL_PASSWORD']);
        self::assertSame('${DB_ROOT_PASSWORD:?set a real value in .env.production}', $fragment['mysql']['environment']['MYSQL_ROOT_PASSWORD']);
    }

    /**
     * The root password is a separate secret from the app user's own DB_PASSWORD, not the same
     * value doubling as both -- a leaked app credential would otherwise hand over full MySQL
     * admin access too. "app" never needs it -- it only ever authenticates as MYSQL_USER -- so it
     * has no business appearing in environmentVariables().
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
     * "localhost" makes mysqladmin use the unix socket, which the image's temporary init-phase
     * server (no TCP at all) also answers -- the container would report healthy several seconds
     * before anything could actually connect to it over the network.
     */
    public function test_the_healthcheck_pings_over_tcp_not_the_init_phase_sockets(): void
    {
        $fragment = (new MySqlService())->composeFragment(ShipEnvironment::Development);

        $test = implode(' ', $fragment['mysql']['healthcheck']['test']);

        self::assertStringContainsString('127.0.0.1', $test);
        self::assertStringNotContainsString('localhost', $test);
    }
}

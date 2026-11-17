<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\MariaDbService;

final class MariaDbServiceTest extends TestCase
{
    public function test_app_credentials_match_what_the_mariadb_container_is_provisioned_with(): void
    {
        $service = new MariaDbService();
        $mariadbEnv = $service->composeFragment(ShipEnvironment::Development)['mariadb']['environment'];
        $appEnv = $service->environmentVariables();

        self::assertSame($mariadbEnv['MARIADB_DATABASE'], $appEnv['DB_DATABASE']);
        self::assertSame($mariadbEnv['MARIADB_USER'], $appEnv['DB_USERNAME']);
        self::assertSame($mariadbEnv['MARIADB_PASSWORD'], $appEnv['DB_PASSWORD']);
    }

    public function test_the_app_connects_with_laravels_mariadb_driver_over_the_compose_network(): void
    {
        $appEnv = (new MariaDbService())->environmentVariables();

        self::assertSame('mariadb', $appEnv['DB_CONNECTION']);
        self::assertSame('mariadb', $appEnv['DB_HOST']);
        self::assertSame('3306', $appEnv['DB_PORT']);
    }

    public function test_the_passwords_are_required_in_production_not_just_overridable(): void
    {
        $env = (new MariaDbService())->composeFragment(ShipEnvironment::Production)['mariadb']['environment'];

        self::assertSame('${DB_PASSWORD:?set a real value in .env.production}', $env['MARIADB_PASSWORD']);
        self::assertSame('${DB_ROOT_PASSWORD:?set a real value in .env.production}', $env['MARIADB_ROOT_PASSWORD']);
    }

    public function test_the_root_password_is_a_separate_secret_from_the_app_users_own_password(): void
    {
        $service = new MariaDbService();
        $mariadbEnv = $service->composeFragment(ShipEnvironment::Development)['mariadb']['environment'];

        self::assertNotSame($mariadbEnv['MARIADB_PASSWORD'], $mariadbEnv['MARIADB_ROOT_PASSWORD']);
        self::assertArrayNotHasKey('DB_ROOT_PASSWORD', $service->environmentVariables());
    }

    public function test_data_is_persisted_to_a_named_volume(): void
    {
        $fragment = (new MariaDbService())->composeFragment(ShipEnvironment::Production);

        self::assertSame(['ship-mariadb-data:/var/lib/mysql'], $fragment['mariadb']['volumes']);
    }

    /**
     * --connect goes over TCP, which the image's temporary init-phase server doesn't listen on.
     */
    public function test_the_healthcheck_requires_a_tcp_connection(): void
    {
        $fragment = (new MariaDbService())->composeFragment(ShipEnvironment::Development);

        self::assertSame(['CMD', 'healthcheck.sh', '--connect', '--innodb_initialized'], $fragment['mariadb']['healthcheck']['test']);
    }

    public function test_a_named_instance_gets_its_own_service_volume_and_prefixed_variables(): void
    {
        $service = new MariaDbService();
        $fragment = $service->composeFragment(ShipEnvironment::Development, 'analytics');
        $appEnv = $service->environmentVariables('analytics');

        self::assertArrayHasKey('mariadb-analytics', $fragment);
        self::assertSame(['ship-mariadb-analytics-data:/var/lib/mysql'], $fragment['mariadb-analytics']['volumes']);
        self::assertSame('${ANALYTICS_DB_PASSWORD:-secret}', $fragment['mariadb-analytics']['environment']['MARIADB_PASSWORD']);
        self::assertSame('mariadb-analytics', $appEnv['ANALYTICS_DB_HOST']);
        self::assertSame('mariadb', $appEnv['ANALYTICS_DB_CONNECTION']);
        self::assertArrayNotHasKey('DB_HOST', $appEnv);
    }

    public function test_db_shell_command_targets_the_mariadb_service(): void
    {
        $service = new MariaDbService();

        self::assertSame('mariadb', $service->databaseShellCommand()['service']);
        self::assertSame('mariadb-analytics', $service->databaseShellCommand('analytics')['service']);
        self::assertStringContainsString('exec mariadb ', implode(' ', $service->databaseShellCommand()['command']));
    }
}

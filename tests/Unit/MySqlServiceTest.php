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

    public function test_db_shell_command_targets_the_mysql_service(): void
    {
        $shell = (new MySqlService())->databaseShellCommand();

        self::assertSame('mysql', $shell['service']);
        self::assertStringContainsString('mysql', implode(' ', $shell['command']));
    }
}

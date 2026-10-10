<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Docker\MySqlUsernameGuard;

final class MySqlUsernameGuardTest extends TestCase
{
    /**
     * The official mysql image refuses to start with MYSQL_USER=root, and DB_USERNAME=root is a
     * common value in a Laravel .env.
     */
    public function test_it_flags_db_username_root_for_the_default_mysql_instance(): void
    {
        $config = new ShipConfig(phpVersion: '8.4', services: ['database' => 'mysql']);

        self::assertSame(['DB_USERNAME'], MySqlUsernameGuard::problems($config, ['DB_USERNAME' => 'root']));
    }

    public function test_it_is_silent_when_db_username_is_anything_else(): void
    {
        $config = new ShipConfig(phpVersion: '8.4', services: ['database' => 'mysql']);

        self::assertSame([], MySqlUsernameGuard::problems($config, ['DB_USERNAME' => 'app']));
    }

    public function test_it_is_silent_when_mysql_is_not_even_selected(): void
    {
        $config = new ShipConfig(phpVersion: '8.4', services: ['database' => 'pgsql']);

        self::assertSame([], MySqlUsernameGuard::problems($config, ['DB_USERNAME' => 'root']));
    }

    public function test_it_checks_a_named_additional_mysql_instance_too(): void
    {
        $config = new ShipConfig(
            phpVersion: '8.4',
            services: ['database' => 'pgsql'],
            additionalServices: [['group' => 'database', 'service' => 'mysql', 'name' => 'analytics']],
        );

        self::assertSame(['ANALYTICS_DB_USERNAME'], MySqlUsernameGuard::problems($config, ['ANALYTICS_DB_USERNAME' => 'root']));
    }
}

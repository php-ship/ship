<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Docker\MySqlUsernameGuard;

final class MySqlUsernameGuardTest extends TestCase
{
    /**
     * Regression coverage for a real bug found via an independent audit: the official mysql
     * image's own entrypoint refuses to start at all when MYSQL_USER=root, crashing the whole
     * container -- and DB_USERNAME=root is a real value to find in a project's own .env, Laravel's
     * own stock default for years before the framework's sqlite-first skeleton.
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

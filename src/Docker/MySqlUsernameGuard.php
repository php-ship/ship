<?php

declare(strict_types=1);

namespace Ship\Docker;

use Ship\Config\ShipConfig;

/**
 * The official mysql image refuses to start when `MYSQL_USER=root`, and `DB_USERNAME=root` is a
 * common value in a Laravel `.env`. ComposeFileBuilder only sees the expression
 * `${DB_USERNAME:-app}`, so this checks the env file directly and lets the caller fail with a
 * specific message instead of "mysql never became healthy".
 */
final class MySqlUsernameGuard
{
    /**
     * @param array<string, string> $env
     * @return list<string> the DB_USERNAME-shaped env var names set to "root"
     */
    public static function problems(ShipConfig $config, array $env): array
    {
        $problems = [];

        foreach (self::mysqlInstanceNames($config) as $instanceName) {
            $prefix = $instanceName === null ? '' : strtoupper($instanceName) . '_';
            $var = "{$prefix}DB_USERNAME";

            if (($env[$var] ?? null) === 'root') {
                $problems[] = $var;
            }
        }

        return $problems;
    }

    /**
     * @return list<?string>
     */
    private static function mysqlInstanceNames(ShipConfig $config): array
    {
        $names = [];

        if (($config->services['database'] ?? null) === 'mysql') {
            $names[] = null;
        }

        foreach ($config->additionalServices as $additional) {
            if ($additional['service'] === 'mysql') {
                $names[] = $additional['name'];
            }
        }

        return $names;
    }
}

<?php

declare(strict_types=1);

namespace Ship\Docker;

use Ship\Config\ShipConfig;

/**
 * Per the official image's own documented behavior, `mysql`'s entrypoint explicitly refuses to
 * start at all when `MYSQL_USER=root` -- "MYSQL_USER=root, MYSQL_USER and MYSQL_PASSWORD are for
 * configuring a regular user" -- crashing the whole container, not just one query.
 * `DB_USERNAME=root` is a real value to find in a project's own `.env`: it was Laravel's own
 * stock default for years before the framework's sqlite-first skeleton. ship can't catch this
 * inside `ComposeFileBuilder` itself -- `MYSQL_USER` is set to the Compose expression
 * `${DB_USERNAME:-app}`, never the actual resolved value, which only exists once a real
 * `.env`/`.env.production` is read -- so this checks that file directly instead, early enough to
 * fail with a clear, specific message instead of the generic "mysql never became healthy" a
 * crashed container would otherwise produce.
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

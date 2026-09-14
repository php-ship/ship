<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ProvidesDatabaseShell;
use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;

final class MySqlService implements ServiceDefinition, ProvidesDatabaseShell
{
    public function key(): string
    {
        return 'mysql';
    }

    public function label(): string
    {
        return 'MySQL';
    }

    public function group(): string
    {
        return 'database';
    }

    public function composeFragment(ShipEnvironment $environment): array
    {
        return [
            'mysql' => [
                'image' => 'mysql:9.7',
                'environment' => [
                    'MYSQL_DATABASE' => '${DB_DATABASE:-app}',
                    'MYSQL_USER' => '${DB_USERNAME:-app}',
                    'MYSQL_PASSWORD' => '${DB_PASSWORD:-secret}',
                    // No separate root secret to manage -- the app user's own
                    // password doubles as root's, since nothing here needs
                    // root access beyond what MySQL's own image setup uses it for.
                    'MYSQL_ROOT_PASSWORD' => '${DB_PASSWORD:-secret}',
                ],
                'volumes' => $environment->isDevelopment()
                    ? ['ship-mysql-data:/var/lib/mysql']
                    : [],
                'healthcheck' => [
                    'test' => ['CMD', 'mysqladmin', 'ping', '-h', 'localhost'],
                    'interval' => '5s',
                    'timeout' => '5s',
                    'retries' => 5,
                ],
            ],
        ];
    }

    public function environmentVariables(): array
    {
        return [
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'mysql',
            'DB_PORT' => '3306',
            // Same expressions as composeFragment()'s "mysql" service, so both
            // sides always resolve from the same source at the same time.
            'DB_DATABASE' => '${DB_DATABASE:-app}',
            'DB_USERNAME' => '${DB_USERNAME:-app}',
            'DB_PASSWORD' => '${DB_PASSWORD:-secret}',
        ];
    }

    public function removes(): array
    {
        return [];
    }

    public function databaseShellCommand(): array
    {
        return [
            'service' => 'mysql',
            'command' => ['sh', '-c', 'exec mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'],
        ];
    }
}

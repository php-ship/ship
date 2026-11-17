<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ProvidesDatabaseShell;
use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\RequiredEnv;

final class MariaDbService implements ServiceDefinition, ProvidesDatabaseShell
{
    use SupportsNamedInstances;

    public function key(): string
    {
        return 'mariadb';
    }

    public function label(): string
    {
        return 'MariaDB';
    }

    public function group(): string
    {
        return 'database';
    }

    public function composeFragment(ShipEnvironment $environment, ?string $instanceName = null): array
    {
        $name = $this->composeServiceName($instanceName);
        $prefix = $this->envPrefix($instanceName);

        return [
            $name => [
                'image' => 'mariadb:13.0',
                'environment' => [
                    'MARIADB_DATABASE' => "\${{$prefix}DB_DATABASE:-app}",
                    'MARIADB_USER' => "\${{$prefix}DB_USERNAME:-app}",
                    // A default in dev, required in production (see RequiredEnv), so a project
                    // never ships with a publicly known password.
                    'MARIADB_PASSWORD' => RequiredEnv::expr("{$prefix}DB_PASSWORD", 'secret', $environment),
                    // A separate secret from the app user's DB_PASSWORD, as with MySQL: "app"
                    // never authenticates as root.
                    'MARIADB_ROOT_PASSWORD' => RequiredEnv::expr("{$prefix}DB_ROOT_PASSWORD", 'rootsecret', $environment),
                ],
                // Persisted in both environments; a production database must survive a restart
                // or redeploy.
                'volumes' => ["ship-{$name}-data:/var/lib/mysql"],
                'healthcheck' => [
                    // The image's own healthcheck script. --connect goes over TCP, which the
                    // image's temporary init-phase server (socket only) doesn't accept, so it
                    // can't report healthy before initialization has finished.
                    'test' => ['CMD', 'healthcheck.sh', '--connect', '--innodb_initialized'],
                    'interval' => '5s',
                    'timeout' => '5s',
                    'retries' => 5,
                ],
            ],
        ];
    }

    public function environmentVariables(?string $instanceName = null): array
    {
        $name = $this->composeServiceName($instanceName);
        $prefix = $this->envPrefix($instanceName);

        return [
            // Laravel 11+'s own MariaDB driver (pdo_mysql underneath).
            "{$prefix}DB_CONNECTION" => 'mariadb',
            "{$prefix}DB_HOST" => $name,
            "{$prefix}DB_PORT" => '3306',
            // The same expressions composeFragment() provisions MariaDB with.
            "{$prefix}DB_DATABASE" => "\${{$prefix}DB_DATABASE:-app}",
            "{$prefix}DB_USERNAME" => "\${{$prefix}DB_USERNAME:-app}",
            "{$prefix}DB_PASSWORD" => "\${{$prefix}DB_PASSWORD:-secret}",
        ];
    }

    public function removes(): array
    {
        return [];
    }

    public function databaseShellCommand(?string $instanceName = null): array
    {
        return [
            'service' => $this->composeServiceName($instanceName),
            // Reads the container's own env vars, so it matches whatever the container was
            // provisioned with.
            'command' => ['sh', '-c', 'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"'],
        ];
    }
}

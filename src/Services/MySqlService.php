<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ProvidesDatabaseShell;
use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;

final class MySqlService implements ServiceDefinition, ProvidesDatabaseShell
{
    use SupportsNamedInstances;

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

    public function composeFragment(ShipEnvironment $environment, ?string $instanceName = null): array
    {
        $name = $this->composeServiceName($instanceName);
        $prefix = $this->envPrefix($instanceName);

        return [
            $name => [
                'image' => 'mysql:9.7',
                'environment' => [
                    'MYSQL_DATABASE' => "\${{$prefix}DB_DATABASE:-app}",
                    'MYSQL_USER' => "\${{$prefix}DB_USERNAME:-app}",
                    'MYSQL_PASSWORD' => "\${{$prefix}DB_PASSWORD:-secret}",
                    // No separate root secret to manage -- the app user's own
                    // password doubles as root's, since nothing here needs
                    // root access beyond what MySQL's own image setup uses it for.
                    'MYSQL_ROOT_PASSWORD' => "\${{$prefix}DB_PASSWORD:-secret}",
                ],
                'volumes' => $environment->isDevelopment()
                    ? ["ship-{$name}-data:/var/lib/mysql"]
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

    public function environmentVariables(?string $instanceName = null): array
    {
        $name = $this->composeServiceName($instanceName);
        $prefix = $this->envPrefix($instanceName);

        return [
            "{$prefix}DB_CONNECTION" => 'mysql',
            "{$prefix}DB_HOST" => $name,
            "{$prefix}DB_PORT" => '3306',
            // Same expressions as composeFragment()'s own service, so both sides
            // always resolve from the same source at the same time.
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
            'command' => ['sh', '-c', 'exec mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'],
        ];
    }
}

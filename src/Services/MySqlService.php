<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ProvidesDatabaseShell;
use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\RequiredEnv;

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
                    // A default in dev, required in production (see RequiredEnv), so a project
                    // never ships with a publicly known password.
                    'MYSQL_PASSWORD' => RequiredEnv::expr("{$prefix}DB_PASSWORD", 'secret', $environment),
                    // A separate secret from the app user's DB_PASSWORD: "app" never authenticates
                    // as root, so a leaked app credential doesn't grant MySQL admin access.
                    'MYSQL_ROOT_PASSWORD' => RequiredEnv::expr("{$prefix}DB_ROOT_PASSWORD", 'rootsecret', $environment),
                ],
                // Persisted in both environments; a production database must survive a restart
                // or redeploy.
                'volumes' => ["ship-{$name}-data:/var/lib/mysql"],
                'healthcheck' => [
                    // 127.0.0.1, not "localhost", which makes mysqladmin use the unix socket. On
                    // a fresh volume the image first runs a temporary socket-only server, so a
                    // socket ping reports healthy before TCP connections are accepted.
                    'test' => ['CMD', 'mysqladmin', 'ping', '-h', '127.0.0.1'],
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
            // The same expressions composeFragment() provisions MySQL with.
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

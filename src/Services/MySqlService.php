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
                // Persisted in both environments -- a production database losing every row on
                // the next `docker compose restart`/redeploy is a real data-loss bug, not a
                // dev/prod distinction worth making. See docs/roadmap.md for the fix across
                // every stateful service. $environment is otherwise unused here now --
                // required by ServiceDefinition regardless.
                'volumes' => ["ship-{$name}-data:/var/lib/mysql"],
                'healthcheck' => [
                    // 127.0.0.1, not "localhost" -- found the hard way: "localhost" makes mysqladmin
                    // use the unix socket, and on a fresh volume the image first starts a
                    // *temporary* server that listens on that socket only (port 0, no TCP), then
                    // stops it and starts the real one. Measured: the socket ping succeeded from
                    // ~9s to ~13s while TCP was still refusing connections, so the container went
                    // "healthy" a few seconds before anything could actually connect to it -- and
                    // whatever ran right after (`ship up`'s own wait, a deploy command's
                    // migration) hit "connection refused". A TCP ping only succeeds once the real
                    // server is up.
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

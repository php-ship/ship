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
                    // A friendly default in dev, but required (docker compose itself refuses to
                    // run at all otherwise) in production -- a real bug found via an independent
                    // audit: every project that never set a real DB_PASSWORD in .env.production
                    // silently shared the exact same publicly-known default, with no error or
                    // warning. See RequiredEnv's own docblock.
                    'MYSQL_PASSWORD' => RequiredEnv::expr("{$prefix}DB_PASSWORD", 'secret', $environment),
                    // A separate secret from the app user's own DB_PASSWORD, not the same value
                    // doubling as both -- "app" only ever authenticates as MYSQL_USER, never as
                    // root, so the app container has no legitimate reason to even know the root
                    // password (it's never in environmentVariables() below). Reusing DB_PASSWORD
                    // for both meant a leaked app credential (a debug page, a logged query, a
                    // compromised app container) handed over full MySQL admin access too, not
                    // just the app's own scoped schema.
                    'MYSQL_ROOT_PASSWORD' => RequiredEnv::expr("{$prefix}DB_ROOT_PASSWORD", 'rootsecret', $environment),
                ],
                // Persisted in both environments -- a production database losing every row on
                // the next `docker compose restart`/redeploy is a real data-loss bug, not a
                // dev/prod distinction worth making. See docs/roadmap.md for the fix across
                // every stateful service.
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

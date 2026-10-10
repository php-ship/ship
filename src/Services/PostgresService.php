<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ProvidesDatabaseShell;
use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\RequiredEnv;

final class PostgresService implements ServiceDefinition, ProvidesDatabaseShell
{
    use SupportsNamedInstances;

    public function key(): string
    {
        return 'pgsql';
    }

    public function label(): string
    {
        return 'PostgreSQL';
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
                'image' => 'postgres:18-alpine',
                'environment' => [
                    'POSTGRES_DB' => "\${{$prefix}DB_DATABASE:-app}",
                    'POSTGRES_USER' => "\${{$prefix}DB_USERNAME:-app}",
                    // A default in dev, required in production (see RequiredEnv).
                    'POSTGRES_PASSWORD' => RequiredEnv::expr("{$prefix}DB_PASSWORD", 'secret', $environment),
                ],
                // Persisted in both environments, like every stateful service.
                'volumes' => ["ship-{$name}-data:/var/lib/postgresql"],
                'healthcheck' => [
                    'test' => ['CMD-SHELL', "pg_isready -U \${{$prefix}DB_USERNAME:-app}"],
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
            "{$prefix}DB_CONNECTION" => 'pgsql',
            "{$prefix}DB_HOST" => $name,
            "{$prefix}DB_PORT" => '5432',
            // The same expressions composeFragment() provisions Postgres with.
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
            'command' => ['sh', '-c', 'exec psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"'],
        ];
    }
}

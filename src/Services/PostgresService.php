<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ProvidesDatabaseShell;
use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;

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
                    'POSTGRES_PASSWORD' => "\${{$prefix}DB_PASSWORD:-secret}",
                ],
                'volumes' => $environment->isDevelopment()
                    ? ["ship-{$name}-data:/var/lib/postgresql"]
                    : [],
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
            // Same `${..._DATABASE:-app}`/`${..._USERNAME:-app}`/`${..._PASSWORD:-secret}`
            // expressions as composeFragment()'s own service, so both sides always resolve
            // from the same source at the same compose-parse time, never two independent
            // guesses that could silently drift apart.
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
            // Reads the container's own env vars rather than ship's, so this
            // always matches whatever the container was actually provisioned
            // with, even if DB_USERNAME/DB_DATABASE were overridden.
            'command' => ['sh', '-c', 'exec psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"'],
        ];
    }
}

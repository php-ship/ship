<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ProvidesDatabaseShell;
use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;

final class PostgresService implements ServiceDefinition, ProvidesDatabaseShell
{
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

    public function composeFragment(ShipEnvironment $environment): array
    {
        return [
            'pgsql' => [
                'image' => 'postgres:18-alpine',
                'environment' => [
                    'POSTGRES_DB' => '${DB_DATABASE:-app}',
                    'POSTGRES_USER' => '${DB_USERNAME:-app}',
                    'POSTGRES_PASSWORD' => '${DB_PASSWORD:-secret}',
                ],
                'volumes' => $environment->isDevelopment()
                    ? ['ship-pgsql-data:/var/lib/postgresql']
                    : [],
                'healthcheck' => [
                    'test' => ['CMD-SHELL', 'pg_isready -U ${DB_USERNAME:-app}'],
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
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => 'pgsql',
            'DB_PORT' => '5432',
            // Same `${DB_DATABASE:-app}`/`${DB_USERNAME:-app}`/
            // `${DB_PASSWORD:-secret}` expressions as composeFragment()'s
            // "pgsql" service, so both sides always resolve from the same
            // source at the same compose-parse time, never two
            // independent guesses that could silently drift apart.
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
            'service' => 'pgsql',
            // Reads the container's own env vars rather than ship's, so this
            // always matches whatever the "pgsql" container was actually
            // provisioned with, even if DB_USERNAME/DB_DATABASE were overridden.
            'command' => ['sh', '-c', 'exec psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"'],
        ];
    }
}

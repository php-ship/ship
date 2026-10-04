<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\PostgresService;

/**
 * Cross-checks what the "pgsql" container is provisioned with against what "app" is told to
 * connect with, rather than asserting either side in isolation.
 */
final class PostgresServiceTest extends TestCase
{
    public function test_app_credentials_match_what_the_pgsql_container_is_provisioned_with(): void
    {
        $service = new PostgresService();
        $pgsqlEnv = $service->composeFragment(ShipEnvironment::Development)['pgsql']['environment'];
        $appEnv = $service->environmentVariables();

        self::assertSame($pgsqlEnv['POSTGRES_DB'], $appEnv['DB_DATABASE']);
        self::assertSame($pgsqlEnv['POSTGRES_USER'], $appEnv['DB_USERNAME']);
        self::assertSame($pgsqlEnv['POSTGRES_PASSWORD'], $appEnv['DB_PASSWORD']);
    }

    /**
     * Production requires a real value instead of falling back to the same friendly default
     * development uses -- `docker compose` itself refuses to run at all when no real value is
     * set in .env.production.
     */
    public function test_the_password_is_required_in_production_not_just_overridable(): void
    {
        $fragment = (new PostgresService())->composeFragment(ShipEnvironment::Production);

        self::assertSame('${DB_PASSWORD:?set a real value in .env.production}', $fragment['pgsql']['environment']['POSTGRES_PASSWORD']);
    }

    public function test_db_shell_command_targets_the_pgsql_service(): void
    {
        $shell = (new PostgresService())->databaseShellCommand();

        self::assertSame('pgsql', $shell['service']);
        self::assertStringContainsString('psql', implode(' ', $shell['command']));
    }
}

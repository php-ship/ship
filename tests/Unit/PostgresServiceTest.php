<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\PostgresService;

/**
 * Cross-checks what the "postgres" container is provisioned with against what "app" connects with.
 */
final class PostgresServiceTest extends TestCase
{
    public function test_app_credentials_match_what_the_postgres_container_is_provisioned_with(): void
    {
        $service = new PostgresService();
        $postgresEnv = $service->composeFragment(ShipEnvironment::Development)['postgres']['environment'];
        $appEnv = $service->environmentVariables();

        self::assertSame($postgresEnv['POSTGRES_DB'], $appEnv['DB_DATABASE']);
        self::assertSame($postgresEnv['POSTGRES_USER'], $appEnv['DB_USERNAME']);
        self::assertSame($postgresEnv['POSTGRES_PASSWORD'], $appEnv['DB_PASSWORD']);
    }

    /**
     * Production requires a real password instead of the development default.
     */
    public function test_the_password_is_required_in_production_not_just_overridable(): void
    {
        $fragment = (new PostgresService())->composeFragment(ShipEnvironment::Production);

        self::assertSame('${DB_PASSWORD:?set a real value in .env.production}', $fragment['postgres']['environment']['POSTGRES_PASSWORD']);
    }

    public function test_db_shell_command_targets_the_postgres_service(): void
    {
        $shell = (new PostgresService())->databaseShellCommand();

        self::assertSame('postgres', $shell['service']);
        self::assertStringContainsString('psql', implode(' ', $shell['command']));
    }
}

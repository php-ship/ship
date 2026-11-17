<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\SqlServerService;

final class SqlServerServiceTest extends TestCase
{
    public function test_app_credentials_match_what_the_app_login_is_created_with(): void
    {
        $service = new SqlServerService();
        $env = $service->composeFragment(ShipEnvironment::Development)['sqlserver']['environment'];
        $appEnv = $service->environmentVariables();

        self::assertSame('sqlsrv', $appEnv['DB_CONNECTION']);
        self::assertSame('sqlserver', $appEnv['DB_HOST']);
        self::assertSame('1433', $appEnv['DB_PORT']);
        self::assertSame($env['SHIP_MSSQL_DATABASE'], $appEnv['DB_DATABASE']);
        self::assertSame($env['SHIP_MSSQL_USERNAME'], $appEnv['DB_USERNAME']);
        self::assertSame($env['SHIP_MSSQL_PASSWORD'], $appEnv['DB_PASSWORD']);
        self::assertSame(
            "sqlsrv://{$env['SHIP_MSSQL_USERNAME']}:{$env['SHIP_MSSQL_PASSWORD']}@sqlserver:1433/{$env['SHIP_MSSQL_DATABASE']}?trust_server_certificate=true",
            $appEnv['DB_URL'],
        );
    }

    /**
     * Developer Edition isn't licensed for production.
     */
    public function test_it_is_absent_from_production(): void
    {
        self::assertSame([], (new SqlServerService())->composeFragment(ShipEnvironment::Production));
    }

    public function test_it_runs_developer_edition_with_the_eula_accepted_on_amd64(): void
    {
        $fragment = (new SqlServerService())->composeFragment(ShipEnvironment::Development)['sqlserver'];

        self::assertSame('Developer', $fragment['environment']['MSSQL_PID']);
        self::assertSame('Y', $fragment['environment']['ACCEPT_EULA']);
        self::assertSame('linux/amd64', $fragment['platform']);
    }

    /**
     * SQL Server's policy rejects passwords like "secret", which would stop the container.
     *
     * @return iterable<string, array{string}>
     */
    public static function defaultPasswords(): iterable
    {
        yield 'sa' => ['MSSQL_SA_PASSWORD'];
        yield 'app login' => ['SHIP_MSSQL_PASSWORD'];
    }

    #[DataProvider('defaultPasswords')]
    public function test_default_passwords_meet_sql_servers_policy(string $variable): void
    {
        $value = (new SqlServerService())->composeFragment(ShipEnvironment::Development)['sqlserver']['environment'][$variable];
        self::assertSame(1, preg_match('/:-([^}]+)\}$/', $value, $match));
        $password = $match[1];

        $classes = (int) (preg_match('/[A-Z]/', $password) === 1) + (int) (preg_match('/[a-z]/', $password) === 1)
            + (int) (preg_match('/\d/', $password) === 1) + (int) (preg_match('/[^A-Za-z\d]/', $password) === 1);

        self::assertGreaterThanOrEqual(8, strlen($password));
        self::assertGreaterThanOrEqual(3, $classes);
    }

    public function test_the_sa_password_is_a_separate_secret_the_app_never_receives(): void
    {
        $service = new SqlServerService();
        $env = $service->composeFragment(ShipEnvironment::Development)['sqlserver']['environment'];

        self::assertSame('${DB_ROOT_PASSWORD:-ShipRoot123}', $env['MSSQL_SA_PASSWORD']);
        self::assertArrayNotHasKey('DB_ROOT_PASSWORD', $service->environmentVariables());
        self::assertStringNotContainsString('DB_ROOT_PASSWORD', implode(' ', $service->environmentVariables()));
    }

    /**
     * Every $ in the entrypoint is $$-escaped, so Compose passes the script through untouched.
     */
    public function test_the_entrypoint_script_escapes_every_dollar_for_compose(): void
    {
        $script = (new SqlServerService())->composeFragment(ShipEnvironment::Development)['sqlserver']['entrypoint'][2];

        self::assertSame(0, preg_match('/(?<!\$)\$(?!\$)/', str_replace('$$', '', $script)));
        self::assertStringContainsString('/opt/mssql/bin/launch_sqlservr.sh /opt/mssql/bin/sqlservr &', $script);
        // sqlcmd's $(var) substitution is disabled, and values are quoted for SQL.
        self::assertStringContainsString('sqlcmd -C -b -x ', $script);
        self::assertStringContainsString("sed \"s/'/''/g\"", $script);
    }

    /**
     * launch_sqlservr.sh doesn't forward signals; signalling it instead of SQL Server would let
     * `docker stop` kill the server without a clean shutdown.
     */
    public function test_stop_signals_reach_sql_server_itself_and_it_has_time_to_shut_down(): void
    {
        $fragment = (new SqlServerService())->composeFragment(ShipEnvironment::Development)['sqlserver'];
        $script = $fragment['entrypoint'][2];

        self::assertStringContainsString("trap 'pkill -TERM -o -x sqlservr' TERM INT", $script);
        self::assertStringNotContainsString('kill -TERM $$pid', $script);
        self::assertSame('30s', $fragment['stop_grace_period']);
    }

    public function test_sa_is_refused_as_the_app_username_before_sql_server_starts(): void
    {
        $script = (new SqlServerService())->composeFragment(ShipEnvironment::Development)['sqlserver']['entrypoint'][2];

        self::assertLessThan(strpos($script, 'launch_sqlservr.sh'), strpos($script, 'sa) echo'));
    }

    public function test_the_healthcheck_logs_in_as_the_app_user_to_the_app_database(): void
    {
        $test = (new SqlServerService())->composeFragment(ShipEnvironment::Development)['sqlserver']['healthcheck']['test'];

        self::assertStringContainsString('-U "$$SHIP_MSSQL_USERNAME"', $test[1]);
        self::assertStringContainsString('-d "$$SHIP_MSSQL_DATABASE"', $test[1]);
    }

    public function test_a_named_instance_gets_its_own_service_volume_and_prefixed_variables(): void
    {
        $service = new SqlServerService();
        $fragment = $service->composeFragment(ShipEnvironment::Development, 'legacy');
        $appEnv = $service->environmentVariables('legacy');

        self::assertSame(['ship-sqlserver-legacy-data:/var/opt/mssql'], $fragment['sqlserver-legacy']['volumes']);
        self::assertSame('${LEGACY_DB_PASSWORD:-ShipSecret123}', $fragment['sqlserver-legacy']['environment']['SHIP_MSSQL_PASSWORD']);
        self::assertSame('sqlserver-legacy', $appEnv['LEGACY_DB_HOST']);
        self::assertStringContainsString('@sqlserver-legacy:1433/', $appEnv['LEGACY_DB_URL']);
        self::assertArrayNotHasKey('DB_HOST', $appEnv);
    }

    public function test_db_shell_command_logs_in_as_the_app_user(): void
    {
        $service = new SqlServerService();

        self::assertSame('sqlserver', $service->databaseShellCommand()['service']);
        self::assertSame('sqlserver-legacy', $service->databaseShellCommand('legacy')['service']);
        self::assertStringContainsString('sqlcmd -C -S localhost -U "$SHIP_MSSQL_USERNAME"', implode(' ', $service->databaseShellCommand()['command']));
    }

    public function test_it_requires_the_pdo_sqlsrv_php_extension(): void
    {
        self::assertSame(['pdo_sqlsrv'], (new SqlServerService())->requiredPhpExtensions());
    }
}

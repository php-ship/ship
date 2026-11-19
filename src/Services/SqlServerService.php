<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Config\ShipConfig;
use Ship\Contracts\ProvidesDatabaseShell;
use Ship\Contracts\RequiresPhpExtensions;
use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\RequiredEnv;

/**
 * Microsoft SQL Server. Development runs the Developer edition, which is free and licensed for
 * development and testing. A release runs the edition MSSQL_PID names (Standard, Enterprise, or a
 * product key), Developer when it's unset: a release can be tried on a server before it's
 * licensed, and `ship build`/`ship release` warn that it still needs a license (see
 * licenseWarnings()). Licensing is the user's: ACCEPT_EULA accepts Microsoft's terms, as the
 * image requires.
 *
 * The image creates no database and no user besides `sa`, so the entrypoint starts SQL Server,
 * creates the app's database and a login that owns it (both idempotent, on every boot), then
 * waits on the server. `sa`'s password (DB_ROOT_PASSWORD) is a separate secret the app never
 * receives. Passwords must meet SQL Server's policy: at least 8 characters from three of
 * uppercase, lowercase, digits and symbols. The app's password also goes into DB_URL, so it must
 * not need percent-encoding.
 */
final class SqlServerService implements ServiceDefinition, ProvidesDatabaseShell, RequiresPhpExtensions
{
    use SupportsNamedInstances;

    private const SQLCMD = '/opt/mssql-tools18/bin/sqlcmd';

    /**
     * $$ is a literal $ for the shell, since Compose interpolates the whole file. Values reach SQL
     * as N'...' literals with quotes doubled, identifiers go through QUOTENAME(), and sqlcmd's own
     * $(var) substitution is disabled (-x), so no credential can alter the statements.
     */
    private const ENTRYPOINT_SCRIPT = <<<'SH'
        case "$$(printf '%s' "$$SHIP_MSSQL_USERNAME" | tr '[:upper:]' '[:lower:]')" in
          sa) echo 'ship: DB_USERNAME is "sa", SQL Server'"'"'s built-in admin login (its password is DB_ROOT_PASSWORD). Pick a different username.' >&2; exit 1 ;;
        esac
        /opt/mssql/bin/launch_sqlservr.sh /opt/mssql/bin/sqlservr &
        pid=$$!
        # launch_sqlservr.sh doesn't forward signals to its sqlservr child, so the stop signal goes to
        # SQL Server itself (the oldest sqlservr, which coordinates shutdown), not to the wrapper.
        trap 'pkill -TERM -o -x sqlservr' TERM INT
        sql() { /opt/mssql-tools18/bin/sqlcmd -C -b -x -S localhost -U sa -P "$$MSSQL_SA_PASSWORD" "$$@"; }
        lit() { printf '%s' "$$1" | sed "s/'/''/g"; }
        until sql -l 2 -Q 'SELECT 1' >/dev/null 2>&1; do
          kill -0 $$pid 2>/dev/null || { echo 'ship: SQL Server exited during startup; see the log above (a weak DB_ROOT_PASSWORD is the usual cause).' >&2; exit 1; }
          sleep 1
        done
        if ! sql -Q "SET NOCOUNT ON;
          DECLARE @db sysname = N'$$(lit "$$SHIP_MSSQL_DATABASE")', @user sysname = N'$$(lit "$$SHIP_MSSQL_USERNAME")',
            @pw nvarchar(128) = N'$$(lit "$$SHIP_MSSQL_PASSWORD")', @sql nvarchar(max);
          IF DB_ID(@db) IS NULL BEGIN SET @sql = N'CREATE DATABASE ' + QUOTENAME(@db); EXEC (@sql); END;
          IF SUSER_ID(@user) IS NULL BEGIN
            SET @sql = N'CREATE LOGIN ' + QUOTENAME(@user) + N' WITH PASSWORD = ' + QUOTENAME(@pw, '''') + N', DEFAULT_DATABASE = ' + QUOTENAME(@db);
            EXEC (@sql);
          END;
          IF IS_SRVROLEMEMBER('sysadmin', @user) = 0 BEGIN
            SET @sql = N'USE ' + QUOTENAME(@db) + N'; IF USER_ID(' + QUOTENAME(@user, '''') + N') IS NULL CREATE USER '
              + QUOTENAME(@user) + N' FOR LOGIN ' + QUOTENAME(@user) + N'; ALTER ROLE db_owner ADD MEMBER ' + QUOTENAME(@user) + N';';
            EXEC (@sql);
          END;"; then
          echo 'ship: could not create the app database or login; see the error above (a DB_PASSWORD that fails the password policy is the usual cause).' >&2
          pkill -TERM -o -x sqlservr; wait $$pid; exit 1
        fi
        wait $$pid; status=$$?
        # A trapped signal interrupts the first wait; the second lets SQL Server finish shutting down.
        if kill -0 $$pid 2>/dev/null; then wait $$pid; status=$$?; fi
        exit $$status
        SH;

    public function key(): string
    {
        return 'sqlserver';
    }

    public function label(): string
    {
        return 'SQL Server';
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
                'image' => 'mcr.microsoft.com/mssql/server:2025-CU11-ubuntu-24.04',
                // Microsoft publishes amd64 only. Explicit, so ARM machines (Apple Silicon) run it
                // under emulation instead of failing to find a matching image.
                'platform' => 'linux/amd64',
                'entrypoint' => ['/bin/bash', '-c', self::ENTRYPOINT_SCRIPT],
                // A clean shutdown checkpoints every database, which can outlast Docker's 10s default.
                'stop_grace_period' => '30s',
                'environment' => [
                    'ACCEPT_EULA' => 'Y',
                    // The edition, or a product key. Production reads it from .env.production.
                    'MSSQL_PID' => $environment->isDevelopment() ? 'Developer' : "\${{$prefix}MSSQL_PID:-Developer}",
                    // A separate secret from the app login's DB_PASSWORD: "app" never
                    // authenticates as sa. Production requires both (see RequiredEnv).
                    'MSSQL_SA_PASSWORD' => RequiredEnv::expr("{$prefix}DB_ROOT_PASSWORD", 'ShipRoot123', $environment),
                    'SHIP_MSSQL_DATABASE' => "\${{$prefix}DB_DATABASE:-app}",
                    'SHIP_MSSQL_USERNAME' => "\${{$prefix}DB_USERNAME:-app}",
                    'SHIP_MSSQL_PASSWORD' => RequiredEnv::expr("{$prefix}DB_PASSWORD", 'ShipSecret123', $environment),
                ],
                'volumes' => ["ship-{$name}-data:/var/opt/mssql"],
                'healthcheck' => [
                    // Logs in as the app user to the app database, which only succeeds once the
                    // entrypoint has created both.
                    'test' => [
                        'CMD-SHELL',
                        self::SQLCMD . ' -C -l 2 -S localhost -U "$$SHIP_MSSQL_USERNAME" -P "$$SHIP_MSSQL_PASSWORD" '
                            . '-d "$$SHIP_MSSQL_DATABASE" -Q "SELECT 1" >/dev/null',
                    ],
                    'interval' => '5s',
                    'timeout' => '5s',
                    'retries' => 20,
                    'start_period' => '20s',
                ],
            ],
        ];
    }

    public function environmentVariables(?string $instanceName = null): array
    {
        $name = $this->composeServiceName($instanceName);
        $prefix = $this->envPrefix($instanceName);
        $database = "\${{$prefix}DB_DATABASE:-app}";
        $username = "\${{$prefix}DB_USERNAME:-app}";
        $password = "\${{$prefix}DB_PASSWORD:-ShipSecret123}";

        return [
            "{$prefix}DB_CONNECTION" => 'sqlsrv',
            "{$prefix}DB_HOST" => $name,
            "{$prefix}DB_PORT" => '1433',
            // The same expressions composeFragment() provisions the app login with.
            "{$prefix}DB_DATABASE" => $database,
            "{$prefix}DB_USERNAME" => $username,
            "{$prefix}DB_PASSWORD" => $password,
            // ODBC Driver 18 encrypts and verifies the server certificate by default, and the
            // container's certificate is self-signed. Laravel's sqlsrv connection only reads
            // DB_TRUST_SERVER_CERTIFICATE from a published, edited config/database.php, but it
            // applies a DB_URL's query options as-is, so this works without touching the app.
            "{$prefix}DB_URL" => "sqlsrv://{$username}:{$password}@{$name}:1433/{$database}?trust_server_certificate=true",
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
            // Reads the container's own env vars, so it matches whatever the login was created with.
            'command' => [
                'sh',
                '-c',
                'exec ' . self::SQLCMD . ' -C -S localhost -U "$SHIP_MSSQL_USERNAME" -P "$SHIP_MSSQL_PASSWORD" -d "$SHIP_MSSQL_DATABASE"',
            ],
        ];
    }

    /**
     * Laravel's sqlsrv driver. The extension installer also installs Microsoft's ODBC driver.
     */
    public function requiredPhpExtensions(): array
    {
        return ['pdo_sqlsrv'];
    }

    /**
     * A warning for each SQL Server (the database, and each named instance) a release would run as
     * the Developer edition: MSSQL_PID unset or "Developer" in $env (.env.production).
     *
     * @param array<string, string> $env
     * @return list<string>
     */
    public static function licenseWarnings(ShipConfig $config, array $env): array
    {
        $instances = [
            ...(($config->services['database'] ?? null) === 'sqlserver' ? [null] : []),
            ...array_column(array_filter($config->additionalServices, static fn (array $a): bool => $a['service'] === 'sqlserver'), 'name'),
        ];

        $warnings = [];
        foreach ($instances as $instance) {
            $setting = ($instance === null ? '' : strtoupper($instance) . '_') . 'MSSQL_PID';
            $edition = trim($env[$setting] ?? '');

            if ($edition === '' || strtolower($edition) === 'developer') {
                $warnings[] = sprintf(
                    'SQL Server%s runs as the Developer edition, which Microsoft licenses for development and '
                        . 'testing only. Fine for trying a release on a server; before it serves real users, set '
                        . '%s in .env.production to your licensed edition (Standard, Enterprise, ...) or product key. '
                        . 'Licensing it is up to you.',
                    $instance === null ? '' : " \"{$instance}\"",
                    $setting,
                );
            }
        }

        return $warnings;
    }
}

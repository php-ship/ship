<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ProvidesDatabaseShell;
use Ship\Contracts\RequiresPhpExtensions;
use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\RequiredEnv;

/**
 * MongoDB, provisioned like MySQL: a root user with its own secret (MONGODB_ROOT_PASSWORD) that the
 * app never receives, and an app user that owns only the app's database. The official image only
 * creates the root user itself, so the entrypoint writes a small init script creating the app
 * user; like every /docker-entrypoint-initdb.d script, it only runs on an empty data volume.
 *
 * The app gets MONGODB_URI and MONGODB_DATABASE, the variables mongodb/laravel-mongodb's documented
 * connection reads. DB_CONNECTION is left alone: until that package is installed and configured, a
 * "mongodb" connection doesn't exist and every database call would fail.
 */
final class MongoDbService implements ServiceDefinition, ProvidesDatabaseShell, RequiresPhpExtensions
{
    use SupportsNamedInstances;

    /**
     * Run by the image's entrypoint through mongosh, authenticated as root, with `db` set to
     * MONGO_INITDB_DATABASE. No "$" anywhere, so Compose has nothing to interpolate.
     */
    private const CREATE_APP_USER_JS = <<<'JS'
        const database = process.env.MONGO_INITDB_DATABASE;
        db.getSiblingDB(database).createUser({
          user: process.env.SHIP_MONGODB_USERNAME,
          pwd: process.env.SHIP_MONGODB_PASSWORD,
          roles: [{ role: 'dbOwner', db: database }],
        });
        JS;

    public function key(): string
    {
        return 'mongodb';
    }

    public function label(): string
    {
        return 'MongoDB';
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
                'image' => 'mongo:9.0',
                'entrypoint' => [
                    'sh',
                    '-c',
                    "cat > /docker-entrypoint-initdb.d/ship-app-user.js <<'JS'\n"
                        . self::CREATE_APP_USER_JS . "\n"
                        . "JS\n"
                        . 'exec docker-entrypoint.sh mongod',
                ],
                'environment' => [
                    'MONGO_INITDB_DATABASE' => "\${{$prefix}MONGODB_DATABASE:-app}",
                    // A separate secret from the app user's MONGODB_PASSWORD: "app" never
                    // authenticates as root.
                    'MONGO_INITDB_ROOT_USERNAME' => 'root',
                    'MONGO_INITDB_ROOT_PASSWORD' => RequiredEnv::expr("{$prefix}MONGODB_ROOT_PASSWORD", 'rootsecret', $environment),
                    'SHIP_MONGODB_USERNAME' => "\${{$prefix}MONGODB_USERNAME:-app}",
                    // A default in dev, required in production (see RequiredEnv).
                    'SHIP_MONGODB_PASSWORD' => RequiredEnv::expr("{$prefix}MONGODB_PASSWORD", 'secret', $environment),
                ],
                // Persisted in both environments, like every stateful service.
                'volumes' => ["ship-{$name}-data:/data/db"],
                'healthcheck' => [
                    // The container's own address, not localhost: the image's temporary
                    // init-phase server only listens on 127.0.0.1, so a localhost ping would
                    // report healthy before initialization has finished. $$ keeps Compose from
                    // interpolating the command substitution itself.
                    'test' => ['CMD-SHELL', 'mongosh --quiet --host "$$(hostname -i)" --eval "db.adminCommand(\'ping\').ok" | grep -qx 1'],
                    'interval' => '5s',
                    'timeout' => '5s',
                    'retries' => 10,
                ],
            ],
        ];
    }

    public function environmentVariables(?string $instanceName = null): array
    {
        $name = $this->composeServiceName($instanceName);
        $prefix = $this->envPrefix($instanceName);

        return [
            // The same expressions composeFragment() provisions the app user with. The password
            // is embedded in a URI, so it must not need percent-encoding (no @ : / ? # [ ] %).
            "{$prefix}MONGODB_URI" => "mongodb://\${{$prefix}MONGODB_USERNAME:-app}:\${{$prefix}MONGODB_PASSWORD:-secret}"
                . "@{$name}:27017/\${{$prefix}MONGODB_DATABASE:-app}",
            "{$prefix}MONGODB_DATABASE" => "\${{$prefix}MONGODB_DATABASE:-app}",
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
            'command' => [
                'sh',
                '-c',
                'exec mongosh -u "$SHIP_MONGODB_USERNAME" -p "$SHIP_MONGODB_PASSWORD" '
                    . '--authenticationDatabase "$MONGO_INITDB_DATABASE" "$MONGO_INITDB_DATABASE"',
            ],
        ];
    }

    public function requiredPhpExtensions(): array
    {
        return ['mongodb'];
    }
}

<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\MongoDbService;

final class MongoDbServiceTest extends TestCase
{
    public function test_the_app_uri_carries_the_same_credentials_the_app_user_is_created_with(): void
    {
        $service = new MongoDbService();
        $mongoEnv = $service->composeFragment(ShipEnvironment::Development)['mongodb']['environment'];
        $appEnv = $service->environmentVariables();

        self::assertSame(
            "mongodb://{$mongoEnv['SHIP_MONGODB_USERNAME']}:{$mongoEnv['SHIP_MONGODB_PASSWORD']}@mongodb:27017/{$mongoEnv['MONGO_INITDB_DATABASE']}",
            $appEnv['MONGODB_URI'],
        );
        self::assertSame($mongoEnv['MONGO_INITDB_DATABASE'], $appEnv['MONGODB_DATABASE']);
    }

    /**
     * Without laravel-mongodb installed there is no "mongodb" connection, so overriding the app's
     * DB_CONNECTION would break every database call.
     */
    public function test_it_leaves_the_apps_default_database_connection_alone(): void
    {
        $appEnv = (new MongoDbService())->environmentVariables();

        self::assertArrayNotHasKey('DB_CONNECTION', $appEnv);
        self::assertArrayNotHasKey('DB_DATABASE', $appEnv);
    }

    public function test_the_passwords_are_required_in_production_not_just_overridable(): void
    {
        $env = (new MongoDbService())->composeFragment(ShipEnvironment::Production)['mongodb']['environment'];

        self::assertSame('${MONGODB_PASSWORD:?set a real value in .env.production}', $env['SHIP_MONGODB_PASSWORD']);
        self::assertSame('${MONGODB_ROOT_PASSWORD:?set a real value in .env.production}', $env['MONGO_INITDB_ROOT_PASSWORD']);
    }

    public function test_the_root_password_is_a_separate_secret_the_app_never_receives(): void
    {
        $service = new MongoDbService();
        $mongoEnv = $service->composeFragment(ShipEnvironment::Development)['mongodb']['environment'];
        $appEnv = $service->environmentVariables();

        self::assertNotSame($mongoEnv['SHIP_MONGODB_PASSWORD'], $mongoEnv['MONGO_INITDB_ROOT_PASSWORD']);
        self::assertStringNotContainsString('MONGODB_ROOT_PASSWORD', implode(' ', $appEnv));
    }

    /**
     * The image itself only creates the root user; the entrypoint adds the app user's init script.
     */
    public function test_the_entrypoint_writes_an_app_user_init_script_then_starts_the_image_entrypoint(): void
    {
        $fragment = (new MongoDbService())->composeFragment(ShipEnvironment::Development);
        $script = $fragment['mongodb']['entrypoint'][2];

        self::assertSame(['sh', '-c'], array_slice($fragment['mongodb']['entrypoint'], 0, 2));
        self::assertStringContainsString('/docker-entrypoint-initdb.d/', $script);
        self::assertStringContainsString('createUser', $script);
        self::assertStringContainsString("role: 'dbOwner'", $script);
        self::assertStringEndsWith('exec docker-entrypoint.sh mongod', $script);
        // Compose would interpolate any bare $.
        self::assertStringNotContainsString('$', $script);
    }

    /**
     * The init-phase server listens on 127.0.0.1 only, so the healthcheck must not ping localhost.
     */
    public function test_the_healthcheck_pings_the_containers_own_address_with_compose_escaping(): void
    {
        $fragment = (new MongoDbService())->composeFragment(ShipEnvironment::Development);
        $test = $fragment['mongodb']['healthcheck']['test'];

        self::assertSame('CMD-SHELL', $test[0]);
        self::assertStringContainsString('--host "$$(hostname -i)"', $test[1]);
        self::assertStringNotContainsString('localhost', $test[1]);
        self::assertStringNotContainsString('127.0.0.1', $test[1]);
    }

    public function test_a_named_instance_gets_its_own_service_volume_and_prefixed_variables(): void
    {
        $service = new MongoDbService();
        $fragment = $service->composeFragment(ShipEnvironment::Development, 'events');
        $appEnv = $service->environmentVariables('events');

        self::assertArrayHasKey('mongodb-events', $fragment);
        self::assertSame(['ship-mongodb-events-data:/data/db'], $fragment['mongodb-events']['volumes']);
        self::assertSame('${EVENTS_MONGODB_DATABASE:-app}', $fragment['mongodb-events']['environment']['MONGO_INITDB_DATABASE']);
        self::assertStringContainsString('@mongodb-events:27017/', $appEnv['EVENTS_MONGODB_URI']);
        self::assertSame(['EVENTS_MONGODB_URI', 'EVENTS_MONGODB_DATABASE'], array_keys($appEnv));
    }

    public function test_db_shell_command_authenticates_as_the_app_user(): void
    {
        $service = new MongoDbService();
        $command = implode(' ', $service->databaseShellCommand()['command']);

        self::assertSame('mongodb', $service->databaseShellCommand()['service']);
        self::assertSame('mongodb-events', $service->databaseShellCommand('events')['service']);
        self::assertStringContainsString('exec mongosh -u "$SHIP_MONGODB_USERNAME"', $command);
    }

    public function test_it_requires_the_mongodb_php_extension(): void
    {
        self::assertSame(['mongodb'], (new MongoDbService())->requiredPhpExtensions());
    }
}

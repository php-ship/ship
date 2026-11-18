<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\TypesenseService;

final class TypesenseServiceTest extends TestCase
{
    /**
     * The settings Laravel Scout's typesense config reads.
     */
    public function test_the_app_gets_scouts_typesense_settings_and_driver(): void
    {
        self::assertSame([
            'SCOUT_DRIVER' => 'typesense',
            'TYPESENSE_HOST' => 'typesense',
            'TYPESENSE_PORT' => '8108',
            'TYPESENSE_PROTOCOL' => 'http',
            'TYPESENSE_API_KEY' => '${TYPESENSE_API_KEY:-shipsearchkey}',
        ], (new TypesenseService())->environmentVariables());
    }

    public function test_app_credentials_match_what_typesense_is_provisioned_with(): void
    {
        $service = new TypesenseService();
        $env = $service->composeFragment(ShipEnvironment::Development)['typesense']['environment'];

        self::assertSame($env['TYPESENSE_API_KEY'], $service->environmentVariables()['TYPESENSE_API_KEY']);
    }

    public function test_the_api_key_is_required_in_production_and_data_is_persisted(): void
    {
        $fragment = (new TypesenseService())->composeFragment(ShipEnvironment::Production)['typesense'];

        self::assertMatchesRegularExpression('/^typesense\/typesense:\d+\.\d+$/', $fragment['image']);
        self::assertSame('${TYPESENSE_API_KEY:?set a real value in .env.production}', $fragment['environment']['TYPESENSE_API_KEY']);
        self::assertSame('/data', $fragment['environment']['TYPESENSE_DATA_DIR']);
        self::assertSame(['ship-typesense-data:/data'], $fragment['volumes']);
    }

    /**
     * The image has no HTTP client; bash's /dev/tcp asks /health for {"ok":true}.
     */
    public function test_the_healthcheck_asks_the_health_endpoint_through_bash(): void
    {
        $test = (new TypesenseService())->composeFragment(ShipEnvironment::Development)['typesense']['healthcheck']['test'];

        self::assertSame(['CMD', 'bash', '-c'], array_slice($test, 0, 3));
        self::assertStringContainsString('/dev/tcp/127.0.0.1/8108', $test[3]);
        self::assertStringContainsString('GET /health', $test[3]);
        self::assertStringContainsString('"ok":true', $test[3]);
        self::assertStringNotContainsString('$', $test[3]);
    }

    /**
     * A named instance is a second index server; it doesn't change Scout's default driver.
     */
    public function test_a_named_instance_gets_prefixed_settings_and_its_own_key(): void
    {
        $service = new TypesenseService();
        $fragment = $service->composeFragment(ShipEnvironment::Development, 'docs');

        self::assertSame(['ship-typesense-docs-data:/data'], $fragment['typesense-docs']['volumes']);
        self::assertSame('${DOCS_TYPESENSE_API_KEY:-shipsearchkey}', $fragment['typesense-docs']['environment']['TYPESENSE_API_KEY']);
        self::assertArrayNotHasKey('SCOUT_DRIVER', $service->environmentVariables('docs'));
        self::assertSame('typesense-docs', $service->environmentVariables('docs')['DOCS_TYPESENSE_HOST']);
    }
}

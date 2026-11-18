<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\CentrifugoService;

final class CentrifugoServiceTest extends TestCase
{
    public function test_it_is_the_framework_agnostic_alternative_to_reverb(): void
    {
        $service = new CentrifugoService();

        self::assertSame('broadcasting', $service->group());
        self::assertMatchesRegularExpression('/^centrifugo\/centrifugo:v\d+\.\d+$/', $service->composeFragment(ShipEnvironment::Development)['centrifugo']['image']);
    }

    public function test_app_credentials_match_what_centrifugo_is_configured_with(): void
    {
        $service = new CentrifugoService();
        $env = $service->composeFragment(ShipEnvironment::Development)['centrifugo']['environment'];
        $appEnv = $service->environmentVariables();

        self::assertSame($env['CENTRIFUGO_HTTP_API_KEY'], $appEnv['CENTRIFUGO_API_KEY']);
        self::assertSame($env['CENTRIFUGO_CLIENT_TOKEN_HMAC_SECRET_KEY'], $appEnv['CENTRIFUGO_TOKEN_SECRET']);
        self::assertSame('http://centrifugo:8000', $appEnv['CENTRIFUGO_URL']);
        self::assertSame('ws://localhost:${CENTRIFUGO_PORT:-8001}/connection/websocket', $appEnv['VITE_CENTRIFUGO_URL']);
    }

    /**
     * Laravel has no built-in Centrifugo driver.
     */
    public function test_it_leaves_the_apps_broadcast_connection_alone(): void
    {
        self::assertArrayNotHasKey('BROADCAST_CONNECTION', (new CentrifugoService())->environmentVariables());
    }

    /**
     * With no allowed origins Centrifugo refuses every browser, so production requires them along
     * with the secrets.
     */
    public function test_secrets_and_allowed_origins_are_required_in_production(): void
    {
        $env = (new CentrifugoService())->composeFragment(ShipEnvironment::Production)['centrifugo']['environment'];

        self::assertSame('${CENTRIFUGO_API_KEY:?set a real value in .env.production}', $env['CENTRIFUGO_HTTP_API_KEY']);
        self::assertSame('${CENTRIFUGO_TOKEN_SECRET:?set a real value in .env.production}', $env['CENTRIFUGO_CLIENT_TOKEN_HMAC_SECRET_KEY']);
        self::assertSame('${CENTRIFUGO_ALLOWED_ORIGINS:?set a real value in .env.production}', $env['CENTRIFUGO_CLIENT_ALLOWED_ORIGINS']);
    }

    public function test_the_admin_ui_is_enabled_in_development_only(): void
    {
        $service = new CentrifugoService();

        self::assertSame('true', $service->composeFragment(ShipEnvironment::Development)['centrifugo']['environment']['CENTRIFUGO_ADMIN_ENABLED']);
        self::assertArrayNotHasKey('CENTRIFUGO_ADMIN_ENABLED', $service->composeFragment(ShipEnvironment::Production)['centrifugo']['environment']);
        self::assertSame('${CENTRIFUGO_ALLOWED_ORIGINS:-*}', $service->composeFragment(ShipEnvironment::Development)['centrifugo']['environment']['CENTRIFUGO_CLIENT_ALLOWED_ORIGINS']);
    }

    public function test_it_is_published_on_8001_to_stay_clear_of_octanes_8000_and_checks_its_own_health(): void
    {
        $fragment = (new CentrifugoService())->composeFragment(ShipEnvironment::Development)['centrifugo'];

        self::assertSame(['127.0.0.1:${CENTRIFUGO_PORT:-8001}:8000'], $fragment['ports']);
        self::assertSame('true', $fragment['environment']['CENTRIFUGO_HEALTH_ENABLED']);
        self::assertSame(['CMD', 'wget', '-qO-', 'http://127.0.0.1:8000/health'], $fragment['healthcheck']['test']);
    }

    public function test_it_is_a_websocket_entry_point_with_a_health_path(): void
    {
        self::assertSame(
            ['centrifugo' => ['port' => 8000, 'role' => 'websocket', 'health' => '/health']],
            (new CentrifugoService())->httpEntryPoints(),
        );
    }
}

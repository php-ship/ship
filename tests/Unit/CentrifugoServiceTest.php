<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\ComposeFileBuilder;
use Ship\Docker\ReleaseManifest;
use Ship\Services\CentrifugoService;
use Ship\Services\RedisService;
use Ship\Services\ServiceRegistry;
use Ship\Services\ValkeyService;
use Symfony\Component\Yaml\Yaml;

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
            ['centrifugo' => ['port' => 8000, 'role' => 'websocket', 'health' => '/health', 'urlSetting' => 'VITE_CENTRIFUGO_URL']],
            (new CentrifugoService())->httpEntryPoints(),
        );
    }

    /**
     * The Redis engine is opt-in: memory unless CENTRIFUGO_ENGINE says otherwise.
     */
    public function test_the_engine_is_memory_unless_centrifugo_engine_opts_in(): void
    {
        $env = (new CentrifugoService())->composeFragment(ShipEnvironment::Production)['centrifugo']['environment'];

        self::assertSame('${CENTRIFUGO_ENGINE:-memory}', $env['CENTRIFUGO_ENGINE_TYPE']);
    }

    public function test_the_redis_address_defaults_to_the_projects_redis_and_starts_after_it(): void
    {
        $service = (new CentrifugoService())->withConfig(new ShipConfig(phpVersion: '8.4', services: ['cache' => 'redis', 'broadcasting' => 'centrifugo']));
        $fragment = $service->composeFragment(ShipEnvironment::Production)['centrifugo'];

        self::assertSame('${CENTRIFUGO_REDIS_ADDRESS:-redis://redis:6379}', $fragment['environment']['CENTRIFUGO_ENGINE_REDIS_ADDRESS']);
        self::assertSame(['redis'], $fragment['depends_on']);
    }

    /**
     * Valkey serves the Redis protocol, so it is the default address too, and a rename is followed.
     */
    public function test_a_valkey_cache_is_the_default_redis_address_too(): void
    {
        $config = new ShipConfig(phpVersion: '8.4', services: ['cache' => 'valkey', 'broadcasting' => 'centrifugo'], serviceNames: ['valkey' => 'client-cache']);
        $parsed = Yaml::parse((new ComposeFileBuilder(new ServiceRegistry([new ValkeyService(), new CentrifugoService()])))->build($config, ShipEnvironment::Production));

        self::assertSame('${CENTRIFUGO_REDIS_ADDRESS:-redis://client-cache:6379}', $parsed['services']['centrifugo']['environment']['CENTRIFUGO_ENGINE_REDIS_ADDRESS']);
        self::assertSame(['client-cache'], $parsed['services']['centrifugo']['depends_on']);
    }

    /**
     * Memcached isn't Redis: there's nothing for the Redis engine to default to.
     */
    public function test_a_memcached_cache_gives_no_default_redis_address(): void
    {
        $service = (new CentrifugoService())->withConfig(new ShipConfig(phpVersion: '8.4', services: ['cache' => 'memcached', 'broadcasting' => 'centrifugo']));
        $fragment = $service->composeFragment(ShipEnvironment::Production)['centrifugo'];

        self::assertSame('${CENTRIFUGO_REDIS_ADDRESS:-}', $fragment['environment']['CENTRIFUGO_ENGINE_REDIS_ADDRESS']);
        self::assertArrayNotHasKey('depends_on', $fragment);
    }

    public function test_the_default_redis_address_follows_a_renamed_redis(): void
    {
        $config = new ShipConfig(phpVersion: '8.4', services: ['cache' => 'redis', 'broadcasting' => 'centrifugo'], serviceNames: ['redis' => 'client-cache']);
        $parsed = Yaml::parse((new ComposeFileBuilder(new ServiceRegistry([new RedisService(), new CentrifugoService()])))->build($config, ShipEnvironment::Production));

        self::assertSame('${CENTRIFUGO_REDIS_ADDRESS:-redis://client-cache:6379}', $parsed['services']['centrifugo']['environment']['CENTRIFUGO_ENGINE_REDIS_ADDRESS']);
        self::assertSame(['client-cache'], $parsed['services']['centrifugo']['depends_on']);
    }

    /**
     * Without a project Redis there's nothing to default to or wait for: opting in means naming one.
     */
    public function test_without_a_project_redis_the_address_has_no_default_and_nothing_is_waited_for(): void
    {
        $service = (new CentrifugoService())->withConfig(new ShipConfig(phpVersion: '8.4', services: ['broadcasting' => 'centrifugo']));
        $fragment = $service->composeFragment(ShipEnvironment::Production)['centrifugo'];

        self::assertSame('${CENTRIFUGO_REDIS_ADDRESS:-}', $fragment['environment']['CENTRIFUGO_ENGINE_REDIS_ADDRESS']);
        self::assertArrayNotHasKey('depends_on', $fragment);
    }

    /**
     * Opting in happens at deploy time, so release.json lists both settings for a deployer.
     */
    public function test_both_engine_settings_are_referenced_by_the_compose_file(): void
    {
        $config = new ShipConfig(phpVersion: '8.4', services: ['cache' => 'redis', 'broadcasting' => 'centrifugo']);
        $yaml = (new ComposeFileBuilder(new ServiceRegistry([new RedisService(), new CentrifugoService()])))->build($config, ShipEnvironment::Production);

        $referenced = ReleaseManifest::referencedVariables($yaml);

        self::assertContains('CENTRIFUGO_ENGINE', $referenced);
        self::assertContains('CENTRIFUGO_REDIS_ADDRESS', $referenced);
    }
}

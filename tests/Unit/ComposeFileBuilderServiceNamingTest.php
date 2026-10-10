<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\ComposeFileBuilder;
use Ship\Services\DuskService;
use Ship\Services\MeilisearchService;
use Ship\Services\MySqlService;
use Ship\Services\OctaneSwooleService;
use Ship\Services\PostgresService;
use Ship\Services\RedisService;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Yaml\Yaml;

/**
 * Covers ShipConfig::$serviceNames/$externalNetwork, which let several ship-managed projects
 * share one Docker network without colliding on default service aliases.
 */
final class ComposeFileBuilderServiceNamingTest extends TestCase
{
    private function registry(): ServiceRegistry
    {
        return new ServiceRegistry([
            new PostgresService(),
            new MySqlService(),
            new RedisService(),
            new MeilisearchService(),
            new OctaneSwooleService(),
            new DuskService(),
        ]);
    }

    public function test_default_names_leave_the_compose_output_unchanged(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: ['database' => 'pgsql']);

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertArrayHasKey('app', $parsed['services']);
        self::assertArrayHasKey('webserver', $parsed['services']);
        self::assertSame(['app'], $parsed['services']['webserver']['depends_on']);
    }

    public function test_a_custom_app_name_renames_the_service_and_every_reference_to_it(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        // Dusk is selected only because APP_URL is injected with it; this checks that APP_URL
        // follows the rename.
        $config = new ShipConfig(
            phpVersion: '8.4',
            services: ['database' => 'pgsql', 'testing' => 'dusk'],
            serviceNames: ['app' => 'client-app'],
        );

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertArrayNotHasKey('app', $parsed['services']);
        self::assertArrayHasKey('client-app', $parsed['services']);
        self::assertSame(['client-app'], $parsed['services']['webserver']['depends_on']);
        self::assertSame('http://webserver', $parsed['services']['client-app']['environment']['APP_URL']);
    }

    public function test_a_custom_webserver_name_renames_the_service_and_app_url_follows_it(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(
            phpVersion: '8.4',
            services: ['database' => 'pgsql', 'testing' => 'dusk'],
            serviceNames: ['webserver' => 'client-web'],
        );

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertArrayNotHasKey('webserver', $parsed['services']);
        self::assertArrayHasKey('client-web', $parsed['services']);
        self::assertSame(['app'], $parsed['services']['client-web']['depends_on']);
        self::assertSame('http://client-web', $parsed['services']['app']['environment']['APP_URL']);
    }

    /**
     * With an Octane runtime there is no "webserver": renaming it must not create one, and
     * APP_URL falls back to the app service.
     */
    public function test_webserver_name_is_ignored_when_no_webserver_exists(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(
            phpVersion: '8.4',
            services: ['database' => 'pgsql', 'runtime' => 'octane-swoole', 'testing' => 'dusk'],
            serviceNames: ['app' => 'client-app', 'webserver' => 'client-web'],
        );

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertArrayNotHasKey('client-web', $parsed['services']);
        self::assertArrayNotHasKey('webserver', $parsed['services']);
        self::assertSame('http://client-app', $parsed['services']['client-app']['environment']['APP_URL']);
    }

    /**
     * DB_HOST has to follow a renamed database service, or the app can't reach it.
     */
    public function test_a_custom_database_name_renames_the_service_and_its_own_hostname_env_var(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(
            phpVersion: '8.4',
            services: ['database' => 'mysql'],
            serviceNames: ['mysql' => 'client-db'],
        );

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertArrayNotHasKey('mysql', $parsed['services']);
        self::assertArrayHasKey('client-db', $parsed['services']);
        self::assertSame('client-db', $parsed['services']['app']['environment']['DB_HOST']);
        // DB_CONNECTION is a driver identifier that happens to equal the old compose name.
        self::assertSame('mysql', $parsed['services']['app']['environment']['DB_CONNECTION']);
    }

    /**
     * A hostname embedded in a URL (MEILISEARCH_HOST, AWS_ENDPOINT) is renamed too.
     */
    public function test_a_renamed_services_hostname_is_fixed_up_even_when_embedded_in_a_url(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(
            phpVersion: '8.4',
            services: ['search' => 'meilisearch'],
            serviceNames: ['meilisearch' => 'client-search'],
        );

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertArrayNotHasKey('meilisearch', $parsed['services']);
        self::assertArrayHasKey('client-search', $parsed['services']);
        self::assertSame('http://client-search:7700', $parsed['services']['app']['environment']['MEILISEARCH_HOST']);
    }

    /**
     * A value that merely contains the old name as a substring is left alone (see
     * ComposeFileBuilder::renameHostnameReferences()).
     */
    public function test_renaming_a_service_does_not_touch_unrelated_env_values_containing_its_old_name(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(
            phpVersion: '8.4',
            services: ['cache' => 'redis'],
            serviceNames: ['redis' => 'client-cache'],
            additionalServices: [],
        );

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertSame('redis', $parsed['services']['app']['environment']['CACHE_STORE']);
        self::assertSame('client-cache', $parsed['services']['app']['environment']['REDIS_HOST']);
    }

    public function test_an_external_network_is_declared_and_attached_to_the_app_service_only(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(
            phpVersion: '8.4',
            services: ['database' => 'pgsql'],
            externalNetwork: 'shared_infra',
        );

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertSame(['name' => 'shared_infra', 'external' => true], $parsed['networks']['external']);
        self::assertContains('external', $parsed['services']['app']['networks']);
        self::assertNotContains('external', $parsed['services']['webserver']['networks']);
    }

    public function test_no_external_network_is_declared_by_default(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: ['database' => 'pgsql']);

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertArrayNotHasKey('external', $parsed['networks']);
    }
}

<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\ComposeFileBuilder;
use Ship\Services\MeilisearchService;
use Ship\Services\MySqlService;
use Ship\Services\OctaneSwooleService;
use Ship\Services\PostgresService;
use Ship\Services\RedisService;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Yaml\Yaml;

/**
 * Covers ShipConfig::$serviceNames/$externalNetwork -- letting several ship-managed projects share
 * one external Docker network (each project's own database/cache/etc. already running there)
 * without colliding on the identical compose-service-name-derived network alias every project
 * gets by default. See ShipConfig's own docblock.
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
        $config = new ShipConfig(
            phpVersion: '8.4',
            services: ['database' => 'pgsql'],
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
            services: ['database' => 'pgsql'],
            serviceNames: ['webserver' => 'client-web'],
        );

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertArrayNotHasKey('webserver', $parsed['services']);
        self::assertArrayHasKey('client-web', $parsed['services']);
        self::assertSame(['app'], $parsed['services']['client-web']['depends_on']);
        self::assertSame('http://client-web', $parsed['services']['app']['environment']['APP_URL']);
    }

    /**
     * No "webserver" exists at all once an Octane runtime is selected (see OctaneSwooleService's
     * own removes()) -- renaming a service that was never created must not resurrect it, and
     * APP_URL must still fall back to the (possibly also renamed) app service.
     */
    public function test_webserver_name_is_ignored_when_no_webserver_exists(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(
            phpVersion: '8.4',
            services: ['database' => 'pgsql', 'runtime' => 'octane-swoole'],
            serviceNames: ['app' => 'client-app', 'webserver' => 'client-web'],
        );

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertArrayNotHasKey('client-web', $parsed['services']);
        self::assertArrayNotHasKey('webserver', $parsed['services']);
        self::assertSame('http://client-app', $parsed['services']['client-app']['environment']['APP_URL']);
    }

    /**
     * The exact scenario this feature exists for beyond app/webserver: a project's own database
     * renamed away from the bare engine name ("mysql") so it can't collide with an unrelated
     * container already using that same alias on a shared external network -- DB_HOST has to
     * follow the rename, or the app itself can no longer reach its own database.
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
        // DB_CONNECTION is a Laravel driver identifier ("mysql"), not a hostname -- it happens to
        // be spelled exactly like the *old* compose name, and must stay that way after the rename.
        self::assertSame('mysql', $parsed['services']['app']['environment']['DB_CONNECTION']);
    }

    /**
     * Some services embed their hostname inside a URL (MeilisearchService's MEILISEARCH_HOST =>
     * "http://meilisearch:7700", same shape SeaweedFS/Garage use for AWS_ENDPOINT) rather than as
     * a bare value like DB_HOST -- the rename has to reach inside that string too, not just values
     * that happen to equal the old name exactly.
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
     * An unrelated env var that merely mentions a renamed service's old name as a *substring* of
     * something else entirely must not get mangled -- only the exact "://name:" shape a hostname
     * actually appears in is ever touched (see ComposeFileBuilder::renameHostnameReferences()).
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

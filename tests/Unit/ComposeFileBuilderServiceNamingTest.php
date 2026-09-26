<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\ComposeFileBuilder;
use Ship\Services\OctaneSwooleService;
use Ship\Services\PostgresService;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Yaml\Yaml;

/**
 * Covers ShipConfig::$appName/$webserverName/$externalNetwork -- letting several ship-managed
 * projects share one external Docker network (each project's own database/cache/etc. already
 * running there) without colliding on the identical "app"/"webserver" network alias every project
 * gets by default. See ShipConfig's own docblock.
 */
final class ComposeFileBuilderServiceNamingTest extends TestCase
{
    private function registry(): ServiceRegistry
    {
        return new ServiceRegistry([new PostgresService(), new OctaneSwooleService()]);
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
        $config = new ShipConfig(phpVersion: '8.4', services: ['database' => 'pgsql'], appName: 'client-app');

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertArrayNotHasKey('app', $parsed['services']);
        self::assertArrayHasKey('client-app', $parsed['services']);
        self::assertSame(['client-app'], $parsed['services']['webserver']['depends_on']);
        self::assertSame('http://webserver', $parsed['services']['client-app']['environment']['APP_URL']);
    }

    public function test_a_custom_webserver_name_renames_the_service_and_app_url_follows_it(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: ['database' => 'pgsql'], webserverName: 'client-web');

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
            appName: 'client-app',
            webserverName: 'client-web',
        );

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertArrayNotHasKey('client-web', $parsed['services']);
        self::assertArrayNotHasKey('webserver', $parsed['services']);
        self::assertSame('http://client-app', $parsed['services']['client-app']['environment']['APP_URL']);
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

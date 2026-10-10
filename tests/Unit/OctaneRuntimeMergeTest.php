<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\ComposeFileBuilder;
use Ship\Services\DuskService;
use Ship\Services\OctaneFrankenPhpService;
use Ship\Services\OctaneSwooleService;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Yaml\Yaml;

final class OctaneRuntimeMergeTest extends TestCase
{
    public function test_octane_overrides_app_command_without_erasing_its_build_config(): void
    {
        $registry = new ServiceRegistry([new OctaneSwooleService()]);
        $builder = new ComposeFileBuilder($registry);

        $config = new ShipConfig(phpVersion: '8.4', services: ['runtime' => 'octane-swoole']);

        $yaml = $builder->build($config, ShipEnvironment::Development);
        $parsed = Yaml::parse($yaml);

        // Overridden by the service. In development a shell wrapper conditionally appends
        // --watch (see OctaneSwooleServiceTest).
        self::assertSame(
            ['sh', '-c', 'if [ -d node_modules/chokidar ]; then php artisan octane:start --server=swoole --host=0.0.0.0 --port=8000 --watch; else php artisan octane:start --server=swoole --host=0.0.0.0 --port=8000; fi'],
            $parsed['services']['app']['command'],
        );

        // Preserved from baseServices() (not touched by the fragment).
        self::assertSame('ship/Dockerfile', $parsed['services']['app']['build']['dockerfile']);
        self::assertSame(['.:/var/www/html'], $parsed['services']['app']['volumes']);
    }

    public function test_octane_runtime_removes_the_webserver_service(): void
    {
        $registry = new ServiceRegistry([new OctaneSwooleService()]);
        $builder = new ComposeFileBuilder($registry);

        $withRuntime = Yaml::parse(
            $builder->build(new ShipConfig(phpVersion: '8.4', services: ['runtime' => 'octane-swoole']), ShipEnvironment::Development),
        );
        $withoutRuntime = Yaml::parse(
            $builder->build(new ShipConfig(phpVersion: '8.4', services: []), ShipEnvironment::Development),
        );

        self::assertArrayNotHasKey('webserver', $withRuntime['services']);
        self::assertArrayHasKey('webserver', $withoutRuntime['services']);
    }

    /**
     * APP_URL is only injected when Dusk is selected: Selenium needs the internal hostname.
     */
    public function test_app_url_points_at_webserver_when_no_octane_runtime_is_selected(): void
    {
        $registry = new ServiceRegistry([new DuskService()]);
        $builder = new ComposeFileBuilder($registry);

        $config = new ShipConfig(phpVersion: '8.4', services: ['testing' => 'dusk']);
        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertSame('http://webserver', $parsed['services']['app']['environment']['APP_URL']);
    }

    public function test_app_url_points_at_app_itself_when_an_octane_runtime_is_selected(): void
    {
        $registry = new ServiceRegistry([new OctaneSwooleService(), new DuskService()]);
        $builder = new ComposeFileBuilder($registry);

        $config = new ShipConfig(phpVersion: '8.4', services: ['runtime' => 'octane-swoole', 'testing' => 'dusk']);
        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertSame('http://app', $parsed['services']['app']['environment']['APP_URL']);
    }

    /**
     * Injected unconditionally, APP_URL would override the project's own value with a hostname
     * no browser can resolve.
     */
    public function test_app_url_is_not_injected_when_dusk_is_not_selected(): void
    {
        $registry = new ServiceRegistry();
        $builder = new ComposeFileBuilder($registry);

        $parsed = Yaml::parse($builder->build(new ShipConfig(phpVersion: '8.4', services: []), ShipEnvironment::Development));

        self::assertArrayNotHasKey('APP_URL', $parsed['services']['app']['environment']);
    }

    /**
     * The Dusk selection doesn't vary by environment, so the injection is limited to development
     * explicitly.
     */
    public function test_app_url_is_not_injected_in_production_even_when_dusk_is_selected(): void
    {
        $registry = new ServiceRegistry([new DuskService()]);
        $builder = new ComposeFileBuilder($registry);

        $config = new ShipConfig(phpVersion: '8.4', services: ['testing' => 'dusk']);
        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Production));

        self::assertArrayNotHasKey('APP_URL', $parsed['services']['app']['environment']);
    }

    /**
     * Both fragments landing on "app" default "networks" to ["ship"]; the merge must not produce
     * ["ship", "ship"], which Compose rejects.
     */
    public function test_networks_is_not_duplicated_when_a_second_fragment_merges_into_app(): void
    {
        $registry = new ServiceRegistry([new OctaneSwooleService()]);
        $builder = new ComposeFileBuilder($registry);

        $config = new ShipConfig(phpVersion: '8.4', services: ['runtime' => 'octane-swoole']);
        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertSame(['ship'], $parsed['services']['app']['networks']);
    }

    public function test_frankenphp_overrides_only_the_dockerfile_path_not_the_rest_of_build(): void
    {
        $registry = new ServiceRegistry([new OctaneFrankenPhpService()]);
        $builder = new ComposeFileBuilder($registry);

        $config = new ShipConfig(phpVersion: '8.4', services: ['runtime' => 'octane-frankenphp']);
        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        $build = $parsed['services']['app']['build'];

        // Overridden by OctaneFrankenPhpService.
        self::assertSame('ship/Dockerfile.frankenphp', $build['dockerfile']);

        // Preserved from baseServices() by the shallow `build` merge.
        self::assertSame('.', $build['context']);
        self::assertSame('dev', $build['target']);
        self::assertSame('8.4', $build['args']['PHP_VERSION']);
    }
}

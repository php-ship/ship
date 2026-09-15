<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\ComposeFileBuilder;
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

        // Overridden by the service (scalar key: replaced outright).
        self::assertSame(
            ['php', 'artisan', 'octane:start', '--server=swoole', '--host=0.0.0.0', '--port=8000'],
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

    public function test_app_url_points_at_webserver_when_no_octane_runtime_is_selected(): void
    {
        $registry = new ServiceRegistry();
        $builder = new ComposeFileBuilder($registry);

        $parsed = Yaml::parse($builder->build(new ShipConfig(phpVersion: '8.4', services: []), ShipEnvironment::Development));

        self::assertSame('http://webserver', $parsed['services']['app']['environment']['APP_URL']);
    }

    public function test_app_url_points_at_app_itself_when_an_octane_runtime_is_selected(): void
    {
        $registry = new ServiceRegistry([new OctaneSwooleService()]);
        $builder = new ComposeFileBuilder($registry);

        $config = new ShipConfig(phpVersion: '8.4', services: ['runtime' => 'octane-swoole']);
        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertSame('http://app', $parsed['services']['app']['environment']['APP_URL']);
    }

    /**
     * Regression test for a real bug reported from live use: baseServices() sets "app".networks to
     * ["ship"], then applyService() defaults every fragment missing its own networks key to ["ship"]
     * too (see its own docblock) -- Octane's fragment for "app" doesn't set one, so
     * mergeServiceFragment() used to concatenate the two into ["ship", "ship"], which Compose's
     * schema rejects outright ("app" is the only service two fragments both land on without either
     * setting networks explicitly, which is why only it showed the duplicate).
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

        // Preserved from baseServices() — this is exactly what the old
        // wholesale-replace merge would have wiped out.
        self::assertSame('.', $build['context']);
        self::assertSame('dev', $build['target']);
        self::assertSame('8.4', $build['args']['PHP_VERSION']);
    }
}

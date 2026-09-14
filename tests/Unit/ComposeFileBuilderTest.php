<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\ComposeFileBuilder;
use Ship\Services\PostgresService;
use Ship\Services\RedisService;
use Ship\Services\ReverbService;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Yaml\Yaml;

final class ComposeFileBuilderTest extends TestCase
{
    private function registry(): ServiceRegistry
    {
        return new ServiceRegistry([new PostgresService(), new RedisService()]);
    }

    public function test_it_includes_selected_services_in_the_compose_output(): void
    {
        $builder = new ComposeFileBuilder($this->registry());

        $config = new ShipConfig(phpVersion: '8.4', services: ['database' => 'pgsql']);

        $yaml = $builder->build($config, ShipEnvironment::Development);
        $parsed = Yaml::parse($yaml);

        self::assertArrayHasKey('pgsql', $parsed['services']);
        self::assertSame('pgsql', $parsed['services']['app']['environment']['DB_CONNECTION']);
    }

    public function test_it_merges_environment_variables_from_every_selected_service(): void
    {
        $builder = new ComposeFileBuilder($this->registry());

        $config = new ShipConfig(
            phpVersion: '8.4',
            services: ['database' => 'pgsql', 'cache' => 'redis'],
        );

        $yaml = $builder->build($config, ShipEnvironment::Development);
        $parsed = Yaml::parse($yaml);

        self::assertSame('pgsql', $parsed['services']['app']['environment']['DB_CONNECTION']);
        self::assertSame('redis', $parsed['services']['app']['environment']['REDIS_HOST']);
    }

    public function test_it_omits_dev_only_volumes_in_production(): void
    {
        $builder = new ComposeFileBuilder($this->registry());

        $config = new ShipConfig(phpVersion: '8.4', services: ['database' => 'pgsql']);

        $yaml = $builder->build($config, ShipEnvironment::Production);
        $parsed = Yaml::parse($yaml);

        self::assertSame([], $parsed['services']['pgsql']['volumes']);
        self::assertArrayNotHasKey('volumes', $parsed);
    }

    public function test_top_level_volumes_reflect_every_selected_services_named_volumes(): void
    {
        $builder = new ComposeFileBuilder($this->registry());

        $config = new ShipConfig(
            phpVersion: '8.4',
            services: ['database' => 'pgsql', 'cache' => 'redis'],
        );

        $yaml = $builder->build($config, ShipEnvironment::Development);
        $parsed = Yaml::parse($yaml);

        self::assertArrayHasKey('ship-pgsql-data', $parsed['volumes']);
        self::assertArrayHasKey('ship-redis-data', $parsed['volumes']);
    }

    public function test_webserver_builds_from_the_unified_dockerfile_with_environment_specific_target(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: []);

        $dev = Yaml::parse($builder->build($config, ShipEnvironment::Development));
        $prod = Yaml::parse($builder->build($config, ShipEnvironment::Production));

        self::assertSame('ship/Dockerfile', $dev['services']['webserver']['build']['dockerfile']);
        self::assertSame('dev-nginx', $dev['services']['webserver']['build']['target']);
        self::assertSame('ship/Dockerfile', $prod['services']['webserver']['build']['dockerfile']);
        self::assertSame('prod-nginx', $prod['services']['webserver']['build']['target']);
    }

    public function test_app_publishes_the_vite_dev_server_port_only_in_development(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: []);

        $dev = Yaml::parse($builder->build($config, ShipEnvironment::Development));
        $prod = Yaml::parse($builder->build($config, ShipEnvironment::Production));

        self::assertContains('${VITE_PORT:-5173}:5173', $dev['services']['app']['ports']);
        self::assertSame([], $prod['services']['app']['ports']);
    }

    public function test_reverb_gets_its_own_service_with_the_projects_php_version_backfilled(): void
    {
        $registry = new ServiceRegistry([new ReverbService()]);
        $builder = new ComposeFileBuilder($registry);

        $config = new ShipConfig(phpVersion: '8.3', services: ['broadcasting' => 'reverb']);
        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertArrayHasKey('reverb', $parsed['services']);
        // "app" already sets this explicitly in baseServices() -- what
        // matters here is that "reverb" (which never does) picks up the
        // same PHP version rather than the Dockerfile's own ARG default.
        self::assertSame('8.3', $parsed['services']['reverb']['build']['args']['PHP_VERSION']);
        self::assertSame('8.3', $parsed['services']['app']['build']['args']['PHP_VERSION']);
    }

    public function test_php_version_backfill_does_not_touch_the_nginx_webserver_target(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.3', services: []);

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertArrayNotHasKey('args', $parsed['services']['webserver']['build']);
    }

    /**
     * Regression test for a bug only a real `docker compose config` catches, not
     * `Yaml::parse()`: an empty PHP array dumps as YAML `{}` by default, since PHP
     * has no way to distinguish an empty list from an empty map -- but Compose's
     * schema requires `ports` to be a sequence, so `{}` fails validation outright.
     * Asserting on the raw string (not the parsed-back array, which can't tell
     * `{}` and `[]` apart either) is the only way this test can actually fail.
     */
    public function test_empty_ports_dump_as_a_yaml_sequence_not_a_mapping(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: []);

        $yaml = $builder->build($config, ShipEnvironment::Production);

        self::assertStringContainsString("ports: []\n", $yaml);
        self::assertStringNotContainsString('ports: {}', $yaml);
    }

    public function test_every_service_gets_a_restart_policy_so_a_crash_recovers_on_its_own(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(
            phpVersion: '8.4',
            services: ['database' => 'pgsql', 'cache' => 'redis'],
        );

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Production));

        foreach (array_keys($parsed['services']) as $name) {
            self::assertSame('unless-stopped', $parsed['services'][$name]['restart'], "service \"{$name}\"");
        }
    }

    public function test_app_and_webserver_load_an_optional_env_file_for_real_production_secrets(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: []);

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Production));

        self::assertSame([['path' => '.env', 'required' => false]], $parsed['services']['app']['env_file']);
        self::assertSame([['path' => '.env', 'required' => false]], $parsed['services']['webserver']['env_file']);
    }
}

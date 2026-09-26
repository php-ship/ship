<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\ComposeFileBuilder;
use Ship\Services\MySqlService;
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

        self::assertContains('${VITE_PORT:-5173}:${VITE_PORT:-5173}', $dev['services']['app']['ports']);
        self::assertSame([], $prod['services']['app']['ports']);
    }

    /**
     * Regression test for a real bug found from live use: the container side used to be a fixed
     * "5173" regardless of $VITE_PORT, so a project whose own vite.config.js listens on a
     * different port (its own VITE_PORT) never actually got it published -- HMR just silently
     * never connected. Both sides now follow the same variable -- asserted as an exact string, not
     * just "the two halves match," since the halves themselves both contain a ":" as part of
     * `${VAR:-default}` syntax, which would make a naive split on ":" pick the wrong one.
     */
    public function test_the_vite_dev_server_ports_container_side_follows_the_same_variable_as_the_host_side(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: []);

        $dev = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertSame('${VITE_PORT:-5173}:${VITE_PORT:-5173}', $dev['services']['app']['ports'][0]);
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

    public function test_node_version_is_configurable_and_backfilled_the_same_way_as_php_version(): void
    {
        $registry = new ServiceRegistry([new ReverbService()]);
        $builder = new ComposeFileBuilder($registry);

        $config = new ShipConfig(
            phpVersion: '8.4',
            services: ['broadcasting' => 'reverb'],
            nodeVersion: '20',
        );
        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertSame('20', $parsed['services']['app']['build']['args']['NODE_VERSION']);
        self::assertSame('20', $parsed['services']['reverb']['build']['args']['NODE_VERSION']);
    }

    public function test_php_extensions_are_space_joined_into_a_single_build_arg_and_backfilled_onto_reverb(): void
    {
        $registry = new ServiceRegistry([new ReverbService()]);
        $builder = new ComposeFileBuilder($registry);

        $config = new ShipConfig(
            phpVersion: '8.4',
            services: ['broadcasting' => 'reverb'],
            phpExtensions: ['gd', 'zip', 'bcmath'],
        );
        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertSame('gd zip bcmath', $parsed['services']['app']['build']['args']['PHP_EXTENSIONS']);
        self::assertSame('gd zip bcmath', $parsed['services']['reverb']['build']['args']['PHP_EXTENSIONS']);
    }

    public function test_php_extensions_defaults_to_an_empty_build_arg_when_not_specified(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: []);

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertSame('', $parsed['services']['app']['build']['args']['PHP_EXTENSIONS']);
    }

    public function test_node_version_defaults_to_24_when_not_specified(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: []);

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertSame('24', $parsed['services']['app']['build']['args']['NODE_VERSION']);
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

    public function test_an_additional_named_instance_gets_its_own_compose_service_and_prefixed_env_vars(): void
    {
        $registry = new ServiceRegistry([new PostgresService(), new MySqlService()]);
        $builder = new ComposeFileBuilder($registry);
        $config = new ShipConfig(
            phpVersion: '8.4',
            services: ['database' => 'pgsql'],
            additionalServices: [['group' => 'database', 'service' => 'mysql', 'name' => 'analytics']],
        );

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        // Default instance untouched: same compose service name and env var names as always,
        // not overwritten by the additional instance sharing the same "DB_" variable family.
        self::assertArrayHasKey('pgsql', $parsed['services']);
        self::assertSame('pgsql', $parsed['services']['app']['environment']['DB_HOST']);
        self::assertSame('pgsql', $parsed['services']['app']['environment']['DB_CONNECTION']);

        // Additional instance: its own compose service, its own prefixed env vars.
        self::assertArrayHasKey('mysql-analytics', $parsed['services']);
        self::assertSame('mysql-analytics', $parsed['services']['app']['environment']['ANALYTICS_DB_HOST']);
        self::assertSame('mysql', $parsed['services']['app']['environment']['ANALYTICS_DB_CONNECTION']);
    }

    public function test_two_additional_instances_of_the_same_service_get_distinct_compose_services_and_volumes(): void
    {
        $registry = new ServiceRegistry([new RedisService()]);
        $builder = new ComposeFileBuilder($registry);
        $config = new ShipConfig(
            phpVersion: '8.4',
            services: ['cache' => 'redis'],
            additionalServices: [['group' => 'cache', 'service' => 'redis', 'name' => 'queue']],
        );

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertArrayHasKey('redis', $parsed['services']);
        self::assertArrayHasKey('redis-queue', $parsed['services']);
        self::assertArrayHasKey('ship-redis-data', $parsed['volumes']);
        self::assertArrayHasKey('ship-redis-queue-data', $parsed['volumes']);

        // Only the default instance sets the app-wide default store -- a named instance adds a
        // second reachable Redis, it doesn't change what the app uses by default.
        self::assertSame('redis', $parsed['services']['app']['environment']['CACHE_STORE']);
        self::assertArrayNotHasKey('QUEUE_CACHE_STORE', $parsed['services']['app']['environment']);
        self::assertSame('redis-queue', $parsed['services']['app']['environment']['QUEUE_REDIS_HOST']);
    }
}

<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\ComposeFileBuilder;
use Ship\Services\MailpitService;
use Ship\Services\MySqlService;
use Ship\Services\OctaneFrankenPhpService;
use Ship\Services\OctaneSwooleService;
use Ship\Services\PostgresService;
use Ship\Services\RedisService;
use Ship\Services\ReverbService;
use Ship\Services\ServiceRegistry;
use Ship\Services\SiloService;
use Symfony\Component\Yaml\Yaml;

final class ComposeFileBuilderTest extends TestCase
{
    private function registry(): ServiceRegistry
    {
        return new ServiceRegistry([new PostgresService(), new RedisService()]);
    }

    /**
     * Named volumes and network aliases are keyed off the top-level `name:`; without it Compose
     * uses the project directory's basename.
     */
    public function test_a_given_project_name_becomes_the_top_level_name(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: []);

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development, projectName: 'acme-api'));

        self::assertSame('acme-api', $parsed['name']);
    }

    public function test_no_project_name_means_no_top_level_name_key(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: []);

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertArrayNotHasKey('name', $parsed);
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

    /**
     * In production Mailpit's MAIL_HOST would override the real mail config (`environment:` beats
     * `env_file:`), so it contributes nothing there.
     */
    public function test_dev_only_tooling_like_mailpit_is_entirely_absent_in_production(): void
    {
        $builder = new ComposeFileBuilder(new ServiceRegistry([new MailpitService()]));
        $config = new ShipConfig(phpVersion: '8.4', services: ['mail' => 'mailpit']);

        $yaml = $builder->build($config, ShipEnvironment::Production);
        $parsed = Yaml::parse($yaml);

        self::assertArrayNotHasKey('mailpit', $parsed['services']);
        self::assertArrayNotHasKey('MAIL_HOST', $parsed['services']['app']['environment']);
    }

    /**
     * A production database must keep its data across a restart or redeploy.
     */
    public function test_a_stateful_services_data_volume_persists_in_production_too(): void
    {
        $builder = new ComposeFileBuilder($this->registry());

        $config = new ShipConfig(phpVersion: '8.4', services: ['database' => 'pgsql']);

        $yaml = $builder->build($config, ShipEnvironment::Production);
        $parsed = Yaml::parse($yaml);

        self::assertSame(['ship-pgsql-data:/var/lib/postgresql'], $parsed['services']['pgsql']['volumes']);
        self::assertArrayHasKey('ship-pgsql-data', $parsed['volumes']);
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

        self::assertContains('127.0.0.1:${VITE_PORT:-5173}:${VITE_PORT:-5173}', $dev['services']['app']['ports']);
        self::assertSame([], $prod['services']['app']['ports']);
    }

    /**
     * Xdebug (dev image only) needs a route back to the IDE on the host.
     */
    public function test_app_gets_a_host_docker_internal_route_only_in_development(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: []);

        $dev = Yaml::parse($builder->build($config, ShipEnvironment::Development));
        $prod = Yaml::parse($builder->build($config, ShipEnvironment::Production));

        self::assertContains('host.docker.internal:host-gateway', $dev['services']['app']['extra_hosts']);
        self::assertSame([], $prod['services']['app']['extra_hosts']);
    }

    /**
     * Both sides follow VITE_PORT, so a vite.config.js listening on a non-default port still gets
     * it published. Asserted as an exact string, since `${VAR:-default}` itself contains a ":".
     */
    public function test_the_vite_dev_server_ports_container_side_follows_the_same_variable_as_the_host_side(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: []);

        $dev = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertSame('127.0.0.1:${VITE_PORT:-5173}:${VITE_PORT:-5173}', $dev['services']['app']['ports'][0]);
    }

    /**
     * Behind a reverse proxy nothing should be published to the host: a Docker-published port
     * bypasses host firewalls.
     */
    public function test_publish_ports_false_strips_every_published_port_in_production(): void
    {
        $builder = new ComposeFileBuilder(new ServiceRegistry([new ReverbService()]));
        $config = new ShipConfig(phpVersion: '8.4', services: ['broadcasting' => 'reverb'], publishPorts: false);

        $prod = Yaml::parse($builder->build($config, ShipEnvironment::Production));

        foreach ($prod['services'] as $name => $service) {
            self::assertSame([], $service['ports'], "\"{$name}\" still publishes a port.");
        }
    }

    public function test_publish_ports_false_never_touches_development(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: [], publishPorts: false);

        $dev = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertNotSame([], $dev['services']['app']['ports']);
        self::assertNotSame([], $dev['services']['webserver']['ports']);
    }

    public function test_production_does_not_publish_the_webserver_port_by_default(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: []);

        $prod = Yaml::parse($builder->build($config, ShipEnvironment::Production));

        self::assertSame([], $prod['services']['webserver']['ports']);
    }

    public function test_production_publishes_the_webserver_port_when_explicitly_enabled(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: [], publishPorts: true);

        $prod = Yaml::parse($builder->build($config, ShipEnvironment::Production));

        self::assertSame(['${APP_PORT:-80}:80'], $prod['services']['webserver']['ports']);
    }

    public function test_production_does_not_publish_the_silo_console_by_default(): void
    {
        $builder = new ComposeFileBuilder(new ServiceRegistry([new SiloService()]));
        $config = new ShipConfig(phpVersion: '8.4', services: ['storage' => 'silo']);

        $prod = Yaml::parse($builder->build($config, ShipEnvironment::Production));

        self::assertSame([], $prod['services']['silo']['ports']);
    }

    /**
     * A bare `HOST:CONTAINER` mapping binds every interface, so development binds 127.0.0.1.
     * Production mappings are left alone.
     */
    public function test_the_webserver_port_binds_loopback_only_in_development(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: []);

        $dev = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertSame(['127.0.0.1:${APP_PORT:-80}:80'], $dev['services']['webserver']['ports']);
    }

    /**
     * Each process is a separate service with the app's build config, environment and networks.
     */
    public function test_a_process_becomes_a_service_built_like_the_app_with_its_environment_and_networks(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(
            phpVersion: '8.4',
            services: ['database' => 'pgsql'],
            externalNetwork: 'shared_infra',
            processes: ['horizon' => 'php artisan horizon', 'scheduler' => 'php artisan schedule:work'],
        );

        $prod = Yaml::parse($builder->build($config, ShipEnvironment::Production));

        self::assertSame(['sh', '-c', 'php artisan horizon'], $prod['services']['horizon']['command']);
        self::assertSame(['sh', '-c', 'php artisan schedule:work'], $prod['services']['scheduler']['command']);
        self::assertSame($prod['services']['app']['build'], $prod['services']['horizon']['build']);
        self::assertSame($prod['services']['app']['networks'], $prod['services']['horizon']['networks']);
        self::assertContains('external', $prod['services']['horizon']['networks']);
        self::assertSame('pgsql', $prod['services']['horizon']['environment']['DB_HOST']);
        self::assertSame('unless-stopped', $prod['services']['horizon']['restart']);
        self::assertArrayNotHasKey('ports', $prod['services']['horizon']);
    }

    /**
     * Compose interpolates a bare $VAR itself, so "$" is escaped to "$$" to reach the container's
     * shell.
     */
    public function test_a_dollar_sign_in_a_process_command_survives_composes_own_interpolation(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(
            phpVersion: '8.4',
            services: [],
            processes: ['worker' => 'php artisan queue:work --queue=$QUEUE'],
        );

        $prod = Yaml::parse($builder->build($config, ShipEnvironment::Production));

        self::assertSame(['sh', '-c', 'php artisan queue:work --queue=$$QUEUE'], $prod['services']['worker']['command']);
    }

    /**
     * Compose's default 10s grace period SIGKILLs Horizon or a queue worker mid-job.
     */
    public function test_a_process_gets_a_long_stop_grace_period_and_runs_as_www_data(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: [], processes: ['horizon' => 'php artisan horizon']);

        $prod = Yaml::parse($builder->build($config, ShipEnvironment::Production));

        self::assertSame('60s', $prod['services']['horizon']['stop_grace_period']);
        self::assertSame('www-data', $prod['services']['horizon']['environment']['SHIP_RUN_AS']);
    }

    public function test_processes_are_production_only(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: [], processes: ['horizon' => 'php artisan horizon']);

        $dev = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertArrayNotHasKey('horizon', $dev['services']);
    }

    public function test_a_process_named_like_an_existing_service_is_rejected(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: [], processes: ['webserver' => 'php artisan horizon']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('collides with a service ship already generates');

        $builder->build($config, ShipEnvironment::Production);
    }

    public function test_a_process_name_that_is_not_a_valid_service_name_is_rejected(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: [], processes: ['My Worker' => 'php artisan queue:work']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a valid name');

        $builder->build($config, ShipEnvironment::Production);
    }

    /**
     * hostUser produces the build args, the SHIP_HOST_USER env var the entrypoint reads, and the
     * x-ship marker the exec commands read, all from one generation.
     */
    public function test_a_host_user_becomes_build_args_an_env_var_and_a_marker(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: [], serviceNames: ['app' => 'admin-app']);

        $dev = Yaml::parse($builder->build($config, ShipEnvironment::Development, false, ['uid' => 1000, 'gid' => 1001]));

        self::assertSame('1000', $dev['services']['admin-app']['build']['args']['HOST_UID']);
        self::assertSame('1001', $dev['services']['admin-app']['build']['args']['HOST_GID']);
        self::assertSame('1000:1001', $dev['services']['admin-app']['environment']['SHIP_HOST_USER']);
        self::assertSame(['hostUser' => '1000:1001', 'appService' => 'admin-app'], $dev['x-ship']);
    }

    public function test_without_a_host_user_nothing_about_it_is_generated(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: []);

        $dev = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertArrayNotHasKey('HOST_UID', $dev['services']['app']['build']['args']);
        self::assertArrayNotHasKey('SHIP_HOST_USER', $dev['services']['app']['environment']);
        self::assertArrayNotHasKey('x-ship', $dev);
    }

    /**
     * ReverbService can't see $hostUser, so ComposeFileBuilder gives Reverb SHIP_HOST_USER too;
     * without it Reverb would run as root in dev.
     */
    public function test_reverb_gets_ship_host_user_too_when_a_host_user_is_set(): void
    {
        $registry = new ServiceRegistry([new ReverbService()]);
        $builder = new ComposeFileBuilder($registry);
        $config = new ShipConfig(phpVersion: '8.4', services: ['broadcasting' => 'reverb']);

        $dev = Yaml::parse($builder->build($config, ShipEnvironment::Development, false, ['uid' => 1000, 'gid' => 1001]));

        self::assertSame('1000:1001', $dev['services']['reverb']['environment']['SHIP_HOST_USER']);
    }

    /**
     * Reverb shares "app"'s tree and entrypoint, so it is told to wait for "app"'s composer
     * install rather than run a second one. Dev only.
     */
    public function test_reverb_is_told_to_skip_its_own_composer_install_in_dev(): void
    {
        $registry = new ServiceRegistry([new ReverbService()]);
        $builder = new ComposeFileBuilder($registry);
        $config = new ShipConfig(phpVersion: '8.4', services: ['broadcasting' => 'reverb']);

        $dev = Yaml::parse($builder->build($config, ShipEnvironment::Development));
        $prod = Yaml::parse($builder->build($config, ShipEnvironment::Production));

        self::assertSame('1', $dev['services']['reverb']['environment']['SHIP_DEV_SKIP_INSTALL']);
        self::assertArrayNotHasKey('SHIP_DEV_SKIP_INSTALL', $prod['services']['reverb']['environment']);
    }

    /**
     * Under Mutagen `ship up` installs dependencies once the sync has finished, so "app" must not
     * race it. With a plain bind mount "app" still installs for itself.
     */
    public function test_app_is_told_to_skip_its_own_composer_install_only_when_mutagen_is_active(): void
    {
        $builder = new ComposeFileBuilder(new ServiceRegistry());
        $config = new ShipConfig(phpVersion: '8.4', services: []);

        $withMutagen = Yaml::parse($builder->build($config, ShipEnvironment::Development, mutagenSync: true));
        $withoutMutagen = Yaml::parse($builder->build($config, ShipEnvironment::Development, mutagenSync: false));
        $prod = Yaml::parse($builder->build($config, ShipEnvironment::Production));

        self::assertSame('1', $withMutagen['services']['app']['environment']['SHIP_DEV_SKIP_INSTALL']);
        self::assertArrayNotHasKey('SHIP_DEV_SKIP_INSTALL', $withoutMutagen['services']['app']['environment']);
        self::assertArrayNotHasKey('SHIP_DEV_SKIP_INSTALL', $prod['services']['app']['environment']);
    }

    public function test_reverb_gets_its_own_service_with_the_projects_php_version_backfilled(): void
    {
        $registry = new ServiceRegistry([new ReverbService()]);
        $builder = new ComposeFileBuilder($registry);

        $config = new ShipConfig(phpVersion: '8.3', services: ['broadcasting' => 'reverb']);
        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertArrayHasKey('reverb', $parsed['services']);
        // "reverb" never sets this itself; it must get the configured version, not the
        // Dockerfile's ARG default.
        self::assertSame('8.3', $parsed['services']['reverb']['build']['args']['PHP_VERSION']);
        self::assertSame('8.3', $parsed['services']['app']['build']['args']['PHP_VERSION']);
    }

    /**
     * Reverb needs "app"'s injected environment to reach the same services, SHIP_RUN_AS so it
     * doesn't run as root in production, and matching build args so both share one image.
     */
    public function test_reverb_gets_apps_env_vars_ship_run_as_and_matching_build_args(): void
    {
        $registry = new ServiceRegistry([new MySqlService(), new OctaneSwooleService(), new ReverbService()]);
        $builder = new ComposeFileBuilder($registry);

        $config = new ShipConfig(
            phpVersion: '8.4',
            services: ['database' => 'mysql', 'runtime' => 'octane-swoole', 'broadcasting' => 'reverb'],
        );
        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Production));

        self::assertSame('mysql', $parsed['services']['reverb']['environment']['DB_CONNECTION']);
        self::assertSame('www-data', $parsed['services']['reverb']['environment']['SHIP_RUN_AS']);
        self::assertSame(
            $parsed['services']['app']['build']['args']['OCTANE_RUNTIME'],
            $parsed['services']['reverb']['build']['args']['OCTANE_RUNTIME'],
        );
    }

    /**
     * Reverb must use the synced named volume under Mutagen, not the unsynced bind mount.
     */
    public function test_reverb_uses_the_synced_named_volume_when_mutagen_is_active(): void
    {
        $registry = new ServiceRegistry([new ReverbService()]);
        $builder = new ComposeFileBuilder($registry);

        $config = new ShipConfig(phpVersion: '8.4', services: ['broadcasting' => 'reverb']);
        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development, mutagenSync: true));

        self::assertSame(['ship-app-sync:/var/www/html'], $parsed['services']['reverb']['volumes']);
        self::assertSame($parsed['services']['app']['volumes'], $parsed['services']['reverb']['volumes']);
    }

    public function test_reverb_keeps_the_bind_mount_when_mutagen_is_not_active(): void
    {
        $registry = new ServiceRegistry([new ReverbService()]);
        $builder = new ComposeFileBuilder($registry);

        $config = new ShipConfig(phpVersion: '8.4', services: ['broadcasting' => 'reverb']);
        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development, mutagenSync: false));

        self::assertSame(['.:/var/www/html'], $parsed['services']['reverb']['volumes']);
    }

    public function test_reverb_keeps_its_own_dockerfile_even_when_app_overrides_its_own(): void
    {
        $registry = new ServiceRegistry([new OctaneFrankenPhpService(), new ReverbService()]);
        $builder = new ComposeFileBuilder($registry);

        $config = new ShipConfig(phpVersion: '8.4', services: ['runtime' => 'octane-frankenphp', 'broadcasting' => 'reverb']);
        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Production));

        self::assertSame('ship/Dockerfile.frankenphp', $parsed['services']['app']['build']['dockerfile']);
        self::assertSame('ship/Dockerfile', $parsed['services']['reverb']['build']['dockerfile']);
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
     * An empty PHP array dumps as YAML `{}` by default, which Compose's schema rejects for
     * `ports`. Asserted on the raw string, since the parsed array can't tell `{}` from `[]`.
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

    public function test_app_loads_an_optional_env_file_for_real_production_secrets(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: []);

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Production));

        self::assertSame([['path' => '.env', 'required' => false]], $parsed['services']['app']['env_file']);
    }

    /**
     * nginx's config is static, so "webserver" has no use for the app's secrets.
     */
    public function test_webserver_does_not_load_the_apps_env_file(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: []);

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Production));

        self::assertArrayNotHasKey('env_file', $parsed['services']['webserver']);
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

        // The default instance keeps its compose service name and unprefixed env vars.
        self::assertArrayHasKey('pgsql', $parsed['services']);
        self::assertSame('pgsql', $parsed['services']['app']['environment']['DB_HOST']);
        self::assertSame('pgsql', $parsed['services']['app']['environment']['DB_CONNECTION']);

        // The additional instance gets its own compose service and prefixed env vars.
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

        // Only the default instance sets the app-wide default store.
        self::assertSame('redis', $parsed['services']['app']['environment']['CACHE_STORE']);
        self::assertArrayNotHasKey('QUEUE_CACHE_STORE', $parsed['services']['app']['environment']);
        self::assertSame('redis-queue', $parsed['services']['app']['environment']['QUEUE_REDIS_HOST']);
    }

    public function test_renaming_two_services_to_the_same_name_is_rejected(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(
            phpVersion: '8.4',
            services: [],
            serviceNames: ['app' => 'shared', 'webserver' => 'shared'],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('both end up named "shared"');

        $builder->build($config, ShipEnvironment::Production);
    }

    public function test_renaming_app_to_an_already_selected_services_own_name_is_rejected(): void
    {
        $registry = new ServiceRegistry([new MySqlService()]);
        $builder = new ComposeFileBuilder($registry);
        $config = new ShipConfig(
            phpVersion: '8.4',
            services: ['database' => 'mysql'],
            serviceNames: ['app' => 'mysql'],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('both end up named "mysql"');

        $builder->build($config, ShipEnvironment::Production);
    }

    public function test_two_additional_services_sharing_a_name_is_rejected(): void
    {
        $registry = new ServiceRegistry([new MySqlService(), new PostgresService()]);
        $builder = new ComposeFileBuilder($registry);
        $config = new ShipConfig(
            phpVersion: '8.4',
            services: [],
            additionalServices: [
                ['group' => 'database', 'service' => 'mysql', 'name' => 'dup'],
                ['group' => 'database', 'service' => 'pgsql', 'name' => 'dup'],
            ],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"dup" is used more than once');

        $builder->build($config, ShipEnvironment::Production);
    }

    public function test_an_additional_instance_of_a_service_without_named_instance_support_is_rejected(): void
    {
        $registry = new ServiceRegistry([new ReverbService()]);
        $builder = new ComposeFileBuilder($registry);
        $config = new ShipConfig(
            phpVersion: '8.4',
            services: [],
            additionalServices: [['group' => 'broadcasting', 'service' => 'reverb', 'name' => 'second']],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"reverb" doesn\'t support more than one instance');

        $builder->build($config, ShipEnvironment::Production);
    }

    /**
     * A service's fragment can depend on ship.json (ConfigAwareService): siloImage reaches the
     * default Silo instance and every named one.
     */
    public function test_the_configured_silo_image_applies_to_every_silo_instance(): void
    {
        $builder = new ComposeFileBuilder(new ServiceRegistry([new SiloService()]));
        $config = new ShipConfig(
            phpVersion: '8.4',
            services: ['storage' => 'silo'],
            additionalServices: [['group' => 'storage', 'service' => 'silo', 'name' => 'archive']],
            siloImage: ShipConfig::SILO_IMAGE_DISTROLESS,
        );

        /** @var array{services: array<string, array{image?: string}>} $parsed */
        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Production));

        self::assertStringEndsWith('-distroless', $parsed['services']['silo']['image'] ?? '');
        self::assertStringEndsWith('-distroless', $parsed['services']['silo-archive']['image'] ?? '');
    }
}

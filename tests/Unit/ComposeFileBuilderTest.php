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
     * Without a top-level name:, Compose derives the project name from --project-directory's own
     * basename instead -- every named volume and the default network alias are keyed off it, so
     * two differently-pathed checkouts sharing a basename (or the same project checked out under
     * two different `ship release` tag directories) would otherwise share both.
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
     * MailpitService itself has to check the environment -- without it, production would get a
     * Mailpit container too, and its MAIL_HOST would unconditionally override whatever real mail
     * config .env.production sets (environment: always wins over env_file:, see
     * OPTIONAL_ENV_FILE's own docblock), silently capturing real mail -- password reset links
     * included -- into an unauthenticated web UI instead of ever sending it.
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
     * A database losing every row on the next `docker compose restart`/redeploy would be a real
     * data-loss bug, not a dev/prod distinction worth making -- unlike "app"'s own bind mount
     * (dev only; see baseServices()), which this is not testing.
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
     * Requested from real use: Xdebug (installed unconditionally in dev, see
     * stubs/docker/php/Dockerfile) needs a route back to the IDE listening on the host, and
     * "host.docker.internal" isn't a real DNS name Docker resolves without this. Dev only --
     * Xdebug isn't installed in production, so nothing there needs it.
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
     * The container side has to follow the same $VITE_PORT the host side does, not a fixed
     * "5173" -- otherwise a project whose own vite.config.js listens on a different port (its own
     * VITE_PORT) never gets it published, and HMR silently never connects. Asserted as an exact
     * string, not just "the two halves match," since the halves themselves both contain a ":" as
     * part of `${VAR:-default}` syntax, which would make a naive split on ":" pick the wrong one.
     */
    public function test_the_vite_dev_server_ports_container_side_follows_the_same_variable_as_the_host_side(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: []);

        $dev = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertSame('127.0.0.1:${VITE_PORT:-5173}:${VITE_PORT:-5173}', $dev['services']['app']['ports'][0]);
    }

    /**
     * Requested from real use: a deployment with a reverse proxy (Caddy, Traefik, ...) reaching the
     * containers over a shared network doesn't want anything published to the host at all -- a
     * Docker-published port bypasses host firewalls like ufw, exposing the app directly on the
     * server's public IP and skipping the proxy's TLS and headers entirely.
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
     * A bare `HOST:CONTAINER` mapping binds every interface (0.0.0.0), reachable from anything
     * else on the same network -- or the open internet, on a cloud dev box with no firewall --
     * for a port that only ever needs to reach the developer's own machine, so development binds
     * 127.0.0.1 explicitly instead. Production is untouched: a published production port often
     * does need to be reachable from outside.
     */
    public function test_the_webserver_port_binds_loopback_only_in_development(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: []);

        $dev = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertSame(['127.0.0.1:${APP_PORT:-80}:80'], $dev['services']['webserver']['ports']);
    }

    /**
     * Requested from real use: a project runs Horizon and the scheduler alongside the app. Separate
     * services from the same build config (not a second process supervised inside the app
     * container): independently restartable, visible in `docker compose ps`, and stopped by their own
     * SIGTERM. They need the app's environment and networks to reach the same database.
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
     * Compose interpolates a bare $VAR in a command string itself (against the host's own
     * environment, not the container's), so a process command referencing a real shell variable
     * needs it escaped through as a literal $$, or Compose would silently blank it out before the
     * container's shell ever runs it.
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
     * Requested from real use on WSL2: everything the dev container writes (vendor/, public/build,
     * storage/) ends up root-owned on the host. hostUser builds the dev image with the host's own
     * UID/GID instead -- the entrypoint reads SHIP_HOST_USER, and the `ship exec`-family commands
     * read the x-ship marker to add --user, so both halves have to come out of the same generation.
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
     * SHIP_HOST_USER has to reach Reverb too, not just "app" -- ReverbService's own
     * composeFragment() has no access to $hostUser on its own, so ComposeFileBuilder backfills
     * it. The dev entrypoint drops anything that's "its own long-lived program" (an Octane
     * server, or Reverb, the same category) to that user before exec'ing it, *if*
     * SHIP_HOST_USER is set; without it Reverb would keep running as root in dev even with
     * hostUser enabled, the exact problem hostUser exists to avoid.
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
     * Reverb and "app" share the exact same dev entrypoint and project tree, so both
     * independently satisfying that entrypoint's own "composer.json present, vendor/autoload.php
     * missing" condition would run `composer install` twice, concurrently, into the same vendor/.
     * SHIP_DEV_SKIP_INSTALL tells the entrypoint to wait for "app"'s result instead -- dev only,
     * since production bakes vendor/ into the image at build time and never runs this entrypoint
     * logic at all.
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
     * With an Octane runtime selected, "app" itself is not php-fpm, so its own entrypoint would
     * otherwise race `ship up`'s own MutagenSync::installComposerDependencies() to run `composer
     * install` the moment composer.json merely appears -- often before composer.lock has finished
     * syncing too, writing a fresh composer.lock into the synced tree instead of honoring the
     * project's pinned versions. "app" gets the same SHIP_DEV_SKIP_INSTALL signal Reverb does,
     * but only when Mutagen is actually active -- a plain bind mount never starts out empty the
     * way Mutagen's named volume does, so "app" still has to install for itself there (a project
     * cloned with no local vendor/ at all).
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
        // "app" already sets this explicitly in baseServices() -- what
        // matters here is that "reverb" (which never does) picks up the
        // same PHP version rather than the Dockerfile's own ARG default.
        self::assertSame('8.3', $parsed['services']['reverb']['build']['args']['PHP_VERSION']);
        self::assertSame('8.3', $parsed['services']['app']['build']['args']['PHP_VERSION']);
    }

    /**
     * Reverb has to receive "app"'s own injected environment (DB_*, REDIS_*, ...), or anything it
     * touches that needs the database -- a private-channel auth callback, say -- fails to
     * connect; SHIP_RUN_AS, or it runs as root in production; and matching build args
     * (OCTANE_RUNTIME included), or selecting Octane/Swoole alongside Reverb forces a second,
     * wasteful image build for content that should be identical to "app"'s.
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
     * Reverb's dev volume has to follow $mutagenSync too, not stay the raw bind mount --
     * ReverbService's own composeFragment() has no access to it on its own, so
     * ComposeFileBuilder backfills it, matching "app"/"webserver" switching to the synced named
     * volume when SHIP_MUTAGEN is active. Without this Reverb would read the unsynced host tree
     * directly.
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

    public function test_app_loads_an_optional_env_file_for_real_production_secrets(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: []);

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Production));

        self::assertSame([['path' => '.env', 'required' => false]], $parsed['services']['app']['env_file']);
    }

    /**
     * "webserver" (nginx) serves a byte-for-byte static config -- no envsubst, no runtime
     * variable of its own -- so it must not get the same env_file: as "app" does: every app
     * secret in .env/.env.production (DB credentials, API keys, ...) would be readable inside the
     * nginx container too, for no actual use. A reverse proxy serving static files and forwarding
     * to php-fpm has no legitimate need for any of them.
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
}

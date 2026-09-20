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
 * Covers ComposeFileBuilder::build()'s $mutagenSync parameter -- see Ship\Sync\MutagenSync's own
 * docblock for why the sync target has to be a named volume, not the bind mount it replaces
 * (nginx's "webserver" needs the same tree Mutagen syncs into "app", and named volumes are the
 * only way two containers see identical, single-copy content without a second sync session).
 */
final class ComposeFileBuilderMutagenTest extends TestCase
{
    private function registry(): ServiceRegistry
    {
        return new ServiceRegistry([new PostgresService(), new OctaneSwooleService()]);
    }

    public function test_mutagen_mode_swaps_apps_bind_mount_for_a_named_volume(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: ['database' => 'pgsql']);

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development, mutagenSync: true));

        self::assertSame(['ship-app-sync:/var/www/html'], $parsed['services']['app']['volumes']);
        self::assertArrayHasKey('ship-app-sync', $parsed['volumes']);
    }

    /**
     * "webserver" only exists when no Octane runtime is selected (see OctaneSwooleService's own
     * removes()) -- it needs the *same* synced tree as "app" for its own static-file serving, so
     * it has to share the identical named volume rather than get its own separate sync session.
     */
    public function test_mutagen_mode_shares_the_same_named_volume_with_webserver(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: ['database' => 'pgsql']);

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development, mutagenSync: true));

        self::assertArrayHasKey('webserver', $parsed['services']);
        self::assertSame(['ship-app-sync:/var/www/html'], $parsed['services']['webserver']['volumes']);
    }

    public function test_mutagen_mode_is_ignored_when_no_webserver_exists(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: ['database' => 'pgsql', 'runtime' => 'octane-swoole']);

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development, mutagenSync: true));

        self::assertArrayNotHasKey('webserver', $parsed['services']);
        self::assertSame(['ship-app-sync:/var/www/html'], $parsed['services']['app']['volumes']);
    }

    /**
     * Production bakes the source into the image at build time (see the "builder"/"prod" build
     * stages) -- there's no bind mount there to begin with, so $mutagenSync changes nothing.
     */
    public function test_mutagen_mode_never_applies_in_production(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: ['database' => 'pgsql']);

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Production, mutagenSync: true));

        self::assertSame([], $parsed['services']['app']['volumes']);
        self::assertArrayNotHasKey('ship-app-sync', $parsed['volumes'] ?? []);
    }

    public function test_mutagen_mode_defaults_to_off(): void
    {
        $builder = new ComposeFileBuilder($this->registry());
        $config = new ShipConfig(phpVersion: '8.4', services: ['database' => 'pgsql']);

        $parsed = Yaml::parse($builder->build($config, ShipEnvironment::Development));

        self::assertSame(['.:/var/www/html'], $parsed['services']['app']['volumes']);
    }
}

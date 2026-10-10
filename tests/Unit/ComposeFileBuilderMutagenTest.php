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
 * Covers ComposeFileBuilder::build()'s $mutagenSync parameter: the sync target is a named volume
 * that "app" and "webserver" share.
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
     * "webserver" serves static files from the same tree, so it mounts the same named volume.
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
     * Production has no bind mount to replace, so $mutagenSync changes nothing.
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

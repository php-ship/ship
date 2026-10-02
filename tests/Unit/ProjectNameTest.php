<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Docker\ProjectName;

final class ProjectNameTest extends TestCase
{
    public function test_it_falls_back_to_the_project_directory_basename(): void
    {
        $config = new ShipConfig(phpVersion: '8.4', services: []);

        self::assertSame('my-app', ProjectName::resolve($config, '/var/www/my-app'));
    }

    public function test_an_explicit_name_wins_over_the_directory_basename(): void
    {
        $config = new ShipConfig(phpVersion: '8.4', services: [], name: 'acme-api');

        self::assertSame('acme-api', ProjectName::resolve($config, '/var/www/something-else'));
    }

    /**
     * Docker image names only allow lowercase letters, digits, and "." "_" "-" -- a directory
     * basename like "My Project" or a hand-typed ship.json value has to be normalized into that,
     * not handed straight to `docker build` where it would fail with an opaque error.
     */
    public function test_characters_docker_rejects_are_normalized(): void
    {
        $config = new ShipConfig(phpVersion: '8.4', services: []);

        self::assertSame('my-project', ProjectName::resolve($config, '/home/dev/My Project'));
    }

    public function test_a_name_with_nothing_usable_left_falls_back_to_a_fixed_default(): void
    {
        $config = new ShipConfig(phpVersion: '8.4', services: [], name: '!!!');

        self::assertSame('ship-app', ProjectName::resolve($config, '/anything'));
    }
}

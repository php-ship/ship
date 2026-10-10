<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
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
     * A directory basename like "My Project" is normalized into a valid Docker image name.
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

    /**
     * Docker allows only a single separator between alphanumeric runs: "foo..bar" is rejected as
     * "invalid reference format" even though every character in it is allowed.
     */
    public function test_consecutive_separator_characters_are_collapsed_to_one(): void
    {
        $config = new ShipConfig(phpVersion: '8.4', services: [], name: 'foo..bar');

        self::assertSame('foo-bar', ProjectName::resolve($config, '/anything'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidSeparatorRuns(): iterable
    {
        yield 'double dot' => ['foo..bar', 'foo-bar'];
        yield 'triple underscore' => ['foo___bar', 'foo-bar'];
        yield 'mixed dot and underscore' => ['foo._bar', 'foo-bar'];
        yield 'dot immediately after a collapsed run' => ['foo!!.bar', 'foo-bar'];
    }

    #[DataProvider('invalidSeparatorRuns')]
    public function test_every_invalid_separator_run_is_collapsed_to_one_hyphen(string $name, string $expected): void
    {
        $config = new ShipConfig(phpVersion: '8.4', services: [], name: $name);

        self::assertSame($expected, ProjectName::resolve($config, '/anything'));
    }
}

<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Symfony\Component\Filesystem\Filesystem;

final class ShipConfigTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/ship-config-test-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot, recursive: true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectRoot);
    }

    public function test_from_file_throws_a_clear_error_when_ship_json_does_not_exist(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Run `ship init` first');

        ShipConfig::fromFile($this->projectRoot . '/ship.json');
    }

    public function test_from_file_throws_on_malformed_json_rather_than_silently_returning_defaults(): void
    {
        file_put_contents($this->projectRoot . '/ship.json', '{not valid json');

        $this->expectException(\JsonException::class);

        ShipConfig::fromFile($this->projectRoot . '/ship.json');
    }

    /**
     * Every one of these is what a project genuinely on an old ship.json (predating a field
     * that's since been added -- nodeVersion, additionalServices) would have on disk. Silently
     * defaulting keeps `ship up` working for it without forcing a re-`ship init` just to pick up
     * a schema addition that doesn't concern that project at all.
     */
    public function test_from_file_defaults_every_field_a_minimal_ship_json_omits(): void
    {
        file_put_contents($this->projectRoot . '/ship.json', json_encode(['services' => ['database' => 'pgsql']]));

        $config = ShipConfig::fromFile($this->projectRoot . '/ship.json');

        self::assertSame('8.4', $config->phpVersion);
        self::assertSame('24', $config->nodeVersion);
        self::assertSame(['database' => 'pgsql'], $config->services);
        self::assertSame([], $config->extensions);
        self::assertSame([], $config->additionalServices);
        self::assertSame([], $config->serviceNames);
        self::assertNull($config->externalNetwork);
    }

    public function test_to_file_and_from_file_round_trip_every_field_unchanged(): void
    {
        $original = new ShipConfig(
            phpVersion: '8.3',
            services: ['database' => 'mysql', 'cache' => 'redis'],
            extensions: ['Acme\\Ship\\CustomService'],
            nodeVersion: '22',
            additionalServices: [['group' => 'database', 'service' => 'pgsql', 'name' => 'analytics']],
            serviceNames: ['app' => 'client-app', 'webserver' => 'client-web', 'mysql' => 'client-db'],
            externalNetwork: 'shared_infra',
        );

        $path = $this->projectRoot . '/ship.json';
        $original->toFile($path);
        $roundTripped = ShipConfig::fromFile($path);

        self::assertEquals($original, $roundTripped);
    }

    /**
     * A plain `ship init` project (the overwhelming majority) never touches serviceNames/
     * externalNetwork -- their ship.json should read exactly as it did before this feature
     * existed, not grow 2 new lines nobody asked for. See ShipConfig::toFile()'s own comment.
     */
    public function test_to_file_omits_service_names_and_external_network_when_left_at_default(): void
    {
        $path = $this->projectRoot . '/ship.json';
        (new ShipConfig(phpVersion: '8.4', services: ['database' => 'pgsql']))->toFile($path);

        $written = (string) file_get_contents($path);

        self::assertStringNotContainsString('serviceNames', $written);
        self::assertStringNotContainsString('externalNetwork', $written);
    }

    public function test_to_file_writes_pretty_printed_json_ending_in_a_newline(): void
    {
        $path = $this->projectRoot . '/ship.json';
        (new ShipConfig(phpVersion: '8.4', services: ['database' => 'pgsql']))->toFile($path);

        $written = file_get_contents($path);

        self::assertStringEndsWith("\n", $written);
        self::assertStringContainsString("\n    \"php\": \"8.4\",\n", $written);
    }
}

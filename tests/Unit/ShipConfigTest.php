<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
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
     * `"php": 8.4` (a bare JSON number) gets a message naming ship.json, not a raw TypeError.
     */
    public function test_from_file_rejects_a_non_string_php_version_with_a_clear_error(): void
    {
        file_put_contents($this->projectRoot . '/ship.json', json_encode(['php' => 8.4, 'services' => []]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"php" must be a string');

        ShipConfig::fromFile($this->projectRoot . '/ship.json');
    }

    public function test_from_file_rejects_a_non_string_node_version_with_a_clear_error(): void
    {
        file_put_contents($this->projectRoot . '/ship.json', json_encode(['node' => 24, 'services' => []]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"node" must be a string');

        ShipConfig::fromFile($this->projectRoot . '/ship.json');
    }

    /**
     * serviceNames values become compose keys, so their format is checked.
     */
    public function test_from_file_rejects_an_invalid_service_name(): void
    {
        file_put_contents(
            $this->projectRoot . '/ship.json',
            json_encode(['services' => [], 'serviceNames' => ['app' => 'not a valid name!']]),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('serviceNames."app"');

        ShipConfig::fromFile($this->projectRoot . '/ship.json');
    }

    /**
     * additionalServices names also become an env var prefix, so a hyphen is rejected.
     */
    public function test_from_file_rejects_an_additional_service_name_with_a_hyphen(): void
    {
        file_put_contents(
            $this->projectRoot . '/ship.json',
            json_encode(['services' => [], 'additionalServices' => [['group' => 'database', 'service' => 'mysql', 'name' => 'my-analytics']]]),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('additionalServices');

        ShipConfig::fromFile($this->projectRoot . '/ship.json');
    }

    /**
     * A non-array value gets a message naming ship.json, not a PHP warning and a TypeError.
     *
     * @return iterable<string, array{string, mixed}>
     */
    public static function nonArrayFields(): iterable
    {
        yield 'serviceNames' => ['serviceNames', 'not-an-array'];
        yield 'additionalServices' => ['additionalServices', 'not-an-array'];
        yield 'processes' => ['processes', 'not-an-array'];
        yield 'deployCommands' => ['deployCommands', 'not-an-array'];
    }

    #[DataProvider('nonArrayFields')]
    public function test_from_file_rejects_a_non_array_value_with_a_clear_error(string $field, mixed $value): void
    {
        file_put_contents(
            $this->projectRoot . '/ship.json',
            json_encode(['services' => [], $field => $value]),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("\"{$field}\"");

        ShipConfig::fromFile($this->projectRoot . '/ship.json');
    }

    /**
     * A non-string process command is rejected.
     */
    public function test_from_file_rejects_a_non_string_process_command(): void
    {
        file_put_contents(
            $this->projectRoot . '/ship.json',
            json_encode(['services' => [], 'processes' => ['worker' => 123]]),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"processes"');

        ShipConfig::fromFile($this->projectRoot . '/ship.json');
    }

    public function test_from_file_rejects_a_non_string_deploy_command(): void
    {
        file_put_contents(
            $this->projectRoot . '/ship.json',
            json_encode(['services' => [], 'deployCommands' => [123]]),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"deployCommands"');

        ShipConfig::fromFile($this->projectRoot . '/ship.json');
    }

    public function test_from_file_rejects_a_non_array_additional_service_entry(): void
    {
        file_put_contents(
            $this->projectRoot . '/ship.json',
            json_encode(['services' => [], 'additionalServices' => ['just-a-string']]),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('additionalServices');

        ShipConfig::fromFile($this->projectRoot . '/ship.json');
    }

    /**
     * Each of these gets a message naming ship.json, not a raw constructor TypeError.
     *
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function furtherInvalidFields(): iterable
    {
        yield 'publishPorts as a quoted string' => [['publishPorts' => 'false'], '"publishPorts"'];
        yield 'hostUser as a quoted string' => [['hostUser' => 'true'], '"hostUser"'];
        yield 'extensions as a non-list value' => [['extensions' => 'Foo'], '"extensions"'];
        yield 'phpExtensions as a non-list value' => [['phpExtensions' => 'gd'], '"phpExtensions"'];
        yield 'name as a non-string' => [['name' => 5], '"name"'];
        yield 'externalNetwork as a non-string' => [['externalNetwork' => 5], '"externalNetwork"'];
        yield 'a non-string value under services' => [['services' => ['database' => 5]], 'services."database"'];
    }

    #[DataProvider('furtherInvalidFields')]
    public function test_from_file_rejects_further_invalid_field_shapes(array $extra, string $expectedInMessage): void
    {
        file_put_contents(
            $this->projectRoot . '/ship.json',
            json_encode(['services' => [], ...$extra]),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($expectedInMessage);

        ShipConfig::fromFile($this->projectRoot . '/ship.json');
    }

    /**
     * A missing "service" key is caught when the config is loaded, not as an "Undefined array
     * key" warning in ComposeFileBuilder.
     */
    public function test_from_file_rejects_an_additional_service_entry_missing_the_service_key(): void
    {
        file_put_contents(
            $this->projectRoot . '/ship.json',
            json_encode(['services' => [], 'additionalServices' => [['group' => 'database', 'name' => 'analytics']]]),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"service"');

        ShipConfig::fromFile($this->projectRoot . '/ship.json');
    }

    /**
     * A top-level value that isn't an object gets a message naming ship.json.
     *
     * @return iterable<string, array{string}>
     */
    public static function nonObjectTopLevelValues(): iterable
    {
        yield 'a JSON array' => [json_encode([1, 2, 3])];
        yield 'a bare string' => [json_encode('just a string')];
        yield 'a bare number' => [json_encode(5)];
    }

    #[DataProvider('nonObjectTopLevelValues')]
    public function test_from_file_rejects_a_non_object_top_level_value(string $json): void
    {
        file_put_contents($this->projectRoot . '/ship.json', $json);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be a JSON object');

        ShipConfig::fromFile($this->projectRoot . '/ship.json');
    }

    /**
     * A ship.json written before a field existed keeps working: omitted fields are defaulted.
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
        self::assertSame([], $config->phpExtensions);
        self::assertFalse($config->publishPorts);
        self::assertSame([], $config->deployCommands);
        self::assertSame([], $config->processes);
        self::assertFalse($config->hostUser);
        self::assertNull($config->name);
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
            phpExtensions: ['gd', 'zip', 'bcmath'],
            publishPorts: false,
            deployCommands: ['php artisan migrate --force', 'php artisan telescope:setup-database'],
            processes: ['horizon' => 'php artisan horizon'],
            hostUser: true,
            name: 'acme-api',
        );

        $path = $this->projectRoot . '/ship.json';
        $original->toFile($path);
        $roundTripped = ShipConfig::fromFile($path);

        self::assertEquals($original, $roundTripped);
    }

    /**
     * Optional fields left at their defaults aren't written, so a plain project's ship.json
     * stays minimal.
     */
    public function test_to_file_omits_service_names_external_network_and_php_extensions_when_left_at_default(): void
    {
        $path = $this->projectRoot . '/ship.json';
        (new ShipConfig(phpVersion: '8.4', services: ['database' => 'pgsql']))->toFile($path);

        $written = (string) file_get_contents($path);

        self::assertStringNotContainsString('serviceNames', $written);
        self::assertStringNotContainsString('externalNetwork', $written);
        self::assertStringNotContainsString('phpExtensions', $written);
        self::assertStringNotContainsString('publishPorts', $written);
        self::assertStringNotContainsString('deployCommands', $written);
        self::assertStringNotContainsString('processes', $written);
        self::assertStringNotContainsString('hostUser', $written);
        self::assertStringNotContainsString('"name"', $written);
    }

    public function test_to_file_persists_explicit_production_port_publishing(): void
    {
        $path = $this->projectRoot . '/ship.json';
        (new ShipConfig(phpVersion: '8.4', services: [], publishPorts: true))->toFile($path);

        self::assertTrue(ShipConfig::fromFile($path)->publishPorts);
        self::assertStringContainsString('"publishPorts": true', (string) file_get_contents($path));
    }

    public function test_to_file_writes_pretty_printed_json_ending_in_a_newline(): void
    {
        $path = $this->projectRoot . '/ship.json';
        (new ShipConfig(phpVersion: '8.4', services: ['database' => 'pgsql']))->toFile($path);

        $written = file_get_contents($path);

        self::assertStringEndsWith("\n", $written);
        self::assertStringContainsString("\n    \"php\": \"8.4\",\n", $written);
    }

    public function test_try_from_file_returns_null_when_the_file_does_not_exist(): void
    {
        self::assertNull(ShipConfig::tryFromFile($this->projectRoot . '/ship.json'));
    }

    public function test_try_from_file_returns_null_for_malformed_json(): void
    {
        file_put_contents($this->projectRoot . '/ship.json', '{not valid json');

        self::assertNull(ShipConfig::tryFromFile($this->projectRoot . '/ship.json'));
    }

    /**
     * tryFromFile() keeps a field with the wrong format (but the right type) as-is, so one bad
     * value doesn't discard the rest. fromFile() still rejects it when the config is used.
     */
    public function test_try_from_file_preserves_a_field_that_fails_formatting_rules_as_is(): void
    {
        $path = $this->projectRoot . '/ship.json';
        file_put_contents($path, json_encode([
            'php' => '8.4',
            'services' => [],
            'serviceNames' => ['app' => 'Not A Valid Name!'],
            'phpExtensions' => ['gd'],
            'name' => 'acme-api',
        ]));

        $config = ShipConfig::tryFromFile($path);

        self::assertNotNull($config);
        self::assertSame(['app' => 'Not A Valid Name!'], $config->serviceNames);
        self::assertSame(['gd'], $config->phpExtensions);
        self::assertSame('acme-api', $config->name);
    }

    public function test_try_from_file_filters_non_string_entries_out_of_list_and_map_fields(): void
    {
        $path = $this->projectRoot . '/ship.json';
        file_put_contents($path, json_encode([
            'php' => '8.4',
            'services' => [],
            'extensions' => ['Real\\Extension', 123, null],
            'serviceNames' => ['app' => 'client-app', 'webserver' => 42],
            'publishPorts' => 'not-a-bool',
        ]));

        $config = ShipConfig::tryFromFile($path);

        self::assertNotNull($config);
        self::assertSame(['Real\\Extension'], $config->extensions);
        self::assertSame(['app' => 'client-app'], $config->serviceNames);
        self::assertFalse($config->publishPorts);
    }
}

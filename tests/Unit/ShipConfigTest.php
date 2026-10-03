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
     * Regression coverage for a real bug found via an independent audit: `"php": 8.4` (a bare
     * JSON number -- the quotes around the string are an easy hand-edit mistake to drop)
     * previously reached the constructor's own strict `string $phpVersion` type unchecked,
     * surfacing as a raw "Argument #1 ($phpVersion) must be of type string, float given"
     * TypeError instead of a message that so much as names ship.json.
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
     * Regression coverage for a real bug found via an independent audit: serviceNames values went
     * straight into generated compose keys with no validation at all, unlike `processes` names,
     * which already get exactly this check.
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
     * additionalServices names are stricter than serviceNames -- no hyphen -- because they also
     * become a `.env`-style env var prefix, which a hyphen breaks.
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
     * Regression coverage for a real bug found via an independent re-audit: a non-array
     * serviceNames/additionalServices/processes/deployCommands used to reach a raw `foreach()
     * argument must be of type array|object` PHP warning followed by an uncaught constructor
     * TypeError -- confirmed live -- instead of a clean message naming ship.json, exactly the
     * failure mode this validation exists to replace.
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
     * Regression coverage for a real bug found via an independent re-audit: processes/
     * deployCommands had no validation at all before this -- a non-string command (or, for
     * processes, a non-string name) would have reached whatever used it downstream unchecked.
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
        self::assertSame([], $config->phpExtensions);
        self::assertTrue($config->publishPorts);
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
     * A plain `ship init` project (the overwhelming majority) never touches serviceNames/
     * externalNetwork/phpExtensions -- their ship.json should read exactly as it did before these
     * features existed, not grow new lines nobody asked for. See ShipConfig::toFile()'s own
     * comment.
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
     * Regression coverage for a real bug found via an independent re-audit: fromFile() validates
     * and throws on the first invalid field it finds, which InitCommand::readExistingConfig()'s
     * own try/catch treated as "nothing to preserve at all" -- one bad serviceNames value
     * reintroduced the exact data-loss bug the preservation fix exists to prevent. tryFromFile()
     * preserves a field that's merely the wrong *format* (not the wrong *type*) as-is instead of
     * dropping it -- silently discarding it here would just be a second, quieter way to lose a
     * hand-edited value with no error at all; fromFile()'s own validation still catches it for
     * real, with a clear and actionable error, the next time anything actually uses the config.
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
        self::assertTrue($config->publishPorts);
    }
}

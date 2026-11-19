<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\ComposeFileBuilder;
use Ship\Docker\Healthchecks;
use Ship\Docker\HttpEntryPoints;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * A ship.json process that receives HTTP: `{"command": ..., "http": {...}}` (see
 * ShipConfig::$processHttp). It's an http[] entry, checked over HTTP, on the external network.
 */
final class ProcessHttpTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/ship-process-http-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot, recursive: true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectRoot);
    }

    /**
     * @param array<string, mixed> $processes
     */
    private function config(array $processes, array $extra = []): ShipConfig
    {
        file_put_contents($this->projectRoot . '/ship.json', json_encode(['php' => '8.4', 'services' => [], 'processes' => $processes, ...$extra]));

        return ShipConfig::fromFile($this->projectRoot . '/ship.json');
    }

    private const REVERB = [
        'command' => 'php artisan reverb:start --host=0.0.0.0 --port=8080',
        'http' => ['port' => 8080, 'role' => 'websocket', 'urlSetting' => 'REVERB_PUBLIC_HOST'],
    ];

    public function test_the_object_form_gives_the_command_and_the_http_entry(): void
    {
        $config = $this->config(['horizon' => 'php artisan horizon', 'reverb' => self::REVERB]);

        self::assertSame(['horizon' => 'php artisan horizon', 'reverb' => self::REVERB['command']], $config->processes);
        self::assertSame(['reverb' => ['port' => 8080, 'role' => 'websocket', 'health' => null, 'urlSetting' => 'REVERB_PUBLIC_HOST']], $config->processHttp);
    }

    /**
     * Writing ship.json back (as `ship init` does) keeps the object form.
     */
    public function test_the_object_form_survives_a_round_trip(): void
    {
        $config = $this->config(['horizon' => 'php artisan horizon', 'reverb' => self::REVERB]);
        $config->toFile($this->projectRoot . '/ship.json');

        $again = ShipConfig::fromFile($this->projectRoot . '/ship.json');

        self::assertSame($config->processes, $again->processes);
        self::assertSame($config->processHttp, $again->processHttp);
        self::assertSame('php artisan horizon', json_decode((string) file_get_contents($this->projectRoot . '/ship.json'), true)['processes']['horizon']);
    }

    /**
     * What ship writes is what the shared schema (ship-ecosystem/spec) accepts.
     */
    public function test_what_ship_writes_passes_the_shared_schema(): void
    {
        $this->config(['horizon' => 'php artisan horizon', 'reverb' => self::REVERB])->toFile($this->projectRoot . '/ship.json');

        $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/schema/ship.schema.json');
        $result = (new Validator())->validate(json_decode((string) file_get_contents($this->projectRoot . '/ship.json')), $schema);

        self::assertTrue($result->isValid());
    }

    /**
     * A project with no services still writes "services" as an object.
     */
    public function test_a_project_without_services_passes_the_shared_schema(): void
    {
        (new ShipConfig(phpVersion: '8.4', services: []))->toFile($this->projectRoot . '/ship.json');

        $json = (string) file_get_contents($this->projectRoot . '/ship.json');
        $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/schema/ship.schema.json');

        self::assertStringContainsString('"services": {}', $json);
        self::assertTrue((new Validator())->validate(json_decode($json), $schema)->isValid());
        self::assertSame([], ShipConfig::fromFile($this->projectRoot . '/ship.json')->services);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidEntries(): iterable
    {
        yield 'no port' => [['command' => 'x', 'http' => ['role' => 'websocket']], 'http.port'];
        yield 'port out of range' => [['command' => 'x', 'http' => ['port' => 70000, 'role' => 'site']], 'http.port'];
        yield 'no role' => [['command' => 'x', 'http' => ['port' => 8080]], 'http.role'];
        yield 'relative health path' => [['command' => 'x', 'http' => ['port' => 8080, 'role' => 'site', 'health' => 'up']], 'http.health'];
        yield 'a value instead of a setting name' => [['command' => 'x', 'http' => ['port' => 8080, 'role' => 'site', 'urlSetting' => 'https://example.com']], 'http.urlSetting'];
        yield 'unknown http field' => [['command' => 'x', 'http' => ['port' => 8080, 'role' => 'site', 'tls' => true]], 'unknown fields (tls)'];
        yield 'no command' => [['http' => ['port' => 8080, 'role' => 'site']], '"command"'];
    }

    #[DataProvider('invalidEntries')]
    public function test_an_invalid_entry_is_rejected_with_what_is_wrong(mixed $entry, string $problem): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($problem);

        $this->config(['reverb' => $entry]);
    }

    /**
     * The lenient reader `ship init` uses drops a broken entry and keeps the rest.
     */
    public function test_the_lenient_reader_drops_only_a_broken_entry(): void
    {
        file_put_contents($this->projectRoot . '/ship.json', json_encode(['php' => '8.4', 'services' => [], 'processes' => [
            'horizon' => 'php artisan horizon',
            'reverb' => self::REVERB,
            'broken' => ['command' => 'x', 'http' => ['port' => 'eighty']],
        ]]));

        $config = ShipConfig::tryFromFile($this->projectRoot . '/ship.json');

        self::assertNotNull($config);
        self::assertSame(['horizon', 'reverb'], array_keys($config->processes));
        self::assertSame(['reverb'], array_keys($config->processHttp));
    }

    public function test_it_is_an_http_entry_checked_over_http_on_the_external_network(): void
    {
        $registry = new ServiceRegistry([]);
        $config = $this->config(['horizon' => 'php artisan horizon', 'reverb' => self::REVERB], ['externalNetwork' => 'proxy']);

        $compose = Yaml::parse((new ComposeFileBuilder($registry))->build($config, ShipEnvironment::Production));
        $entries = HttpEntryPoints::resolve($compose['services'], $config, $registry, ShipEnvironment::Production);

        self::assertContains(['service' => 'reverb', 'port' => 8080, 'role' => 'websocket', 'health' => null, 'urlSetting' => 'REVERB_PUBLIC_HOST'], $entries);
        self::assertSame(Healthchecks::httpServer(8080), $compose['services']['reverb']['healthcheck']);
        self::assertContains('external', $compose['services']['reverb']['networks']);
        self::assertSame([], $compose['services']['reverb']['ports'] ?? []);

        // A plain worker is unchanged.
        self::assertSame(Healthchecks::stayedUp(), $compose['services']['horizon']['healthcheck']);
        self::assertNotContains('horizon', array_column($entries, 'service'));
    }

    public function test_a_health_path_must_answer_2xx_and_publish_ports_publishes_it(): void
    {
        $process = self::REVERB;
        $process['http']['health'] = '/up';
        $config = $this->config(['ws-server' => $process], ['publishPorts' => true]);

        $compose = Yaml::parse((new ComposeFileBuilder(new ServiceRegistry([])))->build($config, ShipEnvironment::Production));

        self::assertSame(Healthchecks::httpPath(8080, '/up'), $compose['services']['ws-server']['healthcheck']);
        self::assertSame(['${WS_SERVER_PORT:-8080}:8080'], $compose['services']['ws-server']['ports']);
    }

    /**
     * Processes are production only; development has no such service and no entry.
     */
    public function test_development_has_no_process_entry(): void
    {
        $registry = new ServiceRegistry([]);
        $config = $this->config(['reverb' => self::REVERB]);

        $compose = Yaml::parse((new ComposeFileBuilder($registry))->build($config, ShipEnvironment::Development));

        self::assertArrayNotHasKey('reverb', $compose['services']);
        self::assertNotContains('reverb', array_column(HttpEntryPoints::resolve($compose['services'], $config, $registry, ShipEnvironment::Development), 'service'));
    }
}

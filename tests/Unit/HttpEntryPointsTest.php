<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\ComposeFileBuilder;
use Ship\Docker\HttpEntryPoints;
use Ship\Services\CentrifugoService;
use Ship\Services\OctaneFrankenPhpService;
use Ship\Services\OctaneSwooleService;
use Ship\Services\PostgresService;
use Ship\Services\ReverbService;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Yaml\Yaml;

final class HttpEntryPointsTest extends TestCase
{
    private function registry(): ServiceRegistry
    {
        return new ServiceRegistry([new PostgresService(), new ReverbService(), new CentrifugoService(), new OctaneSwooleService(), new OctaneFrankenPhpService()]);
    }

    /**
     * @return array{entries: list<array{service: string, port: int, role: string, health: ?string}>, services: array<string, array<string, mixed>>}
     */
    private function resolve(ShipConfig $config, ShipEnvironment $environment = ShipEnvironment::Production): array
    {
        $registry = $this->registry();
        $services = Yaml::parse((new ComposeFileBuilder($registry))->build($config, $environment))['services'];

        return ['entries' => HttpEntryPoints::resolve($services, $config, $registry, $environment), 'services' => $services];
    }

    public function test_nginx_is_the_site_on_port_80(): void
    {
        $entries = $this->resolve(new ShipConfig(phpVersion: '8.4', services: ['database' => 'postgres']))['entries'];

        self::assertSame([['service' => 'webserver', 'port' => 80, 'role' => 'site', 'health' => null]], $entries);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function octaneRuntimes(): iterable
    {
        yield 'swoole' => ['octane-swoole'];
        yield 'frankenphp' => ['octane-frankenphp'];
    }

    #[DataProvider('octaneRuntimes')]
    public function test_octane_serves_the_site_from_app_on_port_8000(string $runtime): void
    {
        $entries = $this->resolve(new ShipConfig(phpVersion: '8.4', services: ['runtime' => $runtime]))['entries'];

        self::assertSame([['service' => 'app', 'port' => 8000, 'role' => 'site', 'health' => null]], $entries);
    }

    public function test_reverb_adds_a_websocket_entry_point(): void
    {
        $entries = $this->resolve(new ShipConfig(phpVersion: '8.4', services: ['broadcasting' => 'reverb']))['entries'];

        self::assertSame([
            ['service' => 'webserver', 'port' => 80, 'role' => 'site', 'health' => null],
            ['service' => 'reverb', 'port' => 8080, 'role' => 'websocket', 'health' => null],
        ], $entries);
    }

    public function test_centrifugo_adds_a_websocket_entry_point_with_its_health_path(): void
    {
        $entries = $this->resolve(new ShipConfig(phpVersion: '8.4', services: ['broadcasting' => 'centrifugo']))['entries'];

        self::assertSame(['service' => 'centrifugo', 'port' => 8000, 'role' => 'websocket', 'health' => '/health'], $entries[1]);
    }

    public function test_renamed_services_are_reported_by_their_final_names(): void
    {
        $config = new ShipConfig(
            phpVersion: '8.4',
            services: ['broadcasting' => 'reverb'],
            serviceNames: ['webserver' => 'client-web', 'reverb' => 'client-ws'],
        );

        self::assertSame(['client-web', 'client-ws'], array_column($this->resolve($config)['entries'], 'service'));
    }

    public function test_every_entry_point_is_on_the_external_network(): void
    {
        $config = new ShipConfig(phpVersion: '8.4', services: ['broadcasting' => 'reverb'], externalNetwork: 'proxy');
        $result = $this->resolve($config);

        foreach ($result['entries'] as $entry) {
            self::assertContains('external', $result['services'][$entry['service']]['networks'], $entry['service']);
        }
    }
}

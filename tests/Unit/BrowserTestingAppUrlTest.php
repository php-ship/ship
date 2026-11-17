<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\ComposeFileBuilder;
use Ship\Services\CypressService;
use Ship\Services\DuskService;
use Ship\Services\OctaneFrankenPhpService;
use Ship\Services\PlaywrightService;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Yaml\Yaml;

/**
 * Covers NeedsAppUrl: each browser testing service gets the app's internal address in the
 * variable and container it reads it from, and nothing else changes.
 */
final class BrowserTestingAppUrlTest extends TestCase
{
    /**
     * @param array<string, string> $services
     * @param array<string, string> $serviceNames
     * @return array<string, mixed>
     */
    private function build(array $services, ShipEnvironment $environment = ShipEnvironment::Development, array $serviceNames = [], bool $mutagenSync = false): array
    {
        $registry = new ServiceRegistry([new DuskService(), new PlaywrightService(), new CypressService(), new OctaneFrankenPhpService()]);
        $config = new ShipConfig(phpVersion: '8.4', services: $services, serviceNames: $serviceNames);

        return Yaml::parse((new ComposeFileBuilder($registry))->build($config, $environment, mutagenSync: $mutagenSync));
    }

    public function test_playwright_gets_its_base_url_on_app_and_leaves_app_url_alone(): void
    {
        $env = $this->build(['testing' => 'playwright'])['services']['app']['environment'];

        self::assertSame('http://webserver', $env['PLAYWRIGHT_BASE_URL']);
        self::assertSame('ws://playwright:3000/', $env['PW_TEST_CONNECT_WS_ENDPOINT']);
        self::assertArrayNotHasKey('APP_URL', $env);
    }

    public function test_cypress_gets_its_base_url_on_its_own_container_and_leaves_app_url_alone(): void
    {
        $services = $this->build(['testing' => 'cypress'])['services'];

        self::assertSame(['CYPRESS_BASE_URL' => 'http://webserver'], $services['cypress']['environment']);
        self::assertArrayNotHasKey('APP_URL', $services['app']['environment']);
        self::assertArrayNotHasKey('CYPRESS_BASE_URL', $services['app']['environment']);
    }

    public function test_octane_is_reached_on_its_own_port(): void
    {
        $services = $this->build(['runtime' => 'octane-frankenphp', 'testing' => 'cypress'])['services'];

        self::assertSame('http://app:8000', $services['cypress']['environment']['CYPRESS_BASE_URL']);
    }

    public function test_a_renamed_cypress_service_still_gets_its_base_url(): void
    {
        $services = $this->build(['testing' => 'cypress'], serviceNames: ['cypress' => 'client-e2e'])['services'];

        self::assertArrayNotHasKey('cypress', $services);
        self::assertSame('http://webserver', $services['client-e2e']['environment']['CYPRESS_BASE_URL']);
    }

    public function test_nothing_is_injected_in_production(): void
    {
        foreach (['dusk', 'playwright', 'cypress'] as $driver) {
            $services = $this->build(['testing' => $driver], ShipEnvironment::Production)['services'];

            self::assertArrayNotHasKey($driver, $services);
            self::assertArrayNotHasKey('APP_URL', $services['app']['environment']);
            self::assertArrayNotHasKey('PLAYWRIGHT_BASE_URL', $services['app']['environment']);
            self::assertArrayNotHasKey('CYPRESS_INSTALL_BINARY', $services['app']['environment']);
        }
    }

    /**
     * Under Mutagen there is no bind mount of the project to share.
     */
    public function test_cypress_mounts_the_synced_volume_under_mutagen(): void
    {
        $parsed = $this->build(['testing' => 'cypress'], mutagenSync: true);

        self::assertSame(['ship-app-sync:/e2e'], $parsed['services']['cypress']['volumes']);
        self::assertSame(['.:/e2e'], $this->build(['testing' => 'cypress'])['services']['cypress']['volumes']);
    }
}

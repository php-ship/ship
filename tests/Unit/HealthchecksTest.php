<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\ComposeFileBuilder;
use Ship\Docker\Healthchecks;
use Ship\Services\OctaneFrankenPhpService;
use Ship\Services\OctaneRoadRunnerService;
use Ship\Services\OctaneSwooleService;
use Ship\Services\PostgresService;
use Ship\Services\ReverbService;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Yaml\Yaml;

/**
 * Production healthchecks of the services that run the app, so `docker compose up --wait` waits
 * until they serve. The commands themselves are exercised against real containers in CI's
 * docker-build job.
 */
final class HealthchecksTest extends TestCase
{
    /**
     * @return array<string, array<string, mixed>>
     */
    private function services(ShipConfig $config, ShipEnvironment $environment = ShipEnvironment::Production): array
    {
        $registry = new ServiceRegistry([new PostgresService(), new ReverbService(), new OctaneSwooleService(), new OctaneRoadRunnerService(), new OctaneFrankenPhpService()]);

        return Yaml::parse((new ComposeFileBuilder($registry))->build($config, $environment))['services'];
    }

    public function test_php_fpm_and_nginx_check_the_fpm_ping(): void
    {
        $services = $this->services(new ShipConfig(phpVersion: '8.4', services: ['database' => 'postgres']));

        self::assertSame(Healthchecks::phpFpm(), $services['app']['healthcheck']);
        self::assertSame(Healthchecks::nginx(), $services['webserver']['healthcheck']);
        self::assertStringContainsString('cgi-fcgi -bind -connect 127.0.0.1:9000', $services['app']['healthcheck']['test'][1]);
        self::assertStringContainsString('http://127.0.0.1/ship-fpm-ping', $services['webserver']['healthcheck']['test'][1]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function octaneRuntimes(): iterable
    {
        yield 'swoole' => ['octane-swoole'];
        yield 'roadrunner' => ['octane-roadrunner'];
        yield 'frankenphp' => ['octane-frankenphp'];
    }

    /**
     * Octane serves HTTP itself, so its check replaces php-fpm's.
     */
    #[DataProvider('octaneRuntimes')]
    public function test_an_octane_app_checks_its_own_http_server(string $runtime): void
    {
        $services = $this->services(new ShipConfig(phpVersion: '8.4', services: ['runtime' => $runtime]));

        self::assertSame(Healthchecks::httpServer(8000), $services['app']['healthcheck']);
        self::assertArrayNotHasKey('webserver', $services);
    }

    public function test_reverb_checks_its_http_server_and_workers_check_they_stay_up(): void
    {
        $config = new ShipConfig(phpVersion: '8.4', services: ['broadcasting' => 'reverb'], processes: ['horizon' => 'php artisan horizon']);
        $services = $this->services($config);

        self::assertSame(Healthchecks::httpServer(8080), $services['reverb']['healthcheck']);
        self::assertSame(Healthchecks::stayedUp(), $services['horizon']['healthcheck']);
    }

    /**
     * The first development boot runs composer install, which can outlast any healthcheck budget.
     */
    public function test_development_has_no_app_healthchecks(): void
    {
        $config = new ShipConfig(phpVersion: '8.4', services: ['database' => 'postgres', 'broadcasting' => 'reverb']);
        $services = $this->services($config, ShipEnvironment::Development);

        self::assertArrayNotHasKey('healthcheck', $services['app']);
        self::assertArrayNotHasKey('healthcheck', $services['webserver']);
        self::assertArrayNotHasKey('healthcheck', $services['reverb']);
        self::assertArrayHasKey('healthcheck', $services['postgres']);
    }

    /**
     * Compose interpolates a bare $; the worker check's own $ must reach the shell as $$.
     */
    public function test_the_worker_check_escapes_every_dollar_for_compose(): void
    {
        $test = Healthchecks::stayedUp()['test'][1];

        self::assertSame(0, preg_match('/(?<!\$)\$(?!\$)/', str_replace('$$', '', $test)));
        self::assertStringContainsString('/proc/1/stat', $test);
    }

    /**
     * The HTTP check runs as a plain argv (no shell), so its PHP code must not need escaping.
     */
    public function test_the_http_check_needs_no_shell_and_no_compose_escaping(): void
    {
        $test = Healthchecks::httpServer(8000)['test'];

        self::assertSame(['CMD', 'php', '-r'], array_slice($test, 0, 3));
        self::assertStringNotContainsString('$', $test[3]);
        self::assertStringContainsString("'ignore_errors' => true", $test[3]);
    }
}

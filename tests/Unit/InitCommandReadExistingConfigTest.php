<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Console\Commands\InitCommand;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Filesystem\Filesystem;

final class InitCommandReadExistingConfigTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/ship-init-existing-config-test-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot, recursive: true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectRoot);
    }

    public function test_it_returns_null_when_no_ship_json_exists_yet(): void
    {
        self::assertNull($this->invoke());
    }

    /**
     * Regression coverage for a real bug found via an independent audit: re-running `ship init`
     * rebuilt ShipConfig from only the four fields its own prompts touch, silently dropping every
     * hand-edited field it never asks about -- publishPorts: false alone being lost would
     * silently re-expose ports a project turned off deliberately.
     */
    public function test_it_reads_hand_edited_fields_a_fresh_init_run_never_prompts_for(): void
    {
        (new ShipConfig(
            phpVersion: '8.3',
            services: ['database' => 'mysql'],
            serviceNames: ['app' => 'client-app'],
            externalNetwork: 'shared_infra',
            phpExtensions: ['gd'],
            publishPorts: false,
            deployCommands: ['php artisan migrate --force'],
            processes: ['horizon' => 'php artisan horizon'],
            hostUser: true,
            name: 'acme-api',
        ))->toFile($this->projectRoot . '/ship.json');

        $existing = $this->invoke();

        self::assertNotNull($existing);
        self::assertSame(['app' => 'client-app'], $existing->serviceNames);
        self::assertSame('shared_infra', $existing->externalNetwork);
        self::assertSame(['gd'], $existing->phpExtensions);
        self::assertFalse($existing->publishPorts);
        self::assertSame(['php artisan migrate --force'], $existing->deployCommands);
        self::assertSame(['horizon' => 'php artisan horizon'], $existing->processes);
        self::assertTrue($existing->hostUser);
        self::assertSame('acme-api', $existing->name);
    }

    public function test_it_returns_null_for_a_malformed_ship_json_instead_of_throwing(): void
    {
        file_put_contents($this->projectRoot . '/ship.json', '{not valid json');

        self::assertNull($this->invoke());
    }

    private function invoke(): ?ShipConfig
    {
        $command = new InitCommand($this->projectRoot, new ServiceRegistry(ServiceRegistry::defaults()));

        return (new \ReflectionMethod($command, 'readExistingConfig'))->invoke($command);
    }
}

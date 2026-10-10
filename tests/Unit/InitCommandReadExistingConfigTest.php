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

    public function test_it_adds_production_secret_and_release_ignore_rules_without_duplicates(): void
    {
        file_put_contents($this->projectRoot . '/.gitignore', "/vendor/\n/dist/ship/\n");
        $command = new InitCommand($this->projectRoot, new ServiceRegistry(ServiceRegistry::defaults()));
        $method = new \ReflectionMethod($command, 'ensureGitignoreExcludesSecrets');

        $method->invoke($command);
        $first = file_get_contents($this->projectRoot . '/.gitignore');
        $method->invoke($command);

        self::assertSame($first, file_get_contents($this->projectRoot . '/.gitignore'));
        self::assertSame(1, substr_count($first, '/.env.production'));
        self::assertSame(1, substr_count($first, '/dist/ship/'));
        self::assertStringContainsString('/vendor/', $first);
    }

    /**
     * Fields `ship init` never prompts for must survive a re-run.
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

    /**
     * services/additionalServices are read back too, since the prompts default to them.
     */
    public function test_it_reads_services_and_additional_services_too(): void
    {
        (new ShipConfig(
            phpVersion: '8.4',
            services: ['database' => 'mysql'],
            additionalServices: [['group' => 'cache', 'service' => 'redis', 'name' => 'queue']],
        ))->toFile($this->projectRoot . '/ship.json');

        $existing = $this->invoke();

        self::assertNotNull($existing);
        self::assertSame(['database' => 'mysql'], $existing->services);
        self::assertSame(
            [['group' => 'cache', 'service' => 'redis', 'name' => 'queue']],
            $existing->additionalServices,
        );
    }

    public function test_it_returns_null_for_a_malformed_ship_json_instead_of_throwing(): void
    {
        file_put_contents($this->projectRoot . '/ship.json', '{not valid json');

        self::assertNull($this->invoke());
    }

    /**
     * One field failing fromFile()'s format check (serviceNames.app here) must not discard the
     * others, or itself: readExistingConfig() uses the lenient ShipConfig::tryFromFile().
     */
    public function test_one_invalid_field_does_not_discard_every_other_hand_edited_field(): void
    {
        file_put_contents($this->projectRoot . '/ship.json', json_encode([
            'php' => '8.4',
            'services' => [],
            'serviceNames' => ['app' => 'Not A Valid Name!'],
            'phpExtensions' => ['gd'],
            'publishPorts' => false,
            'name' => 'acme-api',
        ]));

        $existing = $this->invoke();

        self::assertNotNull($existing);
        self::assertSame(['app' => 'Not A Valid Name!'], $existing->serviceNames);
        self::assertSame(['gd'], $existing->phpExtensions);
        self::assertFalse($existing->publishPorts);
        self::assertSame('acme-api', $existing->name);
    }

    private function invoke(): ?ShipConfig
    {
        $command = new InitCommand($this->projectRoot, new ServiceRegistry(ServiceRegistry::defaults()));

        return (new \ReflectionMethod($command, 'readExistingConfig'))->invoke($command);
    }
}

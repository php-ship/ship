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

    /**
     * Regression coverage for a real bug found via a seventh independent audit: readExistingConfig()
     * hardcoded services/additionalServices to [] on the theory that InitCommand's own prompts
     * always supply both fresh -- true of the *value* written to ship.json, but not of the
     * prompts' own defaults, which now read this object back to avoid resetting every group to
     * "None" on a second `ship init` run. Hardcoding either to [] here silently fed every prompt
     * that "nothing is currently selected" default regardless of what ship.json actually had.
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
     * Regression coverage for a real bug found via an independent re-audit of the fix above:
     * readExistingConfig() used to call ShipConfig::fromFile(), which validates and throws on the
     * first invalid field it finds (serviceNames.app here, which fails the compose-name regex) --
     * caught by this method's own try/catch and treated as "nothing to preserve," reintroducing
     * the exact data-loss bug being fixed, just behind a new trigger (a validation failure instead
     * of a JSON syntax error). One field failing fromFile()'s own stricter format check must not
     * discard every *other* hand-edited field right along with it -- or itself: it's preserved
     * as-is here too (see ShipConfig::tryFromFile()'s own docblock for why).
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

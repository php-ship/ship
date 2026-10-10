<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Console\Application;
use Ship\Console\Commands\InitCommand;
use Ship\Support\ShipVersion;
use Ship\Tests\Fixtures\DatabaseServiceWithoutShell;
use Symfony\Component\Filesystem\Filesystem;

final class ApplicationTest extends TestCase
{
    /**
     * `ship --version` reflects the installed version (ShipVersion::current() returns "dev-main"
     * in this checkout), not a hardcoded literal.
     */
    public function test_the_cli_version_comes_from_shipversion_not_a_hardcoded_literal(): void
    {
        $application = new Application(sys_get_temp_dir());

        self::assertSame(ShipVersion::current(), $application->getVersion());
        self::assertNotSame('0.1.0-dev', $application->getVersion());
    }

    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/ship-application-test-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot, recursive: true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectRoot);
    }

    /**
     * ship.json doesn't exist before the first `ship init`; the CLI must still boot.
     */
    public function test_every_base_command_is_registered_even_without_a_ship_json(): void
    {
        $application = new Application($this->projectRoot);

        foreach (['init', 'up', 'down', 'exec', 'shell', 'logs', 'db', 'composer', 'npm', 'config:test'] as $name) {
            self::assertTrue($application->has($name), "Expected \"{$name}\" to be registered.");
        }
    }

    public function test_it_does_not_crash_when_ship_json_is_malformed(): void
    {
        file_put_contents($this->projectRoot . '/ship.json', '{not valid json');

        $application = new Application($this->projectRoot);

        self::assertTrue($application->has('up'));
    }

    public function test_it_does_not_crash_when_ship_json_lists_a_nonexistent_extension_class(): void
    {
        (new ShipConfig(
            phpVersion: '8.4',
            services: [],
            extensions: ['Nonexistent\\Ship\\Extension'],
        ))->toFile($this->projectRoot . '/ship.json');

        $application = new Application($this->projectRoot);

        self::assertTrue($application->has('up'));
    }

    /**
     * An extension must register into the same registry InitCommand uses.
     */
    public function test_a_valid_service_definition_extension_is_registered_into_init_commands_registry(): void
    {
        (new ShipConfig(
            phpVersion: '8.4',
            services: [],
            extensions: [DatabaseServiceWithoutShell::class],
        ))->toFile($this->projectRoot . '/ship.json');

        $application = new Application($this->projectRoot);

        /** @var InitCommand $init */
        $init = $application->find('init');
        $registry = (new \ReflectionProperty($init, 'registry'))->getValue($init);

        self::assertSame(DatabaseServiceWithoutShell::class, $registry->get('no-shell-db')::class);
    }

    /**
     * Every command receives Application's own registry (asserted as the same object), so
     * extension classes aren't instantiated a second time.
     */
    public function test_up_build_release_and_db_commands_share_applications_own_registry(): void
    {
        $application = new Application($this->projectRoot);

        /** @var InitCommand $init */
        $init = $application->find('init');
        $initRegistry = (new \ReflectionProperty($init, 'registry'))->getValue($init);

        foreach (['up', 'build', 'release', 'db'] as $commandName) {
            $command = $application->find($commandName);
            $registry = (new \ReflectionProperty($command, 'registry'))->getValue($command);

            self::assertSame($initRegistry, $registry, "\"{$commandName}\" does not share Application's registry.");
        }
    }

    /**
     * `ship composer`/`ship npm` and framework proxies must target the app service's configured
     * name (ShipConfig::$serviceNames), not a literal "app".
     */
    public function test_proxy_commands_target_ship_jsons_configured_app_name(): void
    {
        touch($this->projectRoot . '/artisan');
        touch($this->projectRoot . '/composer.json');
        (new ShipConfig(phpVersion: '8.4', services: [], serviceNames: ['app' => 'client-app']))
            ->toFile($this->projectRoot . '/ship.json');

        $application = new Application($this->projectRoot);

        self::assertStringContainsString('"client-app"', $application->find('composer')->getDescription());
        self::assertStringContainsString('"client-app"', $application->find('npm')->getDescription());
        self::assertStringContainsString('"client-app"', $application->find('artisan')->getDescription());
    }

    public function test_no_framework_specific_commands_are_registered_for_a_plain_non_framework_project(): void
    {
        $application = new Application($this->projectRoot);

        self::assertFalse($application->has('artisan'));
        self::assertFalse($application->has('console'));
    }

    public function test_artisan_is_registered_for_a_project_that_looks_like_laravel(): void
    {
        touch($this->projectRoot . '/artisan');
        touch($this->projectRoot . '/composer.json');

        $application = new Application($this->projectRoot);

        self::assertTrue($application->has('artisan'));
        self::assertFalse($application->has('console'));
    }

    public function test_console_is_registered_for_a_project_that_looks_like_symfony(): void
    {
        mkdir($this->projectRoot . '/bin');
        touch($this->projectRoot . '/bin/console');
        touch($this->projectRoot . '/composer.json');

        $application = new Application($this->projectRoot);

        self::assertTrue($application->has('console'));
        self::assertFalse($application->has('artisan'));
    }
}

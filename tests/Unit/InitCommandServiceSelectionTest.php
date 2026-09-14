<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Console\Commands\InitCommand;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Covers what InitCommandViteReminderTest and InitCommandReverbWarningTest exercise but never
 * assert on: that the picker's typed answers actually land in ship.json under the right group
 * keys, and that publishStubs() copies (or omits) the right files for a given selection --
 * nginx only when no runtime is selected, Garage's config stub only when Garage is selected.
 */
final class InitCommandServiceSelectionTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/ship-init-test-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot, recursive: true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectRoot);
    }

    private function runInit(array $answers): void
    {
        $command = new InitCommand($this->projectRoot, new ServiceRegistry(ServiceRegistry::defaults()));
        $tester = new CommandTester($command);
        $tester->setInputs([...$answers, '8.4']);
        $tester->execute([]);
    }

    private function shipJson(): array
    {
        return json_decode(file_get_contents($this->projectRoot . '/ship.json'), associative: true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_selected_services_land_in_ship_json_under_the_right_group_keys(): void
    {
        $this->runInit([
            'PostgreSQL', 'Redis', 'None', 'None', 'Meilisearch',
            'Mailpit (local mail capture)', 'None', 'None', 'None',
        ]);

        $services = $this->shipJson()['services'];

        self::assertSame('pgsql', $services['database']);
        self::assertSame('redis', $services['cache']);
        self::assertSame('meilisearch', $services['search']);
        self::assertSame('mailpit', $services['mail']);
        self::assertArrayNotHasKey('runtime', $services);
        self::assertArrayNotHasKey('storage', $services);
        self::assertArrayNotHasKey('testing', $services);
        self::assertArrayNotHasKey('frontend', $services);
        self::assertArrayNotHasKey('broadcasting', $services);
    }

    public function test_nginx_is_published_when_no_runtime_is_selected(): void
    {
        $this->runInit(['None', 'None', 'None', 'None', 'None', 'None', 'None', 'None', 'None']);

        self::assertFileExists($this->projectRoot . '/ship/nginx/default.conf');
    }

    public function test_nginx_is_omitted_when_an_octane_runtime_is_selected(): void
    {
        // "Octane (RoadRunner)", not the default Swoole option -- its label is plain ASCII,
        // sidestepping a Windows console-codepage quirk CommandTester's simulated input stream
        // hits with the Swoole option's em dash. Exercises the identical code path either way.
        $this->runInit([
            'None', 'None', 'Octane (RoadRunner)', 'None', 'None',
            'None', 'None', 'None', 'None',
        ]);

        self::assertFileDoesNotExist($this->projectRoot . '/ship/nginx/default.conf');
    }

    public function test_garages_config_stub_is_published_only_when_garage_is_selected(): void
    {
        $this->runInit([
            'None', 'None', 'None', 'Garage (S3-compatible storage, lightweight alt.)',
            'None', 'None', 'None', 'None', 'None',
        ]);

        self::assertFileExists($this->projectRoot . '/ship/garage/garage.toml');
    }

    public function test_garages_config_stub_is_not_published_for_seaweedfs(): void
    {
        $this->runInit([
            'None', 'None', 'None', 'SeaweedFS (S3-compatible storage)',
            'None', 'None', 'None', 'None', 'None',
        ]);

        self::assertDirectoryDoesNotExist($this->projectRoot . '/ship/garage');
    }

    public function test_the_base_php_stubs_are_always_published_regardless_of_selection(): void
    {
        $this->runInit(['None', 'None', 'None', 'None', 'None', 'None', 'None', 'None', 'None']);

        self::assertFileExists($this->projectRoot . '/ship/Dockerfile');
    }
}

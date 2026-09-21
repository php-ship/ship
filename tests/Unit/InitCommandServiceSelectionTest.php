<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ship\Console\Commands\InitCommand;
use Ship\Services\ServiceRegistry;
use Ship\Support\ShipVersion;
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

    /**
     * Read back by UpCommand's version-mismatch warning -- see UpCommandTest for that side.
     */
    public function test_the_installed_ship_version_is_recorded_for_later_mismatch_detection(): void
    {
        $this->runInit(['None', 'None', 'None', 'None', 'None', 'None', 'None', 'None', 'None']);

        self::assertFileExists($this->projectRoot . '/ship/.ship-version');
        self::assertSame(ShipVersion::current(), trim(file_get_contents($this->projectRoot . '/ship/.ship-version')));
    }

    public function test_an_additional_named_instance_is_recorded_in_ship_json(): void
    {
        $command = new InitCommand($this->projectRoot, new ServiceRegistry(ServiceRegistry::defaults()));
        $tester = new CommandTester($command);
        $tester->setInputs([
            // Main loop: default database only, everything else None.
            'PostgreSQL', 'None', 'None', 'None', 'None', 'None', 'None', 'None', 'None',
            // Add-another-instance loop.
            'yes', 'database', 'MySQL', 'analytics', 'no',
            // PHP / Node versions.
            '8.4', '24',
        ]);
        $tester->execute([]);

        $additional = $this->shipJson()['additionalServices'];

        self::assertCount(1, $additional);
        self::assertSame(['group' => 'database', 'service' => 'mysql', 'name' => 'analytics'], $additional[0]);
    }

    public function test_it_rejects_a_duplicate_instance_name_and_asks_again(): void
    {
        $command = new InitCommand($this->projectRoot, new ServiceRegistry(ServiceRegistry::defaults()));
        $tester = new CommandTester($command);
        $tester->setInputs([
            'PostgreSQL', 'None', 'None', 'None', 'None', 'None', 'None', 'None', 'None',
            'yes', 'database', 'MySQL', 'analytics',
            'yes', 'cache', 'Redis', 'analytics', 'queue',
            'no',
            '8.4', '24',
        ]);
        $tester->execute([]);

        $additional = $this->shipJson()['additionalServices'];

        self::assertCount(2, $additional);
        self::assertSame('analytics', $additional[0]['name']);
        self::assertSame('queue', $additional[1]['name']);
        self::assertStringContainsString('already used', $tester->getDisplay());
    }

    /**
     * An instance name becomes both a Compose service name suffix and an environment variable
     * prefix (see SupportsNamedInstances) -- confirmed live that a space in it makes `docker
     * compose config` reject the whole generated file outright, with an error that never points
     * back to this prompt. A hyphen is accepted by Compose but not by a `.env` file's KEY=VALUE
     * syntax, so it's rejected here too, not just whitespace.
     *
     * @return iterable<string, array{string}>
     */
    public static function invalidInstanceNames(): iterable
    {
        yield 'a space' => ['my analytics'];
        yield 'a hyphen' => ['my-analytics'];
        yield 'starts with a digit' => ['1analytics'];
        yield 'uppercase-only input still normalized then re-checked' => ['@nalytics'];
    }

    #[DataProvider('invalidInstanceNames')]
    public function test_it_rejects_an_instance_name_with_invalid_characters_and_asks_again(string $invalidName): void
    {
        $command = new InitCommand($this->projectRoot, new ServiceRegistry(ServiceRegistry::defaults()));
        $tester = new CommandTester($command);
        $tester->setInputs([
            'PostgreSQL', 'None', 'None', 'None', 'None', 'None', 'None', 'None', 'None',
            'yes', 'database', 'MySQL', $invalidName, 'analytics',
            'no',
            '8.4', '24',
        ]);
        $tester->execute([]);

        $additional = $this->shipJson()['additionalServices'];

        self::assertCount(1, $additional);
        self::assertSame('analytics', $additional[0]['name']);
        self::assertStringContainsString('can only contain lowercase letters', $tester->getDisplay());
    }
}

<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Console\Commands\InitCommand;
use Ship\Services\ServiceRegistry;
use Ship\Services\SiloService;
use Ship\Support\ShipVersion;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Covers that the picker's answers land in ship.json under the right group keys, and that
 * publishStubs() copies the right files for a given selection.
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
        // The project name first (its default).
        $tester->setInputs(['', ...$answers, '8.4']);
        $tester->execute([]);
    }

    private function shipJson(): array
    {
        return json_decode(file_get_contents($this->projectRoot . '/ship.json'), associative: true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_selected_services_land_in_ship_json_under_the_right_group_keys(): void
    {
        $this->runInit([
            'Database,Cache,Search engine,Mail capture', 'PostgreSQL', 'Redis', 'Meilisearch', 'Mailpit (local mail capture)',
        ]);

        $services = $this->shipJson()['services'];

        self::assertSame('postgres', $services['database']);
        self::assertSame('redis', $services['cache']);
        self::assertSame('meilisearch', $services['search']);
        self::assertSame('mailpit', $services['mail']);
        self::assertArrayNotHasKey('runtime', $services);
        self::assertArrayNotHasKey('storage', $services);
        self::assertArrayNotHasKey('testing', $services);
        self::assertArrayNotHasKey('frontend', $services);
        self::assertArrayNotHasKey('broadcasting', $services);
        self::assertArrayNotHasKey('broker', $services);
    }

    public function test_nginx_is_published_when_no_runtime_is_selected(): void
    {
        $this->runInit(['None']);

        self::assertFileExists($this->projectRoot . '/ship/nginx/default.conf');
    }

    /**
     * Only the exact front controller is passed to PHP-FPM, not any path ending in .php.
     */
    public function test_nginx_restricts_php_execution_to_the_front_controller(): void
    {
        $this->runInit(['None']);

        $conf = (string) file_get_contents($this->projectRoot . '/ship/nginx/default.conf');

        self::assertStringContainsString('location = /index.php', $conf);
    }

    /**
     * Any other .php file under public/ returns 404 instead of being served as raw source.
     */
    public function test_nginx_denies_every_other_php_file_instead_of_serving_it_as_source(): void
    {
        $this->runInit(['None']);

        $conf = (string) file_get_contents($this->projectRoot . '/ship/nginx/default.conf');

        self::assertMatchesRegularExpression('/location ~ \\\\\.php\$ \{\s*return 404;/', $conf);
    }

    /**
     * server_tokens off keeps the nginx version out of the Server header.
     */
    public function test_nginx_does_not_advertise_its_own_version(): void
    {
        $this->runInit(['None']);

        $conf = (string) file_get_contents($this->projectRoot . '/ship/nginx/default.conf');

        self::assertStringContainsString('server_tokens off;', $conf);
    }

    /**
     * The stub's "app:9000" upstream follows a renamed app service.
     */
    public function test_nginx_upstream_follows_a_renamed_app_service(): void
    {
        file_put_contents(
            $this->projectRoot . '/ship.json',
            json_encode(['php' => '8.4', 'services' => [], 'serviceNames' => ['app' => 'client-app']]),
        );

        $this->runInit(['None']);

        $conf = (string) file_get_contents($this->projectRoot . '/ship/nginx/default.conf');

        self::assertStringContainsString('set $upstream_app client-app:9000;', $conf);
        self::assertStringNotContainsString('set $upstream_app app:9000;', $conf);
    }

    public function test_nginx_is_omitted_when_an_octane_runtime_is_selected(): void
    {
        // RoadRunner rather than Swoole: its label is plain ASCII, avoiding a Windows
        // console-codepage problem with the em dash in Swoole's label.
        $this->runInit([
            'Application runtime', 'Octane (RoadRunner)',
        ]);

        self::assertFileDoesNotExist($this->projectRoot . '/ship/nginx/default.conf');
    }

    public function test_garages_config_stub_is_published_only_when_garage_is_selected(): void
    {
        $this->runInit([
            'Object storage', 'Garage (S3-compatible storage, lightweight alt.)',
        ]);

        self::assertFileExists($this->projectRoot . '/ship/garage/garage.toml');
    }

    public function test_garages_config_stub_is_not_published_for_seaweedfs(): void
    {
        $this->runInit([
            'Object storage', 'SeaweedFS (S3-compatible storage)',
        ]);

        self::assertDirectoryDoesNotExist($this->projectRoot . '/ship/garage');
    }

    /**
     * Garage selected only as a named instance still needs its stub files to build from.
     */
    public function test_garages_config_stub_is_published_when_selected_only_as_an_additional_instance(): void
    {
        $command = new InitCommand($this->projectRoot, new ServiceRegistry(ServiceRegistry::defaults()));
        $tester = new CommandTester($command);
        $tester->setInputs([
            '', // the project name
            'Object storage', 'SeaweedFS (S3-compatible storage)',
            'yes', 'Object storage', 'Garage (S3-compatible storage, lightweight alt.)', 'archive', 'no',
            'no', // no processes of our own
            '', // no external networks
            '8.4', '24',
        ]);
        $tester->execute([]);

        self::assertFileExists($this->projectRoot . '/ship/garage/garage.toml');
    }

    /**
     * A kind ticked by mistake can still be left out: an Octane runtime ticked, then PHP-FPM
     * preferred.
     */
    public function test_a_ticked_kind_can_still_be_answered_with_none(): void
    {
        $this->runInit(['Database,Application runtime', 'PostgreSQL', 'None (PHP-FPM behind nginx)']);

        self::assertSame(['database' => 'postgres'], $this->shipJson()['services']);
        self::assertFileExists($this->projectRoot . '/ship/nginx/default.conf');
    }

    public function test_the_base_php_stubs_are_always_published_regardless_of_selection(): void
    {
        $this->runInit(['None']);

        self::assertFileExists($this->projectRoot . '/ship/Dockerfile');
    }

    /**
     * Read back by UpCommand's version-mismatch warning (see UpCommandTest).
     */
    public function test_the_installed_ship_version_is_recorded_for_later_mismatch_detection(): void
    {
        $this->runInit(['None']);

        self::assertFileExists($this->projectRoot . '/ship/.ship-version');
        self::assertSame(ShipVersion::current(), trim(file_get_contents($this->projectRoot . '/ship/.ship-version')));
    }

    public function test_an_additional_named_instance_is_recorded_in_ship_json(): void
    {
        $command = new InitCommand($this->projectRoot, new ServiceRegistry(ServiceRegistry::defaults()));
        $tester = new CommandTester($command);
        $tester->setInputs([
            '', // the project name
            // Main loop: default database only, everything else None.
            'Database', 'PostgreSQL',
            // Add-another-instance loop.
            'yes', 'Database', 'MySQL', 'analytics', 'no',
            // PHP / Node versions.
            'no', // no processes of our own
            '', // no external networks
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
            '', // the project name
            'Database', 'PostgreSQL',
            'yes', 'Database', 'MySQL', 'analytics',
            'yes', 'Cache', 'Redis', 'analytics', 'queue',
            'no',
            'no', // no processes of our own
            '', // no external networks
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
     * An instance name becomes a compose service suffix and an env var prefix (see
     * SupportsNamedInstances), so whitespace and hyphens are rejected.
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
            '', // the project name
            'Database', 'PostgreSQL',
            'yes', 'Database', 'MySQL', $invalidName, 'analytics',
            'no',
            'no', // no processes of our own
            '', // no external networks
            '8.4', '24',
        ]);
        $tester->execute([]);

        $additional = $this->shipJson()['additionalServices'];

        self::assertCount(1, $additional);
        self::assertSame('analytics', $additional[0]['name']);
        self::assertStringContainsString('can only contain lowercase letters', $tester->getDisplay());
    }

    /**
     * Each group prompt defaults to the existing selection, so accepting every default keeps the
     * project's services.
     */
    public function test_re_running_init_defaults_each_group_to_its_existing_selection(): void
    {
        file_put_contents(
            $this->projectRoot . '/ship.json',
            json_encode(['php' => '8.4', 'services' => ['database' => 'mysql']]),
        );

        // Enter keeps the ticked kinds, then the database's current service.
        $this->runInit(['', '']);

        self::assertSame('mysql', $this->shipJson()['services']['database']);
    }

    /**
     * An existing selection that is no longer a valid choice falls back to "None".
     */
    public function test_a_stale_existing_selection_no_longer_offered_falls_back_to_none(): void
    {
        file_put_contents(
            $this->projectRoot . '/ship.json',
            json_encode(['php' => '8.4', 'services' => ['database' => 'no-longer-registered']]),
        );

        // Its kind starts unticked, so Enter leaves the database out.
        $this->runInit(['']);

        self::assertArrayNotHasKey('database', $this->shipJson()['services']);
    }

    /**
     * Existing additional instances are kept without re-prompting, even when nothing new is
     * added.
     */
    public function test_re_running_init_keeps_existing_additional_instances_without_re_prompting(): void
    {
        file_put_contents(
            $this->projectRoot . '/ship.json',
            json_encode([
                'php' => '8.4',
                'services' => [],
                'additionalServices' => [['group' => 'database', 'service' => 'mysql', 'name' => 'analytics']],
            ]),
        );

        $this->runInit(['None', 'no']);

        $additional = $this->shipJson()['additionalServices'];

        self::assertCount(1, $additional);
        self::assertSame(['group' => 'database', 'service' => 'mysql', 'name' => 'analytics'], $additional[0]);
    }

    /**
     * Silo's standard image doesn't run on every CPU, so selecting Silo asks which image to use
     * and explains why.
     */
    public function test_selecting_silo_asks_for_its_image_and_records_a_distroless_choice(): void
    {
        $command = new InitCommand($this->projectRoot, new ServiceRegistry(ServiceRegistry::defaults()));
        $tester = new CommandTester($command);
        $tester->setInputs([
            '', // the project name
            'Object storage', (new SiloService())->label(),
            'no',
            'Distroless (runs on older CPUs too; no shell in the container)',
            'no', // no processes of our own
            '', // no external networks
            '8.4', '24',
        ]);
        $tester->execute([]);

        self::assertSame('silo', $this->shipJson()['services']['storage']);
        self::assertSame('distroless', $this->shipJson()['siloImage']);
        self::assertStringContainsString('x86-64-v2', $tester->getDisplay());
        self::assertStringContainsString('Which Silo image?', $tester->getDisplay());
    }

    public function test_the_standard_silo_image_is_the_default_and_is_not_written_to_ship_json(): void
    {
        $command = new InitCommand($this->projectRoot, new ServiceRegistry(ServiceRegistry::defaults()));
        $tester = new CommandTester($command);
        $tester->setInputs([
            '', // the project name
            'Object storage', (new SiloService())->label(),
            'no',
            '',
            'no', // no processes of our own
            '', // no external networks
            '8.4', '24',
        ]);
        $tester->execute([]);

        self::assertSame('silo', $this->shipJson()['services']['storage']);
        self::assertArrayNotHasKey('siloImage', $this->shipJson());
    }

    public function test_re_running_init_defaults_the_silo_image_to_the_existing_choice(): void
    {
        file_put_contents(
            $this->projectRoot . '/ship.json',
            json_encode(['php' => '8.4', 'services' => ['storage' => 'silo'], 'siloImage' => 'distroless']),
        );

        $command = new InitCommand($this->projectRoot, new ServiceRegistry(ServiceRegistry::defaults()));
        $tester = new CommandTester($command);
        $tester->setInputs(['', '', '', 'no', '', 'no', '', '8.4', '24']);
        $tester->execute([]);

        self::assertSame('distroless', $this->shipJson()['siloImage']);
    }

    public function test_the_silo_image_question_is_not_asked_when_silo_is_not_selected(): void
    {
        $this->runInit(['Database', 'PostgreSQL']);

        self::assertArrayNotHasKey('siloImage', $this->shipJson());
    }

    /**
     * The Dockerfiles COPY it from ship/ in their base stage.
     */
    public function test_the_php_security_floor_check_is_published(): void
    {
        $this->runInit(['None']);

        self::assertFileExists($this->projectRoot . '/ship/check-php-security-floor.php');
    }

    /**
     * An end-of-life branch is re-asked at the prompt instead of failing the first `ship up`.
     */
    public function test_an_end_of_life_php_version_is_re_asked(): void
    {
        $command = new InitCommand($this->projectRoot, new ServiceRegistry(ServiceRegistry::defaults()));
        $tester = new CommandTester($command);
        $tester->setInputs(['', 'None', 'no', 'no', '', '8.1', '8.4']);
        $tester->execute([]);

        self::assertStringContainsString('past end of life', $tester->getDisplay());
        self::assertSame('8.4', $this->shipJson()['php']);
    }

    public function test_a_new_project_is_named_after_its_directory(): void
    {
        $this->runInit(['None']);

        $name = $this->shipJson()['name'];
        self::assertMatchesRegularExpression(ShipConfig::NAME_PATTERN, $name);
        self::assertSame(strtolower(basename($this->projectRoot)), str_replace('_', '-', $name));
    }

    public function test_re_running_init_keeps_the_projects_name(): void
    {
        file_put_contents($this->projectRoot . '/ship.json', json_encode(['php' => '8.4', 'services' => [], 'name' => 'nirootech-client']));

        $this->runInit(['None']);

        self::assertSame('nirootech-client', $this->shipJson()['name']);
    }

    public function test_a_name_the_schema_rejects_is_asked_again(): void
    {
        $tester = new CommandTester(new InitCommand($this->projectRoot, new ServiceRegistry(ServiceRegistry::defaults())));
        $tester->setInputs(['My.App', 'my-app', 'None', 'no', 'no', '', '8.4']);
        $tester->execute([]);

        self::assertStringContainsString('Lowercase letters and digits', $tester->getDisplay());
        self::assertSame('my-app', $this->shipJson()['name']);
    }
}

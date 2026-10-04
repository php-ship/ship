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

    /**
     * Only the exact front controller is ever passed to PHP-FPM -- not `location ~ \.php$`,
     * which would pass *any* request path ending in .php to PHP-FPM, existing file or not.
     */
    public function test_nginx_restricts_php_execution_to_the_front_controller(): void
    {
        $this->runInit(['None', 'None', 'None', 'None', 'None', 'None', 'None', 'None', 'None']);

        $conf = (string) file_get_contents($this->projectRoot . '/ship/nginx/default.conf');

        self::assertStringContainsString('location = /index.php', $conf);
    }

    /**
     * Restricting execution to the exact front controller alone would leave every *other* .php
     * file under public/ falling through to "location /"'s try_files, which serves it statically
     * as raw PHP source instead of running it -- a source-disclosure problem in place of
     * arbitrary execution. This rule 404s a stray second.php instead of returning its source,
     * while /index.php itself still reaches the exact-match fastcgi_pass block untouched (nginx's
     * exact match always wins over this regex for /index.php itself).
     */
    public function test_nginx_denies_every_other_php_file_instead_of_serving_it_as_source(): void
    {
        $this->runInit(['None', 'None', 'None', 'None', 'None', 'None', 'None', 'None', 'None']);

        $conf = (string) file_get_contents($this->projectRoot . '/ship/nginx/default.conf');

        self::assertMatchesRegularExpression('/location ~ \\\\\.php\$ \{\s*return 404;/', $conf);
    }

    /**
     * server_tokens off -- nginx's own default (on) advertises the exact nginx version in every
     * response's Server: header, a smaller, specific CVE search target than "nginx" alone.
     */
    public function test_nginx_does_not_advertise_its_own_version(): void
    {
        $this->runInit(['None', 'None', 'None', 'None', 'None', 'None', 'None', 'None', 'None']);

        $conf = (string) file_get_contents($this->projectRoot . '/ship/nginx/default.conf');

        self::assertStringContainsString('server_tokens off;', $conf);
    }

    /**
     * The stub's own "app:9000" upstream has to follow a renamed app service, not stay
     * hardcoded -- renaming it via ship.json's serviceNames would otherwise leave nginx trying
     * to reach a DNS name nothing in the stack answers to, 502ing every request.
     */
    public function test_nginx_upstream_follows_a_renamed_app_service(): void
    {
        file_put_contents(
            $this->projectRoot . '/ship.json',
            json_encode(['php' => '8.4', 'services' => [], 'serviceNames' => ['app' => 'client-app']]),
        );

        $this->runInit(['None', 'None', 'None', 'None', 'None', 'None', 'None', 'None', 'None']);

        $conf = (string) file_get_contents($this->projectRoot . '/ship/nginx/default.conf');

        self::assertStringContainsString('set $upstream_app client-app:9000;', $conf);
        self::assertStringNotContainsString('set $upstream_app app:9000;', $conf);
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

    /**
     * This check has to cover Garage selected *only* as a named additionalServices instance too,
     * not just the default storage pick -- otherwise that instance's own `build:` would have
     * nothing to build from, since its stub files were never published at all.
     */
    public function test_garages_config_stub_is_published_when_selected_only_as_an_additional_instance(): void
    {
        $command = new InitCommand($this->projectRoot, new ServiceRegistry(ServiceRegistry::defaults()));
        $tester = new CommandTester($command);
        $tester->setInputs([
            'None', 'None', 'None', 'SeaweedFS (S3-compatible storage)', 'None', 'None', 'None', 'None', 'None',
            'yes', 'storage', 'Garage (S3-compatible storage, lightweight alt.)', 'archive', 'no',
            '8.4', '24',
        ]);
        $tester->execute([]);

        self::assertFileExists($this->projectRoot . '/ship/garage/garage.toml');
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

    /**
     * Every group prompt must default to ship.json's existing selection, not always "None" --
     * re-running `ship init` on a project that already has one (exactly what `ship up` itself
     * tells users to do after a stub-version mismatch) would otherwise drop the database/cache/
     * etc. selection the moment the user just accepts each prompt's own default instead of
     * retyping every choice by hand.
     */
    public function test_re_running_init_defaults_each_group_to_its_existing_selection(): void
    {
        file_put_contents(
            $this->projectRoot . '/ship.json',
            json_encode(['php' => '8.4', 'services' => ['database' => 'mysql']]),
        );

        $this->runInit(['', 'None', 'None', 'None', 'None', 'None', 'None', 'None', 'None']);

        self::assertSame('mysql', $this->shipJson()['services']['database']);
    }

    /**
     * A group's existing selection is only offered as a default when it's still a real choice --
     * e.g. an extension that provided it was removed from ship.json since -- rather than handed
     * to select() as a default it doesn't recognize.
     */
    public function test_a_stale_existing_selection_no_longer_offered_falls_back_to_none(): void
    {
        file_put_contents(
            $this->projectRoot . '/ship.json',
            json_encode(['php' => '8.4', 'services' => ['database' => 'no-longer-registered']]),
        );

        $this->runInit(['', 'None', 'None', 'None', 'None', 'None', 'None', 'None', 'None']);

        self::assertArrayNotHasKey('database', $this->shipJson()['services']);
    }

    /**
     * Regression coverage for the same audit: additionalServices has no interactive way to edit
     * or remove an existing entry, so re-running `ship init` rebuilt the list from scratch every
     * time -- confirmed live that declining to add anything new ("no" to the first prompt) still
     * silently discarded every instance a previous `ship init` run had already added.
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

        $this->runInit(['None', 'None', 'None', 'None', 'None', 'None', 'None', 'None', 'None', 'no']);

        $additional = $this->shipJson()['additionalServices'];

        self::assertCount(1, $additional);
        self::assertSame(['group' => 'database', 'service' => 'mysql', 'name' => 'analytics'], $additional[0]);
    }
}

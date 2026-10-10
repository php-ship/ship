<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Console\Commands\ConfigTestCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Covers that `ship config:test` surfaces each class of problem and reports all of them in one
 * pass.
 */
final class ConfigTestCommandTest extends TestCase
{
    private string $projectRoot;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/ship-config-test-command-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot, recursive: true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectRoot);
    }

    public function test_it_fails_clearly_when_no_ship_json_exists(): void
    {
        $exitCode = $this->runCommand();

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Run `ship init` first', $this->tester->getDisplay());
    }

    public function test_it_fails_clearly_on_an_invalid_ship_json(): void
    {
        file_put_contents($this->projectRoot . '/ship.json', '{not valid json');

        $exitCode = $this->runCommand();

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('ship.json:', $this->tester->getDisplay());
    }

    /**
     * Registry membership doesn't depend on the environment, so the problem is reported once,
     * not once per build.
     */
    public function test_it_reports_an_unregistered_service_key_exactly_once(): void
    {
        (new ShipConfig(phpVersion: '8.4', services: ['database' => 'not-a-real-service']))
            ->toFile($this->projectRoot . '/ship.json');

        $exitCode = $this->runCommand();

        self::assertSame(Command::FAILURE, $exitCode);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('not-a-real-service', $display);
        self::assertStringContainsString('1 problem found', $display);
    }

    /**
     * A key under additionalServices is checked the same way as one under services.
     */
    public function test_it_reports_an_unregistered_additional_service_key(): void
    {
        (new ShipConfig(
            phpVersion: '8.4',
            services: [],
            additionalServices: [['group' => 'database', 'service' => 'not-a-real-service', 'name' => 'analytics']],
        ))->toFile($this->projectRoot . '/ship.json');

        $exitCode = $this->runCommand();

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('not-a-real-service', $this->tester->getDisplay());
    }

    /**
     * ComposeFileBuilder::build() throws on the first problem, so the two checks run
     * independently and neither hides the other.
     */
    public function test_it_reports_a_bad_service_key_and_a_bad_process_name_together(): void
    {
        (new ShipConfig(
            phpVersion: '8.4',
            services: ['database' => 'not-a-real-service'],
            processes: ['Invalid Name' => 'php artisan queue:work'],
        ))->toFile($this->projectRoot . '/ship.json');

        $exitCode = $this->runCommand();

        self::assertSame(Command::FAILURE, $exitCode);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('not-a-real-service', $display);
        self::assertStringContainsString('Invalid Name', $display);
        self::assertStringContainsString('2 problems found', $display);
    }

    public function test_it_passes_on_a_minimal_valid_config_with_no_env_files(): void
    {
        (new ShipConfig(phpVersion: '8.4', services: []))->toFile($this->projectRoot . '/ship.json');

        $exitCode = $this->runCommand();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('look sound', $this->tester->getDisplay());
    }

    /**
     * MySqlService's DB_PASSWORD is required in production (see RequiredEnv).
     */
    public function test_it_reports_a_required_production_variable_missing_from_env_production(): void
    {
        (new ShipConfig(phpVersion: '8.4', services: ['database' => 'mysql']))
            ->toFile($this->projectRoot . '/ship.json');

        $exitCode = $this->runCommand();

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('DB_PASSWORD', $this->tester->getDisplay());
        self::assertStringContainsString('.env.production', $this->tester->getDisplay());
    }

    public function test_it_passes_once_the_required_production_variable_is_actually_set(): void
    {
        (new ShipConfig(phpVersion: '8.4', services: ['database' => 'mysql']))
            ->toFile($this->projectRoot . '/ship.json');
        file_put_contents(
            $this->projectRoot . '/.env.production',
            "DB_PASSWORD=a-real-secret\nDB_ROOT_PASSWORD=a-different-real-secret\n",
        );

        $exitCode = $this->runCommand();

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    public function test_it_reports_db_username_root_in_either_env_file(): void
    {
        (new ShipConfig(phpVersion: '8.4', services: ['database' => 'mysql']))
            ->toFile($this->projectRoot . '/ship.json');
        file_put_contents($this->projectRoot . '/.env', "DB_USERNAME=root\n");

        $exitCode = $this->runCommand();

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('DB_USERNAME', $this->tester->getDisplay());
        self::assertStringContainsString('(development)', $this->tester->getDisplay());
    }

    /**
     * A stale nginx upstream is a warning, not a failure.
     */
    public function test_a_stale_nginx_upstream_warns_but_does_not_fail_the_command(): void
    {
        (new ShipConfig(phpVersion: '8.4', services: [], serviceNames: ['app' => 'client-app']))
            ->toFile($this->projectRoot . '/ship.json');
        mkdir($this->projectRoot . '/ship/nginx', recursive: true);
        file_put_contents(
            $this->projectRoot . '/ship/nginx/default.conf',
            "location = /index.php {\n    set \$upstream_app app:9000;\n}\n",
        );

        $exitCode = $this->runCommand();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('client-app', $this->tester->getDisplay());
    }

    /**
     * Every problem is reported, not just the first.
     */
    public function test_it_reports_multiple_unrelated_problems_in_one_pass(): void
    {
        (new ShipConfig(
            phpVersion: '8.4',
            services: ['database' => 'mysql'],
            processes: ['Invalid Name' => 'php artisan queue:work'],
        ))->toFile($this->projectRoot . '/ship.json');
        file_put_contents($this->projectRoot . '/.env', "DB_USERNAME=root\n");

        $exitCode = $this->runCommand();

        self::assertSame(Command::FAILURE, $exitCode);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('2 problems found', $display);
        self::assertStringContainsString('is not a valid name', $display);
        self::assertStringContainsString('DB_USERNAME', $display);
    }

    private function runCommand(): int
    {
        $this->tester = new CommandTester(new ConfigTestCommand($this->projectRoot));

        return $this->tester->execute([]);
    }
}

<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Console\Commands\DbCommand;
use Ship\Runtime\ProcessRunner;
use Ship\Tests\Fixtures\DatabaseServiceWithoutShell;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DbCommandTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/ship-test-' . uniqid();
        mkdir($this->projectRoot);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->projectRoot . '/*') ?: []);
        rmdir($this->projectRoot);
    }

    public function test_it_fails_clearly_when_no_database_service_is_selected(): void
    {
        (new ShipConfig(phpVersion: '8.4', services: []))->toFile($this->projectRoot . '/ship.json');

        $tester = new CommandTester(new DbCommand($this->projectRoot, new ProcessRunner()));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('No database service is selected', $tester->getDisplay());
    }

    public function test_it_fails_clearly_when_the_selected_service_has_no_db_shell(): void
    {
        (new ShipConfig(
            phpVersion: '8.4',
            services: ['database' => 'no-shell-db'],
            extensions: [DatabaseServiceWithoutShell::class],
        ))->toFile($this->projectRoot . '/ship.json');

        $tester = new CommandTester(new DbCommand($this->projectRoot, new ProcessRunner()));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString("doesn't provide a `ship db` shell", $tester->getDisplay());
    }
}

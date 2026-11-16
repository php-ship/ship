<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Console\Commands\DoctorCommand;
use Ship\Docker\StackHealth;
use Ship\Runtime\ProcessRunner;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class StackHealthTest extends TestCase
{
    public function test_parses_line_delimited_and_array_output_the_same(): void
    {
        $rows = [
            ['Service' => 'app', 'State' => 'running'],
            ['Service' => 'pgsql', 'State' => 'running', 'Health' => 'healthy'],
        ];
        $lines = implode("\n", array_map(static fn (array $row): string => (string) json_encode($row), $rows));

        self::assertSame(StackHealth::parseRecords($lines), StackHealth::parseRecords((string) json_encode($rows)));
        self::assertSame(['app', 'pgsql'], array_keys(StackHealth::parseRecords($lines)));
        self::assertSame([], StackHealth::parseRecords(''));
    }

    public function test_healthy_stack_has_no_problems(): void
    {
        $report = StackHealth::diagnose(
            ['app' => ['build' => ['dockerfile' => 'ship/Dockerfile']], 'pgsql' => ['image' => 'postgres:17']],
            [
                'app' => ['Service' => 'app', 'State' => 'running'],
                'pgsql' => ['Service' => 'pgsql', 'State' => 'running', 'Health' => 'healthy'],
            ],
        );

        self::assertSame([], $report['problems']);
        self::assertSame(['app: built from ship/Dockerfile; running', 'pgsql: postgres:17; running; health healthy'], $report['lines']);
    }

    public function test_crashed_unhealthy_starting_and_missing_services_are_problems(): void
    {
        $report = StackHealth::diagnose(
            ['app' => [], 'pgsql' => [], 'redis' => [], 'mailpit' => [], 'webserver' => []],
            [
                'app' => ['Service' => 'app', 'State' => 'exited', 'ExitCode' => 3],
                'pgsql' => ['Service' => 'pgsql', 'State' => 'running', 'Health' => 'unhealthy'],
                'redis' => ['Service' => 'redis', 'State' => 'running', 'Health' => 'starting'],
                'mailpit' => ['Service' => 'mailpit', 'State' => 'restarting', 'ExitCode' => 1],
            ],
        );

        self::assertSame([
            'app is exited (health: none, exit code: 3)',
            'pgsql is running (health: unhealthy, exit code: ?)',
            'redis is running (health: starting)',
            'mailpit is restarting (health: none, exit code: 1)',
            'webserver has no container',
        ], $report['problems']);
    }

    public function test_doctor_asks_for_ship_up_when_the_stack_was_never_started(): void
    {
        $root = sys_get_temp_dir() . '/ship-doctor-test-' . bin2hex(random_bytes(8));
        mkdir($root, recursive: true);

        try {
            file_put_contents($root . '/ship.json', (string) json_encode(['php' => '8.4', 'services' => []]));
            $tester = new CommandTester(new DoctorCommand($root, new ProcessRunner()));

            self::assertSame(1, $tester->execute([]));
            self::assertStringContainsString('run `ship up`', $tester->getDisplay());
            self::assertStringContainsString('PHP 8.4', $tester->getDisplay());
        } finally {
            (new Filesystem())->remove($root);
        }
    }
}

<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Console\Commands\BuildCommand;
use Ship\Runtime\ProcessRunner;
use Ship\Services\MySqlService;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class BuildCommandTest extends TestCase
{
    /**
     * Regression coverage for a real bug via a seventh independent audit: `ship build` had no
     * upfront check at all for a required production variable missing from .env.production (the
     * same check ConfigTestCommand's own dry run already made) -- it surfaced as a raw `docker
     * compose build` interpolation error instead, assuming Docker was even installed to produce
     * it. No Docker involved in this test: failing this check happens before
     * ProductionBuildRunner -- and therefore Docker -- is ever reached.
     */
    public function test_it_fails_fast_on_a_missing_required_production_variable(): void
    {
        $projectRoot = sys_get_temp_dir() . '/ship-build-env-check-' . bin2hex(random_bytes(8));
        mkdir($projectRoot, recursive: true);
        (new ShipConfig(phpVersion: '8.4', services: ['database' => 'mysql']))->toFile($projectRoot . '/ship.json');

        $command = new BuildCommand($projectRoot, new ProcessRunner(), new ServiceRegistry([new MySqlService()]), []);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('DB_PASSWORD', $tester->getDisplay());
        self::assertStringContainsString('.env.production', $tester->getDisplay());

        (new Filesystem())->remove($projectRoot);
    }
}

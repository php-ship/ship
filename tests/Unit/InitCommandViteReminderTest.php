<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Console\Commands\InitCommand;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Covers InitCommand::warnAboutViteDevServerConfigIfNeeded() -- see its
 * docblock and docs/roadmap.md's former "Known gaps" entry ("ship init
 * doesn't currently print a reminder [...] it probably should") for why
 * this exists. Doesn't cover the interactive service-picker part of
 * InitCommand at all (registry is empty here on purpose, so every group
 * is skipped) -- that's still untested, tracked separately in
 * docs/roadmap.md.
 */
final class InitCommandViteReminderTest extends TestCase
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

    private function runInit(): string
    {
        $command = new InitCommand($this->projectRoot, new ServiceRegistry([]));
        $tester = new CommandTester($command);
        $tester->execute([], ['interactive' => false]);

        return $tester->getDisplay();
    }

    public function test_it_warns_when_vite_is_a_dependency_and_no_config_handles_hmr_yet(): void
    {
        file_put_contents(
            $this->projectRoot . '/package.json',
            json_encode(['devDependencies' => ['vite' => '^5.0.0']]),
        );

        $output = $this->runInit();

        self::assertStringContainsString('This project uses Vite', $output);
        self::assertStringContainsString('strictPort: true', $output);
    }

    public function test_it_stays_quiet_when_the_existing_config_already_mentions_hmr(): void
    {
        file_put_contents(
            $this->projectRoot . '/package.json',
            json_encode(['devDependencies' => ['vite' => '^5.0.0']]),
        );
        file_put_contents(
            $this->projectRoot . '/vite.config.js',
            "export default { server: { hmr: { host: 'localhost' } } };",
        );

        $output = $this->runInit();

        self::assertStringNotContainsString('This project uses Vite', $output);
    }

    public function test_it_stays_quiet_when_the_project_has_no_package_json_at_all(): void
    {
        $output = $this->runInit();

        self::assertStringNotContainsString('This project uses Vite', $output);
    }
}

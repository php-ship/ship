<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Ship\Console\Commands\InitCommand;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Exercises InitCommand::select()'s Laravel Prompts branch directly. Every other InitCommand
 * test runs without laravel/prompts installed at all, so canUseLaravelPromptsInteractiveUi()'s
 * function_exists() check is always false there and only the ChoiceQuestion fallback ever runs
 * -- that's deliberate (see that method's own docblock), so this test can't just require the real
 * package as a dev dependency: laravel/prompts autoloads its helper functions globally for the
 * whole PHP process via Composer's "files" autoloading, which would make
 * function_exists('Laravel\Prompts\select') true for every other InitCommand test too, once they
 * share a process -- breaking every one of them. #[RunInSeparateProcess] plus a hand-rolled fake
 * (tests/Fixtures/fake-laravel-prompts.php, required only here) keeps the fake fully contained to
 * this one isolated process instead.
 *
 * Skipped on Windows: canUseLaravelPromptsInteractiveUi() hard-gates on
 * PHP_OS_FAMILY !== 'Windows' in production for the same reason Laravel's own Prompts fallback
 * wiring does, and there's no way to fake the OS itself the way the required file fakes the
 * function -- CI's ubuntu-latest/macos-latest matrix legs are what actually cover this branch.
 */
final class InitCommandLaravelPromptsTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('canUseLaravelPromptsInteractiveUi() never returns true on Windows.');
        }

        $this->projectRoot = sys_get_temp_dir() . '/ship-init-prompts-test-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot, recursive: true);
    }

    protected function tearDown(): void
    {
        if (isset($this->projectRoot)) {
            (new Filesystem())->remove($this->projectRoot);
        }
    }

    #[RunInSeparateProcess]
    public function test_selecting_a_non_default_option_through_the_prompts_ui_lands_in_ship_json(): void
    {
        require __DIR__ . '/../Fixtures/fake-laravel-prompts.php';

        $command = new InitCommand($this->projectRoot, new ServiceRegistry(ServiceRegistry::defaults()));
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());

        $config = json_decode(
            file_get_contents($this->projectRoot . '/ship.json'),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame('pgsql', $config['services']['database']);
    }
}

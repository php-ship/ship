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
 * Exercises InitCommand::select()'s Laravel Prompts branch against a hand-rolled fake
 * (tests/Fixtures/fake-laravel-prompts.php) in a separate process. The real package can't be a
 * dev dependency: it autoloads its functions globally, which would switch every other InitCommand
 * test onto this branch.
 *
 * Skipped on Windows, where canUseLaravelPromptsInteractiveUi() is always false.
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

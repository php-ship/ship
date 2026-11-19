<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Ship\Console\Commands\InitCommand;
use Ship\Console\Ui;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * `ship init` through Laravel Prompts' own interface, with its terminal faked (Prompt::fake()):
 * key presses instead of typed answers. Every other InitCommand test goes through the plain
 * fallback (see Ui).
 *
 * In a separate process: falling back is sticky in Prompts, so a process that already ran a plain
 * `ship init` can't draw this one.
 */
final class InitCommandLaravelPromptsTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Prompts refuses to draw on native Windows, where ship uses the plain fallback.');
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
    public function test_arrow_keys_choose_services_and_an_invalid_version_is_asked_again(): void
    {
        $registry = new ServiceRegistry(ServiceRegistry::defaults());
        Prompt::fake([
            // The checklist: tick Database (the first kind), continue.
            Key::SPACE, Key::ENTER,
            // Database: its first service.
            Key::ENTER,
            // No additional instances.
            Key::ENTER,
            // PHP: an end-of-life branch is refused in place, then 8.4 is typed.
            ...array_fill(0, 3, Key::BACKSPACE), '7', '.', '4', Key::ENTER,
            ...array_fill(0, 3, Key::BACKSPACE), '8', '.', '4', Key::ENTER,
            // Node: the default.
            Key::ENTER,
        ]);

        $tester = new CommandTester(new InitCommand(
            $this->projectRoot,
            $registry,
            ui: static fn (InputInterface $input, OutputInterface $output): Ui => new Ui($input, $output, rich: true),
        ));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());

        /** @var array{php: string, services: array<string, string>} $config */
        $config = json_decode((string) file_get_contents($this->projectRoot . '/ship.json'), associative: true, flags: JSON_THROW_ON_ERROR);

        self::assertSame($registry->inGroup('database')[0]->key(), $config['services']['database']);
        self::assertSame(['database'], array_keys($config['services']));
        self::assertSame('8.4', $config['php']);

        self::assertStringContainsString('past end of life', $tester->getDisplay());
        self::assertStringContainsString('Run `ship up`', $tester->getDisplay());
    }

    /**
     * Ctrl+U goes back to the previous question, which starts at the answer given before.
     */
    #[RunInSeparateProcess]
    public function test_ctrl_u_goes_back_to_the_previous_question(): void
    {
        $registry = new ServiceRegistry(ServiceRegistry::defaults());

        Prompt::fake([
            // Tick Database, continue.
            Key::SPACE, Key::ENTER,
            // Database: the second service.
            Key::DOWN, Key::ENTER,
            // At "Add a second instance…?": back to Database, which starts at the second service;
            // the third instead.
            Key::CTRL_U,
            Key::DOWN, Key::ENTER,
            // No additional instances, then PHP and Node at their defaults.
            Key::ENTER, Key::ENTER, Key::ENTER,
        ]);

        $tester = new CommandTester(new InitCommand(
            $this->projectRoot,
            $registry,
            ui: static fn (InputInterface $input, OutputInterface $output): Ui => new Ui($input, $output, rich: true),
        ));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());

        /** @var array{services: array<string, string>} $config */
        $config = json_decode((string) file_get_contents($this->projectRoot . '/ship.json'), associative: true, flags: JSON_THROW_ON_ERROR);

        self::assertSame($registry->inGroup('database')[2]->key(), $config['services']['database']);
    }
}

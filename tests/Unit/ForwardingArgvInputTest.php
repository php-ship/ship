<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Console\Application;
use Ship\Console\ForwardingArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Ship's global options are only read before a forwarding command's name: what follows belongs to
 * the command it runs.
 */
final class ForwardingArgvInputTest extends TestCase
{
    private const FORWARDING = ['npm', 'pnpm', 'exec', 'artisan'];

    public function test_options_after_a_forwarding_command_are_not_ships(): void
    {
        foreach ([['pnpm', '--version'], ['pnpm', '-V'], ['artisan', 'migrate', '--help'], ['npm', 'install', '--silent'], ['exec', 'app', 'php', '-v']] as $line) {
            $input = new ForwardingArgvInput(['ship', ...$line], self::FORWARDING);

            foreach (['--version', '-V', '--help', '-h', '--silent', '--quiet', '-q', '--no-interaction', '-n', '-v'] as $option) {
                self::assertFalse($input->hasParameterOption($option, true), implode(' ', $line) . ": {$option}");
            }
        }
    }

    public function test_options_before_the_command_name_are_still_ships(): void
    {
        $input = new ForwardingArgvInput(['ship', '-q', 'npm', 'install', '--silent'], self::FORWARDING);

        self::assertTrue($input->hasParameterOption('-q', true));
        self::assertFalse($input->hasParameterOption('--silent', true));
    }

    public function test_other_commands_keep_every_option(): void
    {
        $input = new ForwardingArgvInput(['ship', 'up', '--quiet'], self::FORWARDING);

        self::assertTrue($input->hasParameterOption('--quiet', true));
    }

    /**
     * The forwarded tokens themselves are untouched.
     */
    public function test_the_forwarded_tokens_are_untouched(): void
    {
        $input = new ForwardingArgvInput(['ship', 'pnpm', '--version'], self::FORWARDING);

        self::assertSame(['--version'], $input->getRawTokens(strip: true));
    }

    /**
     * Through the real Application: `ship --version` is ship's, `ship npm --version` isn't. It
     * reaches `ship npm`, whose guard stops it here (a Yarn project), before anything runs.
     */
    public function test_the_application_reads_the_command_line_this_way(): void
    {
        $projectRoot = sys_get_temp_dir() . '/ship-forwarding-' . bin2hex(random_bytes(8));
        mkdir($projectRoot);
        file_put_contents($projectRoot . '/package.json', '{"name":"app"}');
        touch($projectRoot . '/yarn.lock');
        $argv = $_SERVER['argv'] ?? [];

        try {
            $application = new Application($projectRoot);
            $application->setAutoExit(false);
            $application->setCatchExceptions(true);

            $_SERVER['argv'] = ['ship', '--version'];
            $output = new BufferedOutput();
            $application->run(null, $output);
            self::assertStringStartsWith('ship ', $output->fetch());

            $_SERVER['argv'] = ['ship', 'npm', '--version'];
            $output = new BufferedOutput();
            $application->run(null, $output);
            $display = $output->fetch();
            self::assertStringNotContainsString($application->getLongVersion(), $display);
            self::assertStringContainsString('This project uses yarn', $display);
        } finally {
            $_SERVER['argv'] = $argv;
            (new Filesystem())->remove($projectRoot);
        }
    }
}

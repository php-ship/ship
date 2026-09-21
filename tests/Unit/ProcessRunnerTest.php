<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Runtime\ProcessRunner;

/**
 * Runs real, trivial, cross-platform processes rather than mocking Symfony's Process -- the
 * interesting behavior here (TTY-vs-not stdin wiring, output streaming) is exactly the kind of
 * thing a mock would assert "was called correctly" without ever proving actually works; a real
 * php -r invocation costs nothing and proves it for real, the same standard the rest of this
 * project holds Docker-touching code to.
 */
final class ProcessRunnerTest extends TestCase
{
    public function test_run_quiet_captures_and_trims_output(): void
    {
        $output = (new ProcessRunner())->runQuiet(['php', '-r', 'echo "  hello  ";']);

        self::assertSame('hello', $output);
    }

    public function test_run_quiet_returns_an_empty_string_for_a_binary_that_does_not_exist(): void
    {
        $output = (new ProcessRunner())->runQuiet(['ship-runner-test-definitely-not-a-real-binary']);

        self::assertSame('', $output);
    }

    public function test_run_interactive_propagates_the_real_exit_code(): void
    {
        $exitCode = (new ProcessRunner())->runInteractive(['php', '-r', 'exit(0);']);

        self::assertSame(0, $exitCode);
    }

    public function test_run_interactive_propagates_a_non_zero_exit_code(): void
    {
        $exitCode = (new ProcessRunner())->runInteractive(['php', '-r', 'exit(7);']);

        self::assertSame(7, $exitCode);
    }

    public function test_run_quiet_honors_the_given_working_directory(): void
    {
        $cwd = sys_get_temp_dir();

        $output = (new ProcessRunner())->runQuiet(['php', '-r', 'echo getcwd();'], $cwd);

        self::assertSame(realpath($cwd), realpath($output));
    }
}

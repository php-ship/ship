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

    /**
     * Regression coverage for a real bug found via an independent audit: a command that actually
     * hits the timeout used to throw ProcessTimedOutException straight out of runQuiet(),
     * uncaught -- crashing the whole `ship` process with a raw stack trace. A genuinely slow
     * command (sleeps longer than a deliberately tiny timeout) now degrades to the same empty
     * string every other runQuiet() failure already produces, instead of throwing.
     */
    public function test_run_quiet_returns_an_empty_string_instead_of_throwing_on_timeout(): void
    {
        $output = (new ProcessRunner())->runQuiet(['php', '-r', 'sleep(5);'], timeoutSeconds: 0.2);

        self::assertSame('', $output);
    }

    public function test_run_quiet_accepts_a_longer_timeout_for_a_slow_command(): void
    {
        $output = (new ProcessRunner())->runQuiet(['php', '-r', 'usleep(100000); echo "done";'], timeoutSeconds: 5);

        self::assertSame('done', $output);
    }

    /**
     * Regression coverage for a real bug found via a seventh independent audit: MutagenSync's own
     * `mutagen sync create` call discarded its result entirely, so a real failure (confirmed live
     * against the actual `mutagen` binary: a nonexistent container gives exit 1 and a specific
     * "container does not exist" stderr message) fell straight through to a 120-second polling
     * loop that was never going to succeed, instead of failing fast with the real cause.
     */
    public function test_run_quiet_with_result_captures_exit_code_and_both_streams(): void
    {
        $result = (new ProcessRunner())->runQuietWithResult([
            'php', '-r', 'echo "out"; fwrite(STDERR, "err"); exit(3);',
        ]);

        self::assertSame(3, $result['exitCode']);
        self::assertSame('out', $result['output']);
        self::assertSame('err', $result['errorOutput']);
    }

    public function test_run_quiet_with_result_reports_a_negative_exit_code_on_timeout_instead_of_throwing(): void
    {
        $result = (new ProcessRunner())->runQuietWithResult(['php', '-r', 'sleep(5);'], timeoutSeconds: 0.2);

        self::assertSame(-1, $result['exitCode']);
        self::assertSame('', $result['output']);
        self::assertSame('', $result['errorOutput']);
    }
}

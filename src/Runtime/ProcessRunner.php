<?php

declare(strict_types=1);

namespace Ship\Runtime;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Every command routes through here -- the trick that removes the WSL requirement, outright: Symfony's
 * Process spawns fine on Windows, macOS, and Linux -- no shell-specific code, unlike a bash script.
 */
final class ProcessRunner
{
    /**
     * Runs a command with stdio attached to ours, streaming output live; used for anything interactive.
     *
     * $env, when given, is merged over the inherited environment (Symfony's own Process behavior) --
     * used by ProductionBuildRunner to make `.env.production` available to `docker compose build`'s
     * own `${VAR}` substitution, without changing anything for every other caller that omits it.
     *
     * @param list<string> $command
     * @param ?array<string, string> $env
     */
    public function runInteractive(array $command, ?string $cwd = null, ?array $env = null): int
    {
        $process = new Process($command, $cwd, $env, timeout: null);
        $tty = Process::isTtySupported();
        $process->setTty($tty);

        // TTY mode attaches the child straight to the real terminal device, stdin included. Without it
        // (Windows has no Symfony TTY support), Process otherwise leaves the child's stdin disconnected,
        // so anything reading from it -- a mysql/psql/bash prompt, `artisan tinker` -- hits EOF at once
        // instead of receiving what the user types.
        if (!$tty) {
            $process->setInput(STDIN);
        }

        return $process->run(function (string $type, string $buffer): void {
            fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
        });
    }

    /**
     * Runs a command and captures its output instead of streaming it, e.g. `docker compose version`.
     *
     * $timeoutSeconds is overridable (default 30) for a caller expecting a genuinely slower
     * command -- `mutagen sync create` scanning a large project tree before it even returns, say
     * -- without raising the budget for every other caller too.
     *
     * A command that actually hits the timeout degrades to the same empty-string result as any
     * other failure, rather than letting ProcessTimedOutException escape uncaught and crash the
     * whole `ship` process with a raw stack trace. Every existing caller already treats an empty
     * result as "the thing isn't there"/"that didn't work" (see MutagenSync's own docblock), so a
     * timeout is just that exact same signal, not a new failure mode none of them were ever
     * written to expect.
     *
     * @param list<string> $command
     */
    public function runQuiet(array $command, ?string $cwd = null, float $timeoutSeconds = 30): string
    {
        $process = new Process($command, $cwd, timeout: $timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return '';
        }

        return trim($process->getOutput());
    }

    /**
     * Like runQuiet(), but for a caller that actually needs to tell "succeeded with no output"
     * apart from "failed", or show *why* a failure happened -- runQuiet()'s own "empty string"
     * convention can't distinguish either, which is exactly right for every caller that only ever
     * treats "the thing isn't there" and "that didn't work" as the same signal, but wrong for one
     * (MutagenSync's own `mutagen sync create`) that needs to fail fast on a real error instead of
     * silently falling through to a timeout loop that was never going to succeed.
     *
     * @param list<string> $command
     * @return array{exitCode: int, output: string, errorOutput: string}
     */
    public function runQuietWithResult(array $command, ?string $cwd = null, float $timeoutSeconds = 30): array
    {
        $process = new Process($command, $cwd, timeout: $timeoutSeconds);

        try {
            $exitCode = $process->run();
        } catch (ProcessTimedOutException) {
            return ['exitCode' => -1, 'output' => '', 'errorOutput' => ''];
        }

        return [
            'exitCode' => $exitCode,
            'output' => trim($process->getOutput()),
            'errorOutput' => trim($process->getErrorOutput()),
        ];
    }
}

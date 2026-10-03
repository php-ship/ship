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
     * A real bug found via an independent audit: a command that actually hits the timeout used to
     * throw ProcessTimedOutException straight out of here, uncaught -- crashing the whole `ship`
     * process with a raw stack trace instead of a friendly error. Every existing caller already
     * treats an empty result as "the thing isn't there"/"that didn't work" (see MutagenSync's own
     * docblock), so a timeout now degrades to that exact same signal instead of a new failure mode
     * none of them were ever written to expect.
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
}

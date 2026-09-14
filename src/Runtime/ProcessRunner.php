<?php

declare(strict_types=1);

namespace Ship\Runtime;

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
     * @param list<string> $command
     */
    public function runInteractive(array $command, ?string $cwd = null): int
    {
        $process = new Process($command, $cwd, timeout: null);
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
     * @param list<string> $command
     */
    public function runQuiet(array $command, ?string $cwd = null): string
    {
        $process = new Process($command, $cwd, timeout: 30);
        $process->run();

        return trim($process->getOutput());
    }
}

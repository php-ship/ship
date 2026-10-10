<?php

declare(strict_types=1);

namespace Ship\Runtime;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Every external command runs through here. Symfony's Process spawns on Windows, macOS and Linux
 * alike, which is what lets `ship` work without a shell script or WSL.
 */
final class ProcessRunner
{
    /**
     * Runs a command with stdio attached to ours, streaming output live.
     *
     * $env is merged over the inherited environment.
     *
     * @param list<string> $command
     * @param ?array<string, string> $env
     */
    public function runInteractive(array $command, ?string $cwd = null, ?array $env = null): int
    {
        $process = new Process($command, $cwd, $env, timeout: null);
        $tty = Process::isTtySupported();
        $process->setTty($tty);

        // Without TTY mode (unsupported on Windows) the child's stdin would be disconnected, and
        // an interactive prompt (mysql, psql, `artisan tinker`) would hit EOF at once.
        if (!$tty) {
            $process->setInput(STDIN);
        }

        return $process->run(function (string $type, string $buffer): void {
            fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
        });
    }

    /**
     * Runs a command and returns its captured output, or an empty string on any failure,
     * including a timeout. Callers treat an empty result as "not there" or "didn't work".
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
     * Like runQuiet(), for a caller that needs the exit code and stderr to tell "no output" from
     * "failed" and to report why.
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

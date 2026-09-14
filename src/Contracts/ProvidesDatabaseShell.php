<?php

declare(strict_types=1);

namespace Ship\Contracts;

/**
 * Optional add-on for a database ServiceDefinition, letting `ship db` open an interactive
 * client shell in the right container with the right command -- implementations differ per
 * engine (psql vs mysql), so this isn't part of the base ServiceDefinition contract itself.
 */
interface ProvidesDatabaseShell
{
    /**
     * @return array{service: string, command: list<string>} the compose service to exec into,
     *         and the command to run inside it
     */
    public function databaseShellCommand(): array;
}

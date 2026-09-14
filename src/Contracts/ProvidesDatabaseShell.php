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
     * Same $instanceName meaning as ServiceDefinition::composeFragment() -- null targets the default
     * database (ship.json's `services.database`), a name targets that named additional instance, so
     * the returned compose service matches whichever one `ship db [name]` was asked to open.
     *
     * @return array{service: string, command: list<string>} the compose service to exec into,
     *         and the command to run inside it
     */
    public function databaseShellCommand(?string $instanceName = null): array;
}

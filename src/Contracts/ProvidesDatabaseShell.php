<?php

declare(strict_types=1);

namespace Ship\Contracts;

/**
 * Optional add-on for a database ServiceDefinition, letting `ship db` open the engine's
 * interactive client shell (psql, mysql) in the right container.
 */
interface ProvidesDatabaseShell
{
    /**
     * $instanceName has the same meaning as in ServiceDefinition::composeFragment(): null for the
     * default database, a name for that additional instance.
     *
     * @return array{service: string, command: list<string>} the compose service to exec into,
     *         and the command to run inside it
     */
    public function databaseShellCommand(?string $instanceName = null): array;
}

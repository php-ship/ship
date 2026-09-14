<?php

declare(strict_types=1);

namespace Ship\Tests\Fixtures;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;

/**
 * A database ServiceDefinition that doesn't implement ProvidesDatabaseShell, for testing
 * `ship db`'s behavior against a service with no shell support.
 */
final class DatabaseServiceWithoutShell implements ServiceDefinition
{
    public function key(): string
    {
        return 'no-shell-db';
    }

    public function label(): string
    {
        return 'No-shell test database';
    }

    public function group(): string
    {
        return 'database';
    }

    public function composeFragment(ShipEnvironment $environment): array
    {
        return [];
    }

    public function environmentVariables(): array
    {
        return [];
    }

    public function removes(): array
    {
        return [];
    }
}

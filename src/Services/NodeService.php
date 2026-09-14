<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;

/**
 * Deliberately shallow -- `ship npm` works regardless of a selection, as Node installs unconditionally
 * in the base image; most PHP projects need it anyway. What this is actually for: choosing the Node
 * major version, which needs the same build-arg plumbing OCTANE_RUNTIME uses, still not wired up.
 */
final class NodeService implements ServiceDefinition
{
    public function key(): string
    {
        return 'node';
    }

    public function label(): string
    {
        return 'Node.js / npm';
    }

    public function group(): string
    {
        return 'frontend';
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

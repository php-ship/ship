<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;

/**
 * Deliberately shallow -- `ship npm` works regardless of a selection, as Node installs unconditionally
 * in the base image; most PHP projects need it anyway. This group exists so `ship init` prompts for
 * it, but the Node version itself is a top-level ShipConfig field (like phpVersion), not something
 * this class carries -- ComposeFileBuilder backfills NODE_VERSION the same way it does PHP_VERSION.
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

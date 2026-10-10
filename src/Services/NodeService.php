<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;

/**
 * Deliberately shallow: Node is installed in the base image regardless, so `ship npm` always
 * works. This group exists so `ship init` prompts for it. The Node version is a top-level
 * ShipConfig field, backfilled as a build arg by ComposeFileBuilder.
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

    public function composeFragment(ShipEnvironment $environment, ?string $instanceName = null): array
    {
        return [];
    }

    public function environmentVariables(?string $instanceName = null): array
    {
        return [];
    }

    public function removes(): array
    {
        return [];
    }
}

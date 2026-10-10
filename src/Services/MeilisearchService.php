<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\RequiredEnv;

final class MeilisearchService implements ServiceDefinition
{
    use SupportsNamedInstances;

    public function key(): string
    {
        return 'meilisearch';
    }

    public function label(): string
    {
        return 'Meilisearch';
    }

    public function group(): string
    {
        return 'search';
    }

    public function composeFragment(ShipEnvironment $environment, ?string $instanceName = null): array
    {
        $name = $this->composeServiceName($instanceName);
        $prefix = $this->envPrefix($instanceName);

        return [
            $name => [
                'image' => 'getmeili/meilisearch:v1.53',
                'environment' => [
                    // A default in dev, required in production (see RequiredEnv).
                    'MEILI_MASTER_KEY' => RequiredEnv::expr("{$prefix}MEILISEARCH_KEY", 'shipsearchkey', $environment),
                    'MEILI_NO_ANALYTICS' => 'true',
                ],
                // Persisted in both environments, like every stateful service.
                'volumes' => ["ship-{$name}-data:/meili_data"],
            ],
        ];
    }

    public function environmentVariables(?string $instanceName = null): array
    {
        $name = $this->composeServiceName($instanceName);
        $prefix = $this->envPrefix($instanceName);

        return [
            // Scout doesn't use Meilisearch unless SCOUT_DRIVER says so. Only the default
            // instance sets it; a named instance doesn't change the app's default driver.
            ...($instanceName === null ? ['SCOUT_DRIVER' => 'meilisearch'] : []),
            "{$prefix}MEILISEARCH_HOST" => "http://{$name}:7700",
            "{$prefix}MEILISEARCH_KEY" => "\${{$prefix}MEILISEARCH_KEY:-shipsearchkey}",
        ];
    }

    public function removes(): array
    {
        return [];
    }
}

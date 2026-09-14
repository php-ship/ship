<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;

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
                    'MEILI_MASTER_KEY' => "\${{$prefix}MEILISEARCH_KEY:-shipsearchkey}",
                    'MEILI_NO_ANALYTICS' => 'true',
                ],
                'volumes' => $environment->isDevelopment()
                    ? ["ship-{$name}-data:/meili_data"]
                    : [],
            ],
        ];
    }

    public function environmentVariables(?string $instanceName = null): array
    {
        $name = $this->composeServiceName($instanceName);
        $prefix = $this->envPrefix($instanceName);

        return [
            // Laravel Scout doesn't default to Meilisearch just because a
            // Meilisearch container exists -- SCOUT_DRIVER has to say so
            // explicitly, or Scout silently keeps using its own default.
            // (Scout itself and meilisearch/meilisearch-php are still a
            // `composer require` the consuming app has to do -- that's an
            // app-level dependency choice, not something this Docker layer
            // can or should force.) Only the default instance sets it -- a
            // named instance adds a second reachable Meilisearch, it doesn't
            // change which one Scout uses by default.
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

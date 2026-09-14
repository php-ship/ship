<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;

final class MeilisearchService implements ServiceDefinition
{
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

    public function composeFragment(ShipEnvironment $environment): array
    {
        return [
            'meilisearch' => [
                'image' => 'getmeili/meilisearch:v1.53',
                'environment' => [
                    'MEILI_MASTER_KEY' => '${MEILISEARCH_KEY:-shipsearchkey}',
                    'MEILI_NO_ANALYTICS' => 'true',
                ],
                'volumes' => $environment->isDevelopment()
                    ? ['ship-meilisearch-data:/meili_data']
                    : [],
            ],
        ];
    }

    public function environmentVariables(): array
    {
        return [
            // Laravel Scout doesn't default to Meilisearch just because a
            // Meilisearch container exists -- SCOUT_DRIVER has to say so
            // explicitly, or Scout silently keeps using its own default.
            // (Scout itself and meilisearch/meilisearch-php are still a
            // `composer require` the consuming app has to do -- that's an
            // app-level dependency choice, not something this Docker layer
            // can or should force.)
            'SCOUT_DRIVER' => 'meilisearch',
            'MEILISEARCH_HOST' => 'http://meilisearch:7700',
            'MEILISEARCH_KEY' => 'shipsearchkey',
        ];
    }

    public function removes(): array
    {
        return [];
    }
}

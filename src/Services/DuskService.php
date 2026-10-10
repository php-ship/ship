<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;

final class DuskService implements ServiceDefinition
{
    public function key(): string
    {
        return 'dusk';
    }

    public function label(): string
    {
        return 'Dusk (Selenium/Chrome)';
    }

    public function group(): string
    {
        return 'testing';
    }

    // Only one browser testing driver makes sense per project, so $instanceName is unused.

    public function composeFragment(ShipEnvironment $environment, ?string $instanceName = null): array
    {
        // Dev/test tooling: an empty fragment keeps Selenium out of production (see
        // ComposeFileBuilder::applyService()).
        if (!$environment->isDevelopment()) {
            return [];
        }

        return [
            'selenium' => [
                // Pinned to an exact version: a Chrome upgrade can change test behaviour, so
                // bump it deliberately.
                'image' => 'selenium/standalone-chrome:4.49.0',
                'shm_size' => '2gb',
            ],
        ];
    }

    public function environmentVariables(?string $instanceName = null): array
    {
        return [
            // APP_URL is set by ComposeFileBuilder, which knows whether "app" or "webserver" is
            // the HTTP entrypoint.
            'DUSK_DRIVER_URL' => 'http://selenium:4444/wd/hub',
        ];
    }

    public function removes(): array
    {
        return [];
    }
}

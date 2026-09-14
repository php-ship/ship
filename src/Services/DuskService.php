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

    // Only one browser testing driver ever makes sense per project, so $instanceName -- always
    // null here in practice, nothing ever offers a second one to select -- is unused.

    public function composeFragment(ShipEnvironment $environment, ?string $instanceName = null): array
    {
        return [
            'selenium' => [
                'image' => 'selenium/standalone-chrome:4',
                'shm_size' => '2gb',
            ],
        ];
    }

    public function environmentVariables(?string $instanceName = null): array
    {
        return [
            // APP_URL isn't set here; ComposeFileBuilder computes it once, generically, for every
            // configuration, since whether "app" or "webserver" is the real HTTP entrypoint depends
            // on another service's selection, which this class alone has no visibility into.
            'DUSK_DRIVER_URL' => 'http://selenium:4444/wd/hub',
        ];
    }

    public function removes(): array
    {
        return [];
    }
}

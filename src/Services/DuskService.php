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
        // Dev/test tooling, not infrastructure -- a browser test suite never runs in production,
        // so Selenium has no business building or starting there either. An empty fragment is
        // this class's signal that it contributes nothing in production at all -- see
        // ComposeFileBuilder::applyService() for how that's honored generically.
        if (!$environment->isDevelopment()) {
            return [];
        }

        return [
            'selenium' => [
                // Pinned to a specific version, not the floating "4" major tag -- an unpinned
                // build input means two `ship up` runs weeks apart can silently pull different
                // Selenium/Chrome builds with no corresponding change to ship.json or this file,
                // exactly the kind of drift a Dusk test suite is sensitive to (a Chrome upgrade
                // changing a selector's behavior, say). Bump this deliberately when a newer
                // Selenium image is wanted.
                'image' => 'selenium/standalone-chrome:4.49.0',
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

<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\NeedsAppUrl;
use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;

/**
 * Cypress, with its browsers, in a container of its own with the project mounted at /e2e. Cypress
 * runs tests in the same process as its browsers, so there is no server to connect to from "app":
 * the container idles and tests run in it (`ship exec cypress cypress run`).
 *
 * Development only, like Dusk.
 */
final class CypressService implements ServiceDefinition, NeedsAppUrl
{
    public function key(): string
    {
        return 'cypress';
    }

    public function label(): string
    {
        return 'Cypress';
    }

    public function group(): string
    {
        return 'testing';
    }

    // Only one browser testing driver makes sense per project, so $instanceName is unused.

    public function composeFragment(ShipEnvironment $environment, ?string $instanceName = null): array
    {
        if (!$environment->isDevelopment()) {
            return [];
        }

        return [
            'cypress' => [
                'image' => 'cypress/included:16.3.0',
                // The image's own entrypoint runs the suite once and exits, which the restart
                // policy would turn into an endless loop; idle instead and run on demand.
                'entrypoint' => ['sleep', 'infinity'],
                // Stops promptly (sleep ignores SIGTERM as PID 1) and reaps browser processes.
                'init' => true,
                'working_dir' => '/e2e',
                // Replaced by the Mutagen-synced volume under SHIP_MUTAGEN (see ComposeFileBuilder).
                'volumes' => ['.:/e2e'],
                // Chrome uses /dev/shm heavily; Docker's 64 MB default crashes pages.
                'shm_size' => '2gb',
            ],
        ];
    }

    public function environmentVariables(?string $instanceName = null): array
    {
        return [
            // `npm install` runs in "app", whose Alpine base Cypress's binary doesn't support; the
            // cypress container brings its own. The npm package itself still installs.
            'CYPRESS_INSTALL_BINARY' => '0',
        ];
    }

    public function removes(): array
    {
        return [];
    }

    /**
     * Cypress applies CYPRESS_BASE_URL as its baseUrl by itself, so tests need no config change.
     */
    public function appUrlVariables(): array
    {
        return ['cypress' => 'CYPRESS_BASE_URL'];
    }
}

<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\NeedsAppUrl;
use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;

/**
 * Playwright's browser server: Chromium, Firefox and WebKit in their own container, which the
 * Playwright test runner in "app" connects to through PW_TEST_CONNECT_WS_ENDPOINT (`ship npm exec
 * playwright test`). The app image needs no browsers, which Playwright doesn't support on its
 * Alpine base anyway. The project's @playwright/test must be the same minor version as this server.
 *
 * Development only, like Dusk.
 */
final class PlaywrightService implements ServiceDefinition, NeedsAppUrl
{
    public function key(): string
    {
        return 'playwright';
    }

    public function label(): string
    {
        return 'Playwright (browser server)';
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

        $fragment = [
            // The image ships the browsers, not the playwright package; its version below is read
            // from this tag, so a version bump here moves both.
            'image' => 'mcr.microsoft.com/playwright:v1.65.1-noble',
            'user' => 'pwuser',
            'working_dir' => '/home/pwuser',
            // Reaps the browsers' child processes, which the server doesn't do itself.
            'init' => true,
            // Chromium uses /dev/shm heavily; Docker's 64 MB default crashes pages.
            'shm_size' => '2gb',
            'healthcheck' => [
                // Accepting TCP connections means the server is up. Node is in the image; curl isn't.
                'test' => ['CMD', 'node', '-e', "require('net').connect(3000, '127.0.0.1').on('connect', () => process.exit(0)).on('error', () => process.exit(1))"],
                'interval' => '5s',
                'timeout' => '5s',
                'retries' => 12,
                // npx downloads the playwright package when the container is created.
                'start_period' => '60s',
            ],
        ];

        $version = preg_replace('/^.*:v([0-9.]+)-.*$/', '$1', $fragment['image']);
        $fragment['command'] = ['npx', '-y', "playwright@{$version}", 'run-server', '--host', '0.0.0.0', '--port', '3000'];

        return ['playwright' => $fragment];
    }

    public function environmentVariables(?string $instanceName = null): array
    {
        return [
            // Read by the Playwright test runner: every browser is launched on the server.
            'PW_TEST_CONNECT_WS_ENDPOINT' => 'ws://playwright:3000/',
        ];
    }

    public function removes(): array
    {
        return [];
    }

    /**
     * Playwright has no standard variable for its baseURL, so playwright.config uses this one
     * (`use: { baseURL: process.env.PLAYWRIGHT_BASE_URL }`). The project's APP_URL is left alone.
     */
    public function appUrlVariables(): array
    {
        return ['app' => 'PLAYWRIGHT_BASE_URL'];
    }
}

<?php

declare(strict_types=1);

namespace Ship\Frameworks;

use Ship\Contracts\DescribesRelease;
use Ship\Contracts\FrameworkAdapter;
use Ship\Contracts\SuggestsProcesses;
use Ship\Support\ComposerPackages;

/**
 * Reference FrameworkAdapter implementation.
 */
final class LaravelAdapter implements FrameworkAdapter, DescribesRelease, SuggestsProcesses
{
    public function detect(string $projectRoot): bool
    {
        return is_file($projectRoot . '/artisan')
            && is_file($projectRoot . '/composer.json');
    }

    public function consoleCommands(): array
    {
        return [
            'artisan' => 'php artisan',
        ];
    }

    /**
     * `optimize` caches config/routes/views using the real env vars, which is why it runs at
     * container boot rather than build time.
     *
     * @return list<string>
     */
    public function releaseCommands(): array
    {
        return ['php artisan optimize'];
    }

    public function frameworkName(): string
    {
        return 'laravel';
    }

    /**
     * Laravel 11+ registers its health route in bootstrap/app.php (`->withRouting(health: '/up')`).
     * The path is read from there: it can be changed, or removed.
     */
    public function healthPath(string $projectRoot): ?string
    {
        $bootstrap = @file_get_contents($projectRoot . '/bootstrap/app.php');

        if ($bootstrap === false || preg_match('/\bhealth\s*:\s*([\'"])(\/[^\'"]*)\1/', $bootstrap, $match) !== 1) {
            return null;
        }

        return $match[2];
    }

    public function siteUrlSetting(): string
    {
        return 'APP_URL';
    }

    /**
     * Without APP_KEY, encryption fails, and with it every request that touches the session or a
     * cookie.
     */
    public function requiredEnv(string $projectRoot): array
    {
        return ['APP_KEY'];
    }

    /**
     * Horizon when laravel/horizon is installed (checked with `horizon:status`, which exits 0 only
     * while it runs), a plain queue worker otherwise; the scheduler; and Reverb when laravel/reverb
     * is installed but ship's own Reverb service isn't selected (a project that reads Reverb's
     * address at runtime runs it this way). Reverb binds 0.0.0.0:8080 like its own defaults, and
     * answers /up in the versions that define that route.
     */
    public function suggestedProcesses(string $projectRoot, array $services): array
    {
        $processes = ComposerPackages::has($projectRoot, 'laravel/horizon')
            ? ['horizon' => [
                'label' => 'Horizon: runs queued jobs and its dashboard',
                'command' => 'php artisan horizon',
                'healthcheck' => 'php artisan horizon:status',
            ]]
            : ['queue' => [
                'label' => 'Queue worker: runs queued jobs (queue:work)',
                'command' => 'php artisan queue:work',
            ]];

        $processes['scheduler'] = [
            'label' => 'Scheduler: runs scheduled tasks every minute (schedule:work)',
            'command' => 'php artisan schedule:work',
        ];

        if (ComposerPackages::has($projectRoot, 'laravel/reverb') && ($services['broadcasting'] ?? null) !== 'reverb') {
            $factory = (string) @file_get_contents($projectRoot . '/vendor/laravel/reverb/src/Servers/Reverb/Factory.php');
            $processes['reverb'] = [
                'label' => 'Reverb: the WebSocket server, reached through VITE_REVERB_HOST',
                'command' => 'php artisan reverb:start --host=0.0.0.0 --port=8080',
                'http' => [
                    'port' => 8080,
                    'role' => 'websocket',
                    'health' => str_contains($factory, "'/up'") ? '/up' : null,
                    'urlSetting' => 'VITE_REVERB_HOST',
                ],
            ];
        }

        return $processes;
    }
}

<?php

declare(strict_types=1);

namespace Ship\Frameworks;

use Ship\Contracts\DescribesRelease;
use Ship\Contracts\FrameworkAdapter;

/**
 * Reference FrameworkAdapter implementation.
 */
final class LaravelAdapter implements FrameworkAdapter, DescribesRelease
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
}

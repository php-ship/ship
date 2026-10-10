<?php

declare(strict_types=1);

namespace Ship\Frameworks;

use Ship\Contracts\FrameworkAdapter;

/**
 * Reference FrameworkAdapter implementation.
 */
final class LaravelAdapter implements FrameworkAdapter
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
}

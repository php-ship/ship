<?php

declare(strict_types=1);

namespace Ship\Frameworks;

use Ship\Contracts\FrameworkAdapter;

/**
 * Reference FrameworkAdapter implementation; only talks to Ship through that contract, nothing else.
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
     * `optimize` caches config/routes/views (and anything else the installed Laravel version adds to
     * it) in one call, using whatever real env vars are present -- which is why this runs at container
     * boot, not build time.
     *
     * @return list<string>
     */
    public function releaseCommands(): array
    {
        return ['php artisan optimize'];
    }
}

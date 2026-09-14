<?php

declare(strict_types=1);

namespace Ship\Frameworks;

use Ship\Contracts\FrameworkAdapter;

/**
 * Symfony's `bin/console`/`cache:clear` equivalents of LaravelAdapter's `artisan`/`optimize`.
 */
final class SymfonyAdapter implements FrameworkAdapter
{
    public function detect(string $projectRoot): bool
    {
        return is_file($projectRoot . '/bin/console')
            && is_file($projectRoot . '/composer.json');
    }

    public function consoleCommands(): array
    {
        return [
            'console' => 'php bin/console',
        ];
    }

    /**
     * Symfony's optimize equivalent: cache:clear recompiles the container and warms routes/cache in one
     * step (unless --no-warmup is passed). No --env/--no-debug flags -- relies on APP_ENV/APP_DEBUG as
     * real env vars at boot, so it reflects whatever the orchestrator actually injected.
     *
     * @return list<string>
     */
    public function releaseCommands(): array
    {
        return ['php bin/console cache:clear'];
    }
}

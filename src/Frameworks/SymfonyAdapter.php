<?php

declare(strict_types=1);

namespace Ship\Frameworks;

use Ship\Contracts\DescribesRelease;
use Ship\Contracts\FrameworkAdapter;

/**
 * Symfony's `bin/console`/`cache:clear` equivalents of LaravelAdapter's `artisan`/`optimize`.
 */
final class SymfonyAdapter implements FrameworkAdapter, DescribesRelease
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
     * cache:clear recompiles the container and warms the cache. No --env/--no-debug flags: it
     * relies on APP_ENV/APP_DEBUG being set as real env vars at boot.
     *
     * @return list<string>
     */
    public function releaseCommands(): array
    {
        return ['php bin/console cache:clear'];
    }

    public function frameworkName(): string
    {
        return 'symfony';
    }

    /**
     * Symfony has no built-in health route.
     */
    public function healthPath(string $projectRoot): ?string
    {
        return null;
    }

    /**
     * Symfony reads its settings from the .env files in the image as well as the environment, so
     * nothing is required from .env.production specifically.
     */
    public function requiredEnv(string $projectRoot): array
    {
        return [];
    }
}

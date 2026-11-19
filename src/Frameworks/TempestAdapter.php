<?php

declare(strict_types=1);

namespace Ship\Frameworks;

use Ship\Contracts\DescribesRelease;
use Ship\Contracts\FrameworkAdapter;
use Ship\Contracts\RequiresPhpVersion;

/**
 * Tempest (tempestphp.com): its `tempest` console, and the production steps its deployment guide
 * gives, run at boot like Laravel's `artisan optimize`.
 *
 * Tempest reads its settings with getenv(), loading .env only for what the environment doesn't
 * set, so the release's settings reach it as they are. It keeps its caches, discovery and logs
 * under .tempest/, which the entrypoint hands to www-data after these commands (see
 * EntrypointScriptBuilder).
 */
final class TempestAdapter implements FrameworkAdapter, DescribesRelease, RequiresPhpVersion
{
    /**
     * Tempest 3, the current major version, requires PHP ^8.5.
     */
    private const FALLBACK_MINIMUM_PHP = '8.5';

    /**
     * The `tempest` console file alone could be anything; the composer.json requirement makes it
     * Tempest's.
     */
    public function detect(string $projectRoot): bool
    {
        if (!is_file($projectRoot . '/tempest')) {
            return false;
        }

        $composer = json_decode((string) @file_get_contents($projectRoot . '/composer.json'), associative: true);

        return is_array($composer)
            && (isset($composer['require']['tempest/framework']) || isset($composer['require-dev']['tempest/framework']));
    }

    public function consoleCommands(): array
    {
        return ['tempest' => 'php tempest'];
    }

    /**
     * The build copies the project as it is on disk, .tempest/ included, so a cache left by local
     * development could be baked into the image: every internal cache (config, views, icons,
     * discovery) is cleared first. discovery:generate then builds the discovery cache, which is
     * full when ENVIRONMENT is production (DiscoveryCacheStrategy). --force skips the confirmation
     * Tempest asks for in production.
     *
     * @return list<string>
     */
    public function releaseCommands(): array
    {
        return [
            'php tempest cache:clear --internal --all --force',
            'php tempest discovery:generate',
        ];
    }

    public function frameworkName(): string
    {
        return 'tempest';
    }

    /**
     * Tempest has no built-in health route.
     */
    public function healthPath(string $projectRoot): ?string
    {
        return null;
    }

    public function siteUrlSetting(): string
    {
        return 'BASE_URI';
    }

    /**
     * SIGNING_KEY: encryption and signed URLs fail without it (`php tempest key:generate` makes
     * one). ENVIRONMENT: without it Tempest runs as "local", development mode, even in production.
     */
    public function requiredEnv(string $projectRoot): array
    {
        return ['ENVIRONMENT', 'SIGNING_KEY'];
    }

    /**
     * The PHP branch the installed tempest/framework requires (composer.lock), e.g. "^8.5" gives
     * "8.5"; without a lock file, what Tempest 3 requires.
     */
    public function minimumPhpVersion(string $projectRoot): string
    {
        $lock = json_decode((string) @file_get_contents($projectRoot . '/composer.lock'), associative: true);

        foreach (is_array($lock) && is_array($lock['packages'] ?? null) ? $lock['packages'] : [] as $package) {
            if (is_array($package) && ($package['name'] ?? null) === 'tempest/framework') {
                $constraint = $package['require']['php'] ?? null;

                return is_string($constraint) && preg_match('/(\d+\.\d+)/', $constraint, $match) === 1
                    ? $match[1]
                    : self::FALLBACK_MINIMUM_PHP;
            }
        }

        return self::FALLBACK_MINIMUM_PHP;
    }
}

<?php

declare(strict_types=1);

namespace Ship\Support;

/**
 * The Composer packages a project uses: those composer.lock installs, or without a lock file,
 * those composer.json requires.
 */
final class ComposerPackages
{
    public static function has(string $projectRoot, string $package): bool
    {
        return in_array($package, self::names($projectRoot), true);
    }

    /**
     * @return list<string>
     */
    public static function names(string $projectRoot): array
    {
        $lock = json_decode((string) @file_get_contents($projectRoot . '/composer.lock'), associative: true);

        if (is_array($lock)) {
            $names = [];
            foreach (['packages', 'packages-dev'] as $key) {
                foreach (is_array($lock[$key] ?? null) ? $lock[$key] : [] as $package) {
                    if (is_array($package) && is_string($package['name'] ?? null)) {
                        $names[] = $package['name'];
                    }
                }
            }

            return $names;
        }

        $composer = json_decode((string) @file_get_contents($projectRoot . '/composer.json'), associative: true);
        $names = [];
        foreach (['require', 'require-dev'] as $key) {
            foreach (is_array($composer[$key] ?? null) ? array_keys($composer[$key]) : [] as $name) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }
}

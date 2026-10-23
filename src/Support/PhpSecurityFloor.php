<?php

declare(strict_types=1);

namespace Ship\Support;

/**
 * The oldest PHP release per branch that ship will build an image on.
 *
 * ComposeFileBuilder passes this to the Dockerfiles as the PHP_SECURITY_FLOOR build arg, and their
 * base stage fails the build when the image's actual PHP is older. ship.json's "php" is only a
 * branch ("8.4"), so a stale locally cached php:8.4-fpm-alpine would otherwise keep building on a
 * vulnerable patch release indefinitely. A branch older than every entry here is past end of life
 * and gets no upstream fixes, so it is refused too.
 *
 * Raise these when php.net ships a security release; see
 * https://github.com/php/php-src/security/advisories.
 */
final class PhpSecurityFloor
{
    /**
     * Branch => first patched release.
     *
     * Current floor: CVE-2026-109618 (GHSA-w8hw-w9fx-j79w, RCE via OOM corruption) and
     * CVE-2026-109619 (GHSA-6wmf-4rv5-p3rp, RCE via stale global state), fixed 2026-10-21.
     */
    public const MINIMUM_PATCHED = [
        '8.2' => '8.2.35',
        '8.3' => '8.3.36',
        '8.4' => '8.4.27',
        '8.5' => '8.5.12',
        '8.6' => '8.6.0RC4',
    ];

    /**
     * Space-separated, for a Docker build arg: "8.2.35 8.3.36 ...".
     */
    public static function buildArg(): string
    {
        return implode(' ', self::MINIMUM_PATCHED);
    }

    /**
     * Why $version is unsafe to build on, or null when it isn't. A bare branch ("8.4") is fine
     * as long as the branch is still supported; the Dockerfile checks the resolved patch release.
     */
    public static function problemWith(string $version): ?string
    {
        if (preg_match('/^(\d+)\.(\d+)/', $version, $matches) !== 1) {
            return null;
        }

        $branch = "{$matches[1]}.{$matches[2]}";
        $oldestBranch = array_key_first(self::MINIMUM_PATCHED);

        if (version_compare($branch, $oldestBranch, '<')) {
            return "PHP {$branch} is past end of life and has no fix for known remote code execution "
                . "vulnerabilities. Use PHP {$oldestBranch} or newer.";
        }

        $minimum = self::MINIMUM_PATCHED[$branch] ?? null;

        // A bare branch, or a branch newer than any listed: nothing to compare against here.
        if ($minimum === null || $version === $branch) {
            return null;
        }

        if (version_compare($version, $minimum, '<')) {
            return "PHP {$version} has known remote code execution vulnerabilities. Use {$minimum} or "
                . "newer, or just \"{$branch}\" to always build on its latest patch release.";
        }

        return null;
    }
}

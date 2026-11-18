<?php

declare(strict_types=1);

namespace Ship\Support;

/**
 * The revision of the files `ship init` publishes into ship/ (the Dockerfiles, nginx and php-fpm
 * config), recorded in ship/.stub-revision. It goes up whenever the compose files ship generates
 * start depending on something new in those files: revision 2 adds the php-fpm ping the production
 * healthchecks ask for and the assets stage's VITE_ENV; revision 3 adds Corepack (Yarn, pnpm) and
 * the assets stage's PACKAGE_MANAGER (ship/build-assets.sh).
 *
 * Building against older published files would produce images the generated compose file doesn't
 * match (a healthcheck that can never pass, a bundle without its VITE_* settings), so `ship build`
 * and `ship release` refuse until `ship init` republishes them.
 */
final class StubRevision
{
    public const REQUIRED = 3;

    /**
     * Files published before revisions were recorded count as revision 1.
     */
    public static function published(string $projectRoot): int
    {
        $file = $projectRoot . '/ship/.stub-revision';

        return is_file($file) ? (int) trim((string) file_get_contents($file)) : 1;
    }

    public static function problem(string $projectRoot): ?string
    {
        $published = self::published($projectRoot);

        if ($published >= self::REQUIRED) {
            return null;
        }

        return sprintf(
            'the files in ship/ were published by an older ship (revision %d; this one needs %d), so the '
                . 'images built from them wouldn\'t match the compose file it generates. Run `ship init` '
                . 'again: every prompt defaults to your current selection, so you can press Enter through '
                . 'them. It overwrites the files in ship/, so commit or back up any you edited by hand first.',
            $published,
            self::REQUIRED,
        );
    }
}

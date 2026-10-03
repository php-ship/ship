<?php

declare(strict_types=1);

namespace Ship\Docker;

/**
 * A real security fix: the builder stage's `COPY . .` would bake .env (and any secrets in it)
 * straight into the image without this -- recoverable later via `docker history` even after a
 * following step deletes it, since layers are additive. Merged into any existing .dockerignore,
 * not overwritten. Called from `ship init` (so a fresh project starts out covered) and again from
 * `ProductionBuildRunner` (so `ship build`/`ship release` are covered too, for a project whose
 * .dockerignore was hand-edited, reverted, or never existed because `ship init` ran before this
 * guard did) -- a build is exactly the moment `COPY . .` actually runs, so that's the one place
 * this can't be skipped.
 *
 * `**`-prefixed, not bare `.env`/`.env.*` -- confirmed live, not assumed: a bare pattern only
 * matches at the build context *root*, not recursively, so a nested file (most importantly
 * `dist/ship/<tag>/.env`, `ship release`'s own copy of `.env.production`) was NOT excluded by the
 * un-prefixed form, and landed readable inside the very next image built in that same project --
 * an actual production secret leak, not a theoretical one. `auth.json`/`.npmrc` (Composer's and
 * npm's own credential files -- a private Packagist Pro/registry token, most commonly) get the
 * same recursive treatment, for the same reason: both are real secret-bearing files `COPY . .`
 * would bake in exactly like `.env`. `/dist/ship` -- not the bare `/dist` a real re-audit of this
 * exact fix flagged -- is excluded for the same reason: that's specifically `ship release`'s own
 * generated output (see ReleaseCommand's own `$releaseDir`), images and all, carried forward
 * release after release, never a build input. A bare `/dist` instead silently dropped a project's
 * *own* `dist/` -- a separate build tool's real output the image might legitimately need to
 * `COPY . .` in -- from the build context entirely, with no error, confirmed in a real build.
 */
final class DockerignoreGuard
{
    public static function ensure(string $projectRoot): void
    {
        $path = $projectRoot . '/.dockerignore';
        $required = [
            '**/.env',
            '**/.env.*',
            '!**/.env.example',
            '**/auth.json',
            '**/.npmrc',
            '.git',
            'node_modules',
            'vendor',
            '/dist/ship',
        ];

        $fileLines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
        $existing = $fileLines === false ? [] : $fileLines;
        $missing = array_values(array_diff($required, $existing));

        if ($missing === []) {
            return;
        }

        $header = $existing === []
            ? "# Added by ship -- keeps secrets and build noise out of the\n# Docker build context.\n"
            : "\n# Added by ship:\n";

        file_put_contents($path, $header . implode("\n", $missing) . "\n", FILE_APPEND);
    }
}

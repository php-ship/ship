<?php

declare(strict_types=1);

namespace Ship\Docker;

/**
 * Keeps secrets out of the build context: without these entries the builder stage's `COPY . .`
 * bakes .env into an image layer. Merged into any existing .dockerignore. Called from `ship init`
 * and again from ProductionBuildRunner, in case the file was edited or removed since.
 *
 * The patterns are `**`-prefixed because a bare `.env` only matches at the context root, and
 * `dist/ship/<tag>/.env` (a release's copy of .env.production) must be excluded too. `auth.json`
 * and `.npmrc` carry Composer/npm credentials. `/dist/ship` is `ship release`'s output; the
 * project's own `dist/` is left alone.
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

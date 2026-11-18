<?php

declare(strict_types=1);

namespace Ship\Docker;

use Ship\Docker\ReleaseWriter as Shell;

/**
 * The VITE_* settings of `.env.production` for the production `npm run build`, which bakes them
 * into the JavaScript bundle (Reverb's and Centrifugo's browser-side addresses, VITE_APP_NAME, ...).
 * Every `.env*` file is kept out of the Docker build context, so without this they'd be empty.
 *
 * Only VITE_* settings are passed: they end up in public JavaScript anyway, so nothing secret
 * reaches the build. They're rendered as `export` lines the assets stage `eval`s right before
 * `npm run build`, so each value reaches Vite exactly as written, without a second round of .env
 * parsing. `${OTHER}` references are resolved against the same file first, as Laravel resolves
 * them at runtime (Laravel's default .env sets VITE_REVERB_APP_KEY="${REVERB_APP_KEY}").
 */
final class ViteBuildEnv
{
    /**
     * The process environment variable ComposeFileBuilder's VITE_ENV build arg reads.
     */
    public const VARIABLE = 'SHIP_VITE_ENV';

    /**
     * @param array<string, string> $env the parsed .env.production
     */
    public static function render(array $env): string
    {
        $lines = [];

        foreach ($env as $key => $value) {
            if (preg_match('/^VITE_[A-Za-z0-9_]+$/', $key) !== 1) {
                continue;
            }

            $resolved = (string) preg_replace_callback(
                '/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/',
                static fn (array $match): string => $env[$match[1]] ?? '',
                $value,
            );

            $lines[] = 'export ' . $key . '=' . Shell::shellQuote($resolved);
        }

        return implode("\n", $lines);
    }
}

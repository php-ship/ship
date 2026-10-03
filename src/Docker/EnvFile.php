<?php

declare(strict_types=1);

namespace Ship\Docker;

/**
 * A plain KEY=VALUE reader for `.env.production` -- not a full dotenv implementation (no variable
 * interpolation, no multiline support), since the only consumer (ProductionBuildRunner) just
 * needs values available to the `docker compose build` child process for Compose's own `${VAR}`
 * substitution in a hand-edited ship/Dockerfile's build args, the same way a project's own
 * framework already reads its runtime `.env`.
 */
final class EnvFile
{
    /**
     * @return array<string, string>
     */
    public static function parse(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $values = [];

        $lines = file($path, FILE_IGNORE_NEW_LINES);

        foreach ($lines === false ? [] : $lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            // A real bug found via an independent re-audit, confirmed live: a line written as
            // `export KEY=value` -- valid shell syntax Laravel/Compose both already accept, and a
            // real pattern for a .env.production meant to also be `source`-able directly -- kept
            // "export KEY" as the variable name verbatim, so every lookup against the real "KEY"
            // silently saw it as never set at all (ship config:test reporting it missing, a real
            // production build passing the wrong name to Compose) instead of either reading it
            // correctly or at least failing loudly.
            $key = (string) preg_replace('/^export\s+/', '', trim($key));
            $value = trim($value);

            // A second real bug found the same way: a trailing ` # comment` was kept as part of
            // the value verbatim instead of being stripped, corrupting it outright (the "comment"
            // isn't a comment to anything reading this value afterward). Only for an *unquoted*
            // value -- a quoted one may legitimately contain a literal "#", and the closing quote
            // itself is the actual end of the value, not wherever "#" happens to appear.
            //
            // A third, found via a fifth independent re-audit of the second: a *quoted* value
            // followed by a comment -- `KEY="three" # note` -- still wasn't handled right. The
            // quote-stripping below only ever matched when the closing quote was the value's very
            // *last* character, which a trailing comment makes false, so the whole thing (quotes,
            // comment, and all) fell through as one literal string instead of either path
            // applying. Finding the closing quote explicitly (not just checking the end of the
            // string) and only ever comment-stripping what comes *after* it fixes both at once.
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $quote = $value[0];
                $closing = strpos($value, $quote, 1);

                // An unterminated quote has nothing real to close on -- left as the raw,
                // already-trimmed value, the same lenient fallback this always had.
                if ($closing !== false) {
                    $value = substr($value, 1, $closing - 1);
                }
            } else {
                $hashPos = strpos($value, ' #');

                if ($hashPos !== false) {
                    $value = rtrim(substr($value, 0, $hashPos));
                }
            }

            if ($key !== '') {
                $values[$key] = $value;
            }
        }

        return $values;
    }
}

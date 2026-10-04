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

        foreach ($lines === false ? [] : $lines as $index => $line) {
            // A UTF-8 BOM (several Windows editors, and PowerShell's own Out-File/Set-Content,
            // write one by default) only ever lands on the very first line -- left in place, it
            // silently prepends itself to that line's own key, so the first variable in the file
            // is never found by any exact lookup even though it reads as correct in an editor
            // that hides the BOM on display (most of them do).
            if ($index === 0) {
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
            }

            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            // A line written as `export KEY=value` -- valid shell syntax Laravel/Compose both
            // already accept, and a real pattern for a .env.production meant to also be
            // `source`-able directly -- would otherwise keep "export KEY" as the variable name
            // verbatim, so every lookup against the real "KEY" would silently see it as never set
            // at all (ship config:test reporting it missing, a real production build passing the
            // wrong name to Compose) instead of either reading it correctly or at least failing
            // loudly.
            $key = (string) preg_replace('/^export\s+/', '', trim($key));
            $value = trim($value);

            // A trailing ` # comment` is stripped, not kept as part of the value -- the "comment"
            // isn't a comment to anything reading this value afterward, so leaving it in place
            // would corrupt the value outright. Only for an *unquoted* value -- a quoted one may
            // legitimately contain a literal "#", and the closing quote itself is the actual end
            // of the value, not wherever "#" happens to appear. A *quoted* value followed by a
            // comment -- `KEY="three" # note` -- needs its closing quote found explicitly (not
            // just the end of the string), since the comment makes the closing quote fall short
            // of the value's very last character; only then is everything after it stripped.
            if ($value !== '' && $value[0] === '"') {
                // An *escaped* quote inside a double-quoted value -- `E="a\"b"` -- needs scanning
                // character by character, treating a backslash as consuming whatever follows it
                // (an escaped quote, an escaped backslash, ...) rather than a value boundary --
                // the same thing a shell or a real dotenv parser already does with double-quoted
                // values. A plain strpos() for the closing quote has no concept of escaping at
                // all, and would cut the value short at the escaped quote instead of the real
                // closing one. Single-quoted ones are deliberately left on the simpler strpos()
                // path below: they have no escape mechanism at all in shell/dotenv convention, so
                // the first matching quote is always the real one.
                $closing = null;
                $length = strlen($value);

                for ($i = 1; $i < $length; $i++) {
                    if ($value[$i] === '\\' && $i + 1 < $length) {
                        $i++;
                        continue;
                    }

                    if ($value[$i] === '"') {
                        $closing = $i;
                        break;
                    }
                }

                // An unterminated quote has nothing real to close on -- left as the raw,
                // already-trimmed value, the same lenient fallback this always had.
                if ($closing !== null) {
                    $value = (string) preg_replace('/\\\\(["\\\\])/', '$1', substr($value, 1, $closing - 1));
                }
            } elseif ($value !== '' && $value[0] === "'") {
                $closing = strpos($value, "'", 1);

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

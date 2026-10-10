<?php

declare(strict_types=1);

namespace Ship\Docker;

/**
 * A plain KEY=VALUE reader for `.env`/`.env.production`. Not a full dotenv implementation: no
 * variable interpolation and no multiline values.
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
            // Strip a UTF-8 BOM, which would otherwise become part of the first key.
            if ($index === 0) {
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
            }

            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            // Accept `export KEY=value`.
            $key = (string) preg_replace('/^export\s+/', '', trim($key));
            $value = trim($value);

            // A trailing ` # comment` is stripped from an unquoted value. For a quoted value,
            // the closing quote ends the value and anything after it is dropped.
            if ($value !== '' && $value[0] === '"') {
                // Scan for the closing quote, skipping backslash-escaped characters.
                // Single-quoted values have no escapes and use the plain strpos() below.
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

                // An unterminated quote leaves the raw value as it is.
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

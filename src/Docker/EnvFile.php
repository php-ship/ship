<?php

declare(strict_types=1);

namespace Ship\Docker;

/**
 * A plain KEY=VALUE reader for `.env.production` -- not a full dotenv implementation (no variable
 * interpolation, no export/multiline support), since the only consumer (ProductionBuildRunner) just
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
            $key = trim($key);
            $value = trim($value);

            if (strlen($value) >= 2 && (
                ($value[0] === '"' && str_ends_with($value, '"'))
                || ($value[0] === "'" && str_ends_with($value, "'"))
            )) {
                $value = substr($value, 1, -1);
            }

            if ($key !== '') {
                $values[$key] = $value;
            }
        }

        return $values;
    }
}

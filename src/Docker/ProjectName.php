<?php

declare(strict_types=1);

namespace Ship\Docker;

use Ship\Config\ShipConfig;

/**
 * The project name used for the compose `name:` and production image tags ("<name>-app:<tag>").
 * Falls back to the project directory's basename (see ShipConfig::$name).
 */
final class ProjectName
{
    public static function resolve(ShipConfig $config, string $projectRoot): string
    {
        $raw = $config->name ?? basename(rtrim($projectRoot, '/\\'));

        return self::sanitize($raw);
    }

    /**
     * Normalizes a name into a valid Docker image name component, whose grammar is
     * `[a-z0-9]+((\.|_|__|-+)[a-z0-9]+)*`: characters outside [a-z0-9._-] become "-", and any run
     * of two or more separators collapses to a single "-" ("foo..bar" is otherwise rejected as
     * "invalid reference format"). Falls back to a fixed name when nothing usable is left.
     */
    private static function sanitize(string $name): string
    {
        $lower = strtolower($name);
        $safe = (string) preg_replace('/[^a-z0-9._-]+/', '-', $lower);
        $safe = (string) preg_replace('/[._-]{2,}/', '-', $safe);
        $trimmed = trim($safe, '-._');

        return $trimmed === '' ? 'ship-app' : $trimmed;
    }
}

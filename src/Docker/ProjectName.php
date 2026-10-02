<?php

declare(strict_types=1);

namespace Ship\Docker;

use Ship\Config\ShipConfig;

/**
 * The name `ship build`/`ship release` tag production images under (e.g. "<name>-app:<tag>"). See
 * ShipConfig::$name's own docblock for why this falls back to the project directory's basename
 * rather than requiring every project to set it.
 */
final class ProjectName
{
    public static function resolve(ShipConfig $config, string $projectRoot): string
    {
        $raw = $config->name ?? basename(rtrim($projectRoot, '/\\'));

        return self::sanitize($raw);
    }

    /**
     * Docker image names are restricted to lowercase letters, digits, and separators ('.', '_', '-')
     * -- a directory basename ("My Project", "Ship.Dev") or a hand-typed ship.json value can easily
     * contain neither, so this normalizes rather than letting `docker build` reject it outright with
     * an opaque "invalid reference format". Falls back to a fixed name when nothing usable is left
     * (e.g. a basename that's entirely punctuation) rather than handing `docker` an empty component.
     */
    private static function sanitize(string $name): string
    {
        $lower = strtolower($name);
        $safe = (string) preg_replace('/[^a-z0-9._-]+/', '-', $lower);
        $trimmed = trim($safe, '-._');

        return $trimmed === '' ? 'ship-app' : $trimmed;
    }
}

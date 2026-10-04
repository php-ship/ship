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
     *
     * Confirmed live (`docker build -t foo..bar-app:local` fails outright with "invalid reference
     * format"): Docker's actual grammar for this component is `[a-z0-9]+((\.|_|__|-+)[a-z0-9]+)*`
     * -- a *single* separator (one '.', one or two '_', or any run of '-') between two
     * alphanumeric runs, never two or more literal dots/underscores in a row. The first replace
     * below only ever touches characters *outside* [a-z0-9._-] -- "foo..bar" is already entirely
     * within that set, so it would pass through untouched and still break `docker build`/`docker
     * save` later with the exact same opaque error this class exists to avoid. The second replace
     * collapses any run of two or more separator characters (whatever mix of '.', '_', '-'
     * produced it) down to a single '-', which satisfies Docker's grammar unconditionally rather
     * than special-casing every separator combination it allows.
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

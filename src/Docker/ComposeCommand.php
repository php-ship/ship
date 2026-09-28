<?php

declare(strict_types=1);

namespace Ship\Docker;

use Symfony\Component\Yaml\Yaml;

/**
 * Builds the `docker compose -f ... --project-directory ...` prefix every command shares.
 */
final class ComposeCommand
{
    /**
     * A docker-compose.override.yml at the project root -- the standard Compose convention for customizing
     * a generated file, without ever touching it directly, so nothing here is ever lost when `ship up`,
     * next regenerates that file. Picked up explicitly: passing any -f disables Compose's own default
     * override auto-discovery outright, and ship already passes -f for its own generated file, too.
     *
     * @return list<string>
     */
    public static function baseArgs(string $projectRoot): array
    {
        $args = ['docker', 'compose', '-f', $projectRoot . '/ship/docker-compose.generated.yml'];

        $overridePath = $projectRoot . '/docker-compose.override.yml';
        if (is_file($overridePath)) {
            $args[] = '-f';
            $args[] = $overridePath;
        }

        $args[] = '--project-directory';
        $args[] = $projectRoot;

        return $args;
    }

    /**
     * `docker compose ... exec`, plus `--user uid:gid` when the generated file says the app runs as
     * the host user (ship.json's hostUser) and $service is that app service -- without it, `ship
     * composer`/`ship npm`/`ship shell` exec as root regardless of who the app itself runs as, and
     * root-owned vendor/ and public/build are exactly what the option exists to prevent. Only the
     * app: a database container has no such user, and `mysql`/`psql` don't need one.
     *
     * Read from the generated file, not ship.json, so it follows what was actually generated -- a
     * production file, or a project that has since turned the option off, never gets a --user.
     *
     * @return list<string>
     */
    public static function execPrefix(string $projectRoot, string $service): array
    {
        $prefix = [...self::baseArgs($projectRoot), 'exec'];
        $generated = $projectRoot . '/ship/docker-compose.generated.yml';

        if (!is_file($generated)) {
            return $prefix;
        }

        $contents = (string) file_get_contents($generated);

        if (!str_contains($contents, 'x-ship:')) {
            return $prefix;
        }

        /** @var array{x-ship?: array{hostUser?: string, appService?: string}} $parsed */
        $parsed = Yaml::parse($contents);
        $marker = $parsed['x-ship'] ?? [];

        if (isset($marker['hostUser'], $marker['appService']) && $marker['appService'] === $service) {
            $prefix[] = '--user';
            $prefix[] = $marker['hostUser'];
        }

        return $prefix;
    }
}

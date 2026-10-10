<?php

declare(strict_types=1);

namespace Ship\Docker;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Builds the `docker compose -f ... --project-directory ...` prefix every command shares.
 */
final class ComposeCommand
{
    /**
     * `ship build`/`ship release` use their own compose file. Sharing the dev one would leave it
     * holding a production stack until the next `ship up`, and the exec-family commands would
     * target the wrong environment in the meantime.
     */
    public const PRODUCTION_COMPOSE_FILE = 'ship/docker-compose.production.yml';

    /**
     * A docker-compose.override.yml at the project root is the standard Compose way to customize
     * the generated file. It has to be passed explicitly, since any -f disables Compose's own
     * override auto-discovery.
     *
     * $includeOverride is false for production builds: the override is a dev convenience and
     * mustn't leak into a production image or diverge from the release's compose file.
     *
     * @return list<string>
     */
    public static function baseArgs(string $projectRoot, bool $includeOverride = true, string $composeFile = 'ship/docker-compose.generated.yml'): array
    {
        $args = ['docker', 'compose', '-f', $projectRoot . '/' . $composeFile];

        $overridePath = $projectRoot . '/docker-compose.override.yml';
        if ($includeOverride && is_file($overridePath)) {
            $args[] = '-f';
            $args[] = $overridePath;
        }

        $args[] = '--project-directory';
        $args[] = $projectRoot;

        return $args;
    }

    /**
     * `docker compose ... exec`, plus `--user uid:gid` when the generated file says the app runs as
     * the host user (ship.json's hostUser) and $service is that app service, so `ship composer`/
     * `ship npm`/`ship shell` don't write root-owned files.
     *
     * Read from the generated file rather than ship.json, so a production file or one generated
     * with the option off never gets a --user.
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

        // A malformed generated file falls back to no --user prefix, which none of the exec
        // commands need in order to run.
        try {
            /** @var array{x-ship?: array{hostUser?: string, appService?: string}} $parsed */
            $parsed = Yaml::parse($contents);
        } catch (ParseException) {
            return $prefix;
        }

        $marker = $parsed['x-ship'] ?? [];

        if (isset($marker['hostUser'], $marker['appService']) && $marker['appService'] === $service) {
            $prefix[] = '--user';
            $prefix[] = $marker['hostUser'];
        }

        return $prefix;
    }
}

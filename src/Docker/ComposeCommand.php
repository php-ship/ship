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
     * `ship build`/`ship release` (via ProductionBuildRunner) write and read this one instead of
     * the dev file -- sharing docker-compose.generated.yml between the two would otherwise leave
     * the dev compose file overwritten with a production one after running either, until the
     * next `ship up` regenerated it, during which `ship exec`/`ship shell`/`ship composer`/`ship
     * npm` would all read the wrong target (and lose the hostUser `--user` flag, since production
     * never sets `x-ship`).
     */
    public const PRODUCTION_COMPOSE_FILE = 'ship/docker-compose.production.yml';

    /**
     * A docker-compose.override.yml at the project root -- the standard Compose convention for customizing
     * a generated file, without ever touching it directly, so nothing here is ever lost when `ship up`,
     * next regenerates that file. Picked up explicitly: passing any -f disables Compose's own default
     * override auto-discovery outright, and ship already passes -f for its own generated file, too.
     *
     * $includeOverride is false only for `ship build`/`ship release` (via ProductionBuildRunner) --
     * the override file is a dev convenience (its own docblock above already says so), so picking
     * it up the same way every other caller does would let a project's own dev-only `build:`
     * customization leak into the production image too, and the release's own exported
     * docker-compose.yml (built from ship/docker-compose.generated.yml alone) would no longer
     * match what was actually built. Omitting it from the production build entirely avoids both:
     * nothing to leak, and nothing for the artifact to disagree with.
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

        // A hand-edit, an interrupted write, or a stale file from a different ship version could
        // all leave this genuinely malformed -- same fallback as "doesn't exist"/"no marker"
        // above, not a raw ParseException crashing `ship exec`/`shell`/`composer`/`npm` outright
        // over a --user prefix that's a convenience, not something any of those commands actually
        // need to run at all.
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

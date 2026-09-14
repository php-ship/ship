<?php

declare(strict_types=1);

namespace Ship\Docker;

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
}

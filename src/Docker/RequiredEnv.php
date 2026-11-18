<?php

declare(strict_types=1);

namespace Ship\Docker;

use Ship\Contracts\ShipEnvironment;

/**
 * A credential's Compose interpolation expression: `${VAR:-default}` in development, so a fresh
 * project works with no config, and `${VAR:?...}` in production, so `docker compose` refuses to
 * run rather than fall back to a publicly known default.
 *
 * Applied to a service's own container env (MYSQL_PASSWORD) only. The app-facing value
 * (DB_PASSWORD from environmentVariables()) keeps its default: Compose interpolates the whole
 * file in one pass, so a single unset `:?` aborts the command before anything starts.
 */
final class RequiredEnv
{
    public static function expr(string $var, string $devDefault, ShipEnvironment $environment): string
    {
        return $environment->isDevelopment()
            ? "\${{$var}:-{$devDefault}}"
            : "\${{$var}:?set a real value in .env.production}";
    }

    /**
     * Every `${VAR:?...}` in $composeYaml that $env doesn't set -- what `docker compose` would
     * refuse to run over, found without Docker. Used by `ship config:test`, `ship build` and
     * `ship release`.
     *
     * @param array<string, string> $env
     * @return list<string>
     */
    public static function missingFrom(string $composeYaml, array $env): array
    {
        return array_values(array_filter(
            self::requiredIn($composeYaml),
            static fn (string $var): bool => ($env[$var] ?? '') === '',
        ));
    }

    /**
     * Every variable $composeYaml requires (`${VAR:?...}`), in order of first appearance.
     *
     * @return list<string>
     */
    public static function requiredIn(string $composeYaml): array
    {
        if (preg_match_all('/\$\{([A-Za-z_][A-Za-z0-9_]*):\?/', $composeYaml, $matches) === 0) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }
}

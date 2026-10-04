<?php

declare(strict_types=1);

namespace Ship\Docker;

use Ship\Contracts\ShipEnvironment;

/**
 * A credential's own Compose interpolation expression -- a friendly `${VAR:-default}` in
 * development (so a fresh `ship init` + `ship up` works with zero config, the whole point of
 * ship's own defaults), but `${VAR:?...}` in production, which makes `docker compose` itself
 * refuse to run at all when the real value was never set, rather than silently falling back to
 * the exact same publicly-known default every project that never set it would otherwise share.
 * Without this, `${DB_PASSWORD:-secret}` and friends would use the same `:-default` expression
 * in both environments, so a `.env.production` that forgot (or never had) a real value would
 * just get "secret"/"shipsearchkey"/"ship"/"shipsecret" in production, with no error, warning, or
 * way to notice short of reading the generated compose file by hand.
 *
 * Only ever applied to a credentialed service's *own* container env (e.g. MySqlService's
 * MYSQL_PASSWORD), never to the matching app-facing value (DB_PASSWORD) `environmentVariables()`
 * exposes -- that method has no `$environment` parameter to vary by at all, and doesn't need one
 * here: Compose interpolates an entire generated file as one pass, so a single `:?` anywhere in
 * it aborts the whole command before any service starts, the app-facing side's own friendlier
 * fallback included. The two expressions only ever *look* mismatched in production; they're
 * never both evaluated for real at once.
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
     * Every `${VAR:?...}` this class's own expr() wrote into $composeYaml, that $env doesn't
     * actually set -- what `docker compose build`/`up` would themselves refuse to run over, found
     * here instead, without needing Docker installed or running at all to find out. Used by
     * `ConfigTestCommand` (reports every one found, continuing past it) and by `ship
     * build`/`release` (fail fast on the first one found, before ever invoking Docker) alike.
     *
     * @param array<string, string> $env
     * @return list<string>
     */
    public static function missingFrom(string $composeYaml, array $env): array
    {
        if (preg_match_all('/\$\{([A-Za-z_][A-Za-z0-9_]*):\?/', $composeYaml, $matches) === 0) {
            return [];
        }

        $missing = [];

        foreach (array_unique($matches[1]) as $var) {
            if (($env[$var] ?? '') === '') {
                $missing[] = $var;
            }
        }

        return $missing;
    }
}

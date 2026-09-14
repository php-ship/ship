<?php

declare(strict_types=1);

namespace Ship\Contracts;

/**
 * Describes one optional piece -- a database, a cache, or a runtime, etc. `ComposeFileBuilder` queries
 * each one for its own fragment and merges them up; nothing in the core builder knows what postgres
 * means. That lives here, letting third parties ship their own via `ship.json`'s extensions list.
 */
interface ServiceDefinition
{
    /**
     * Unique key used in ship.json, e.g. "pgsql", "redis", "octane-swoole".
     */
    public function key(): string;

    /**
     * Human-readable name shown in `ship init`'s interactive picker.
     */
    public function label(): string;

    /**
     * The group this service belongs to (database, cache, runtime, ...); usually single-select in init.
     */
    public function group(): string;

    /**
     * Compose fragments this service contributes, keyed by service name -- may contribute more than one.
     *
     * $instanceName is null for the single default selection made in ship.json's `services` object
     * (existing behavior, completely unchanged -- the compose service name stays key(), e.g. "pgsql").
     * When a project adds this same service again under a different name (ship.json's
     * `additionalServices`, for a second database/cache/etc. of a different engine or purpose),
     * $instanceName carries that name and the compose service name becomes "{key()}-{instanceName}"
     * so multiple instances never collide. A service where running more than one copy makes no sense
     * (a runtime, Node, a broadcasting server) can just ignore the parameter -- it always defaults to
     * null in practice for those, since nothing ever offers a second one to select.
     *
     * @return array<string, array<string, mixed>>
     */
    public function composeFragment(ShipEnvironment $environment, ?string $instanceName = null): array;

    /**
     * Environment variables this service injects into the app container, e.g. DB_CONNECTION=pgsql.
     *
     * Same $instanceName meaning as composeFragment(). For a named (non-null) instance, return only
     * the connection-specific variables, each prefixed with the uppercased instance name (e.g.
     * "ANALYTICS_DB_HOST", not "DB_HOST") -- and drop any variable that selects an app-wide default
     * (CACHE_STORE, SCOUT_DRIVER, ...), since a second named instance doesn't change what the app
     * uses by default, only what's additionally reachable under that name.
     *
     * @return array<string, string>
     */
    public function environmentVariables(?string $instanceName = null): array;

    /**
     * Compose service names this removes from the stack (e.g. FrankenPHP removes "nginx"); usually empty.
     *
     * @return list<string>
     */
    public function removes(): array;
}

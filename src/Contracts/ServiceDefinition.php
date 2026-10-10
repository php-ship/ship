<?php

declare(strict_types=1);

namespace Ship\Contracts;

/**
 * One optional piece of the stack -- a database, a cache, a runtime. `ComposeFileBuilder` merges
 * each selected service's fragment; third parties add their own via ship.json's `extensions`.
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
     * Compose fragments this service contributes, keyed by compose service name.
     *
     * $instanceName is null for the default selection in ship.json's `services`. For an
     * `additionalServices` entry it carries that entry's name, and the compose service name becomes
     * "{key()}-{instanceName}". Services that can't have a second instance can ignore it.
     *
     * @return array<string, array<string, mixed>>
     */
    public function composeFragment(ShipEnvironment $environment, ?string $instanceName = null): array;

    /**
     * Environment variables this service injects into the app container, e.g. DB_CONNECTION=pgsql.
     *
     * For a named instance, return only the connection variables, each prefixed with the uppercased
     * instance name ("ANALYTICS_DB_HOST"), and drop anything that selects an app-wide default
     * (CACHE_STORE, SCOUT_DRIVER, ...).
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

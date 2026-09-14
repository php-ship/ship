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
     * @return array<string, array<string, mixed>>
     */
    public function composeFragment(ShipEnvironment $environment): array;

    /**
     * Environment variables this service injects into the app container, e.g. DB_CONNECTION=pgsql.
     *
     * @return array<string, string>
     */
    public function environmentVariables(): array;

    /**
     * Compose service names this removes from the stack (e.g. FrankenPHP removes "nginx"); usually empty.
     *
     * @return list<string>
     */
    public function removes(): array;
}

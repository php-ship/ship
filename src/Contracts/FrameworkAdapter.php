<?php

declare(strict_types=1);

namespace Ship\Contracts;

/**
 * Keeps framework-specific assumptions -- an artisan file, Symfony's bin/console, etc. -- fully out of
 * the core package. `ship` itself only ever knows about this one interface; a Laravel-specific glue
 * adapter is what actually implements `ship artisan`, and could move into its own package simply.
 */
interface FrameworkAdapter
{
    /**
     * Whether this adapter applies to the project at $projectRoot (e.g. an `artisan` file existing).
     */
    public function detect(string $projectRoot): bool;

    /**
     * Console commands this adapter exposes beyond composer/npm/exec, e.g. ['artisan' => 'php artisan'].
     *
     * @return array<string, string>
     */
    public function consoleCommands(): array;

    /**
     * The framework's own optimize/cache-warming command(s), run once at production container boot, not
     * build time, so real env vars are already set (e.g. Laravel's `artisan optimize`). Empty when the
     * framework has nothing equivalent; an unmatched project execs straight into the real process.
     *
     * @return list<string>
     */
    public function releaseCommands(): array;
}

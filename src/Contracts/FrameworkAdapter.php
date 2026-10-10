<?php

declare(strict_types=1);

namespace Ship\Contracts;

/**
 * Keeps framework-specific assumptions (an artisan file, Symfony's bin/console) out of the core
 * package.
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
     * Optimize/cache-warming commands run at every production container boot rather than at build
     * time, so real env vars are set (e.g. Laravel's `artisan optimize`). May be empty.
     *
     * @return list<string>
     */
    public function releaseCommands(): array;
}

<?php

declare(strict_types=1);

namespace Ship\Contracts;

/**
 * Optional add-on for a FrameworkAdapter whose framework needs a newer PHP than ship's default.
 * `ship init` starts the PHP version question at it and refuses an older one, and
 * `ship config:test` reports a ship.json that asks for an older one, since the image's
 * `composer install` would fail on it.
 */
interface RequiresPhpVersion
{
    /**
     * The lowest PHP branch the project's version of the framework runs on, e.g. "8.5".
     */
    public function minimumPhpVersion(string $projectRoot): string;
}

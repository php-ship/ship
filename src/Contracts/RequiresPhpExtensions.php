<?php

declare(strict_types=1);

namespace Ship\Contracts;

/**
 * Optional add-on for a ServiceDefinition whose PHP client library needs an extension outside the
 * Dockerfile's fixed set (e.g. MongoDB's `mongodb`). ComposeFileBuilder adds these to the app
 * image's PHP_EXTENSIONS build arg alongside ship.json's `phpExtensions`, so selecting the service
 * is enough.
 */
interface RequiresPhpExtensions
{
    /**
     * Extension names as mlocati/docker-php-extension-installer spells them.
     *
     * @return list<string>
     */
    public function requiredPhpExtensions(): array;
}

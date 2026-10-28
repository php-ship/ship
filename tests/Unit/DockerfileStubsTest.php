<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Content checks on the published Dockerfile. CI's base-image job builds it for real on every
 * supported PHP branch.
 */
final class DockerfileStubsTest extends TestCase
{
    /**
     * From PHP 8.5, OPcache is compiled into PHP and `docker-php-ext-install opcache` fails, so it
     * must only be built when PHP doesn't already have it.
     */
    public function test_opcache_is_only_built_when_php_does_not_already_have_it(): void
    {
        $dockerfile = (string) file_get_contents(dirname(__DIR__, 2) . '/stubs/docker/php/Dockerfile');

        self::assertSame(1, preg_match('/&& docker-php-ext-install (pdo_pgsql[^\\\\\n]*)/', $dockerfile, $matches));
        self::assertStringNotContainsString('opcache', $matches[1]);
        self::assertStringContainsString(
            "if ! php -r 'exit(extension_loaded(\"Zend OPcache\") ? 0 : 1);'; then \\\n        docker-php-ext-install opcache;",
            $dockerfile,
        );
    }
}

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

    /**
     * zip (laravel/dusk, spreadsheet and archive libraries) and sockets (RoadRunner, php-amqplib)
     * are in the fixed set of both PHP images, so `composer require` of those packages works.
     */
    public function test_both_php_images_always_install_zip_and_sockets(): void
    {
        $root = dirname(__DIR__, 2) . '/stubs/docker/php';

        $dockerfile = (string) file_get_contents($root . '/Dockerfile');
        self::assertSame(1, preg_match('/&& docker-php-ext-install (pdo_pgsql[^\\\\\n]*)/', $dockerfile, $matches));
        self::assertContains('zip', explode(' ', trim($matches[1])));
        self::assertContains('sockets', explode(' ', trim($matches[1])));
        self::assertStringContainsString('libzip-dev', $dockerfile);

        $frankenphp = (string) file_get_contents($root . '/Dockerfile.frankenphp');
        self::assertSame(1, preg_match('/^RUN install-php-extensions (pdo_pgsql.*)$/m', $frankenphp, $matches));
        self::assertContains('zip', explode(' ', $matches[1]));
        self::assertContains('sockets', explode(' ', $matches[1]));
    }

    /**
     * The VITE_* settings reach `npm run build` in both PHP images' assets stage.
     */
    public function test_both_assets_stages_load_the_vite_settings_before_building(): void
    {
        foreach (['Dockerfile', 'Dockerfile.frankenphp'] as $file) {
            $dockerfile = (string) file_get_contents(dirname(__DIR__, 2) . '/stubs/docker/php/' . $file);
            $assets = substr($dockerfile, (int) strpos($dockerfile, 'FROM builder AS assets'));
            $assets = substr($assets, 0, (int) strpos($assets, "\nFROM ") ?: strlen($assets));

            self::assertStringContainsString('ARG VITE_ENV=""', $assets, $file);
            self::assertLessThan(strpos($assets, 'npm run build'), strpos($assets, 'eval "$VITE_ENV"'), $file);
        }
    }
}

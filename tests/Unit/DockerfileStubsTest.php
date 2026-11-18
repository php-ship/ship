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
     * Both PHP images' assets stage builds with ship/build-assets.sh, given the VITE_* settings and
     * the project's package manager, and both images carry the same pinned Corepack.
     */
    public function test_both_assets_stages_build_with_the_script_and_the_project_package_manager(): void
    {
        $corepack = [];

        foreach (['Dockerfile', 'Dockerfile.frankenphp'] as $file) {
            $dockerfile = (string) file_get_contents(dirname(__DIR__, 2) . '/stubs/docker/php/' . $file);
            $assets = substr($dockerfile, (int) strpos($dockerfile, 'FROM builder AS assets'));
            $assets = substr($assets, 0, (int) strpos($assets, "\nFROM ") ?: strlen($assets));

            self::assertStringContainsString('ARG VITE_ENV=""', $assets, $file);
            self::assertStringContainsString('ARG PACKAGE_MANAGER=npm', $assets, $file);
            self::assertStringContainsString('RUN sh /usr/local/bin/ship-build-assets "$PACKAGE_MANAGER"', $assets, $file);

            self::assertSame(1, preg_match('/^ARG COREPACK_VERSION=(\S+)$/m', $dockerfile, $matches), $file);
            $corepack[] = $matches[1];
            self::assertStringContainsString('corepack enable yarn pnpm', $dockerfile, $file);
        }

        self::assertCount(1, array_unique($corepack));
    }

    /**
     * The script loads the VITE_* settings after installing and before building, and builds with the
     * tool that installed (Yarn's Plug'n'Play has no node_modules for npm to use).
     */
    public function test_the_asset_build_script_installs_exactly_and_builds_with_the_same_tool(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/stubs/docker/php/build-assets.sh');

        self::assertLessThan(strpos($script, '"$tool" run build'), strpos($script, 'eval "${VITE_ENV:-}"'));
        self::assertStringContainsString('npm ci', $script);
        self::assertStringContainsString('yarn install --frozen-lockfile', $script);
        self::assertStringContainsString('yarn install --immutable', $script);
        self::assertStringContainsString('pnpm install --frozen-lockfile', $script);
    }

    /**
     * The production healthchecks ask php-fpm for its ping: directly with cgi-fcgi ("app"), and
     * through nginx ("webserver").
     */
    public function test_the_published_files_provide_the_fpm_ping_the_healthchecks_ask_for(): void
    {
        $root = dirname(__DIR__, 2) . '/stubs/docker';
        $dockerfile = (string) file_get_contents($root . '/php/Dockerfile');
        $fpm = (string) file_get_contents($root . '/php/php-fpm.conf');
        $nginx = (string) file_get_contents($root . '/nginx/default.conf');

        self::assertMatchesRegularExpression('/apk add --no-cache [^\\n]*\\bfcgi\\b/', $dockerfile);
        self::assertStringContainsString('COPY ship/php-fpm.conf /usr/local/etc/php-fpm.d/zz-ship.conf', $dockerfile);
        self::assertStringContainsString('ping.path = /ship-fpm-ping', $fpm);
        self::assertStringContainsString('ping.response = pong', $fpm);
        self::assertStringContainsString('location = /ship-fpm-ping {', $nginx);
        self::assertStringContainsString('allow 127.0.0.1;', $nginx);
    }
}

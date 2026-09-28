<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Docker\EntrypointScriptBuilder;

final class EntrypointScriptBuilderTest extends TestCase
{
    public function test_it_ends_with_exec_so_the_real_process_becomes_pid_1(): void
    {
        $script = (new EntrypointScriptBuilder())->build(['php artisan config:cache']);

        self::assertStringEndsWith('exec "$@"' . "\n", $script);
    }

    public function test_it_includes_every_release_command_in_order(): void
    {
        $script = (new EntrypointScriptBuilder())->build([
            'php artisan config:cache',
            'php artisan route:cache',
        ]);

        $configPos = strpos($script, 'php artisan config:cache');
        $routePos = strpos($script, 'php artisan route:cache');
        $execPos = strpos($script, 'exec "$@"');

        self::assertNotFalse($configPos);
        self::assertNotFalse($routePos);
        self::assertNotFalse($execPos);
        self::assertTrue($configPos < $routePos);
        self::assertTrue($routePos < $execPos);
    }

    public function test_it_still_execs_with_zero_release_commands(): void
    {
        $script = (new EntrypointScriptBuilder())->build([]);

        self::assertStringContainsString('exec "$@"', $script);
    }

    public function test_it_starts_with_a_shebang(): void
    {
        $script = (new EntrypointScriptBuilder())->build([]);

        self::assertStringStartsWith('#!/bin/sh', $script);
    }

    /**
     * Regression test: plain php-fpm's request-handling workers run as www-data, but everything
     * before this line (the image build, artisan optimize's view cache) runs as root -- without
     * this chown, anything a worker needs to write at request time hits a permission error against
     * root-owned files. Ordering matters: has to run after release commands (which create the
     * root-owned files in the first place) and before exec (the real process needs it already done).
     * Scoped to specific directories, not the whole tree -- a recursive chown over vendor/ too once
     * took tens of seconds and blocked php-fpm from ever starting on a real Laravel app.
     */
    public function test_it_chowns_only_the_writable_directories_after_release_commands_and_before_exec(): void
    {
        $script = (new EntrypointScriptBuilder())->build(['php artisan optimize']);

        $releasePos = strpos($script, 'php artisan optimize');
        $chownPos = strpos($script, 'chown -R www-data:www-data');
        $execPos = strpos($script, 'exec "$@"');

        self::assertNotFalse($releasePos);
        self::assertNotFalse($chownPos);
        self::assertNotFalse($execPos);
        self::assertTrue($releasePos < $chownPos);
        self::assertTrue($chownPos < $execPos);
        self::assertStringNotContainsString('chown -R www-data:www-data /var/www/html', $script);
        self::assertStringContainsString('for dir in storage bootstrap/cache database var', $script);
    }

    /**
     * Decided per container at runtime (SHIP_RUN_AS), not baked in: the same image and this one
     * script back containers that need opposite things -- php-fpm's master has to start as root to
     * drop its own workers, while an Octane server or a Horizon process must not run as root at all.
     */
    public function test_it_drops_to_the_user_named_by_ship_run_as_when_it_is_set(): void
    {
        $script = (new EntrypointScriptBuilder())->build([]);

        self::assertStringContainsString('if [ -n "$SHIP_RUN_AS" ] && command -v su-exec', $script);
        self::assertStringContainsString('exec su-exec "$SHIP_RUN_AS" "$@"', $script);
    }

    /**
     * With SHIP_RUN_AS unset (php-fpm) the plain exec has to still be there and come last -- and
     * after the chown, so the dropped-to user can write what was just handed to it. exec, not a
     * fork, either way: otherwise `docker stop`'s SIGTERM hits a wrapper instead of the server.
     */
    public function test_the_drop_comes_after_the_chown_and_a_plain_exec_remains_as_the_fallback(): void
    {
        $script = (new EntrypointScriptBuilder())->build(['php artisan optimize']);

        $chownPos = strpos($script, 'chown -R www-data:www-data');
        $dropPos = strpos($script, 'exec su-exec "$SHIP_RUN_AS" "$@"');
        $plainPos = strrpos($script, 'exec "$@"');

        self::assertNotFalse($chownPos);
        self::assertNotFalse($dropPos);
        self::assertNotFalse($plainPos);
        self::assertTrue($chownPos < $dropPos);
        self::assertTrue($dropPos < $plainPos);
    }
}

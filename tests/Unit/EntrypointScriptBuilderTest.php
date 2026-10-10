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
     * php-fpm's workers run as www-data but release commands run as root, so the writable
     * directories are chowned after the release commands and before exec. Only those
     * directories: a recursive chown over vendor/ is slow.
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
     * The drop is decided at runtime through SHIP_RUN_AS, since one image backs both php-fpm
     * (which must start as root) and processes that must not run as root.
     */
    public function test_it_drops_to_the_user_named_by_ship_run_as_when_it_is_set(): void
    {
        $script = (new EntrypointScriptBuilder())->build([]);

        self::assertStringContainsString('if [ -n "$SHIP_RUN_AS" ]', $script);
        self::assertStringContainsString('exec su-exec "$SHIP_RUN_AS" "$@"', $script);
    }

    /**
     * FrankenPHP's Debian image has setpriv instead of su-exec. SHIP_RUN_AS is a bare user name,
     * so the same value serves as --reuid and --regid.
     */
    public function test_it_falls_back_to_setpriv_when_su_exec_is_not_available(): void
    {
        $script = (new EntrypointScriptBuilder())->build([]);

        self::assertStringContainsString('elif command -v setpriv', $script);
        self::assertStringContainsString(
            'exec setpriv --reuid="$SHIP_RUN_AS" --regid="$SHIP_RUN_AS" --clear-groups --no-new-privs "$@"',
            $script,
        );
    }

    /**
     * With SHIP_RUN_AS unset the plain exec remains, last and after the chown. exec keeps the
     * server as PID 1 so SIGTERM reaches it.
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

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
}

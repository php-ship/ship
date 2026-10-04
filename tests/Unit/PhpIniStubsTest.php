<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Both php.ini stubs set upload_max_filesize/post_max_size to match
 * ship/nginx/default.conf's own client_max_body_size (100M) -- otherwise nginx happily forwards
 * a request that large through to php-fpm, which silently rejects it at PHP's own much smaller
 * stock default, emptying $_FILES/$_POST with nothing visible to the end user short of a PHP
 * error log. Both also turn off expose_php, so neither advertises the exact PHP version in every
 * response.
 *
 * Plain file-content assertions, not a live request -- these are static, non-templated stub files
 * (see InitCommand::publishStubs()'s plain mirror() call), so there's no PHP logic here to exercise.
 */
final class PhpIniStubsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function iniPaths(): iterable
    {
        yield 'dev' => [dirname(__DIR__, 2) . '/stubs/docker/php/dev/php.ini'];
        yield 'prod' => [dirname(__DIR__, 2) . '/stubs/docker/php/prod/php.ini'];
    }

    #[DataProvider('iniPaths')]
    public function test_upload_limits_match_nginxs_client_max_body_size(string $path): void
    {
        $ini = (string) file_get_contents($path);

        self::assertStringContainsString('upload_max_filesize=100M', $ini);
        self::assertStringContainsString('post_max_size=100M', $ini);
    }

    #[DataProvider('iniPaths')]
    public function test_expose_php_is_off(string $path): void
    {
        self::assertStringContainsString('expose_php=Off', (string) file_get_contents($path));
    }
}

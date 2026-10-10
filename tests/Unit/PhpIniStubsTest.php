<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Both php.ini stubs set upload_max_filesize/post_max_size to match nginx's
 * client_max_body_size (100M), so a large upload isn't silently dropped by PHP, and turn off
 * expose_php. The stubs are static files, so these are plain content assertions.
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

<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ship\Support\PhpSecurityFloor;
use Symfony\Component\Process\Process;

/**
 * Covers both halves of the floor: PhpSecurityFloor's check of ship.json's "php" value, and the
 * check-php-security-floor.php stub the Dockerfiles run against the image's actual PHP. The stub is
 * run with the host's PHP, passing the image version as its argument.
 */
final class PhpSecurityFloorTest extends TestCase
{
    /**
     * CVE-2026-109618 and CVE-2026-109619 were fixed in these releases.
     */
    public function test_the_floor_includes_the_october_2026_security_releases(): void
    {
        self::assertSame('8.2.35', PhpSecurityFloor::MINIMUM_PATCHED['8.2']);
        self::assertSame('8.3.36', PhpSecurityFloor::MINIMUM_PATCHED['8.3']);
        self::assertSame('8.4.27', PhpSecurityFloor::MINIMUM_PATCHED['8.4']);
        self::assertSame('8.5.12', PhpSecurityFloor::MINIMUM_PATCHED['8.5']);
    }

    /**
     * The stub treats the first entry as the oldest supported branch.
     */
    public function test_the_build_arg_lists_branches_oldest_first(): void
    {
        $branches = array_keys(PhpSecurityFloor::MINIMUM_PATCHED);
        $sorted = $branches;
        usort($sorted, version_compare(...));

        self::assertSame($sorted, $branches);
        self::assertSame(implode(' ', PhpSecurityFloor::MINIMUM_PATCHED), PhpSecurityFloor::buildArg());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedVersions(): iterable
    {
        yield 'a supported branch' => ['8.4'];
        yield 'the oldest supported branch' => ['8.2'];
        yield 'an exactly patched release' => ['8.4.27'];
        yield 'a newer patch release' => ['8.3.40'];
        yield 'a branch newer than any listed' => ['9.0'];
        yield 'a patched release candidate' => ['8.6.0RC4'];
        yield 'something that is not a version' => ['latest'];
    }

    #[DataProvider('acceptedVersions')]
    public function test_it_accepts(string $version): void
    {
        self::assertNull(PhpSecurityFloor::problemWith($version));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedVersions(): iterable
    {
        yield 'an end-of-life branch' => ['8.1', 'past end of life'];
        yield 'an end-of-life patch release' => ['7.4.33', 'past end of life'];
        yield 'a vulnerable 8.4 patch release' => ['8.4.26', 'Use 8.4.27 or newer'];
        yield 'a vulnerable 8.5 patch release' => ['8.5.0', 'Use 8.5.12 or newer'];
        yield 'an unpatched release candidate' => ['8.6.0RC3', 'Use 8.6.0RC4 or newer'];
    }

    #[DataProvider('rejectedVersions')]
    public function test_it_rejects(string $version, string $expectedMessage): void
    {
        self::assertStringContainsString($expectedMessage, (string) PhpSecurityFloor::problemWith($version));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function imageVersionsTheDockerfileAccepts(): iterable
    {
        yield 'an exactly patched release' => ['8.4.27'];
        yield 'a newer patch release' => ['8.2.36'];
        yield 'the patched 8.5 release' => ['8.5.12'];
        yield 'a patched release candidate' => ['8.6.0RC4'];
        yield 'a branch newer than any listed' => ['8.10.0'];
    }

    #[DataProvider('imageVersionsTheDockerfileAccepts')]
    public function test_the_dockerfile_check_accepts(string $imageVersion): void
    {
        $process = $this->runStub($imageVersion, PhpSecurityFloor::buildArg());

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function imageVersionsTheDockerfileRejects(): iterable
    {
        yield 'a stale cached 8.4 image' => ['8.4.26', 'fixed in 8.4.27'];
        yield 'a stale cached 8.3 image' => ['8.3.1', 'fixed in 8.3.36'];
        yield 'an unpatched release candidate' => ['8.6.0RC3', 'fixed in 8.6.0RC4'];
        yield 'an end-of-life branch' => ['8.1.34', 'past end of life'];
    }

    #[DataProvider('imageVersionsTheDockerfileRejects')]
    public function test_the_dockerfile_check_rejects(string $imageVersion, string $expectedMessage): void
    {
        $process = $this->runStub($imageVersion, PhpSecurityFloor::buildArg());

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString($expectedMessage, $process->getErrorOutput());
    }

    public function test_the_dockerfile_check_names_the_stale_image_to_pull(): void
    {
        $process = $this->runStub('8.4.26', PhpSecurityFloor::buildArg(), 'php:8.4-fpm-alpine');

        self::assertStringContainsString('docker pull php:8.4-fpm-alpine', $process->getErrorOutput());
    }

    /**
     * An empty build arg, as when the Dockerfile is built outside ship's generated compose file,
     * skips the check.
     */
    public function test_the_dockerfile_check_is_skipped_without_a_floor(): void
    {
        self::assertSame(0, $this->runStub('8.4.0', '')->getExitCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dockerfiles(): iterable
    {
        yield 'fpm/swoole/roadrunner' => [dirname(__DIR__, 2) . '/stubs/docker/php/Dockerfile'];
        yield 'frankenphp' => [dirname(__DIR__, 2) . '/stubs/docker/php/Dockerfile.frankenphp'];
    }

    /**
     * Run in "base", so dev, builder and prod all inherit it, and before the slow extension builds.
     */
    #[DataProvider('dockerfiles')]
    public function test_both_dockerfiles_run_the_check_first_thing_in_the_base_stage(string $path): void
    {
        $dockerfile = (string) file_get_contents($path);
        $base = strpos($dockerfile, ' AS base');
        $check = strpos($dockerfile, 'RUN SHIP_BASE_IMAGE=');
        $firstOtherRun = strpos($dockerfile, 'RUN ', (int) $check + 1);

        self::assertNotFalse($base);
        self::assertNotFalse($check);
        self::assertGreaterThan($base, $check);
        self::assertGreaterThan($check, $firstOtherRun);
        self::assertStringContainsString('ARG PHP_SECURITY_FLOOR=""', $dockerfile);
        self::assertStringContainsString('COPY ship/check-php-security-floor.php ', $dockerfile);
    }

    private function runStub(string $imageVersion, string $floor, ?string $baseImage = null): Process
    {
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__, 2) . '/stubs/docker/php/check-php-security-floor.php', $imageVersion],
            env: ['PHP_SECURITY_FLOOR' => $floor, 'SHIP_BASE_IMAGE' => $baseImage ?? false],
        );
        $process->run();

        return $process;
    }
}

<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ship\Console\Commands\UpCommand;
use Ship\Runtime\ProcessRunner;

final class UpCommandTest extends TestCase
{
    /**
     * `docker compose port` doesn't fail or print nothing when a container's port mapping exists
     * but the actual host bind never succeeded -- it prints the literal "invalid IP:0". Treating
     * any non-empty output as "bound" (as an earlier version of this check did) misses that case
     * entirely, silently reporting `ship up` as successful when a service is actually unreachable.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function portOutputCases(): iterable
    {
        yield 'a real bound port' => ['0.0.0.0:8080', true];
        yield 'a real bound port on a specific interface' => ['127.0.0.1:8080', true];
        yield 'empty output' => ['', false];
        yield 'the "invalid IP:0" sentinel a failed bind actually prints' => ['invalid IP:0', false];
    }

    #[DataProvider('portOutputCases')]
    public function test_it_tells_a_genuinely_bound_port_from_a_failed_ones_sentinel_output(
        string $output,
        bool $expectedBound,
    ): void {
        $command = new UpCommand(sys_get_temp_dir(), new ProcessRunner());
        $method = new \ReflectionMethod($command, 'looksActuallyBound');

        self::assertSame($expectedBound, $method->invoke($command, $output));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function portMappingCases(): iterable
    {
        yield 'plain host:container mapping' => ['80:80', '80'];
        yield 'env-var-with-default host side' => ['${APP_PORT:-80}:80', '80'];
    }

    #[DataProvider('portMappingCases')]
    public function test_it_extracts_the_container_side_port_from_a_compose_mapping(
        string $mapping,
        ?string $expected,
    ): void {
        $command = new UpCommand(sys_get_temp_dir(), new ProcessRunner());
        $method = new \ReflectionMethod($command, 'containerPortFrom');

        self::assertSame($expected, $method->invoke($command, $mapping));
    }
}

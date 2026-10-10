<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Console\Commands\UpCommand;
use Ship\Runtime\ProcessRunner;
use Ship\Support\ShipVersion;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Filesystem;

final class UpCommandTest extends TestCase
{
    /**
     * `docker compose port` prints the literal "invalid IP:0" when a mapping exists but the host
     * bind failed, so non-empty output alone doesn't mean "bound".
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
     * The wait is computed from each service's own healthcheck (start_period + interval x
     * retries, plus a buffer), since budgets range from 25s to 60s across services.
     *
     * @return iterable<string, array{array{interval?: string, retries?: int, start_period?: string}, int}>
     */
    public static function healthcheckBudgetCases(): iterable
    {
        yield 'MySQL/Postgres/Redis-shaped (5s interval, 5 retries, no start_period)' => [
            ['interval' => '5s', 'retries' => 5],
            0 + (5 * 5) + 5,
        ];
        yield 'Garage/RustFS/Silo-shaped (10s start_period, 5s interval, 10 retries)' => [
            ['interval' => '5s', 'retries' => 10, 'start_period' => '10s'],
            10 + (5 * 10) + 5,
        ];
        yield 'SeaweedFS-shaped (10s interval, 5 retries, no start_period)' => [
            ['interval' => '10s', 'retries' => 5],
            0 + (10 * 5) + 5,
        ];
        yield 'missing fields fall back to Docker-like defaults' => [
            [],
            0 + (30 * 3) + 5,
        ];
    }

    /**
     * @param array{interval?: string, retries?: int, start_period?: string} $healthcheck
     */
    #[DataProvider('healthcheckBudgetCases')]
    public function test_the_healthcheck_wait_budget_is_computed_from_the_services_own_healthcheck(array $healthcheck, int $expectedSeconds): void
    {
        $command = new UpCommand(sys_get_temp_dir(), new ProcessRunner());
        $method = new \ReflectionMethod($command, 'healthcheckBudgetSeconds');

        self::assertSame($expectedSeconds, $method->invoke($command, $healthcheck));
    }

    /**
     * Ship generates plain "<N>s" durations; anything else (a hand-written override's "1m30s")
     * falls back to 30s.
     */
    public function test_parse_seconds_falls_back_to_30_for_a_duration_it_does_not_recognize(): void
    {
        $command = new UpCommand(sys_get_temp_dir(), new ProcessRunner());
        $method = new \ReflectionMethod($command, 'parseSeconds');

        self::assertSame(30, $method->invoke($command, '1m30s'));
    }

    /**
     * A version mismatch only warns; ship/ is never republished automatically.
     */
    public function test_it_warns_when_the_recorded_stub_version_differs_from_whats_installed(): void
    {
        $projectRoot = $this->makeProjectRootWithRecordedVersion('some-other-version-1.2.3');

        $output = new BufferedOutput();
        $command = new UpCommand($projectRoot, new ProcessRunner());
        (new \ReflectionMethod($command, 'warnAboutStubVersionMismatch'))->invoke($command, $output);

        (new Filesystem())->remove($projectRoot);

        $warning = $output->fetch();

        self::assertStringContainsString('some-other-version-1.2.3', $warning);
        self::assertStringContainsString('overwrites the files in ship/', $warning);
    }

    public function test_it_stays_quiet_when_the_recorded_version_matches(): void
    {
        $projectRoot = $this->makeProjectRootWithRecordedVersion((string) ShipVersion::current());

        $output = new BufferedOutput();
        $command = new UpCommand($projectRoot, new ProcessRunner());
        (new \ReflectionMethod($command, 'warnAboutStubVersionMismatch'))->invoke($command, $output);

        (new Filesystem())->remove($projectRoot);

        self::assertSame('', $output->fetch());
    }

    public function test_it_stays_quiet_when_no_version_was_ever_recorded(): void
    {
        $projectRoot = sys_get_temp_dir() . '/ship-up-test-' . bin2hex(random_bytes(8));
        mkdir($projectRoot . '/ship', recursive: true);

        $output = new BufferedOutput();
        $command = new UpCommand($projectRoot, new ProcessRunner());
        (new \ReflectionMethod($command, 'warnAboutStubVersionMismatch'))->invoke($command, $output);

        (new Filesystem())->remove($projectRoot);

        self::assertSame('', $output->fetch());
    }

    private function makeProjectRootWithRecordedVersion(string $version): string
    {
        $projectRoot = sys_get_temp_dir() . '/ship-up-test-' . bin2hex(random_bytes(8));
        mkdir($projectRoot . '/ship', recursive: true);
        file_put_contents($projectRoot . '/ship/.ship-version', $version . "\n");

        return $projectRoot;
    }

    /**
     * FrankenPHP gets the same hostUser decision as any other runtime.
     */
    public function test_frankenphp_is_no_longer_excluded_from_host_user(): void
    {
        $command = new UpCommand(sys_get_temp_dir(), new ProcessRunner());
        $method = new \ReflectionMethod($command, 'resolveHostUser');

        $frankenConfig = new ShipConfig(phpVersion: '8.4', services: ['runtime' => 'octane-frankenphp'], hostUser: true);
        $swooleConfig = new ShipConfig(phpVersion: '8.4', services: ['runtime' => 'octane-swoole'], hostUser: true);

        $frankenOutput = new BufferedOutput();
        $frankenResult = $method->invoke($command, $frankenConfig, false, $frankenOutput);

        $swooleOutput = new BufferedOutput();
        $swooleResult = $method->invoke($command, $swooleConfig, false, $swooleOutput);

        self::assertSame($swooleResult, $frankenResult);
        self::assertSame($swooleOutput->fetch(), $frankenOutput->fetch());
    }
}

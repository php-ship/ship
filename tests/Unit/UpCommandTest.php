<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ship\Console\Commands\UpCommand;
use Ship\Runtime\ProcessRunner;
use Ship\Support\ShipVersion;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Filesystem;

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
     * Never re-publishes ship/ on its own (see the method's own docblock for why -- a project may
     * have hand-edited those files), only warns -- so this checks it produces the right warning
     * text rather than any side effect.
     */
    public function test_it_warns_when_the_recorded_stub_version_differs_from_whats_installed(): void
    {
        $projectRoot = $this->makeProjectRootWithRecordedVersion('some-other-version-1.2.3');

        $output = new BufferedOutput();
        $command = new UpCommand($projectRoot, new ProcessRunner());
        (new \ReflectionMethod($command, 'warnAboutStubVersionMismatch'))->invoke($command, $output);

        (new Filesystem())->remove($projectRoot);

        self::assertStringContainsString('some-other-version-1.2.3', $output->fetch());
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
}

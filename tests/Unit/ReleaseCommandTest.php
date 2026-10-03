<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Console\Commands\ReleaseCommand;
use Ship\Runtime\ProcessRunner;
use Ship\Services\MySqlService;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class ReleaseCommandTest extends TestCase
{
    public function test_a_valid_tag_given_via_the_option_is_returned_as_is(): void
    {
        self::assertSame('1.2.0', $this->resolveTag(['--tag' => '1.2.0'], interactive: true));
    }

    /**
     * CI reliability is the whole point of requiring --tag there (see the instruction this command
     * was built from): a prompt that never gets an answer in a non-interactive pipeline would hang
     * it instead of failing it loudly, so this must fail immediately rather than attempt to ask.
     */
    public function test_a_missing_tag_fails_immediately_in_a_non_interactive_session_instead_of_prompting(): void
    {
        $output = new BufferedOutput();

        self::assertNull($this->resolveTag([], interactive: false, output: $output));
        self::assertStringContainsString('--tag is required', $output->fetch());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTags(): iterable
    {
        yield 'empty string' => [''];
        yield 'starts with a dash' => ['-bad'];
        yield 'starts with a dot' => ['.bad'];
        yield 'contains a slash' => ['1.2/0'];
        yield 'contains whitespace' => ['1.2 0'];
    }

    #[DataProvider('invalidTags')]
    public function test_an_invalid_tag_is_rejected_cleanly(string $tag): void
    {
        $output = new BufferedOutput();

        self::assertNull($this->resolveTag(['--tag' => $tag], interactive: true, output: $output));
        self::assertStringContainsString('not a valid release tag', $output->fetch());
    }

    /**
     * Regression coverage for a real bug found via an independent audit: `docker save`'s own exit
     * code was previously ignored entirely, so a release could report success with a missing or
     * truncated tar. A nonexistent image tag makes `docker save` itself fail predictably, without
     * needing a real image to actually exist.
     *
     * Skipped, not silently "passed", when Docker itself isn't installed -- a real gap found via
     * an independent re-audit: `docker save` against a nonexistent tag and `docker` not existing
     * on PATH at all both make `runInteractive()` return a non-zero exit code, so this assertion
     * held either way, but only the former is actually exercising exportImages()'s own handling
     * of a *genuine* `docker save` failure. Without Docker, this was passing for an unrelated
     * reason -- "the `docker` command couldn't even start" -- that proves nothing about the code
     * under test.
     */
    public function test_export_images_fails_when_docker_save_fails(): void
    {
        if ((new ProcessRunner())->runQuiet(['docker', '--version']) === '') {
            self::markTestSkipped('Docker is not available -- this test needs a real `docker save` failure to verify against.');
        }

        $releaseDir = sys_get_temp_dir() . '/ship-export-images-test-' . bin2hex(random_bytes(8));
        mkdir($releaseDir . '/images', recursive: true);

        $command = new ReleaseCommand(sys_get_temp_dir(), new ProcessRunner());
        $images = [['tag' => 'ship-test-image-that-does-not-exist:none', 'canonicalService' => 'app', 'members' => ['app']]];

        $method = new \ReflectionMethod($command, 'exportImages');
        $succeeded = $method->invoke($command, $images, $releaseDir, new BufferedOutput());

        self::assertFalse($succeeded);
        self::assertFileDoesNotExist($releaseDir . '/images/app.tar');

        (new \Symfony\Component\Filesystem\Filesystem())->remove($releaseDir);
    }

    /**
     * Regression coverage for a real bug found via an independent audit: chmod() is a silent
     * no-op on Windows (NTFS has no Unix executable bit for it to set), so deploy-commands.sh
     * written on a Windows dev machine was never actually executable once copied to the server --
     * with nothing ever telling the operator that.
     */
    public function test_windows_executable_bit_warning_fires_only_on_windows_with_deploy_commands(): void
    {
        $command = new ReleaseCommand(sys_get_temp_dir(), new ProcessRunner());
        $method = new \ReflectionMethod($command, 'windowsExecutableBitWarning');

        $withCommands = new ShipConfig(phpVersion: '8.4', services: [], deployCommands: ['php artisan migrate']);
        $withoutCommands = new ShipConfig(phpVersion: '8.4', services: []);

        self::assertStringContainsString('chmod +x', (string) $method->invoke($command, $withCommands, 'Windows'));
        self::assertNull($method->invoke($command, $withoutCommands, 'Windows'));
        self::assertNull($method->invoke($command, $withCommands, 'Linux'));
    }

    /**
     * Regression coverage for a real bug via a seventh independent audit: `ship release` only
     * ever checked that .env.production *exists*, not that every variable Compose will actually
     * require is set in it (the same check ConfigTestCommand's own dry run already made) -- a
     * missing DB_PASSWORD surfaced as a raw `docker compose build` interpolation error instead,
     * assuming Docker was even installed to produce it at all. No Docker involved in this test:
     * failing this check happens before ProductionBuildRunner -- and therefore Docker -- is ever
     * reached.
     */
    public function test_release_fails_fast_on_a_missing_required_production_variable(): void
    {
        $projectRoot = sys_get_temp_dir() . '/ship-release-env-check-' . bin2hex(random_bytes(8));
        mkdir($projectRoot, recursive: true);
        (new ShipConfig(phpVersion: '8.4', services: ['database' => 'mysql']))->toFile($projectRoot . '/ship.json');
        file_put_contents($projectRoot . '/.env.production', "APP_KEY=base64:something\n");

        $command = new ReleaseCommand($projectRoot, new ProcessRunner(), new ServiceRegistry([new MySqlService()]), []);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--tag' => '1.0.0']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('DB_PASSWORD', $tester->getDisplay());
        self::assertStringContainsString('.env.production', $tester->getDisplay());
        self::assertDirectoryDoesNotExist($projectRoot . '/dist/ship/1.0.0');

        (new Filesystem())->remove($projectRoot);
    }

    /**
     * @param array<string, string> $options
     */
    private function resolveTag(array $options, bool $interactive, ?BufferedOutput $output = null): ?string
    {
        $command = new ReleaseCommand(sys_get_temp_dir(), new ProcessRunner());
        $input = new ArrayInput($options, $command->getDefinition());
        $input->setInteractive($interactive);

        $method = new \ReflectionMethod($command, 'resolveTag');

        return $method->invoke($command, $input, $output ?? new BufferedOutput());
    }
}

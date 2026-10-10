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
     * A prompt would hang a non-interactive pipeline, so a missing --tag fails immediately.
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
     * exportImages() must fail when `docker save` does; a nonexistent tag makes it fail
     * predictably. Skipped without Docker, where the assertion would hold for the wrong reason.
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
     * chmod() is a no-op on Windows, so deploy-commands.sh built there isn't executable on the
     * server; the warning says so.
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

    public function test_copied_production_environment_is_private_on_posix(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows file access is controlled by ACLs rather than POSIX modes.');
        }

        $releaseDir = sys_get_temp_dir() . '/ship-release-permissions-' . bin2hex(random_bytes(8));
        mkdir($releaseDir, 0o700);
        $source = $releaseDir . '/source.env';
        file_put_contents($source, "APP_KEY=secret\n");
        chmod($source, 0o644);

        try {
            $command = new ReleaseCommand(sys_get_temp_dir(), new ProcessRunner());
            (new \ReflectionMethod($command, 'copyProductionEnv'))->invoke($command, $source, $releaseDir);

            self::assertSame(file_get_contents($source), file_get_contents($releaseDir . '/.env'));
            self::assertSame(0o600, fileperms($releaseDir . '/.env') & 0o777);
        } finally {
            (new Filesystem())->remove($releaseDir);
        }
    }

    /**
     * A required production variable missing from .env.production fails before
     * ProductionBuildRunner, and so Docker, is reached.
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

<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ship\Console\Commands\ReleaseCommand;
use Ship\Runtime\ProcessRunner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

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
     */
    public function test_export_images_fails_when_docker_save_fails(): void
    {
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

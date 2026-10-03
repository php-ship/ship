<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Docker\ComposeCommand;

final class ComposeCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ship-test-' . uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*') ?: []);
        rmdir($this->tmpDir);
    }

    public function test_it_omits_the_override_flag_when_no_override_file_exists(): void
    {
        $args = ComposeCommand::baseArgs($this->tmpDir);

        self::assertSame(
            ['docker', 'compose', '-f', $this->tmpDir . '/ship/docker-compose.generated.yml', '--project-directory', $this->tmpDir],
            $args,
        );
    }

    public function test_it_adds_a_second_f_flag_when_an_override_file_exists(): void
    {
        touch($this->tmpDir . '/docker-compose.override.yml');

        $args = ComposeCommand::baseArgs($this->tmpDir);

        self::assertSame(
            [
                'docker', 'compose',
                '-f', $this->tmpDir . '/ship/docker-compose.generated.yml',
                '-f', $this->tmpDir . '/docker-compose.override.yml',
                '--project-directory', $this->tmpDir,
            ],
            $args,
        );
    }

    /**
     * Regression coverage for a real bug found via an independent audit: the override file is a
     * dev convenience, but ProductionBuildRunner's own production build picked it up
     * unconditionally like every other caller -- a project's dev-only `build:` customization
     * silently leaked into the production image, and the release's exported docker-compose.yml
     * never matched what was actually built as a result.
     */
    public function test_include_override_false_omits_it_even_when_the_file_exists(): void
    {
        touch($this->tmpDir . '/docker-compose.override.yml');

        $args = ComposeCommand::baseArgs($this->tmpDir, includeOverride: false);

        self::assertSame(
            ['docker', 'compose', '-f', $this->tmpDir . '/ship/docker-compose.generated.yml', '--project-directory', $this->tmpDir],
            $args,
        );
    }
}

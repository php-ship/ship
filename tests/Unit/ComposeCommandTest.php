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
     * The override file is a dev convenience and must not reach a production build.
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

    /**
     * `ship build`/`ship release` use their own compose file (see
     * ComposeCommand::PRODUCTION_COMPOSE_FILE) instead of overwriting the dev one.
     */
    public function test_a_custom_compose_file_overrides_the_dev_default(): void
    {
        $args = ComposeCommand::baseArgs($this->tmpDir, composeFile: ComposeCommand::PRODUCTION_COMPOSE_FILE);

        self::assertSame(
            ['docker', 'compose', '-f', $this->tmpDir . '/ship/docker-compose.production.yml', '--project-directory', $this->tmpDir],
            $args,
        );
    }
}

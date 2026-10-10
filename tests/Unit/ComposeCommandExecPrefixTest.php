<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Docker\ComposeCommand;
use Symfony\Component\Filesystem\Filesystem;

final class ComposeCommandExecPrefixTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/ship-compose-command-test-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot . '/ship', recursive: true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectRoot);
    }

    private function generated(string $contents): void
    {
        file_put_contents($this->projectRoot . '/ship/docker-compose.generated.yml', $contents);
    }

    public function test_exec_has_no_user_when_nothing_was_generated_yet(): void
    {
        self::assertSame('exec', ComposeCommand::execPrefix($this->projectRoot, 'app')[array_key_last(ComposeCommand::execPrefix($this->projectRoot, 'app'))]);
    }

    public function test_exec_into_the_app_service_runs_as_the_host_user_the_file_was_generated_for(): void
    {
        $this->generated("services:\n  admin-app: {}\nx-ship:\n  hostUser: '1000:1001'\n  appService: admin-app\n");

        $prefix = ComposeCommand::execPrefix($this->projectRoot, 'admin-app');

        self::assertSame(['exec', '--user', '1000:1001'], array_slice($prefix, -3));
    }

    /**
     * Only the app writes host-owned files; a database container has no such user.
     */
    public function test_exec_into_any_other_service_is_left_alone(): void
    {
        $this->generated("services:\n  admin-app: {}\nx-ship:\n  hostUser: '1000:1001'\n  appService: admin-app\n");

        $prefix = ComposeCommand::execPrefix($this->projectRoot, 'mysql');

        self::assertSame('exec', $prefix[array_key_last($prefix)]);
    }

    /**
     * Read from the generated file, not ship.json: a file without the marker never gets --user.
     */
    public function test_a_file_without_the_marker_never_adds_a_user(): void
    {
        $this->generated("services:\n  app: {}\n");

        $prefix = ComposeCommand::execPrefix($this->projectRoot, 'app');

        self::assertNotContains('--user', $prefix);
    }

    /**
     * A malformed generated file falls back to no --user prefix instead of a ParseException.
     */
    public function test_malformed_yaml_falls_back_to_no_user_instead_of_throwing(): void
    {
        $this->generated("services:\n  app: {}\nx-ship:\n  this is not: [valid, yaml");

        $prefix = ComposeCommand::execPrefix($this->projectRoot, 'app');

        self::assertSame('exec', $prefix[array_key_last($prefix)]);
    }
}

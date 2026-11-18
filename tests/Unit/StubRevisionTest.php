<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Support\StubRevision;
use Symfony\Component\Filesystem\Filesystem;

final class StubRevisionTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/ship-stub-revision-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot . '/ship', recursive: true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectRoot);
    }

    /**
     * The revision `ship init` publishes is the one this version requires.
     */
    public function test_the_published_stubs_carry_the_required_revision(): void
    {
        self::assertSame((string) StubRevision::REQUIRED, trim((string) file_get_contents(dirname(__DIR__, 2) . '/stubs/docker/php/.stub-revision')));
    }

    public function test_stubs_published_before_revisions_existed_count_as_revision_one(): void
    {
        self::assertSame(1, StubRevision::published($this->projectRoot));
        self::assertStringContainsString('revision 1', (string) StubRevision::problem($this->projectRoot));
    }

    public function test_the_required_revision_or_a_newer_one_is_fine(): void
    {
        file_put_contents($this->projectRoot . '/ship/.stub-revision', StubRevision::REQUIRED . "\n");
        self::assertNull(StubRevision::problem($this->projectRoot));

        file_put_contents($this->projectRoot . '/ship/.stub-revision', (StubRevision::REQUIRED + 1) . "\n");
        self::assertNull(StubRevision::problem($this->projectRoot));
    }
}

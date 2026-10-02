<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Docker\ReleaseManifest;
use Ship\Runtime\ProcessRunner;

final class ReleaseManifestTest extends TestCase
{
    public function test_it_lists_the_tag_and_images_without_any_secret(): void
    {
        $images = [
            ['tag' => 'acme-app:1.2.0', 'canonicalService' => 'app', 'members' => ['app', 'horizon']],
        ];

        $manifest = ReleaseManifest::build('1.2.0', sys_get_temp_dir(), new ProcessRunner(), $images);

        self::assertSame('1.2.0', $manifest['tag']);
        self::assertSame(
            [['tag' => 'acme-app:1.2.0', 'services' => ['app', 'horizon'], 'file' => 'images/app.tar']],
            $manifest['images'],
        );
        self::assertArrayHasKey('createdAt', $manifest);
    }

    /**
     * A release has to succeed whether or not the project is even a git repository -- this is
     * metadata, not a requirement, so a directory that isn't a repo at all (a plain temp dir, not
     * just an uncommitted one) must get null, not a command failure bubbling up.
     */
    public function test_git_commit_is_null_outside_a_git_repository(): void
    {
        $outsideGit = sys_get_temp_dir();

        $manifest = ReleaseManifest::build('1.2.0', $outsideGit, new ProcessRunner(), []);

        self::assertNull($manifest['gitCommit']);
    }
}

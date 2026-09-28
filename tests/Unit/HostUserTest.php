<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Docker\HostUser;

final class HostUserTest extends TestCase
{
    /**
     * Environment-dependent by nature: null on native Windows (no POSIX functions) and when running
     * as root, a real non-root uid/gid pair anywhere else. Either is correct -- what must never
     * happen is a half-answer, or uid 0 being reported as a user to match.
     */
    public function test_it_reports_nothing_or_a_real_non_root_user(): void
    {
        $user = HostUser::detect();

        if ($user === null) {
            self::assertTrue(!function_exists('posix_getuid') || posix_getuid() === 0);

            return;
        }

        self::assertGreaterThan(0, $user['uid']);
        self::assertGreaterThanOrEqual(0, $user['gid']);
    }
}

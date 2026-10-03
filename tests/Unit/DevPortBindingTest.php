<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\DevPortBinding;

final class DevPortBindingTest extends TestCase
{
    public function test_development_binds_to_loopback(): void
    {
        self::assertSame(
            '127.0.0.1:${APP_PORT:-80}:80',
            DevPortBinding::bind('${APP_PORT:-80}:80', ShipEnvironment::Development),
        );
    }

    public function test_production_is_left_unchanged(): void
    {
        self::assertSame(
            '${APP_PORT:-80}:80',
            DevPortBinding::bind('${APP_PORT:-80}:80', ShipEnvironment::Production),
        );
    }
}

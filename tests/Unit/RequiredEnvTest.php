<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\RequiredEnv;

final class RequiredEnvTest extends TestCase
{
    public function test_development_gets_a_friendly_default(): void
    {
        self::assertSame('${DB_PASSWORD:-secret}', RequiredEnv::expr('DB_PASSWORD', 'secret', ShipEnvironment::Development));
    }

    /**
     * Regression coverage for a real bug found via an independent audit: production previously
     * used the exact same `${VAR:-default}` expression as development, so a `.env.production`
     * that forgot (or never had) a real value silently shared the same publicly-known default
     * every such project would. `:?` makes `docker compose` itself refuse to run at all instead.
     */
    public function test_production_requires_a_real_value_instead_of_a_default(): void
    {
        self::assertSame(
            '${DB_PASSWORD:?set a real value in .env.production}',
            RequiredEnv::expr('DB_PASSWORD', 'secret', ShipEnvironment::Production),
        );
    }
}

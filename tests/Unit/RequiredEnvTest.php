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
     * Production uses `:?`, not the same `${VAR:-default}` expression development uses -- a
     * `.env.production` that forgot (or never had) a real value would otherwise silently share
     * the same publicly-known default every such project would. `:?` makes `docker compose`
     * itself refuse to run at all instead.
     */
    public function test_production_requires_a_real_value_instead_of_a_default(): void
    {
        self::assertSame(
            '${DB_PASSWORD:?set a real value in .env.production}',
            RequiredEnv::expr('DB_PASSWORD', 'secret', ShipEnvironment::Production),
        );
    }

    public function test_missing_from_finds_every_required_variable_not_set(): void
    {
        $compose = 'DB_PASSWORD: ${DB_PASSWORD:?set a real value}' . "\n"
            . 'DB_ROOT_PASSWORD: ${DB_ROOT_PASSWORD:?set a real value}';

        self::assertSame(
            ['DB_PASSWORD', 'DB_ROOT_PASSWORD'],
            RequiredEnv::missingFrom($compose, ['DB_PASSWORD' => '']),
        );
    }

    public function test_missing_from_is_empty_once_every_required_variable_is_set(): void
    {
        $compose = 'DB_PASSWORD: ${DB_PASSWORD:?set a real value}';

        self::assertSame([], RequiredEnv::missingFrom($compose, ['DB_PASSWORD' => 'real-secret']));
    }

    public function test_missing_from_is_empty_when_nothing_is_required_at_all(): void
    {
        self::assertSame([], RequiredEnv::missingFrom('APP_KEY: ${APP_KEY:-}', []));
    }
}

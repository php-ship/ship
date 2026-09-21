<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\NodeService;

/**
 * Deliberately shallow (see this service's own docblock: Node installs unconditionally in the
 * base image regardless of selection) -- this only locks in the identity contract ship init's
 * prompt-building and ServiceRegistry::inGroup() depend on.
 */
final class NodeServiceTest extends TestCase
{
    public function test_it_contributes_nothing_to_the_compose_file(): void
    {
        $service = new NodeService();

        self::assertSame([], $service->composeFragment(ShipEnvironment::Development));
        self::assertSame([], $service->environmentVariables());
        self::assertSame([], $service->removes());
    }

    public function test_it_identifies_itself_as_the_frontend_group(): void
    {
        $service = new NodeService();

        self::assertSame('node', $service->key());
        self::assertSame('frontend', $service->group());
    }
}

<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ship\Services\DuskService;
use Ship\Services\GarageService;
use Ship\Services\MailpitService;
use Ship\Services\MeilisearchService;
use Ship\Services\MySqlService;
use Ship\Services\NodeService;
use Ship\Services\OctaneFrankenPhpService;
use Ship\Services\OctaneRoadRunnerService;
use Ship\Services\OctaneSwooleService;
use Ship\Services\PostgresService;
use Ship\Services\RedisService;
use Ship\Services\ReverbService;
use Ship\Services\SeaweedFsService;
use Ship\Services\ServiceRegistry;

final class ServiceRegistryTest extends TestCase
{
    public function test_get_throws_for_an_unregistered_key(): void
    {
        $registry = new ServiceRegistry();

        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage('No registered service with key "pgsql"');

        $registry->get('pgsql');
    }

    public function test_get_returns_a_registered_service_by_its_key(): void
    {
        $registry = new ServiceRegistry([new PostgresService()]);

        self::assertInstanceOf(PostgresService::class, $registry->get('pgsql'));
    }

    public function test_registering_the_same_key_twice_lets_the_later_one_win(): void
    {
        $registry = new ServiceRegistry([new PostgresService()]);
        $replacement = new PostgresService();
        $registry->register($replacement);

        self::assertSame($replacement, $registry->get('pgsql'));
        self::assertCount(1, $registry->all());
    }

    public function test_in_group_returns_only_services_in_that_group(): void
    {
        $registry = new ServiceRegistry([new PostgresService(), new RedisService(), new MySqlService()]);

        $database = $registry->inGroup('database');

        self::assertCount(2, $database);
        self::assertSame(['pgsql', 'mysql'], array_map(static fn ($s) => $s->key(), $database));
    }

    public function test_in_group_returns_an_empty_list_for_a_group_with_nothing_registered(): void
    {
        $registry = new ServiceRegistry([new PostgresService()]);

        self::assertSame([], $registry->inGroup('search'));
    }

    /**
     * Guards against the one way this class could silently regress: a new ServiceDefinition gets
     * written and registered nowhere, or defaults() stops matching what's actually shipped --
     * either way, `ship init` would just never offer it, with nothing else here to notice.
     *
     * @return iterable<string, array{class-string}>
     */
    public static function everyBuiltInService(): iterable
    {
        yield 'PostgresService' => [PostgresService::class];
        yield 'MySqlService' => [MySqlService::class];
        yield 'RedisService' => [RedisService::class];
        yield 'SeaweedFsService' => [SeaweedFsService::class];
        yield 'GarageService' => [GarageService::class];
        yield 'OctaneSwooleService' => [OctaneSwooleService::class];
        yield 'OctaneRoadRunnerService' => [OctaneRoadRunnerService::class];
        yield 'OctaneFrankenPhpService' => [OctaneFrankenPhpService::class];
        yield 'MeilisearchService' => [MeilisearchService::class];
        yield 'MailpitService' => [MailpitService::class];
        yield 'DuskService' => [DuskService::class];
        yield 'NodeService' => [NodeService::class];
        yield 'ReverbService' => [ReverbService::class];
    }

    /**
     * @param class-string $expectedClass
     */
    #[DataProvider('everyBuiltInService')]
    public function test_defaults_registers_every_built_in_service(string $expectedClass): void
    {
        $classes = array_map(get_class(...), ServiceRegistry::defaults());

        self::assertContains($expectedClass, $classes);
    }

    public function test_defaults_registers_exactly_the_expected_number_of_services(): void
    {
        // A deliberately redundant count alongside the per-class check above: that one only ever
        // proves nothing was *removed*, not that this list matches the intended set exactly --
        // catches an extra/duplicate registration the same way.
        self::assertCount(13, ServiceRegistry::defaults());
    }
}

<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;
use Ship\Extensions\ExtensionLoader;
use Ship\Services\ServiceRegistry;

final class FakeExtensionService implements ServiceDefinition
{
    public function key(): string
    {
        return 'fake-extension';
    }

    public function label(): string
    {
        return 'Fake Extension';
    }

    public function group(): string
    {
        return 'testing-only';
    }

    public function composeFragment(ShipEnvironment $environment): array
    {
        return [];
    }

    public function environmentVariables(): array
    {
        return [];
    }

    public function removes(): array
    {
        return [];
    }
}

final class NeitherContractFixture
{
}

final class ExtensionLoaderTest extends TestCase
{
    public function test_it_registers_a_valid_service_definition_class(): void
    {
        $registry = new ServiceRegistry();
        $warnings = (new ExtensionLoader())->load([FakeExtensionService::class], $registry);

        self::assertSame([], $warnings);
        self::assertSame('fake-extension', $registry->get('fake-extension')->key());
    }

    public function test_it_warns_on_a_class_that_does_not_exist(): void
    {
        $registry = new ServiceRegistry();
        $warnings = (new ExtensionLoader())->load(['Totally\\Nonexistent\\ClassName'], $registry);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('was not found', $warnings[0]);
    }

    public function test_it_warns_on_a_class_implementing_neither_contract(): void
    {
        $registry = new ServiceRegistry();
        $warnings = (new ExtensionLoader())->load([NeitherContractFixture::class], $registry);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('implements neither', $warnings[0]);
    }
}

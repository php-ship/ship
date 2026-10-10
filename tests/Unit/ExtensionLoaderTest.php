<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\FrameworkAdapter;
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

    public function composeFragment(ShipEnvironment $environment, ?string $instanceName = null): array
    {
        return [];
    }

    public function environmentVariables(?string $instanceName = null): array
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

final class FakeExtensionFrameworkAdapter implements FrameworkAdapter
{
    public function detect(string $projectRoot): bool
    {
        return true;
    }

    public function consoleCommands(): array
    {
        return [];
    }

    public function releaseCommands(): array
    {
        return [];
    }
}

final class ExtensionLoaderTest extends TestCase
{
    public function test_it_registers_a_valid_service_definition_class(): void
    {
        $registry = new ServiceRegistry();
        $result = (new ExtensionLoader())->load([FakeExtensionService::class], $registry);

        self::assertSame([], $result['warnings']);
        self::assertSame([], $result['frameworkAdapters']);
        self::assertSame('fake-extension', $registry->get('fake-extension')->key());
    }

    public function test_it_warns_on_a_class_that_does_not_exist(): void
    {
        $registry = new ServiceRegistry();
        $result = (new ExtensionLoader())->load(['Totally\\Nonexistent\\ClassName'], $registry);

        self::assertCount(1, $result['warnings']);
        self::assertStringContainsString('was not found', $result['warnings'][0]);
    }

    public function test_it_warns_on_a_class_implementing_neither_contract(): void
    {
        $registry = new ServiceRegistry();
        $result = (new ExtensionLoader())->load([NeitherContractFixture::class], $registry);

        self::assertCount(1, $result['warnings']);
        self::assertStringContainsString('implements neither', $result['warnings'][0]);
    }

    /**
     * One load() call returns both the registered services and the FrameworkAdapters.
     */
    public function test_it_resolves_a_framework_adapter_class_in_the_same_pass(): void
    {
        $registry = new ServiceRegistry();
        $result = (new ExtensionLoader())->load([FakeExtensionFrameworkAdapter::class], $registry);

        self::assertSame([], $result['warnings']);
        self::assertCount(1, $result['frameworkAdapters']);
        self::assertInstanceOf(FakeExtensionFrameworkAdapter::class, $result['frameworkAdapters'][0]);
    }
}

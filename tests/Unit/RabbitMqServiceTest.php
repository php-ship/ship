<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\RabbitMqService;

final class RabbitMqServiceTest extends TestCase
{
    public function test_app_credentials_match_what_rabbitmq_is_provisioned_with(): void
    {
        $service = new RabbitMqService();
        $rabbitEnv = $service->composeFragment(ShipEnvironment::Development)['rabbitmq']['environment'];
        $appEnv = $service->environmentVariables();

        self::assertSame($rabbitEnv['RABBITMQ_DEFAULT_USER'], $appEnv['RABBITMQ_USER']);
        self::assertSame($rabbitEnv['RABBITMQ_DEFAULT_PASS'], $appEnv['RABBITMQ_PASSWORD']);
        self::assertSame('rabbitmq', $appEnv['RABBITMQ_HOST']);
        self::assertSame('5672', $appEnv['RABBITMQ_PORT']);
        self::assertSame('/', $appEnv['RABBITMQ_VHOST']);
    }

    /**
     * Without laravel-queue-rabbitmq installed there is no "rabbitmq" queue connection.
     */
    public function test_it_leaves_the_apps_queue_connection_alone(): void
    {
        self::assertArrayNotHasKey('QUEUE_CONNECTION', (new RabbitMqService())->environmentVariables());
    }

    public function test_the_password_is_required_in_production_not_just_overridable(): void
    {
        $env = (new RabbitMqService())->composeFragment(ShipEnvironment::Production)['rabbitmq']['environment'];

        self::assertSame('${RABBITMQ_PASSWORD:?set a real value in .env.production}', $env['RABBITMQ_DEFAULT_PASS']);
    }

    /**
     * RabbitMQ's data directory is keyed by node name, which defaults to the hostname.
     */
    public function test_a_fixed_hostname_keeps_the_persisted_data_across_container_recreation(): void
    {
        $fragment = (new RabbitMqService())->composeFragment(ShipEnvironment::Production);

        self::assertSame('rabbitmq', $fragment['rabbitmq']['hostname']);
        self::assertSame(['ship-rabbitmq-data:/var/lib/rabbitmq'], $fragment['rabbitmq']['volumes']);
    }

    public function test_the_management_ui_is_published_on_loopback_in_development_only(): void
    {
        $service = new RabbitMqService();

        self::assertSame(
            ['127.0.0.1:${RABBITMQ_MANAGEMENT_PORT:-15672}:15672'],
            $service->composeFragment(ShipEnvironment::Development)['rabbitmq']['ports'],
        );
        self::assertSame([], $service->composeFragment(ShipEnvironment::Production)['rabbitmq']['ports']);
    }

    public function test_it_has_a_healthcheck_with_a_start_period_for_the_erlang_vm_boot(): void
    {
        $healthcheck = (new RabbitMqService())->composeFragment(ShipEnvironment::Development)['rabbitmq']['healthcheck'];

        self::assertSame(['CMD', 'rabbitmq-diagnostics', '-q', 'ping'], $healthcheck['test']);
        self::assertSame('20s', $healthcheck['start_period']);
    }

    public function test_it_is_the_broker_group(): void
    {
        self::assertSame('broker', (new RabbitMqService())->group());
    }
}

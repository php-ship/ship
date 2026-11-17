<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\RequiresPhpExtensions;
use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\DevPortBinding;
use Ship\Docker\RequiredEnv;

/**
 * RabbitMQ with its management plugin. The app gets RABBITMQ_HOST/PORT/USER/PASSWORD/VHOST, the
 * variables vladimir-yuldashev/laravel-queue-rabbitmq reads. QUEUE_CONNECTION is left alone: until
 * that package is installed, a "rabbitmq" queue connection doesn't exist and every dispatch would
 * fail. One per project, like the other non-data groups, so $instanceName is ignored.
 */
final class RabbitMqService implements ServiceDefinition, RequiresPhpExtensions
{
    public function key(): string
    {
        return 'rabbitmq';
    }

    public function label(): string
    {
        return 'RabbitMQ';
    }

    public function group(): string
    {
        return 'broker';
    }

    public function composeFragment(ShipEnvironment $environment, ?string $instanceName = null): array
    {
        return [
            'rabbitmq' => [
                'image' => 'rabbitmq:4.4-management-alpine',
                // RabbitMQ keeps its data under a node name derived from the hostname. Compose's
                // default hostname is the container id, which changes whenever the container is
                // recreated, so the persisted queues would be orphaned on every redeploy.
                'hostname' => 'rabbitmq',
                'environment' => [
                    'RABBITMQ_DEFAULT_USER' => '${RABBITMQ_USER:-app}',
                    // A default in dev, required in production (see RequiredEnv).
                    'RABBITMQ_DEFAULT_PASS' => RequiredEnv::expr('RABBITMQ_PASSWORD', 'secret', $environment),
                ],
                // Persisted in both environments, like every stateful service: durable queues and
                // their messages must survive a restart or redeploy.
                'volumes' => ['ship-rabbitmq-data:/var/lib/rabbitmq'],
                // The management UI, in development only: in production, publishPorts would
                // otherwise expose an admin console to the host's network.
                'ports' => $environment->isDevelopment()
                    ? [DevPortBinding::bind('${RABBITMQ_MANAGEMENT_PORT:-15672}:15672', $environment)]
                    : [],
                'healthcheck' => [
                    'test' => ['CMD', 'rabbitmq-diagnostics', '-q', 'ping'],
                    'interval' => '10s',
                    'timeout' => '10s',
                    'retries' => 12,
                    // The Erlang VM takes a while to boot; failures during this window don't count.
                    'start_period' => '20s',
                ],
            ],
        ];
    }

    public function environmentVariables(?string $instanceName = null): array
    {
        return [
            'RABBITMQ_HOST' => 'rabbitmq',
            'RABBITMQ_PORT' => '5672',
            // The same expressions composeFragment() provisions RabbitMQ with.
            'RABBITMQ_USER' => '${RABBITMQ_USER:-app}',
            'RABBITMQ_PASSWORD' => '${RABBITMQ_PASSWORD:-secret}',
            'RABBITMQ_VHOST' => '/',
        ];
    }

    public function removes(): array
    {
        return [];
    }

    /**
     * php-amqplib, the client under laravel-queue-rabbitmq, requires ext-sockets; without it
     * `composer require` fails.
     */
    public function requiredPhpExtensions(): array
    {
        return ['sockets'];
    }
}

<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;

final class MailpitService implements ServiceDefinition
{
    use SupportsNamedInstances;

    public function key(): string
    {
        return 'mailpit';
    }

    public function label(): string
    {
        return 'Mailpit (local mail capture)';
    }

    public function group(): string
    {
        return 'mail';
    }

    public function composeFragment(ShipEnvironment $environment, ?string $instanceName = null): array
    {
        $name = $this->composeServiceName($instanceName);

        return [
            $name => [
                'image' => 'axllent/mailpit:v1.31',
                // Only the default instance publishes its web UI on a host port -- there's no
                // single sane default host port for an arbitrary number of named instances
                // without risking a collision. A named instance's UI is still reachable inside
                // the Docker network; add a docker-compose.override.yml entry to publish one too.
                'ports' => $instanceName === null ? ['${MAILPIT_WEB_PORT:-8025}:8025'] : [],
            ],
        ];
    }

    public function environmentVariables(?string $instanceName = null): array
    {
        $name = $this->composeServiceName($instanceName);
        $prefix = $this->envPrefix($instanceName);

        return [
            "{$prefix}MAIL_HOST" => $name,
            "{$prefix}MAIL_PORT" => '1025',
            "{$prefix}MAIL_ENCRYPTION" => 'null',
        ];
    }

    public function removes(): array
    {
        return [];
    }
}

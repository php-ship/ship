<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\DevPortBinding;

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
        // Dev/test tooling. In production its MAIL_HOST would override the real mail config in
        // .env.production (`environment:` beats `env_file:`) and capture real mail, so an empty
        // fragment keeps it out entirely (see ComposeFileBuilder::applyService()).
        if (!$environment->isDevelopment()) {
            return [];
        }

        $name = $this->composeServiceName($instanceName);

        return [
            $name => [
                'image' => 'axllent/mailpit:v1.31',
                // Only the default instance publishes its web UI: there's no collision-free
                // default host port for named instances. Publish one via
                // docker-compose.override.yml if needed.
                'ports' => $instanceName === null ? [DevPortBinding::bind('${MAILPIT_WEB_PORT:-8025}:8025', $environment)] : [],
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

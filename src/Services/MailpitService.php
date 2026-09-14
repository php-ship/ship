<?php

declare(strict_types=1);

namespace Ship\Services;

use Ship\Contracts\ServiceDefinition;
use Ship\Contracts\ShipEnvironment;

final class MailpitService implements ServiceDefinition
{
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

    public function composeFragment(ShipEnvironment $environment): array
    {
        return [
            'mailpit' => [
                'image' => 'axllent/mailpit:v1.31',
                'ports' => ['${MAILPIT_WEB_PORT:-8025}:8025'],
            ],
        ];
    }

    public function environmentVariables(): array
    {
        return [
            'MAIL_HOST' => 'mailpit',
            'MAIL_PORT' => '1025',
            'MAIL_ENCRYPTION' => 'null',
        ];
    }

    public function removes(): array
    {
        return [];
    }
}

<?php

declare(strict_types=1);

namespace Ship\Contracts;

enum ShipEnvironment: string
{
    case Development = 'development';
    case Production = 'production';

    public function isDevelopment(): bool
    {
        return $this === self::Development;
    }
}

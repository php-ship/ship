<?php

declare(strict_types=1);

namespace Ship\Contracts;

use Ship\Config\ShipConfig;

/**
 * Optional add-on for a ServiceDefinition whose compose fragment depends on a ship.json setting
 * (e.g. SiloService's image variant). ComposeFileBuilder calls withConfig() before asking the
 * service for its fragment and env vars.
 */
interface ConfigAwareService
{
    public function withConfig(ShipConfig $config): static;
}

<?php

declare(strict_types=1);

namespace Ship\Support;

use Composer\InstalledVersions;

/**
 * The installed php-ship/ship version. InitCommand::publishStubs() records it next to the
 * published stubs, and UpCommand warns when the two no longer match.
 */
final class ShipVersion
{
    public static function current(): ?string
    {
        try {
            return InstalledVersions::getPrettyVersion('php-ship/ship');
        } catch (\OutOfBoundsException) {
            return null;
        }
    }
}

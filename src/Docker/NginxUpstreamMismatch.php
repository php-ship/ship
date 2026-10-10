<?php

declare(strict_types=1);

namespace Ship\Docker;

use Ship\Config\ShipConfig;

/**
 * The published ship/nginx/default.conf gets the app service's name at `ship init` time (see
 * InitCommand::publishStubs()). Editing ship.json's serviceNames afterwards without re-running
 * `ship init` leaves nginx pointing at a name the stack no longer has. Used by `ship up`,
 * `ship build`, `ship release` and `ship config:test`.
 */
final class NginxUpstreamMismatch
{
    /**
     * Null when there's nothing to warn about: no nginx stub, no `set $upstream_app` line in it,
     * or the name already matches. Only warns; the file may be hand-edited.
     */
    public static function warning(string $projectRoot, ShipConfig $config): ?string
    {
        $confPath = $projectRoot . '/ship/nginx/default.conf';

        if (!is_file($confPath)) {
            return null;
        }

        $conf = (string) file_get_contents($confPath);

        if (preg_match('/set \$upstream_app ([a-zA-Z0-9_.-]+):9000;/', $conf, $matches) !== 1) {
            return null;
        }

        $publishedAppName = $matches[1];
        $currentAppName = $config->serviceNames['app'] ?? 'app';

        if ($publishedAppName === $currentAppName) {
            return null;
        }

        return sprintf(
            '<comment>ship: ship/nginx/default.conf still points at "%s", but ship.json\'s '
                . 'serviceNames now renames the app service to "%s" -- nginx will fail to resolve '
                . 'its upstream. Run `ship init` again to republish it (it re-asks every prompt, so '
                . 'have your current selections ready to re-pick), or edit the `set $upstream_app` '
                . 'line in that file by hand.</comment>',
            $publishedAppName,
            $currentAppName,
        );
    }
}

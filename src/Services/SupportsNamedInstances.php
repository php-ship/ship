<?php

declare(strict_types=1);

namespace Ship\Services;

/**
 * For a ServiceDefinition that can be selected more than once under different names (ship.json's
 * `additionalServices`). One trait, so the service, `ComposeFileBuilder` and `DbCommand` all
 * arrive at the same compose service name.
 */
trait SupportsNamedInstances
{
    /**
     * key() for the default (null) instance, "{key()}-{instanceName}" for a named one.
     */
    private function composeServiceName(?string $instanceName): string
    {
        return $instanceName === null ? $this->key() : "{$this->key()}-{$instanceName}";
    }

    /**
     * Env var prefix for a named instance, e.g. "ANALYTICS_" (DB_HOST becomes ANALYTICS_DB_HOST).
     * Empty for the default instance.
     */
    private function envPrefix(?string $instanceName): string
    {
        return $instanceName === null ? '' : strtoupper($instanceName) . '_';
    }
}

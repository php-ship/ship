<?php

declare(strict_types=1);

namespace Ship\Services;

/**
 * Shared by every ServiceDefinition that supports being selected more than once under a different
 * name (ship.json's `additionalServices`) -- kept as one trait, not duplicated per class, since
 * `ComposeFileBuilder` and `DbCommand` both need to independently arrive at the exact same compose
 * service name a class computes for itself; any drift between two copies of this logic would break
 * that.
 */
trait SupportsNamedInstances
{
    /**
     * The compose service name for a given instance -- key() itself for the default (null) instance,
     * so an existing single-instance project's generated compose file is byte-for-byte unchanged.
     */
    private function composeServiceName(?string $instanceName): string
    {
        return $instanceName === null ? $this->key() : "{$this->key()}-{$instanceName}";
    }

    /**
     * Prefix for a named instance's app-facing environment variables, e.g. "ANALYTICS_" so
     * DB_HOST becomes ANALYTICS_DB_HOST -- empty for the default (null) instance, so its
     * variable names stay exactly what they've always been.
     */
    private function envPrefix(?string $instanceName): string
    {
        return $instanceName === null ? '' : strtoupper($instanceName) . '_';
    }
}

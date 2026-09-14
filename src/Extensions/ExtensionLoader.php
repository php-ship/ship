<?php

declare(strict_types=1);

namespace Ship\Extensions;

use Ship\Contracts\FrameworkAdapter;
use Ship\Contracts\ServiceDefinition;
use Ship\Services\ServiceRegistry;
use Throwable;

/**
 * `ship.json`'s "extensions" array holds fully-qualified class names, e.g.:
 *
 *   "extensions": ["Acme\\Ship\\MeilisearchProService"]
 *
 * Each class must be composer-autoloadable already -- ship never downloads anything itself.
 *
 * A class implementing neither contract is reported, not thrown -- one bad extension can't break ship.
 */
final class ExtensionLoader
{
    /**
     * @param list<string> $extensionClasses
     * @return list<string> human-readable warnings for anything that failed to load
     */
    public function load(array $extensionClasses, ServiceRegistry $registry): array
    {
        $warnings = [];

        foreach ($extensionClasses as $class) {
            if (!class_exists($class)) {
                $warnings[] = "Extension class \"{$class}\" was not found (is it require'd via Composer?).";
                continue;
            }

            try {
                $instance = new $class();
            } catch (Throwable $e) {
                $warnings[] = "Extension class \"{$class}\" could not be instantiated: {$e->getMessage()}";
                continue;
            }

            if ($instance instanceof ServiceDefinition) {
                $registry->register($instance);
                continue;
            }

            if ($instance instanceof FrameworkAdapter) {
                // FrameworkAdapters are collected by the caller (Application),
                // not this registry, so we hand it back rather than register
                // it here — see Application::detectFrameworkAdapters().
                continue;
            }

            $warnings[] = "Extension class \"{$class}\" implements neither ServiceDefinition nor FrameworkAdapter.";
        }

        return $warnings;
    }

    /**
     * Resolves just the FrameworkAdapter instances from the list; Application runs these via detect().
     *
     * @param list<string> $extensionClasses
     * @return list<FrameworkAdapter>
     */
    public function loadFrameworkAdapters(array $extensionClasses): array
    {
        $adapters = [];

        foreach ($extensionClasses as $class) {
            if (!class_exists($class)) {
                continue;
            }

            try {
                $instance = new $class();
            } catch (Throwable) {
                continue;
            }

            if ($instance instanceof FrameworkAdapter) {
                $adapters[] = $instance;
            }
        }

        return $adapters;
    }
}

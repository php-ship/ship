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
     * One instantiation pass per class, not two — a real bug found via an independent audit: this
     * used to return only warnings, with a separate loadFrameworkAdapters() re-instantiating every
     * class all over again just to pick out the FrameworkAdapter ones. Every caller that needed
     * both (Application, and ProductionBuildRunner by way of ship build/ship release) was
     * constructing each extension class twice per run, and a constructor with a side effect (e.g.
     * logging, a warning of its own) would fire twice too. Returning both results from this single
     * loop removes the second pass entirely.
     *
     * @param list<string> $extensionClasses
     * @return array{warnings: list<string>, frameworkAdapters: list<FrameworkAdapter>}
     */
    public function load(array $extensionClasses, ServiceRegistry $registry): array
    {
        $warnings = [];
        $frameworkAdapters = [];

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
                $frameworkAdapters[] = $instance;
                continue;
            }

            $warnings[] = "Extension class \"{$class}\" implements neither ServiceDefinition nor FrameworkAdapter.";
        }

        return ['warnings' => $warnings, 'frameworkAdapters' => $frameworkAdapters];
    }
}

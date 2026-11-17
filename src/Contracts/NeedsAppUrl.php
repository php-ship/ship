<?php

declare(strict_types=1);

namespace Ship\Contracts;

/**
 * Optional add-on for a browser testing ServiceDefinition whose browser runs in its own container
 * and so must reach the app by its compose hostname. That address depends on other selections
 * (nginx's "webserver" on port 80, or "app" itself on Octane's port 8000), so ComposeFileBuilder
 * computes it and sets it on the services this names. Applied only where the service is present,
 * i.e. not in an environment where its fragment is empty.
 */
interface NeedsAppUrl
{
    /**
     * Default compose service name ("app", or one of this service's own) => the env var to set
     * to the app's internal URL there, e.g. ['app' => 'APP_URL'].
     *
     * @return array<string, string>
     */
    public function appUrlVariables(): array;
}

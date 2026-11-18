<?php

declare(strict_types=1);

namespace Ship\Contracts;

/**
 * Optional add-on for a FrameworkAdapter: what `ship release` can only learn from the framework,
 * for release.json (see docs/release-contract.md). Without it, release.json's `framework` and the
 * site's `health` are null and no framework setting is required.
 */
interface DescribesRelease
{
    /**
     * A short lowercase name, e.g. "laravel". release.json's `framework`.
     */
    public function frameworkName(): string;

    /**
     * The HTTP path that answers 2xx while the app works (Laravel's health route), read from the
     * project rather than assumed. Null when the project doesn't define one.
     */
    public function healthPath(string $projectRoot): ?string;

    /**
     * The name of the setting that holds the site's public URL (Laravel's `APP_URL`), or null when
     * the framework has none.
     */
    public function siteUrlSetting(): ?string;

    /**
     * Settings the app can't run without, which `.env.production` must set. `ship release` fails
     * when one is missing, and release.json lists them in `env.required`.
     *
     * @return list<string>
     */
    public function requiredEnv(string $projectRoot): array;
}

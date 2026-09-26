<?php

declare(strict_types=1);

namespace Ship\Config;

use RuntimeException;

/**
 * Value object for ship.json. Kept dumb -- ServiceRegistry validates keys, not this class.
 */
final class ShipConfig
{
    /**
     * @param array<string, string>                                    $services   group => selected
     *        service key, e.g. ['database' => 'pgsql'] -- always the *default* instance of that
     *        group, unprefixed env vars, unchanged since before named instances existed.
     * @param list<string>                                              $extensions composer package
     *        names providing extra ServiceDefinitions
     * @param list<array{group: string, service: string, name: string}> $additionalServices second
     *        (or third, ...) instance of a service already present in $services, or of a different
     *        service in the same group -- a second database of a different engine, a second Redis
     *        for a different purpose, etc. `name` becomes both the compose service suffix and the
     *        env var prefix (see SupportsNamedInstances), so it has to be unique across this list.
     * @param array<string, string> $serviceNames a compose service's default name (e.g. "app",
     *        "webserver", "mysql", "redis" -- whatever key() or composeServiceName(null) would
     *        otherwise produce) mapped to a custom one. Hand-edited, not prompted by `ship init`,
     *        same as $extensions. Only matters when several ship-managed projects share one Docker
     *        network (see $externalNetwork): Compose defaults every container to a network alias
     *        matching its own compose service name, which collides the moment two projects on that
     *        network both run, say, an "app" or a "mysql". Doesn't apply to `additionalServices`
     *        entries -- those already get their own distinct compose name via their own `name`.
     * @param ?string $externalNetwork name of a pre-existing Docker network (created outside
     *        ship, e.g. by another compose project of shared infrastructure) to attach the app
     *        service to, in addition to ship's own internal "ship" network -- lets the app reach a
     *        database/cache/etc. that ship itself never provisioned. Null (the default) attaches
     *        nothing extra.
     * @param list<string> $phpExtensions extra PHP extensions (e.g. "gd", "zip", "bcmath") to
     *        install into the image beyond the fixed set every project already gets
     *        unconditionally (pdo_pgsql, pdo_mysql, intl, mbstring, opcache, pcntl, redis -- see
     *        stubs/docker/php/Dockerfile). Hand-edited, not prompted by `ship init`, same as
     *        $extensions -- a Composer package's own platform requirements (gd for
     *        maatwebsite/excel, bcmath for a money library, ...) aren't something `ship` can infer
     *        from ship.json's service selections alone. Fed straight to
     *        mlocati/docker-php-extension-installer (see the Dockerfile's own ARG), which already
     *        handles the apk build-dependency dance every hand-written extension install in that
     *        file does manually.
     */
    public function __construct(
        public readonly string $phpVersion,
        public readonly array $services,
        public readonly array $extensions = [],
        public readonly string $nodeVersion = '24',
        public readonly array $additionalServices = [],
        public readonly array $serviceNames = [],
        public readonly ?string $externalNetwork = null,
        public readonly array $phpExtensions = [],
    ) {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException(
                "No ship.json found at {$path}. Run `ship init` first."
            );
        }

        /**
         * @var array{
         *     php?: string,
         *     node?: string,
         *     services?: array<string,string>,
         *     extensions?: list<string>,
         *     additionalServices?: list<array{group: string, service: string, name: string}>,
         *     serviceNames?: array<string,string>,
         *     externalNetwork?: ?string,
         *     phpExtensions?: list<string>,
         * } $data
         */
        $data = json_decode((string) file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);

        return new self(
            phpVersion: $data['php'] ?? '8.4',
            services: $data['services'] ?? [],
            extensions: $data['extensions'] ?? [],
            nodeVersion: $data['node'] ?? '24',
            additionalServices: $data['additionalServices'] ?? [],
            serviceNames: $data['serviceNames'] ?? [],
            externalNetwork: $data['externalNetwork'] ?? null,
            phpExtensions: $data['phpExtensions'] ?? [],
        );
    }

    public function toFile(string $path): void
    {
        $payload = [
            'php' => $this->phpVersion,
            'node' => $this->nodeVersion,
            'services' => $this->services,
            'additionalServices' => $this->additionalServices,
            'extensions' => $this->extensions,
        ];

        // Only written when set -- keeps a plain `ship init` project's ship.json exactly as before
        // for everyone who never needs this (see this class's own constructor docblock), instead of
        // every project growing extra lines nobody asked for.
        if ($this->serviceNames !== []) {
            $payload['serviceNames'] = $this->serviceNames;
        }
        if ($this->externalNetwork !== null) {
            $payload['externalNetwork'] = $this->externalNetwork;
        }
        if ($this->phpExtensions !== []) {
            $payload['phpExtensions'] = $this->phpExtensions;
        }

        file_put_contents(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }
}

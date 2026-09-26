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
     * @param string $appName compose service name (and Docker network hostname) for the PHP
     *        container -- hand-edited, not prompted by `ship init`, same as $extensions. Only
     *        matters when several ship-managed projects share one Docker network (see
     *        $externalNetwork): Compose defaults every project's "app" to the identical network
     *        alias, which collides the moment two of them join the same external network.
     * @param string $webserverName same reasoning as $appName, for the nginx container --
     *        independent of it since a project can rename one without the other.
     * @param ?string $externalNetwork name of a pre-existing Docker network (created outside
     *        ship, e.g. by another compose project of shared infrastructure) to attach the app
     *        service to, in addition to ship's own internal "ship" network -- lets the app reach a
     *        database/cache/etc. that ship itself never provisioned. Null (the default) attaches
     *        nothing extra.
     */
    public function __construct(
        public readonly string $phpVersion,
        public readonly array $services,
        public readonly array $extensions = [],
        public readonly string $nodeVersion = '24',
        public readonly array $additionalServices = [],
        public readonly string $appName = 'app',
        public readonly string $webserverName = 'webserver',
        public readonly ?string $externalNetwork = null,
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
         *     appName?: string,
         *     webserverName?: string,
         *     externalNetwork?: ?string,
         * } $data
         */
        $data = json_decode((string) file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);

        return new self(
            phpVersion: $data['php'] ?? '8.4',
            services: $data['services'] ?? [],
            extensions: $data['extensions'] ?? [],
            nodeVersion: $data['node'] ?? '24',
            additionalServices: $data['additionalServices'] ?? [],
            appName: $data['appName'] ?? 'app',
            webserverName: $data['webserverName'] ?? 'webserver',
            externalNetwork: $data['externalNetwork'] ?? null,
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

        // Only written when they diverge from the default -- keeps a plain `ship init` project's
        // ship.json exactly as before for everyone who never needs this (see this class's own
        // constructor docblock), instead of every project growing 3 new lines nobody asked for.
        if ($this->appName !== 'app') {
            $payload['appName'] = $this->appName;
        }
        if ($this->webserverName !== 'webserver') {
            $payload['webserverName'] = $this->webserverName;
        }
        if ($this->externalNetwork !== null) {
            $payload['externalNetwork'] = $this->externalNetwork;
        }

        file_put_contents(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }
}

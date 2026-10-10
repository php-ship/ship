<?php

declare(strict_types=1);

namespace Ship\Config;

use RuntimeException;

/**
 * Value object for ship.json. ServiceRegistry validates service keys, not this class.
 */
final class ShipConfig
{
    /**
     * @param array<string, string> $services group => selected service key, e.g.
     *        ['database' => 'pgsql'] -- the default instance of that group.
     * @param list<string> $extensions fully-qualified class names of extra
     *        ServiceDefinition/FrameworkAdapter implementations.
     * @param list<array{group: string, service: string, name: string}> $additionalServices extra
     *        named instances (a second database, a second Redis, ...). `name` becomes the compose
     *        service suffix and the env var prefix (see SupportsNamedInstances), so it must be unique.
     * @param array<string, string> $serviceNames a compose service's default name ("app",
     *        "webserver", "mysql", ...) mapped to a custom one, for projects sharing a Docker
     *        network where the default aliases would collide. Doesn't apply to additionalServices.
     * @param ?string $externalNetwork a pre-existing Docker network to attach the app service to,
     *        in addition to the internal "ship" one.
     * @param list<string> $phpExtensions extra PHP extensions to install beyond the fixed set (see
     *        stubs/docker/php/Dockerfile), passed to mlocati/docker-php-extension-installer.
     * @param bool $publishPorts true publishes production HTTP ports to the host. The default keeps
     *        them private for a reverse proxy on the project's network: a Docker-published port
     *        bypasses host firewalls. Development always publishes its ports.
     * @param list<string> $deployCommands shell commands run once per deploy (migrations, one-off
     *        setup), written by `ship release` into deploy-commands.sh. Not the same as
     *        FrameworkAdapter::releaseCommands(), which run at every container boot. Production only.
     * @param array<string, string> $processes name => shell command for extra long-running
     *        processes from the app image (queue worker, Horizon, scheduler). Each becomes its own
     *        compose service. Production only.
     * @param bool $hostUser run the dev app as the host's UID/GID instead of root, so files the
     *        container writes aren't root-owned on the host. Dev only, POSIX hosts only, and not
     *        combined with SHIP_MUTAGEN.
     * @param ?string $name project name used for the compose `name:` and production image tags.
     *        Null falls back to the project directory's basename.
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
        public readonly bool $publishPorts = false,
        public readonly array $deployCommands = [],
        public readonly array $processes = [],
        public readonly bool $hostUser = false,
        public readonly ?string $name = null,
    ) {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException(
                "No ship.json found at {$path}. Run `ship init` first."
            );
        }

        $data = json_decode((string) file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);

        // Reject a top-level value that isn't an object. Checked before the @var below, which
        // would otherwise make PHPStan treat this runtime check as always true.
        if (!is_array($data) || (array_is_list($data) && $data !== [])) {
            throw new RuntimeException("ship.json must be a JSON object at {$path}, not an array or a plain value.");
        }

        self::validate($data, $path);

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
         *     publishPorts?: bool,
         *     deployCommands?: list<string>,
         *     processes?: array<string,string>,
         *     hostUser?: bool,
         *     name?: ?string,
         * } $data
         */
        return new self(
            phpVersion: $data['php'] ?? '8.4',
            services: $data['services'] ?? [],
            extensions: $data['extensions'] ?? [],
            nodeVersion: $data['node'] ?? '24',
            additionalServices: $data['additionalServices'] ?? [],
            serviceNames: $data['serviceNames'] ?? [],
            externalNetwork: $data['externalNetwork'] ?? null,
            phpExtensions: $data['phpExtensions'] ?? [],
            publishPorts: $data['publishPorts'] ?? false,
            deployCommands: $data['deployCommands'] ?? [],
            processes: $data['processes'] ?? [],
            hostUser: $data['hostUser'] ?? false,
            name: $data['name'] ?? null,
        );
    }

    /**
     * Lenient reader for `InitCommand::readExistingConfig()`, which needs to preserve hand-edited
     * fields across a re-run. Unlike fromFile(), it never throws on an invalid field: values are
     * filtered by type only, so a badly formatted one is written back as-is and reported by the
     * next command that validates. Returns null only when the file is missing or isn't JSON.
     *
     * `phpVersion` is a placeholder, since `ship init` always re-prompts for it.
     */
    public static function tryFromFile(string $path): ?self
    {
        if (!is_file($path)) {
            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        return new self(
            phpVersion: '8.4',
            services: self::filterStringMap($data['services'] ?? null),
            extensions: self::filterStringList($data['extensions'] ?? null),
            additionalServices: self::filterAdditionalServices($data['additionalServices'] ?? null),
            serviceNames: self::filterStringMap($data['serviceNames'] ?? null),
            externalNetwork: is_string($data['externalNetwork'] ?? null) ? $data['externalNetwork'] : null,
            phpExtensions: self::filterStringList($data['phpExtensions'] ?? null),
            publishPorts: is_bool($data['publishPorts'] ?? null) ? $data['publishPorts'] : false,
            deployCommands: self::filterStringList($data['deployCommands'] ?? null),
            processes: self::filterStringMap($data['processes'] ?? null),
            hostUser: is_bool($data['hostUser'] ?? null) ? $data['hostUser'] : false,
            name: is_string($data['name'] ?? null) ? $data['name'] : null,
        );
    }

    /**
     * @return list<string>
     */
    private static function filterStringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * @return array<string, string>
     */
    private static function filterStringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_filter($value, static fn (mixed $v, mixed $k): bool => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * An entry missing one of its three fields, or with a non-string one, is dropped.
     *
     * @return list<array{group: string, service: string, name: string}>
     */
    private static function filterAdditionalServices(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $filtered = [];

        foreach ($value as $entry) {
            if (
                is_array($entry)
                && is_string($entry['group'] ?? null)
                && is_string($entry['service'] ?? null)
                && is_string($entry['name'] ?? null)
            ) {
                $filtered[] = ['group' => $entry['group'], 'service' => $entry['service'], 'name' => $entry['name']];
            }
        }

        return $filtered;
    }

    /**
     * Turns a wrongly typed or badly named ship.json field into a message naming ship.json instead
     * of a raw TypeError or PHP warning. serviceNames values become compose keys; additionalServices
     * names also become env var prefixes, hence no "-".
     *
     * @param array<string, mixed> $data
     */
    private static function validate(array $data, string $path): void
    {
        foreach (['php', 'node', 'name', 'externalNetwork'] as $key) {
            if (isset($data[$key]) && !is_string($data[$key])) {
                throw new RuntimeException("ship.json's \"{$key}\" must be a string at {$path}.");
            }
        }

        foreach (['publishPorts', 'hostUser'] as $key) {
            if (isset($data[$key]) && !is_bool($data[$key])) {
                throw new RuntimeException(
                    "ship.json's \"{$key}\" must be true or false (not a quoted string) at {$path}.",
                );
            }
        }

        foreach (['extensions', 'phpExtensions', 'deployCommands'] as $key) {
            self::requireStringList($data, $key, $path);
        }

        self::requireArray($data, 'services', $path);

        foreach ($data['services'] ?? [] as $group => $serviceKey) {
            if (!is_string($serviceKey)) {
                throw new RuntimeException("ship.json's services.\"{$group}\" must be a string at {$path}.");
            }
        }

        self::requireArray($data, 'serviceNames', $path);

        foreach ($data['serviceNames'] ?? [] as $group => $serviceName) {
            if (!is_string($serviceName) || preg_match('/^[a-z][a-z0-9_-]*$/', $serviceName) !== 1) {
                throw new RuntimeException(
                    "ship.json's serviceNames.\"{$group}\" is not a valid compose service name -- "
                        . 'lowercase letters, digits, "-" and "_" only, starting with a letter.',
                );
            }
        }

        self::requireArray($data, 'additionalServices', $path);

        foreach ($data['additionalServices'] ?? [] as $additional) {
            if (!is_array($additional)) {
                throw new RuntimeException(
                    "ship.json's additionalServices has an entry that is not an object at {$path}.",
                );
            }

            foreach (['group', 'service'] as $field) {
                if (!is_string($additional[$field] ?? null) || $additional[$field] === '') {
                    throw new RuntimeException(
                        "ship.json's additionalServices has an entry missing a \"{$field}\" at {$path}.",
                    );
                }
            }

            $name = $additional['name'] ?? null;

            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
                throw new RuntimeException(
                    'ship.json\'s additionalServices has an invalid "name" -- lowercase letters, digits, '
                        . 'and "_" only, starting with a letter (no "-": it also becomes an env var prefix).',
                );
            }
        }

        self::requireArray($data, 'processes', $path);

        foreach ($data['processes'] ?? [] as $name => $command) {
            if (!is_string($name) || !is_string($command)) {
                throw new RuntimeException(
                    "ship.json's \"processes\" must map string names to string commands at {$path}.",
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function requireArray(array $data, string $key, string $path): void
    {
        if (isset($data[$key]) && !is_array($data[$key])) {
            throw new RuntimeException("ship.json's \"{$key}\" must be an array or object at {$path}.");
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function requireStringList(array $data, string $key, string $path): void
    {
        self::requireArray($data, $key, $path);

        foreach ($data[$key] ?? [] as $value) {
            if (!is_string($value)) {
                throw new RuntimeException("ship.json's \"{$key}\" must be a list of strings at {$path}.");
            }
        }
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

        // Optional fields are only written when set, so a plain project's ship.json stays minimal.
        if ($this->serviceNames !== []) {
            $payload['serviceNames'] = $this->serviceNames;
        }
        if ($this->externalNetwork !== null) {
            $payload['externalNetwork'] = $this->externalNetwork;
        }
        if ($this->phpExtensions !== []) {
            $payload['phpExtensions'] = $this->phpExtensions;
        }
        if ($this->publishPorts) {
            $payload['publishPorts'] = true;
        }
        if ($this->deployCommands !== []) {
            $payload['deployCommands'] = $this->deployCommands;
        }
        if ($this->processes !== []) {
            $payload['processes'] = $this->processes;
        }
        if ($this->hostUser) {
            $payload['hostUser'] = true;
        }
        if ($this->name !== null) {
            $payload['name'] = $this->name;
        }

        file_put_contents(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }
}

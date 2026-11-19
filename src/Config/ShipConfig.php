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
     * A PHP branch or exact release ("8.4", "8.4.12"): ship.schema.json's pattern for "php".
     */
    public const PHP_VERSION_PATTERN = '/^\d+\.\d+(?:\.\d+)?$/';

    /**
     * ship.schema.json's pattern for "name": lowercase letters and digits, words joined by "-" or
     * "_", starting with a letter.
     */
    public const NAME_PATTERN = '/^[a-z][a-z0-9]*(?:[-_][a-z0-9]+)*$/';

    /**
     * ship.schema.json's pattern for a Docker network name in "externalNetwork".
     */
    public const NETWORK_NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]*$/';

    public const SILO_IMAGE_STANDARD = 'standard';
    public const SILO_IMAGE_DISTROLESS = 'distroless';
    public const SILO_IMAGES = [self::SILO_IMAGE_STANDARD, self::SILO_IMAGE_DISTROLESS];

    /**
     * The ship.json schema in ship-ecosystem/spec, the source of truth for every producer;
     * schema/ship.schema.json is this package's copy, which CI checks is identical.
     */
    public const SCHEMA_URL = 'https://raw.githubusercontent.com/ship-ecosystem/spec/main/schemas/ship.schema.json';

    /**
     * @param array<string, string> $services group => selected service key, e.g.
     *        ['database' => 'postgres'] -- the default instance of that group.
     * @param list<string> $extensions fully-qualified class names of extra
     *        ServiceDefinition/FrameworkAdapter implementations.
     * @param list<array{group: string, service: string, name: string}> $additionalServices extra
     *        named instances (a second database, a second Redis, ...). `name` becomes the compose
     *        service suffix and the env var prefix (see SupportsNamedInstances), so it must be unique.
     * @param array<string, string> $serviceNames a compose service's default name ("app",
     *        "webserver", "mysql", ...) mapped to a custom one, for projects sharing a Docker
     *        network where the default aliases would collide. Doesn't apply to additionalServices.
     * @param ?string $externalNetwork the first of $externalNetworks, for code written when there
     *        could be only one.
     * @param list<string> $externalNetworks pre-existing Docker networks (a reverse proxy's, say)
     *        to attach the app service and every HTTP entry point (see HttpEntryPoints) to, in
     *        addition to the internal "ship" one. ship.json's "externalNetwork": one name, or a list.
     *        Given only $externalNetwork, it's that one.
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
     * @param string $siloImage which image the Silo storage service runs: "standard" (the default)
     *        or "distroless", the same release for CPUs without x86-64-v2, which the standard
     *        image's RHEL 9 base requires. Prompted by `ship init` when Silo is selected. Applies to
     *        every Silo instance, since it depends on the machines running the stack.
     * @param array<string, array{port: int, role: string, health: ?string, urlSetting: ?string}> $processHttp
     *        the processes (by name, from $processes) that receive HTTP traffic: ship.json's object
     *        form, `{"command": "...", "http": {"port": 8080, "role": "websocket", ...}}`. Each
     *        becomes a release.json http[] entry with an HTTP health check, and is reachable on
     *        externalNetwork like the site.
     * @param array<string, string> $processHealthchecks the processes (by name) with their own
     *        health check: a shell command run in the process's container that exits 0 while it
     *        works (`{"command": "...", "healthcheck": "php artisan horizon:status"}`). It replaces
     *        the default check, HTTP or "stayed up".
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
        public readonly string $siloImage = self::SILO_IMAGE_STANDARD,
        public readonly array $processHttp = [],
        public readonly array $processHealthchecks = [],
        array $externalNetworks = [],
    ) {
        $this->externalNetworks = $externalNetworks !== [] ? array_values(array_unique($externalNetworks))
            : ($externalNetwork !== null ? [$externalNetwork] : []);
    }

    /** @var list<string> */
    public readonly array $externalNetworks;

    /**
     * ship.json's "externalNetwork" as a list: one name, or several.
     *
     * @return list<string>
     */
    private static function networkNames(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
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
         *     externalNetwork?: string|list<string>|null,
         *     phpExtensions?: list<string>,
         *     publishPorts?: bool,
         *     deployCommands?: list<string>,
         *     processes?: array<string, string|array{command: string, http?: array{port: int, role: string, health?: ?string, urlSetting?: ?string}}>,
         *     hostUser?: bool,
         *     name?: ?string,
         *     siloImage?: string,
         * } $data
         */
        return new self(
            phpVersion: $data['php'] ?? '8.4',
            services: $data['services'] ?? [],
            extensions: $data['extensions'] ?? [],
            nodeVersion: $data['node'] ?? '24',
            additionalServices: $data['additionalServices'] ?? [],
            serviceNames: $data['serviceNames'] ?? [],
            externalNetwork: self::networkNames($data['externalNetwork'] ?? null)[0] ?? null,
            phpExtensions: $data['phpExtensions'] ?? [],
            publishPorts: $data['publishPorts'] ?? false,
            deployCommands: $data['deployCommands'] ?? [],
            processes: self::processCommands($data['processes'] ?? []),
            hostUser: $data['hostUser'] ?? false,
            name: $data['name'] ?? null,
            siloImage: $data['siloImage'] ?? self::SILO_IMAGE_STANDARD,
            processHttp: self::processHttpEntries($data['processes'] ?? []),
            processHealthchecks: self::processHealthcheckCommands($data['processes'] ?? []),
            externalNetworks: self::networkNames($data['externalNetwork'] ?? null),
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
            externalNetwork: self::networkNames($data['externalNetwork'] ?? null)[0] ?? null,
            phpExtensions: self::filterStringList($data['phpExtensions'] ?? null),
            publishPorts: is_bool($data['publishPorts'] ?? null) ? $data['publishPorts'] : false,
            deployCommands: self::filterStringList($data['deployCommands'] ?? null),
            processes: self::processCommands(self::validProcesses($data['processes'] ?? null)),
            hostUser: is_bool($data['hostUser'] ?? null) ? $data['hostUser'] : false,
            name: is_string($data['name'] ?? null) ? $data['name'] : null,
            siloImage: is_string($data['siloImage'] ?? null) ? $data['siloImage'] : self::SILO_IMAGE_STANDARD,
            processHttp: self::processHttpEntries(self::validProcesses($data['processes'] ?? null)),
            processHealthchecks: self::processHealthcheckCommands(self::validProcesses($data['processes'] ?? null)),
            externalNetworks: array_values(array_filter(self::networkNames($data['externalNetwork'] ?? null), static fn (string $name): bool => preg_match(self::NETWORK_NAME_PATTERN, $name) === 1)),
        );
    }

    /**
     * The healthcheck commands of the processes given in the object form.
     *
     * @param array<array-key, mixed> $processes well-formed entries
     * @return array<string, string>
     */
    private static function processHealthcheckCommands(array $processes): array
    {
        $commands = [];
        foreach ($processes as $name => $entry) {
            if (is_array($entry) && is_string($entry['healthcheck'] ?? null)) {
                $commands[(string) $name] = $entry['healthcheck'];
            }
        }

        return $commands;
    }

    /**
     * The processes entries that are well-formed (see processProblem()); the others are dropped.
     *
     * @return array<string, mixed>
     */
    private static function validProcesses(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_filter($value, static fn (mixed $entry, mixed $name): bool => is_string($name) && self::processProblem($entry) === null, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Each process's command, whichever form it was given in.
     *
     * @param array<array-key, mixed> $processes well-formed entries
     * @return array<string, string>
     */
    private static function processCommands(array $processes): array
    {
        $commands = [];
        foreach ($processes as $name => $entry) {
            $commands[(string) $name] = is_array($entry) ? (string) $entry['command'] : (string) $entry;
        }

        return $commands;
    }

    /**
     * The http blocks of the processes given in the object form, with health and urlSetting null
     * when absent.
     *
     * @param array<array-key, mixed> $processes well-formed entries
     * @return array<string, array{port: int, role: string, health: ?string, urlSetting: ?string}>
     */
    private static function processHttpEntries(array $processes): array
    {
        $entries = [];
        foreach ($processes as $name => $entry) {
            if (is_array($entry) && is_array($entry['http'] ?? null)) {
                /** @var array{port: int, role: string, health?: ?string, urlSetting?: ?string} $http */
                $http = $entry['http'];
                $entries[(string) $name] = [
                    'port' => $http['port'],
                    'role' => $http['role'],
                    'health' => $http['health'] ?? null,
                    'urlSetting' => $http['urlSetting'] ?? null,
                ];
            }
        }

        return $entries;
    }

    /**
     * What's wrong with one processes entry, or null: a command string, or an object with a
     * "command" and an optional "http" (see ship.schema.json).
     */
    private static function processProblem(mixed $entry): ?string
    {
        if (is_string($entry)) {
            return $entry === '' ? 'its command is empty' : null;
        }

        if (!is_array($entry) || array_is_list($entry)) {
            return 'it must be a command string or an object with a "command"';
        }

        $unknown = array_diff(array_keys($entry), ['command', 'healthcheck', 'http']);
        if ($unknown !== []) {
            return 'it has unknown fields (' . implode(', ', $unknown) . '): only "command", "healthcheck" and "http"';
        }

        if (!is_string($entry['command'] ?? null) || $entry['command'] === '') {
            return 'its "command" must be a non-empty string';
        }

        if (array_key_exists('healthcheck', $entry) && (!is_string($entry['healthcheck']) || $entry['healthcheck'] === '')) {
            return 'its "healthcheck" must be a non-empty shell command';
        }

        if (!array_key_exists('http', $entry)) {
            return null;
        }

        $http = $entry['http'];
        if (!is_array($http) || array_is_list($http)) {
            return 'its "http" must be an object with "port" and "role"';
        }

        $unknown = array_diff(array_keys($http), ['port', 'role', 'health', 'urlSetting']);

        return match (true) {
            $unknown !== [] => 'its "http" has unknown fields (' . implode(', ', $unknown) . '): only "port", "role", "health" and "urlSetting"',
            !is_int($http['port'] ?? null) || $http['port'] < 1 || $http['port'] > 65535 => 'its "http.port" must be a port number (1-65535)',
            !is_string($http['role'] ?? null) || $http['role'] === '' => 'its "http.role" must be a role such as "site" or "websocket"',
            isset($http['health']) && (!is_string($http['health']) || !str_starts_with($http['health'], '/')) => 'its "http.health" must be a path starting with "/", or null',
            isset($http['urlSetting']) && (!is_string($http['urlSetting']) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $http['urlSetting']) !== 1) => 'its "http.urlSetting" must be a setting name such as APP_URL, or null',
            default => null,
        };
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
    public static function filterAdditionalServices(mixed $value): array
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
        foreach (['php', 'node', 'name'] as $key) {
            if (isset($data[$key]) && !is_string($data[$key])) {
                throw new RuntimeException("ship.json's \"{$key}\" must be a string at {$path}.");
            }
        }

        // One network name, or a list of them (ship.schema.json's "externalNetwork").
        if (isset($data['externalNetwork'])) {
            $networks = $data['externalNetwork'];
            $names = is_string($networks) ? [$networks] : (is_array($networks) && array_is_list($networks) && $networks !== [] ? $networks : null);

            foreach ($names ?? [null] as $name) {
                if (!is_string($name) || preg_match(self::NETWORK_NAME_PATTERN, $name) !== 1) {
                    throw new RuntimeException(
                        "ship.json's \"externalNetwork\" must be a Docker network name, or a list of them, at {$path}.",
                    );
                }
            }
        }

        // The same pattern as ship.schema.json's "php".
        if (isset($data['php']) && is_string($data['php']) && preg_match(self::PHP_VERSION_PATTERN, $data['php']) !== 1) {
            throw new RuntimeException(
                "ship.json's \"php\" must be a PHP version such as \"8.4\" or \"8.4.12\" at {$path}, not \"{$data['php']}\".",
            );
        }

        if (isset($data['siloImage']) && !in_array($data['siloImage'], self::SILO_IMAGES, true)) {
            throw new RuntimeException(
                "ship.json's \"siloImage\" must be \"standard\" or \"distroless\" at {$path}.",
            );
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

        foreach ($data['processes'] ?? [] as $name => $entry) {
            $problem = is_string($name) ? self::processProblem($entry) : 'its name must be a string';

            if ($problem !== null) {
                throw new RuntimeException("ship.json's processes entry \"{$name}\" is invalid at {$path}: {$problem}.");
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
            '$schema' => self::SCHEMA_URL,
            'php' => $this->phpVersion,
            'node' => $this->nodeVersion,
            // An object even when empty, as the schema says: PHP would encode an empty array as [].
            'services' => $this->services === [] ? new \stdClass() : $this->services,
            'additionalServices' => $this->additionalServices,
            'extensions' => $this->extensions,
        ];

        // Optional fields are only written when set, so a plain project's ship.json stays minimal.
        if ($this->serviceNames !== []) {
            $payload['serviceNames'] = $this->serviceNames;
        }
        // One name stays a string, as ship.json had it before lists were allowed.
        if ($this->externalNetworks !== []) {
            $payload['externalNetwork'] = count($this->externalNetworks) === 1 ? $this->externalNetworks[0] : $this->externalNetworks;
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
            $payload['processes'] = [];
            foreach ($this->processes as $name => $command) {
                $payload['processes'][$name] = isset($this->processHttp[$name]) || isset($this->processHealthchecks[$name])
                    ? [
                        'command' => $command,
                        ...(isset($this->processHealthchecks[$name]) ? ['healthcheck' => $this->processHealthchecks[$name]] : []),
                        ...(isset($this->processHttp[$name]) ? ['http' => $this->processHttp[$name]] : []),
                    ]
                    : $command;
            }
        }
        if ($this->hostUser) {
            $payload['hostUser'] = true;
        }
        if ($this->name !== null) {
            $payload['name'] = $this->name;
        }
        if ($this->siloImage !== self::SILO_IMAGE_STANDARD) {
            $payload['siloImage'] = $this->siloImage;
        }

        file_put_contents(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }
}

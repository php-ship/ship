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
     * @param bool $publishPorts true explicitly publishes production HTTP ports to the host.
     *        The default, false, keeps them private for a reverse proxy joined to the project's
     *        Docker network. Docker-published ports can expose the app on the server's public
     *        IP, bypassing the proxy's TLS and headers. Development always publishes its ports.
     * @param list<string> $deployCommands shell commands meant to run exactly once per deploy --
     *        database migrations, a package's own one-off setup, creating buckets. Deliberately not
     *        the same thing as FrameworkAdapter::releaseCommands(), despite the similar name: those
     *        run at every container *boot* (artisan optimize, ...), which is exactly wrong for a
     *        migration once more than one container shares the image (each would run it, at once).
     *        `ship release --tag` writes these into the release's own deploy-commands.sh rather than
     *        running them itself -- see ReleaseCommand::writeDeployScript() -- since the operator
     *        decides when infrastructure is actually ready on their own server, not `ship`. Run
     *        inside a one-off container of the app service, against whatever database the stack --
     *        or the external network -- provides. Production only; ignored in dev.
     * @param array<string, string> $processes name => shell command for extra long-running
     *        processes that run the same app from the same image -- a queue worker, Laravel
     *        Horizon, the scheduler (`php artisan schedule:work`). Each becomes its own service
     *        (the name is the compose service name) instead of a second process supervised inside
     *        the app container: independently restartable, visible in `docker compose ps`, and
     *        stopped on its own SIGTERM. Production only -- in dev these are `ship artisan ...`
     *        commands you run yourself, against the bind-mounted code.
     * @param bool $hostUser opt in to running the dev app as your own host UID/GID instead of root.
     *        Dev containers run as root on purpose (see docs/roadmap.md: a fixed container UID only
     *        works when it happens to match the bind mount's), which leaves anything the container
     *        writes -- vendor/, public/build, storage/ -- root-owned on hosts where you aren't root
     *        (native Linux, WSL2). The objection to a fixed UID doesn't apply when the UID comes from
     *        the host, so this builds the dev image with yours. Dev only; POSIX hosts only (Windows has
     *        no UID to match, and Docker Desktop's bind mounts don't have the problem); not combined
     *        with SHIP_MUTAGEN -- `ship up` says so when it skips it.
     * @param ?string $name the project name `ship build`/`ship release` use to tag production images
     *        (e.g. "<name>-app:<tag>"). Hand-edited, not prompted by `ship init`, same as $serviceNames
     *        -- null (the default) falls back to the project root directory's own basename, which is
     *        what Docker Compose's own implicit image naming already does today, so a project that
     *        never sets this sees the same names it always would have. Explicit is safer in CI, where
     *        the checkout directory's name is often unpredictable (a runner workspace path, a PR
     *        number, ...) and isn't something worth matching by accident.
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

        // A top-level JSON value that isn't even an object at all -- a bare string, or a JSON
        // array like "[1,2,3]" -- would otherwise reach validate()'s own `array $data` parameter
        // type hint (an uncaught TypeError for a non-array value) or, for an array-shaped-but-
        // positional JSON array, silently proceed with every field defaulted, discarding the fact
        // the file was never ship.json-shaped to begin with rather than naming the problem.
        // array_is_list() can't perfectly distinguish a JSON array from a JSON object using
        // only-numeric string keys once both have been decoded into a PHP array the same way --
        // a limitation accepted here, since a real ship.json never has a reason to use numeric
        // keys at its top level anyway.
        //
        // Deliberately checked (and validate() called) before the @var annotation below asserts
        // a shape -- asserting it first, the way an earlier version of this method did, made
        // PHPStan statically trust that shape unconditionally, flagging this exact runtime check
        // against a non-array $data as "will always evaluate to true" even though json_decode()
        // can genuinely return anything at runtime.
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
     * Best-effort read for `InitCommand::readExistingConfig()` -- preserving whatever's already
     * there across a re-run matters more here than validating it. Deliberately separate from
     * `fromFile()`, which validates (see this class's own `validate()`) and throws on the first
     * invalid field it finds -- using that method here would mean one bad `serviceNames` value
     * discards every *other* hand-edited field right along with it, reintroducing the exact
     * data-loss this exists to prevent. `fromFile()`'s own strict validate-then-throw behavior is
     * still exactly right for the operational path (`ship up`/`build`/`release` actually using
     * the config) -- this is a separate, deliberately lenient reader only
     * `readExistingConfig()` uses.
     *
     * Only filters by *type* (a list is actually a list of strings, a map is actually
     * string-keyed with string values, ...), not by `validate()`'s own stricter *format* checks
     * (a compose-name regex, say) -- a value that's merely the wrong shape for `fromFile()`'s
     * validation is still preserved as-is and written straight back to ship.json. The next
     * operational use (`ship up`, say) still validates it for real and fails with a clear,
     * actionable error then, exactly where fixing it actually matters; silently dropping it here
     * instead would be a second, quieter way to lose a hand-edited value with no error at all.
     * Only a field whose JSON *type* is fundamentally incompatible (a string where an object was
     * expected, say) has nothing meaningful left to preserve, so that one is dropped. Null only
     * when the file doesn't exist or isn't even valid JSON at all -- nothing short of that is
     * worth discarding wholesale.
     *
     * `phpVersion` is given a harmless placeholder, not real validation, since
     * `readExistingConfig()`'s only caller (`ship init`) always re-prompts for it fresh. `services`
     * and `additionalServices` are read for real, though -- InitCommand's own prompts now default
     * to whatever's already selected here, so dropping them the same way `phpVersion` is dropped
     * would feed every group prompt a `None` default no matter what ship.json already has.
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
     * Same lenient, type-only filtering as filterStringList()/filterStringMap() -- an entry
     * missing one of its three fields, or with a non-string one, has nothing meaningful left to
     * preserve, so it's dropped rather than written back half-formed. Format (e.g. "name"'s own
     * charset) is still fromFile()'s job, not this one -- see tryFromFile()'s own docblock.
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
     * Every field below has a failure mode that would otherwise reach a raw PHP TypeError/warning
     * instead of a message naming ship.json at all. `"php": 8.4` (a bare JSON number, an easy
     * hand-edit mistake -- the quotes around the string are easy to drop) would otherwise reach
     * the constructor's own strict `string $phpVersion` type unchecked, surfacing as "Argument
     * #1 ($phpVersion) must be of type string, float given". `serviceNames`/`additionalServices`
     * names go straight into generated compose keys (and, for `additionalServices`, a
     * `.env`-style env var prefix), so they get the same shape check `processes` names already
     * get in `ComposeFileBuilder::addProcessServices()`. The regexes themselves aren't new:
     * `serviceNames` reuses `processes`'s own (a compose key only, so a hyphen is fine);
     * `additionalServices` reuses `ship init`'s own interactive prompt validation (also an env var
     * prefix, so no hyphen -- a `.env` file's own KEY=VALUE syntax doesn't allow one).
     *
     * `serviceNames`/`additionalServices`/`processes`/`deployCommands` each get their own
     * top-level array-type check below too: any of them being some non-array value (a bare
     * string, say) would otherwise reach a raw `foreach() argument must be of type array|object`
     * PHP warning followed by an uncaught constructor `TypeError`.
     *
     * The remaining checks below cover the same failure mode for every other field:
     * `publishPorts`/`hostUser` as a quoted `"false"` instead of a real boolean,
     * `extensions`/`phpExtensions` as some non-list value, `name`/`externalNetwork` as a
     * non-string (an `int`, say), a non-string value under `services`, and an
     * `additionalServices` entry missing its `group`/`service` key entirely (which would
     * otherwise reach an "Undefined array key" PHP warning in `ComposeFileBuilder::build()`
     * instead) -- every one of these would otherwise reach a raw constructor `TypeError` or
     * warning instead of a message naming ship.json.
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

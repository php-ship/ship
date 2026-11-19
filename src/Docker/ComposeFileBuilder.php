<?php

declare(strict_types=1);

namespace Ship\Docker;

use Ship\Config\ShipConfig;
use Ship\Contracts\ConfigAwareService;
use Ship\Contracts\NeedsAppUrl;
use Ship\Contracts\RequiresPhpExtensions;
use Ship\Contracts\ShipEnvironment;
use Ship\Node\PackageManager;
use Ship\Services\ServiceRegistry;
use Ship\Services\SupportsNamedInstances;
use Ship\Support\PhpSecurityFloor;
use Symfony\Component\Yaml\Yaml;

final class ComposeFileBuilder
{
    /**
     * Compose loads .env at container start if present; `required: false` keeps projects without one
     * working. Explicit `environment:` values still win, since Compose applies env_file first.
     */
    private const OPTIONAL_ENV_FILE = [['path' => '.env', 'required' => false]];

    /**
     * The project being built for (see build()): its composer.lock adds the PHP extensions its
     * packages declare. Null builds from ship.json alone.
     */
    private ?string $projectRoot = null;

    public function __construct(
        private readonly ServiceRegistry $registry,
    ) {
    }

    /**
     * $mutagenSync only applies in development; production has no bind mount to replace.
     *
     * $hostUser (see ShipConfig::$hostUser) is development-only and already filtered by the caller.
     *
     * $projectName becomes the file's top-level `name:`, which named volumes and network aliases are
     * keyed off. Without it Compose falls back to the project directory's basename, which differs
     * per release directory (dist/ship/<tag>).
     *
     * @param array{uid: int, gid: int}|null $hostUser
     */
    public function build(
        ShipConfig $config,
        ShipEnvironment $environment,
        bool $mutagenSync = false,
        ?array $hostUser = null,
        ?string $projectName = null,
        ?string $projectRoot = null,
    ): string {
        $this->projectRoot = $projectRoot;
        $serviceNames = $config->serviceNames;

        $this->assertNoServiceNameCollisions($config->services, $serviceNames);
        $this->assertUniqueAdditionalServiceNames($config->additionalServices);
        $this->assertAdditionalServicesSupportNamedInstances($config->additionalServices);
        $this->assertPhpVersionIsPatched($config->phpVersion);

        $compose = [
            ...($projectName !== null ? ['name' => $projectName] : []),
            'services' => $this->renameFragmentKeys(
                $this->baseServices($config, $environment, $mutagenSync, $hostUser),
                $serviceNames,
            ),
            'networks' => [
                'ship' => ['driver' => 'bridge'],
            ],
        ];

        $appEnv = [];
        $removed = [];

        foreach ($config->services as $key) {
            [$compose, $appEnv, $removed] = $this->applyService($compose, $appEnv, $removed, $key, null, $environment, $config);
        }

        foreach ($config->additionalServices as $additional) {
            [$compose, $appEnv, $removed] = $this->applyService(
                $compose,
                $appEnv,
                $removed,
                $additional['service'],
                $additional['name'],
                $environment,
                $config,
            );
        }

        foreach ($removed as $name) {
            unset($compose['services'][$serviceNames[$name] ?? $name]);
        }

        $compose['services'] = $this->backfillVersionBuildArgs(
            $compose['services'],
            $config->phpVersion,
            $config->nodeVersion,
            $this->phpExtensionsBuildArg($config, $this->projectRoot, $environment),
        );
        $compose['services'] = $this->backfillRestartPolicy($compose['services']);

        // prod-nginx copies public/ from the "assets" stage, which builds on the app's "builder"
        // and "base" stages: composer install, the PHP version and extensions, the security floor
        // and the VITE_* settings all apply to it. Without the app's build args it would build on
        // the Dockerfile's defaults, and `composer install` fails for a project needing another PHP
        // version or an extension. Identical args also let the two builds share those stages.
        if (!$environment->isDevelopment()) {
            $compose['services'] = $this->alignWebserverBuildWithApp(
                $compose['services'],
                $config->serviceNames['app'] ?? 'app',
                $config->serviceNames['webserver'] ?? 'webserver',
            );
        }

        // `docker compose build` reuses a locally cached base image (php:8.4-fpm-alpine) forever,
        // so a production build always re-resolves its FROM images and picks up PHP's latest
        // patch release. Development skips this so `ship up` still works offline; the
        // PHP_SECURITY_FLOOR check in the Dockerfile catches a stale dev base image instead.
        if (!$environment->isDevelopment()) {
            $compose['services'] = $this->backfillBuildPull($compose['services']);
        }

        // Fragment keys are already renamed (see applyService()); this fixes references to a
        // renamed service from another fragment, e.g. "webserver"'s depends_on: ["app"].
        $compose['services'] = $this->renameDependsOnReferences($compose['services'], $serviceNames);

        // Production publishes nothing unless publishPorts is set -- see ShipConfig::$publishPorts.
        if (!$config->publishPorts && !$environment->isDevelopment()) {
            foreach ($compose['services'] as $name => $service) {
                $compose['services'][$name]['ports'] = [];
            }
        }

        $appServiceName = $serviceNames['app'] ?? 'app';
        $webserverServiceName = $serviceNames['webserver'] ?? 'webserver';

        $compose['services'][$appServiceName]['environment'] = [
            ...$compose['services'][$appServiceName]['environment'] ?? [],
            ...$appEnv,
            // Read by the dev entrypoint: the user to run composer install and any non-php-fpm
            // command as. Numeric, so it doesn't depend on the image's user name.
            ...($hostUser !== null ? ['SHIP_HOST_USER' => "{$hostUser['uid']}:{$hostUser['gid']}"] : []),
            // Under Mutagen, `ship up` runs composer install itself once the sync has finished
            // (see MutagenSync). The entrypoint must not race it: an early install can run before
            // composer.lock has synced and write a new lock file into the project.
            ...($mutagenSync && $environment->isDevelopment() ? ['SHIP_DEV_SKIP_INSTALL' => '1'] : []),
        ];

        // After the app's own environment, so Dusk's APP_URL wins over appEnv (see NeedsAppUrl).
        $appUrl = isset($compose['services'][$webserverServiceName])
            ? "http://{$webserverServiceName}"
            : "http://{$appServiceName}:8000";

        foreach ($this->appUrlVariables($config, $environment) as $target => $variable) {
            $target = $target === 'app' ? $appServiceName : ($serviceNames[$target] ?? $target);
            $compose['services'][$target]['environment'] = [
                ...$compose['services'][$target]['environment'] ?? [],
                $variable => $appUrl,
            ];
        }

        // Under Mutagen there is no bind mount of the project; any other service mounting it
        // (Reverb, Cypress) shares the synced volume instead, as "app" and "webserver" do.
        if ($mutagenSync && $environment->isDevelopment()) {
            $compose['services'] = $this->useSyncVolumeForProjectMounts($compose['services']);
        }

        // Attaches the app to a pre-existing network (see ShipConfig::$externalNetwork), in
        // addition to "ship". Before the process services are added, so they copy it; the HTTP
        // entry points are attached further down.
        // The first keeps the key "external" it had when there could be only one; the others are
        // "external-2", "external-3", ...
        $externalKeys = [];
        foreach ($config->externalNetworks as $index => $network) {
            $key = $index === 0 ? 'external' : 'external-' . ($index + 1);
            $externalKeys[] = $key;
            $compose['networks'][$key] = ['name' => $network, 'external' => true];
            $compose['services'][$appServiceName]['networks'][] = $key;
        }

        // After the app's own environment and networks are final -- these copy them.
        if (!$environment->isDevelopment()) {
            $compose['services'] = $this->addProcessServices($compose['services'], $config, $appServiceName);
        }

        $compose['services'] = $this->alignReverbWithApp(
            $compose['services'],
            $appServiceName,
            $serviceNames['reverb'] ?? 'reverb',
            $appEnv,
            $environment,
            $mutagenSync,
            $hostUser,
        );

        // A reverse proxy on the external network has to reach every service that receives HTTP,
        // which is "webserver" rather than "app" with nginx, plus Reverb (see HttpEntryPoints).
        if ($externalKeys !== []) {
            foreach (HttpEntryPoints::resolve($compose['services'], $config, $this->registry, $environment) as $entry) {
                $networks = $compose['services'][$entry['service']]['networks'] ?? [];
                $compose['services'][$entry['service']]['networks'] = [...$networks, ...array_values(array_diff($externalKeys, $networks))];
            }
        }

        // Tells the `docker compose exec` commands (see ComposeCommand::execPrefix()) what this
        // file was generated with, which ship.json alone can't say.
        if ($hostUser !== null) {
            $compose['x-ship'] = ['hostUser' => "{$hostUser['uid']}:{$hostUser['gid']}", 'appService' => $appServiceName];
        }

        $namedVolumes = $this->namedVolumesUsedBy($compose['services']);
        if ($namedVolumes !== []) {
            $compose['volumes'] = array_fill_keys($namedVolumes, null);
        }

        // Without this flag an empty PHP array dumps as `{}`, which Compose's schema rejects for
        // ports/volumes/depends_on.
        return Yaml::dump($compose, inline: 6, indent: 2, flags: Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
    }

    /**
     * Merges one selected service into the compose file being built: the default instance from
     * ship.json's `services` ($instanceName null) or a named one from `additionalServices`.
     *
     * @param array<string, mixed> $compose
     * @param array<string, string> $appEnv
     * @param list<string> $removed
     * @return array{0: array<string, mixed>, 1: array<string, string>, 2: list<string>}
     */
    private function applyService(
        array $compose,
        array $appEnv,
        array $removed,
        string $key,
        ?string $instanceName,
        ShipEnvironment $environment,
        ShipConfig $config,
    ): array {
        $serviceNames = $config->serviceNames;
        $service = $this->registry->get($key);

        if ($service instanceof ConfigAwareService) {
            $service = $service->withConfig($config);
        }

        $fragment = $service->composeFragment($environment, $instanceName);

        // An empty fragment means the service is absent in this environment (Mailpit and Dusk in
        // production): no compose service and no env vars injected into "app".
        if ($fragment === []) {
            return [$compose, $appEnv, $removed];
        }

        $env = $service->environmentVariables($instanceName);

        // environmentVariables() embeds the service's unrenamed compose name in some values
        // (DB_HOST, MEILISEARCH_HOST), so those are renamed along with the fragment key.
        foreach ($fragment as $name => $serviceFragment) {
            $newName = $serviceNames[$name] ?? $name;

            if ($newName !== $name) {
                $env = $this->renameHostnameReferences($env, $name, $newName);
            }

            $serviceFragment['networks'] ??= ['ship'];
            $compose['services'][$newName] = isset($compose['services'][$newName])
                ? $this->mergeServiceFragment($compose['services'][$newName], $serviceFragment)
                : $serviceFragment;
        }

        // A later same-name env var wins, so additionalServices can override the default instance.
        $appEnv = [...$appEnv, ...$env];
        $removed = [...$removed, ...$service->removes()];

        return [$compose, $appEnv, $removed];
    }

    /**
     * The app-URL variables (see NeedsAppUrl) every selected service present in this environment
     * asks for, keyed by default compose service name.
     *
     * Selenium, Playwright's browser server and Cypress reach the app over the "ship" network, so
     * they need its internal hostname. Only set where a browser test service is present: on "app"
     * in particular, Dusk's APP_URL overrides the project's own (`environment:` beats
     * `env_file:`) with a hostname no browser outside Docker can resolve.
     *
     * @return array<string, string>
     */
    private function appUrlVariables(ShipConfig $config, ShipEnvironment $environment): array
    {
        $variables = [];

        foreach ($config->services as $key) {
            $service = $this->registry->get($key);

            if ($service instanceof ConfigAwareService) {
                $service = $service->withConfig($config);
            }

            if ($service instanceof NeedsAppUrl && $service->composeFragment($environment) !== []) {
                $variables = [...$variables, ...$service->appUrlVariables()];
            }
        }

        return $variables;
    }

    /**
     * Replaces a bind mount of the project root (".:/path") with the Mutagen-synced volume.
     *
     * @param array<string, array<string, mixed>> $services
     * @return array<string, array<string, mixed>>
     */
    private function useSyncVolumeForProjectMounts(array $services): array
    {
        foreach ($services as $name => $service) {
            /** @var list<string> $volumes */
            $volumes = $service['volumes'] ?? [];

            foreach ($volumes as $index => $mount) {
                if (str_starts_with($mount, '.:')) {
                    $services[$name]['volumes'][$index] = 'ship-app-sync:' . substr($mount, 2);
                }
            }
        }

        return $services;
    }

    /**
     * Renames a service's hostname inside its own env vars. Only keys ending in "_HOST",
     * "_ENDPOINT", "_URI" or "_URL" are touched: driver identifiers such as DB_CONNECTION=mysql or
     * CACHE_STORE=redis merely share the compose name and must not change. Within a matching key,
     * only the bare name, or the name before the port in a URL ("://name:", or "@name:" after
     * credentials, as in MONGODB_URI and SQL Server's DB_URL), is replaced.
     *
     * @param array<string, string> $env
     * @return array<string, string>
     */
    private function renameHostnameReferences(array $env, string $oldName, string $newName): array
    {
        $replacements = [
            "://{$oldName}:" => "://{$newName}:",
            "@{$oldName}:" => "@{$newName}:",
        ];

        foreach ($env as $key => $value) {
            $isAddress = str_ends_with($key, '_HOST') || str_ends_with($key, '_ENDPOINT')
                || str_ends_with($key, '_URI') || str_ends_with($key, '_URL');

            if (!$isAddress) {
                continue;
            }

            $env[$key] = $value === $oldName ? $newName : strtr($value, $replacements);
        }

        return $env;
    }

    /**
     * Merges a fragment into an already-defined service. environment/env_file/volumes/ports/
     * networks/depends_on accumulate, `build` is shallow-merged one level, everything else is
     * overridden.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $fragment
     * @return array<string, mixed>
     */
    private function mergeServiceFragment(array $base, array $fragment): array
    {
        // Scalar lists are deduped after concatenation: two fragments that both default
        // "networks" to ['ship'] would otherwise produce a duplicate Compose rejects. env_file is a
        // list of arrays, so array_unique() doesn't apply to it.
        $accumulatingLists = ['volumes', 'ports', 'networks', 'depends_on'];
        $accumulatingAsIs = ['environment', 'env_file'];

        foreach ($fragment as $key => $value) {
            if ($key === 'build' && isset($base['build']) && is_array($value)) {
                $base['build'] = [...$base['build'], ...$value];
            } elseif (in_array($key, $accumulatingLists, true) && isset($base[$key]) && is_array($value)) {
                $base[$key] = array_values(array_unique([...$base[$key], ...$value]));
            } elseif (in_array($key, $accumulatingAsIs, true) && isset($base[$key]) && is_array($value)) {
                $base[$key] = [...$base[$key], ...$value];
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * Renames the fragment keys that ship.json's serviceNames map mentions.
     *
     * @param array<string, array<string, mixed>> $fragment
     * @param array<string, string> $serviceNames
     * @return array<string, array<string, mixed>>
     */
    private function renameFragmentKeys(array $fragment, array $serviceNames): array
    {
        $renamed = [];

        foreach ($fragment as $name => $definition) {
            $renamed[$serviceNames[$name] ?? $name] = $definition;
        }

        return $renamed;
    }

    /**
     * "app", "webserver" and every selected service must end up with distinct names once
     * serviceNames is applied, or one fragment silently replaces another.
     *
     * @param array<string, string> $services
     * @param array<string, string> $serviceNames
     */
    private function assertNoServiceNameCollisions(array $services, array $serviceNames): void
    {
        $seenAs = [];

        foreach ([...['app', 'webserver'], ...array_values($services)] as $defaultKey) {
            $finalKey = $serviceNames[$defaultKey] ?? $defaultKey;

            if (isset($seenAs[$finalKey])) {
                throw new \InvalidArgumentException(
                    "ship.json: \"{$seenAs[$finalKey]}\" and \"{$defaultKey}\" both end up named "
                        . "\"{$finalKey}\" -- check serviceNames for a rename that collides with "
                        . 'another selected service\'s own name.',
                );
            }

            $seenAs[$finalKey] = $defaultKey;
        }
    }

    /**
     * An additionalServices "name" is both a compose service suffix and an env var prefix, so two
     * entries sharing one would overwrite each other.
     *
     * @param list<array{group: string, service: string, name: string}> $additionalServices
     */
    private function assertUniqueAdditionalServiceNames(array $additionalServices): void
    {
        $seen = [];

        foreach ($additionalServices as $additional) {
            $name = $additional['name'];

            if (isset($seen[$name])) {
                throw new \InvalidArgumentException(
                    "ship.json additionalServices: \"{$name}\" is used more than once -- each entry "
                        . 'needs its own unique name.',
                );
            }

            $seen[$name] = true;
        }
    }

    /**
     * Only a service using the SupportsNamedInstances trait varies its compose service name and env
     * vars by instance name; any other would collide with its own default instance.
     *
     * @param list<array{group: string, service: string, name: string}> $additionalServices
     */
    private function assertAdditionalServicesSupportNamedInstances(array $additionalServices): void
    {
        foreach ($additionalServices as $additional) {
            $service = $this->registry->get($additional['service']);

            if (!in_array(SupportsNamedInstances::class, class_uses($service), true)) {
                throw new \InvalidArgumentException(
                    "ship.json additionalServices: \"{$additional['service']}\" doesn't support more "
                        . 'than one instance -- only its default selection (or none) can be used.',
                );
            }
        }
    }

    /**
     * Rewrites depends_on entries that point at a service renamed through serviceNames.
     *
     * @param array<string, array<string, mixed>> $services
     * @param array<string, string> $serviceNames
     * @return array<string, array<string, mixed>>
     */
    private function renameDependsOnReferences(array $services, array $serviceNames): array
    {
        if ($serviceNames === []) {
            return $services;
        }

        foreach ($services as $name => $service) {
            if (!isset($service['depends_on'])) {
                continue;
            }

            /** @var list<string> $dependsOn */
            $dependsOn = $service['depends_on'];
            $services[$name]['depends_on'] = array_map(
                static fn (string $dependency): string => $serviceNames[$dependency] ?? $dependency,
                $dependsOn,
            );
        }

        return $services;
    }

    /**
     * ship.json's processes (see ShipConfig::$processes): each becomes a service with the app's
     * build config, environment and networks, and no ports. SHIP_RUN_AS makes the entrypoint drop
     * to www-data even when the app is php-fpm and starts as root. The longer stop_grace_period
     * lets Horizon or a queue worker finish its job instead of being SIGKILLed after 10s.
     *
     * A process with an `http` block (ShipConfig::$processHttp) is checked over HTTP instead, and
     * with publishPorts its port is published like Reverb's, at ${<NAME>_PORT:-<port>}. It joins
     * the external network with the other HTTP entry points (see HttpEntryPoints).
     *
     * @param array<string, array<string, mixed>> $services
     * @return array<string, array<string, mixed>>
     */
    private function addProcessServices(array $services, ShipConfig $config, string $appServiceName): array
    {
        $app = $services[$appServiceName];

        foreach ($config->processes as $name => $command) {
            if (preg_match('/^[a-z][a-z0-9_-]*$/', $name) !== 1) {
                throw new \InvalidArgumentException(
                    "ship.json processes: \"{$name}\" is not a valid name -- it becomes a compose service "
                        . 'name, so lowercase letters, digits, "-" and "_" only, starting with a letter.',
                );
            }

            if (isset($services[$name])) {
                throw new \InvalidArgumentException(
                    "ship.json processes: \"{$name}\" collides with a service ship already generates. "
                        . 'Pick another name.',
                );
            }

            $services[$name] = [
                'build' => $app['build'],
                // Compose interpolates a bare $VAR itself, against the host's environment, so
                // every "$" is escaped to "$$" to reach the container's shell intact.
                'command' => ['sh', '-c', str_replace('$', '$$', $command)],
                'env_file' => $app['env_file'],
                'environment' => [...($app['environment'] ?? []), 'SHIP_RUN_AS' => 'www-data'],
                'networks' => $app['networks'],
                'restart' => 'unless-stopped',
                'stop_grace_period' => '60s',
                'healthcheck' => Healthchecks::stayedUp(),
            ];

            // The process's own check when it has one; otherwise over HTTP for an http process, and
            // for a plain worker, with no port to ask, that it stays up rather than crash-looping.
            $http = $config->processHttp[$name] ?? null;
            $healthcheck = $config->processHealthchecks[$name] ?? null;

            if ($healthcheck !== null) {
                $services[$name]['healthcheck'] = Healthchecks::command($healthcheck);
            } elseif ($http !== null) {
                $services[$name]['healthcheck'] = $http['health'] !== null
                    ? Healthchecks::httpPath($http['port'], $http['health'])
                    : Healthchecks::httpServer($http['port']);
            }

            if ($http === null) {
                continue;
            }

            if ($config->publishPorts) {
                $variable = strtoupper(str_replace('-', '_', $name)) . '_PORT';
                $services[$name]['ports'] = ["\${{$variable}:-{$http['port']}}:{$http['port']}"];
            }
        }

        return $services;
    }

    /**
     * Reverb is a separate compose service running the same Laravel app, and ReverbService can't
     * see $appEnv, $hostUser or $mutagenSync, so this aligns it with "app":
     *
     * - "app"'s injected environment (DB_*, REDIS_*, ...), so Reverb can reach the same services.
     * - "app"'s build args, so both build identical image content. Only the args are copied:
     *   FrankenPHP overrides "app"'s dockerfile, which Reverb doesn't need.
     * - SHIP_RUN_AS in production, so Reverb doesn't run as root.
     * - SHIP_HOST_USER in development when hostUser is set, for the same reason.
     * - SHIP_DEV_SKIP_INSTALL in development, so Reverb waits for "app"'s composer install
     *   instead of running a second one into the same vendor/.
     * - The synced named volume under Mutagen, instead of the unsynced bind mount.
     *
     * @param array<string, array<string, mixed>> $services
     * @param array<string, string> $appEnv
     * @param array{uid: int, gid: int}|null $hostUser
     * @return array<string, array<string, mixed>>
     */
    private function alignReverbWithApp(
        array $services,
        string $appServiceName,
        string $reverbServiceName,
        array $appEnv,
        ShipEnvironment $environment,
        bool $mutagenSync,
        ?array $hostUser,
    ): array {
        if (!isset($services[$reverbServiceName])) {
            return $services;
        }

        $services[$reverbServiceName]['build']['args'] = $services[$appServiceName]['build']['args'] ?? [];
        $services[$reverbServiceName]['environment'] = [
            ...$appEnv,
            ...($environment->isDevelopment() && $hostUser !== null
                ? ['SHIP_HOST_USER' => "{$hostUser['uid']}:{$hostUser['gid']}"]
                : []),
            ...($environment->isDevelopment() ? ['SHIP_DEV_SKIP_INSTALL' => '1'] : ['SHIP_RUN_AS' => 'www-data']),
        ];

        if ($environment->isDevelopment() && $mutagenSync) {
            $services[$reverbServiceName]['volumes'] = ['ship-app-sync:/var/www/html'];
        }

        return $services;
    }

    /**
     * Collects the named volumes (e.g. "ship-postgres-data:/data", as opposed to a bind mount) that
     * need a matching entry under the top-level `volumes:` key.
     *
     * A renamed service keeps its original volume name; the mount string is built before any
     * rename happens, and Compose doesn't require the two to match.
     *
     * @param array<string, array<string, mixed>> $services
     * @return list<string>
     */
    private function namedVolumesUsedBy(array $services): array
    {
        $found = [];

        foreach ($services as $service) {
            /** @var list<string> $volumes */
            $volumes = $service['volumes'] ?? [];

            foreach ($volumes as $mount) {
                $source = explode(':', $mount, 2)[0];

                if ($source !== '' && !str_starts_with($source, '.') && !str_starts_with($source, '/')) {
                    $found[$source] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Gives every service building a ship/Dockerfile PHP stage ("app", Reverb) the same
     * PHP_VERSION/NODE_VERSION/PHP_EXTENSIONS/PHP_SECURITY_FLOOR build args. dev-nginx/prod-nginx
     * don't consume them. `??=` lets a value that's already set win.
     *
     * @param array<string, array<string, mixed>> $services
     * @return array<string, array<string, mixed>>
     */
    private function backfillVersionBuildArgs(array $services, string $phpVersion, string $nodeVersion, string $phpExtensions): array
    {
        foreach ($services as $name => $service) {
            $target = $service['build']['target'] ?? null;

            if (!in_array($target, ['dev', 'prod'], true)) {
                continue;
            }

            $services[$name]['build']['args'] ??= [];
            $services[$name]['build']['args']['PHP_VERSION'] ??= $phpVersion;
            $services[$name]['build']['args']['NODE_VERSION'] ??= $nodeVersion;
            $services[$name]['build']['args']['PHP_EXTENSIONS'] ??= $phpExtensions;
            // Not `??=`: a fragment has no business lowering the security floor.
            $services[$name]['build']['args']['PHP_SECURITY_FLOOR'] = PhpSecurityFloor::buildArg();
        }

        return $services;
    }

    /**
     * ship.json's `phpExtensions` plus whatever the selected services (default and named
     * instances) need for their PHP client (see RequiresPhpExtensions), deduplicated in that order.
     */
    private function phpExtensionsBuildArg(ShipConfig $config, ?string $projectRoot, ShipEnvironment $environment): string
    {
        // What the project's packages declare and the image lacks (see PhpExtensions; production
        // leaves out development-only packages), then ship.json's own list and the services' clients.
        $extensions = [
            ...($projectRoot !== null ? PhpExtensions::missingFromComposer($projectRoot, withDev: $environment->isDevelopment()) : []),
            ...$config->phpExtensions,
        ];
        $keys = [...array_values($config->services), ...array_column($config->additionalServices, 'service')];

        foreach ($keys as $key) {
            $service = $this->registry->get($key);

            if ($service instanceof RequiresPhpExtensions) {
                $extensions = [...$extensions, ...$service->requiredPhpExtensions()];
            }
        }

        return implode(' ', array_values(array_unique($extensions)));
    }

    /**
     * @param array<string, array<string, mixed>> $services
     * @return array<string, array<string, mixed>>
     */
    private function alignWebserverBuildWithApp(array $services, string $app, string $webserver): array
    {
        if (($services[$webserver]['build']['target'] ?? null) !== 'prod-nginx' || !isset($services[$app]['build']['args'])) {
            return $services;
        }

        $services[$webserver]['build']['args'] = $services[$app]['build']['args'];

        return $services;
    }

    /**
     * @param array<string, array<string, mixed>> $services
     * @return array<string, array<string, mixed>>
     */
    private function backfillBuildPull(array $services): array
    {
        foreach ($services as $name => $service) {
            if (isset($service['build'])) {
                $services[$name]['build']['pull'] = true;
            }
        }

        return $services;
    }

    /**
     * Fails before a long Docker build that the Dockerfile's PHP_SECURITY_FLOOR check would only
     * reject at the end of the base stage anyway.
     */
    private function assertPhpVersionIsPatched(string $phpVersion): void
    {
        $problem = PhpSecurityFloor::problemWith($phpVersion);

        if ($problem !== null) {
            throw new \InvalidArgumentException("ship.json \"php\": {$problem}");
        }
    }

    /**
     * Every generated service is a long-running daemon, so a crash or host reboot should bring it
     * back. `??=` lets a fragment set its own policy.
     *
     * @param array<string, array<string, mixed>> $services
     * @return array<string, array<string, mixed>>
     */
    private function backfillRestartPolicy(array $services): array
    {
        foreach ($services as $name => $service) {
            $services[$name]['restart'] ??= 'unless-stopped';
        }

        return $services;
    }

    /**
     * @param array{uid: int, gid: int}|null $hostUser
     * @return array<string, array<string, mixed>>
     */
    private function baseServices(
        ShipConfig $config,
        ShipEnvironment $environment,
        bool $mutagenSync,
        ?array $hostUser,
    ): array {
        $target = $environment->isDevelopment() ? 'dev' : 'prod';
        $runtime = $config->services['runtime'] ?? null;

        // Mutagen syncs into the container's filesystem, so a bind mount on the same path would
        // fight it. A named volume lets "webserver" share the synced tree.
        $devVolume = $mutagenSync ? ['ship-app-sync:/var/www/html'] : ['.:/var/www/html'];

        $services = [
            'app' => [
                'build' => [
                    'context' => '.',
                    'dockerfile' => 'ship/Dockerfile',
                    'target' => $target,
                    'args' => [
                        'PHP_VERSION' => $config->phpVersion,
                        'NODE_VERSION' => $config->nodeVersion,
                        'OCTANE_RUNTIME' => $this->runtimeBuildArg($runtime),
                        'PHP_EXTENSIONS' => $this->phpExtensionsBuildArg($config, $this->projectRoot, $environment),
                        // The production `npm run build` bakes .env.production's VITE_* settings into
                        // the bundle. Read from the build's environment, not written into this file
                        // (see ViteBuildEnv and ProductionBuildRunner).
                        ...(!$environment->isDevelopment() ? ['VITE_ENV' => '${' . ViteBuildEnv::VARIABLE . ':-}'] : []),
                        // The package manager the production asset build uses (see PackageManager).
                        ...(!$environment->isDevelopment() ? ['PACKAGE_MANAGER' => '${' . PackageManager::BUILD_VARIABLE . ':-npm}'] : []),
                        ...($hostUser !== null ? ['HOST_UID' => (string) $hostUser['uid'], 'HOST_GID' => (string) $hostUser['gid']] : []),
                    ],
                ],
                // ship-corepack keeps the Yarn/pnpm versions Corepack downloads (COREPACK_HOME in the
                // Dockerfile) across rebuilds.
                'volumes' => $environment->isDevelopment() ? [...$devVolume, 'ship-corepack:/opt/corepack'] : [],
                // Vite's dev server is a separate HTTP+WebSocket server, so it needs its own
                // published port in dev. Both sides follow VITE_PORT, which "app" also loads
                // from .env, so a vite.config.js reading it binds the published port.
                'ports' => $environment->isDevelopment()
                    ? [DevPortBinding::bind('${VITE_PORT:-5173}:${VITE_PORT:-5173}', $environment)]
                    : [],
                'networks' => ['ship'],
                'env_file' => self::OPTIONAL_ENV_FILE,
                // Lets Xdebug (dev image only) reach the IDE on the host; "host-gateway" resolves
                // to the host on Docker Desktop and native Linux alike.
                'extra_hosts' => $environment->isDevelopment() ? ['host.docker.internal:host-gateway'] : [],
                // php-fpm answers its ping. An Octane runtime replaces this with its own check.
                ...(!$environment->isDevelopment() ? ['healthcheck' => Healthchecks::phpFpm()] : []),
            ],
        ];

        // php-fpm needs nginx in front of it; an Octane runtime serves HTTP itself.
        if ($runtime === null) {
            $services['webserver'] = [
                'build' => [
                    'context' => '.',
                    'dockerfile' => 'ship/Dockerfile',
                    // Same Dockerfile as "app": prod-nginx copies the built public/ assets
                    // from the "assets" stage.
                    'target' => $environment->isDevelopment() ? 'dev-nginx' : 'prod-nginx',
                ],
                'volumes' => $environment->isDevelopment() ? $devVolume : [],
                'ports' => [DevPortBinding::bind('${APP_PORT:-80}:80', $environment)],
                'depends_on' => ['app'],
                ...(!$environment->isDevelopment() ? ['healthcheck' => Healthchecks::nginx()] : []),
                'networks' => ['ship'],
            ];
        }

        return $services;
    }

    private function runtimeBuildArg(?string $runtime): string
    {
        return match ($runtime) {
            'octane-swoole' => 'swoole',
            'octane-roadrunner' => 'roadrunner',
            'octane-frankenphp' => 'frankenphp',
            default => 'fpm',
        };
    }
}

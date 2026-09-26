<?php

declare(strict_types=1);

namespace Ship\Docker;

use Ship\Config\ShipConfig;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Yaml\Yaml;

final class ComposeFileBuilder
{
    /**
     * Compose loads .env at container start if present -- required: false means projects without one still
     * work fine, and secrets never get baked into the image itself. Explicit environment: values below,
     * still wins on conflicts, since Compose applies env_file first and environment: second, forever.
     */
    private const OPTIONAL_ENV_FILE = [['path' => '.env', 'required' => false]];

    public function __construct(
        private readonly ServiceRegistry $registry,
    ) {
    }

    /**
     * $mutagenSync only ever changes anything in Development -- Production bakes the source into
     * the image at build time (see "builder"/"prod" stages), so there's no bind mount, and nothing
     * to sync, either way.
     */
    public function build(ShipConfig $config, ShipEnvironment $environment, bool $mutagenSync = false): string
    {
        $compose = [
            'services' => $this->baseServices($config, $environment, $mutagenSync),
            'networks' => [
                'ship' => ['driver' => 'bridge'],
            ],
        ];

        $appEnv = [];
        $removed = [];

        foreach ($config->services as $key) {
            [$compose, $appEnv, $removed] = $this->applyService($compose, $appEnv, $removed, $key, null, $environment);
        }

        foreach ($config->additionalServices as $additional) {
            [$compose, $appEnv, $removed] = $this->applyService(
                $compose,
                $appEnv,
                $removed,
                $additional['service'],
                $additional['name'],
                $environment,
            );
        }

        foreach ($removed as $name) {
            unset($compose['services'][$name]);
        }

        $compose['services'] = $this->backfillVersionBuildArgs(
            $compose['services'],
            $config->phpVersion,
            $config->nodeVersion,
        );
        $compose['services'] = $this->backfillRestartPolicy($compose['services']);

        // Every ServiceDefinition (Octane*, Reverb, Dusk, Garage, ...) merges into -- or reasons
        // about -- "app"/"webserver" as fixed literals; renaming here, once, after all of them have
        // already run, means none of those classes need to know a project renamed its own services
        // at all. Only actually touches anything when $config->appName/webserverName diverge from
        // their defaults -- see ShipConfig's own docblock for why that's opt-in and hand-edited.
        $compose['services'] = $this->renameCoreServices($compose['services'], $config->appName, $config->webserverName);

        $compose['services'][$config->appName]['environment'] = [
            ...$compose['services'][$config->appName]['environment'] ?? [],
            ...$appEnv,
            // Always computed here, always wins over anything a
            // ServiceDefinition set — see DuskService for why per-service
            // guessing doesn't work: whether "app" or "webserver" is the
            // real HTTP entrypoint depends on whether an Octane runtime
            // was *also* selected, which no single ServiceDefinition can
            // see. This is the one place that actually knows, since it's
            // the same check every Octane*Service::removes() already
            // makes (drop "webserver" because Octane serves HTTP itself).
            'APP_URL' => sprintf(
                'http://%s',
                isset($compose['services'][$config->webserverName]) ? $config->webserverName : $config->appName,
            ),
        ];

        // Lets the app reach infrastructure ship itself never provisioned (a shared MySQL/Redis/...
        // some other compose project already runs) -- see ShipConfig::$externalNetwork's own
        // docblock. Additive, not a replacement for "ship": the app still needs that one for
        // "webserver" (or Reverb, ...) to reach it.
        if ($config->externalNetwork !== null) {
            $compose['networks']['external'] = ['name' => $config->externalNetwork, 'external' => true];
            $compose['services'][$config->appName]['networks'][] = 'external';
        }

        $namedVolumes = $this->namedVolumesUsedBy($compose['services']);
        if ($namedVolumes !== []) {
            $compose['volumes'] = array_fill_keys($namedVolumes, null);
        }

        // Without this flag, an empty PHP array (e.g. "app"'s ports in
        // production -- see baseServices()) dumps as YAML `{}`, since PHP
        // can't distinguish an empty list from an empty map. Compose's
        // schema requires ports/volumes/depends_on to be sequences, so
        // `{}` fails `docker compose config` validation outright -- caught
        // by an actual `ship up --prod` smoke test, not by any unit test,
        // since ComposeFileBuilderTest only ever parses the YAML back into
        // PHP, which can't tell `{}` and `[]` apart either.
        return Yaml::dump($compose, inline: 6, indent: 2, flags: Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
    }

    /**
     * One selected service -- either the default instance from ship.json's `services` ($instanceName
     * null) or a named one from `additionalServices` -- merged into the compose services being built
     * so far. Threads $compose/$appEnv/$removed through as a tuple rather than mutating them in place,
     * since build() calls this once per default selection and once per additional one, in a plain
     * foreach either way.
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
    ): array {
        $service = $this->registry->get($key);

        foreach ($service->composeFragment($environment, $instanceName) as $name => $fragment) {
            $fragment['networks'] ??= ['ship'];
            $compose['services'][$name] = isset($compose['services'][$name])
                ? $this->mergeServiceFragment($compose['services'][$name], $fragment)
                : $fragment;
        }

        // A later, same-name env var wins -- lets additionalServices override a key the default
        // instance already set, the same "last one wins" rule PHP's own array union would give if
        // this were still a single flat loop instead of two.
        $appEnv = [...$appEnv, ...$service->environmentVariables($instanceName)];
        $removed = [...$removed, ...$service->removes()];

        return [$compose, $appEnv, $removed];
    }

    /**
     * Merges a fragment into an already-defined service. Its Environment/volumes/ports/networks/depends_on
     * accumulate, rather than erasing, what is already set. `build` is shallow-merged one level, so now
     * overriding the dockerfile doesn't erase context/target/args; everything overrides outright too.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $fragment
     * @return array<string, mixed>
     */
    private function mergeServiceFragment(array $base, array $fragment): array
    {
        // Plain lists of scalars -- concatenating two fragments' copies of the same value (most
        // commonly "networks": applyService() defaults every fragment missing one to ['ship'], so
        // two fragments both landing on "app" -- baseServices() and an Octane runtime's own, say --
        // both default it, then concatenate into ['ship', 'ship'], which Compose's schema rejects)
        // needs deduping after the fact. Not "environment" (associative, a later same-key value
        // already correctly wins on spread, no duplicate-value case exists) or "env_file" (a list of
        // arrays, not scalars -- array_unique() would misbehave, stringifying each element first).
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
     * Renames the "app"/"webserver" keys themselves (a no-op for either one still at its default)
     * and rewrites any other service's depends_on entries pointing at the old name -- currently
     * only "webserver"'s own depends_on: ["app"], but written generically rather than special-cased
     * to that one spot, so a future fragment adding its own depends_on: ["app"] doesn't silently
     * break the moment a project renames it.
     *
     * @param array<string, array<string, mixed>> $services
     * @return array<string, array<string, mixed>>
     */
    private function renameCoreServices(array $services, string $appName, string $webserverName): array
    {
        $renames = [];

        if ($appName !== 'app' && isset($services['app'])) {
            $renames['app'] = $appName;
        }
        if ($webserverName !== 'webserver' && isset($services['webserver'])) {
            $renames['webserver'] = $webserverName;
        }

        if ($renames === []) {
            return $services;
        }

        $renamed = [];

        foreach ($services as $name => $service) {
            if (isset($service['depends_on'])) {
                /** @var list<string> $dependsOn */
                $dependsOn = $service['depends_on'];
                $service['depends_on'] = array_map(
                    static fn (string $dependency): string => $renames[$dependency] ?? $dependency,
                    $dependsOn,
                );
            }

            $renamed[$renames[$name] ?? $name] = $service;
        }

        return $renamed;
    }

    /**
     * Scans every merged service's volumes: entries for ones referencing a named volume (e.g. "pgs:/data")
     * rather than a bind mount (e.g. ".:/var/www/html"). Needs one matching entry under this file's own
     * top-level, volumes: key -- omitting one is a validation error, any unused entry is dead config.
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
     * "app" sets build.args.PHP_VERSION/NODE_VERSION explicitly, but any other service building a
     * ship/Dockerfile's PHP stage (reverb so far) needs the same versions, not the Dockerfile's ARG
     * defaults. Applies solely to dev/prod targets; dev-nginx/prod-nginx don't consume either. `??=`
     * lets one already set win.
     *
     * @param array<string, array<string, mixed>> $services
     * @return array<string, array<string, mixed>>
     */
    private function backfillVersionBuildArgs(array $services, string $phpVersion, string $nodeVersion): array
    {
        foreach ($services as $name => $service) {
            $target = $service['build']['target'] ?? null;

            if (!in_array($target, ['dev', 'prod'], true)) {
                continue;
            }

            $services[$name]['build']['args'] ??= [];
            $services[$name]['build']['args']['PHP_VERSION'] ??= $phpVersion;
            $services[$name]['build']['args']['NODE_VERSION'] ??= $nodeVersion;
        }

        return $services;
    }

    /**
     * Every generated service is a long-running daemon, never a one-shot command, so `unless-stopped`
     * applies uniformly -- without it, a container that crashes (or a host that reboots) just stays down
     * until someone notices and runs `ship up` again. `??=` lets a fragment set its own value instead.
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
     * @return array<string, array<string, mixed>>
     */
    private function baseServices(ShipConfig $config, ShipEnvironment $environment, bool $mutagenSync): array
    {
        $target = $environment->isDevelopment() ? 'dev' : 'prod';
        $runtime = $config->services['runtime'] ?? null;

        // Mutagen (opt-in via SHIP_MUTAGEN, see Ship\Sync\MutagenSync) syncs into a container's
        // own filesystem directly rather than through a live bind mount, so the two can't share
        // "/var/www/html" -- a bind mount there would fight the sync over the same path. Swapped
        // for a named volume instead of dropping the mount entirely so "webserver" (nginx, needs
        // the same tree for its own static-file serving) can share the identical, already-synced
        // content with zero extra sync overhead, just by mounting the same named volume -- rather
        // than syncing into each container separately.
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
                    ],
                ],
                'volumes' => $environment->isDevelopment() ? $devVolume : [],
                // Vite's dev server (`ship npm run dev`) needs its own
                // published port -- it's a separate HTTP+WebSocket server
                // from the app itself, not something nginx/php-fpm proxy.
                // Unconditional in dev for the same reason Node itself is
                // unconditional in the base image (see NodeService's
                // docblock): there's no meaningful "dev environment with
                // no frontend tooling" mode to opt out into, and an
                // unused published port costs nothing. Not published in
                // prod at all -- there is no Vite dev server in
                // production, see the "assets" build stage instead.
                'ports' => $environment->isDevelopment() ? ['${VITE_PORT:-5173}:5173'] : [],
                'networks' => ['ship'],
                'env_file' => self::OPTIONAL_ENV_FILE,
            ],
        ];

        // A plain php-fpm app has no built-in HTTP server, so it needs nginx
        // in front of it. Every Octane runtime serves HTTP itself, so when
        // one is selected this is simply not added — see each
        // Octane*Service::removes(), which is a defensive backstop for the
        // (currently theoretical) case where something else adds a
        // "webserver" service after this point.
        if ($runtime === null) {
            $services['webserver'] = [
                'build' => [
                    'context' => '.',
                    'dockerfile' => 'ship/Dockerfile',
                    // Same Dockerfile as "app", different target -- see
                    // that file's dev-nginx/prod-nginx stages for why:
                    // prod-nginx needs COPY --from=assets for built
                    // public/ assets, which only same-Dockerfile
                    // multi-stage COPY --from can do without relying on
                    // cross-service build ordering.
                    'target' => $environment->isDevelopment() ? 'dev-nginx' : 'prod-nginx',
                ],
                'volumes' => $environment->isDevelopment() ? $devVolume : [],
                'ports' => ['${APP_PORT:-80}:80'],
                'depends_on' => ['app'],
                'networks' => ['ship'],
                'env_file' => self::OPTIONAL_ENV_FILE,
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

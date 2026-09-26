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
        $serviceNames = $config->serviceNames;

        $compose = [
            'services' => $this->renameFragmentKeys(
                $this->baseServices($config, $environment, $mutagenSync),
                $serviceNames,
            ),
            'networks' => [
                'ship' => ['driver' => 'bridge'],
            ],
        ];

        $appEnv = [];
        $removed = [];

        foreach ($config->services as $key) {
            [$compose, $appEnv, $removed] = $this->applyService($compose, $appEnv, $removed, $key, null, $environment, $serviceNames);
        }

        foreach ($config->additionalServices as $additional) {
            [$compose, $appEnv, $removed] = $this->applyService(
                $compose,
                $appEnv,
                $removed,
                $additional['service'],
                $additional['name'],
                $environment,
                $serviceNames,
            );
        }

        foreach ($removed as $name) {
            unset($compose['services'][$serviceNames[$name] ?? $name]);
        }

        $compose['services'] = $this->backfillVersionBuildArgs(
            $compose['services'],
            $config->phpVersion,
            $config->nodeVersion,
        );
        $compose['services'] = $this->backfillRestartPolicy($compose['services']);

        // Every service fragment's own compose key was already renamed as it was built (see
        // applyService()/renameFragmentKeys()) -- the only thing left is a *reference* to an old
        // name from a fragment that isn't itself the one being renamed, e.g. "webserver"'s own
        // depends_on: ["app"]. Written generically against the whole $serviceNames map (not
        // special-cased to app/webserver) so a future fragment adding its own depends_on doesn't
        // silently break the moment a project renames whatever it's depending on.
        $compose['services'] = $this->renameDependsOnReferences($compose['services'], $serviceNames);

        $appServiceName = $serviceNames['app'] ?? 'app';
        $webserverServiceName = $serviceNames['webserver'] ?? 'webserver';

        $compose['services'][$appServiceName]['environment'] = [
            ...$compose['services'][$appServiceName]['environment'] ?? [],
            ...$appEnv,
            // Only set at all when Dusk is selected -- Selenium (a separate container) reaches the
            // app over the "ship" network, not via whatever host-reachable URL a real browser or
            // artisan command would use, and DuskService itself has no visibility into which
            // service ("app" or "webserver") is the real HTTP entrypoint (depends on whether an
            // Octane runtime was *also* selected -- the same check every Octane*Service::removes()
            // already makes). Everywhere else, this used to unconditionally overwrite whatever
            // real, host-reachable APP_URL the project's own .env already set (environment: always
            // wins over env_file:, see OPTIONAL_ENV_FILE's own docblock) -- found from real use:
            // a project with a genuine APP_URL (a custom port, a real domain, ...) had every
            // user-facing link (queued emails, signed URLs, artisan output) silently rewritten to
            // an internal Docker hostname no browser outside the container can resolve.
            ...(($config->services['testing'] ?? null) === 'dusk' ? [
                'APP_URL' => sprintf(
                    'http://%s',
                    isset($compose['services'][$webserverServiceName]) ? $webserverServiceName : $appServiceName,
                ),
            ] : []),
        ];

        // Lets the app reach infrastructure ship itself never provisioned (a shared MySQL/Redis/...
        // some other compose project already runs) -- see ShipConfig::$externalNetwork's own
        // docblock. Additive, not a replacement for "ship": the app still needs that one for
        // "webserver" (or Reverb, ...) to reach it.
        if ($config->externalNetwork !== null) {
            $compose['networks']['external'] = ['name' => $config->externalNetwork, 'external' => true];
            $compose['services'][$appServiceName]['networks'][] = 'external';
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
     * @param array<string, string> $serviceNames
     * @return array{0: array<string, mixed>, 1: array<string, string>, 2: list<string>}
     */
    private function applyService(
        array $compose,
        array $appEnv,
        array $removed,
        string $key,
        ?string $instanceName,
        ShipEnvironment $environment,
        array $serviceNames,
    ): array {
        $service = $this->registry->get($key);
        $env = $service->environmentVariables($instanceName);

        // Renamed together, not separately -- environmentVariables() bakes this same service's own
        // *unrenamed* compose name into a handful of its values (DB_HOST => "mysql", or embedded in
        // a URL, e.g. MEILISEARCH_HOST => "http://meilisearch:7700"), computed independently of
        // composeFragment() with no shared state tying the two together, so nothing else already
        // knows to keep them in sync once a name changes.
        foreach ($service->composeFragment($environment, $instanceName) as $name => $fragment) {
            $newName = $serviceNames[$name] ?? $name;

            if ($newName !== $name) {
                $env = $this->renameHostnameReferences($env, $name, $newName);
            }

            $fragment['networks'] ??= ['ship'];
            $compose['services'][$newName] = isset($compose['services'][$newName])
                ? $this->mergeServiceFragment($compose['services'][$newName], $fragment)
                : $fragment;
        }

        // A later, same-name env var wins -- lets additionalServices override a key the default
        // instance already set, the same "last one wins" rule PHP's own array union would give if
        // this were still a single flat loop instead of two.
        $appEnv = [...$appEnv, ...$env];
        $removed = [...$removed, ...$service->removes()];

        return [$compose, $appEnv, $removed];
    }

    /**
     * Only ever touches a key that actually carries a hostname by its own naming convention --
     * ends in "_HOST" (DB_HOST, REDIS_HOST, MAIL_HOST, MEILISEARCH_HOST, ANALYTICS_DB_HOST, ...) or
     * "_ENDPOINT" (AWS_ENDPOINT) -- deliberately not every env value a service happens to produce.
     * Found for real, not hypothesized: MySqlService's own DB_CONNECTION and RedisService's own
     * CACHE_STORE/SESSION_DRIVER are Laravel driver identifiers that happen to be spelled exactly
     * like the *compose service's own name* ("mysql", "redis") purely by coincidence -- a value-only
     * check with no key filter renamed them right along with the real hostname, which would have
     * quietly changed the app's own cache driver to a name that means nothing to Laravel the moment
     * anyone renamed their "redis" service. Within a matching key, only two value shapes are ever
     * touched -- the bare compose name itself, or that same name embedded in a URL immediately
     * between "://" and the next ":" (MEILISEARCH_HOST, AWS_ENDPOINT) -- rather than a blind
     * str_replace() across the whole value, which would risk mangling something that merely
     * contains the old name as an unrelated substring.
     *
     * @param array<string, string> $env
     * @return array<string, string>
     */
    private function renameHostnameReferences(array $env, string $oldName, string $newName): array
    {
        $needle = "://{$oldName}:";
        $replacement = "://{$newName}:";

        foreach ($env as $key => $value) {
            if (!str_ends_with($key, '_HOST') && !str_ends_with($key, '_ENDPOINT')) {
                continue;
            }

            if ($value === $oldName) {
                $env[$key] = $newName;
            } elseif (str_contains($value, $needle)) {
                $env[$key] = str_replace($needle, $replacement, $value);
            }
        }

        return $env;
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
     * Renames whichever of a fragment's own keys ship.json's serviceNames map mentions -- a no-op
     * for any key the map doesn't touch. Used both for baseServices()'s "app"/"webserver" fragment
     * and (via applyService()) every other selected service's own fragment, so there's exactly one
     * place that decides what a compose key becomes.
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
     * Rewrites a depends_on entry pointing at a name ship.json's serviceNames map renamed --
     * currently only "webserver"'s own depends_on: ["app"], but written generically against the
     * whole map rather than special-cased to that one spot, so a future fragment adding its own
     * depends_on doesn't silently break the moment a project renames whatever it names there.
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
     * Scans every merged service's volumes: entries for ones referencing a named volume (e.g. "pgs:/data")
     * rather than a bind mount (e.g. ".:/var/www/html"). Needs one matching entry under this file's own
     * top-level, volumes: key -- omitting one is a validation error, any unused entry is dead config.
     *
     * A renamed service's own volume name (e.g. MySqlService's "ship-mysql-data") still reflects
     * its *original* key(), not whatever serviceNames renamed the service itself to -- baked into
     * the mount string at composeFragment() build time, before any rename happens, and Compose
     * doesn't require the two to match. Cosmetic only: the data persists under that name across
     * `ship up`/`ship down` regardless of what the service is currently called.
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
                //
                // Both sides use the same $VITE_PORT, not just the host side -- found from real
                // use: a project whose own vite.config.js listens on a non-default port (its own
                // VITE_PORT, read from this exact same .env via env_file: below) had its container
                // side permanently fixed at 5173 regardless, so HMR never connected. Since "app"'s
                // env_file: already loads the same .env this interpolates from, one VITE_PORT value
                // drives both the compose port mapping and whatever port a vite.config.js reading
                // process.env.VITE_PORT actually binds to -- see README's Vite HMR section.
                'ports' => $environment->isDevelopment() ? ['${VITE_PORT:-5173}:${VITE_PORT:-5173}'] : [],
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

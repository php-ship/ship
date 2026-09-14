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

    public function build(ShipConfig $config, ShipEnvironment $environment): string
    {
        $compose = [
            'services' => $this->baseServices($config, $environment),
            'networks' => [
                'ship' => ['driver' => 'bridge'],
            ],
        ];

        $appEnv = [];
        $removed = [];

        foreach ($config->services as $key) {
            $service = $this->registry->get($key);

            foreach ($service->composeFragment($environment) as $name => $fragment) {
                $fragment['networks'] ??= ['ship'];
                $compose['services'][$name] = isset($compose['services'][$name])
                    ? $this->mergeServiceFragment($compose['services'][$name], $fragment)
                    : $fragment;
            }

            $appEnv += $service->environmentVariables();
            $removed = [...$removed, ...$service->removes()];
        }

        foreach ($removed as $name) {
            unset($compose['services'][$name]);
        }

        $compose['services'] = $this->backfillPhpVersionBuildArg($compose['services'], $config->phpVersion);
        $compose['services'] = $this->backfillRestartPolicy($compose['services']);

        $compose['services']['app']['environment'] = [
            ...$compose['services']['app']['environment'] ?? [],
            ...$appEnv,
            // Always computed here, always wins over anything a
            // ServiceDefinition set — see DuskService for why per-service
            // guessing doesn't work: whether "app" or "webserver" is the
            // real HTTP entrypoint depends on whether an Octane runtime
            // was *also* selected, which no single ServiceDefinition can
            // see. This is the one place that actually knows, since it's
            // the same check every Octane*Service::removes() already
            // makes (drop "webserver" because Octane serves HTTP itself).
            'APP_URL' => sprintf('http://%s', isset($compose['services']['webserver']) ? 'webserver' : 'app'),
        ];

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
        $accumulating = ['environment', 'volumes', 'ports', 'networks', 'depends_on', 'env_file'];

        foreach ($fragment as $key => $value) {
            if ($key === 'build' && isset($base['build']) && is_array($value)) {
                $base['build'] = [...$base['build'], ...$value];
            } elseif (in_array($key, $accumulating, true) && isset($base[$key]) && is_array($value)) {
                $base[$key] = [...$base[$key], ...$value];
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
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
     * "app" sets build.args.PHP_VERSION explicitly, but any other service building a ship/Dockerfile's PHP
     * stage (reverb so far) needs the same version, not the Dockerfile's ARG default. Applies solely to
     * dev/prod targets; dev-nginx/prod-nginx don't consume it at all. `??=` lets one already set win.
     *
     * @param array<string, array<string, mixed>> $services
     * @return array<string, array<string, mixed>>
     */
    private function backfillPhpVersionBuildArg(array $services, string $phpVersion): array
    {
        foreach ($services as $name => $service) {
            $target = $service['build']['target'] ?? null;

            if (!in_array($target, ['dev', 'prod'], true)) {
                continue;
            }

            $services[$name]['build']['args'] ??= [];
            $services[$name]['build']['args']['PHP_VERSION'] ??= $phpVersion;
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
    private function baseServices(ShipConfig $config, ShipEnvironment $environment): array
    {
        $target = $environment->isDevelopment() ? 'dev' : 'prod';
        $runtime = $config->services['runtime'] ?? null;

        $services = [
            'app' => [
                'build' => [
                    'context' => '.',
                    'dockerfile' => 'ship/Dockerfile',
                    'target' => $target,
                    'args' => [
                        'PHP_VERSION' => $config->phpVersion,
                        'OCTANE_RUNTIME' => $this->runtimeBuildArg($runtime),
                    ],
                ],
                'volumes' => $environment->isDevelopment() ? ['.:/var/www/html'] : [],
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
                'volumes' => $environment->isDevelopment() ? ['.:/var/www/html'] : [],
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

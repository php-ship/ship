# Roadmap

## What's built

- Extension contracts: `ServiceDefinition` (one per optional infrastructure
  piece), `FrameworkAdapter` (one per target framework), and the optional
  `ProvidesDatabaseShell` add-on a database `ServiceDefinition` can
  implement for `ship db`. `ExtensionLoader` resolves `ship.json`'s
  `extensions` array (a list of FQCNs) into registered instances of each,
  used independently by `Application`, `InitCommand`, and `UpCommand`.
- 11 built-in `ServiceDefinition`s: Postgres, MySQL, Redis, SeaweedFS
  (default object storage) + Garage (alt driver), Octane x
  Swoole/RoadRunner/FrankenPHP, Meilisearch, Mailpit, Dusk/Selenium, Node,
  Reverb.
- Two built-in `FrameworkAdapter`s: `LaravelAdapter` (`ship artisan`,
  `php artisan optimize` at production boot) and `SymfonyAdapter` (`ship
  console`, `php bin/console cache:clear` at production boot).
- `ComposeFileBuilder` merges fragments (not overwrites) so a runtime can
  override `app`'s command while keeping its build/volumes, derives
  top-level named volumes generically, backfills `PHP_VERSION` onto any
  service building a `ship/Dockerfile` PHP stage, switches whether
  `webserver` (nginx) exists based on runtime selection, and gives every
  service a `restart: unless-stopped` policy so a crash (or host reboot)
  recovers on its own instead of staying down.
- One `ship/Dockerfile` builds five targets: `dev`, `builder`, `assets`,
  `prod` (the `app` service), and `dev-nginx`/`prod-nginx` (the
  `webserver` service). `Dockerfile.frankenphp` mirrors the same stage
  structure for the FrankenPHP runtime, which needs a different base
  image entirely.
- `EntrypointScriptBuilder` renders the `prod` stage's `ENTRYPOINT` script
  from whatever `FrameworkAdapter::releaseCommands()` returns, generated
  fresh by every `ship up` since its content depends on which adapter
  matches.
- Commands: `init`, `up` (`--prod` for production), `down`, `exec`,
  `shell`, `db`, `logs`, plus `composer`/`npm`/`artisan`/`console` via
  `ProxyCommand`. `ComposeCommand::baseArgs()` is the shared
  `docker compose -f ... --project-directory ...` prefix every command
  uses, appending a second `-f docker-compose.override.yml` when a
  project has one.
- `app`, `webserver`, and `reverb` all load an optional `.env` from the
  deploy target's filesystem via Compose's `env_file`, never baked into
  the image itself.
- CI matrix across Ubuntu/macOS/Windows x PHP 8.2/8.3/8.4, plus a
  separate `docker-build` job that actually runs `ship init`/`up`/
  `up --prod` against a real fixture Laravel app and a real Docker
  daemon — building both images, migrating a real database, and
  checking the app actually answers over HTTP in both modes. Invokes
  `bin/ship` directly against a plain fixture app rather than through a
  Composer dependency, since `$projectRoot` is just `getcwd()` (see
  `bin/ship`) — that sidesteps a real limitation where a Composer path
  repository pointing at this checkout can't resolve inside an isolated
  Docker build context, which would otherwise make the production build
  half of this job untestable.

## Known gaps

- **`ship up` can leave a late-building service at "Created" without
  starting it** when several services build images in the same run
  (`app`, `webserver`, `reverb` all build from `ship/Dockerfile`). The
  generated compose file's dependency graph is correct — this is a
  Docker Compose/Desktop concurrency quirk under simultaneous builds,
  not something `ship` generates wrong. `ship up` now checks every
  expected service against `docker compose ps --status running` after
  its own `up --build -d` call, retries once, and reports a clear
  failure naming whatever's still not running instead of exiting
  quietly successful.
- **That check only confirms a container is running, not that its
  published ports actually bound.** A container can report "running"
  while Docker silently drops one of its port publishes (observed with
  Reverb's default `8080` colliding with an unrelated container already
  using that host port) — reachable over the internal Docker network,
  but not from the host. `ship up` doesn't currently detect or warn
  about this case.
- **Nothing asserts the picker's answers actually produce the right
  `ship.json`, or that `publishStubs()` copies the right files for a
  given selection.** The `select()`/`ChoiceQuestion` fallback path and
  `publishStubs()` do run inside `InitCommandReverbWarningTest` (real
  typed answers via `CommandTester::setInputs()`, not the `interactive:
  false` shortcut `InitCommandViteReminderTest` uses) and
  `InitCommandViteReminderTest`, so they're exercised -- but only one
  group (`broadcasting`) is actually picked in either, and no test
  inspects the written `ship.json` or the copied `ship/` directory's
  contents (e.g. Garage's stub files, nginx being omitted when a
  runtime is selected). The Laravel Prompts interactive-UI branch
  (`canUseLaravelPromptsInteractiveUi()`) has zero coverage either way
  — `laravel/prompts` isn't installed in this repo's own test suite.
- **Node version isn't actually configurable.** `NodeService` exists as
  a selectable group but its `composeFragment()`/`environmentVariables()`
  are both empty — Node is installed unconditionally in the Dockerfile's
  base stage at a fixed version. Making the version configurable needs
  the same build-arg plumbing `OCTANE_RUNTIME` uses.
- **`ProxyCommand` and `ExecCommand`'s raw-argv forwarding** (both read
  `$_SERVER['argv']` directly rather than Console's parsed arguments, so
  a flag meant for the executed command — `-m` in `artisan make:model
  Post -m`, `--force` in `ship exec app php artisan migrate --force` —
  isn't swallowed as an unrecognized option on `ship` itself) assumes
  the command name appears exactly once in `argv` and isn't itself the
  value of an earlier option — fine today, but would need revisiting if
  a global option is ever added before the command name.
- **Vite HMR still needs a few lines of `vite.config.js` added by hand.**
  `ship` publishes the dev server's port and `ship init` prints a
  reminder with the exact snippet when it detects `vite` in
  `package.json`, but can't safely auto-edit an *existing*
  `vite.config.js` itself — reliably patching arbitrary JS with a tool
  that has no JS parser isn't worth the fragility. See README's
  "Frontend dev server" section for the snippet.
- **Upgrading `ship` on an existing project needs `ship init` re-run.**
  `ship up` doesn't republish stub files (`ship/Dockerfile`,
  `ship/nginx/default.conf`, ...) — only `ship init` does. A project that
  upgrades `ship` and runs `ship up --prod` directly, without re-running
  `init`, keeps whatever stub files it already had on disk. No
  version-check/mismatch-warning mechanism exists yet.
- **Pinned third-party image tags will go stale over time**, same as any
  pinned dependency. No automated version-bump tooling
  (Renovate/Dependabot-style) is set up yet.

## Not started

- Mutagen-based sync mode as an opt-in alternative to bind mounts on
  Windows/macOS, for projects where bind-mount I/O is a bottleneck.
- Cross-service coordination beyond what `ComposeFileBuilder::build()`
  already computes generically (`APP_URL`, `PHP_VERSION` backfill). A
  service that needs to know about *other* selected services beyond
  that has no clean mechanism yet — document the specific need if one
  comes up, rather than speculatively designing for it now.

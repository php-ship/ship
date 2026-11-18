# The ship-release contract

**Status: draft.** Corrections are still allowed without following rule 2. The
contract is frozen once a release has been tested end to end with a deployment
tool on a real project; from then on, rules 1–7 apply in full.

A Ship producer (`ship release --tag <tag>`) writes a release folder: everything
needed to run one version of an application on a machine that has nothing but
Docker. This document describes that folder and its `release.json`, for
deployment tools that read it and for every Ship producer that writes it. The
JSON Schema is `schema/ship-release.schema.json`.

## Rules

1. The contract is named `ship-release` and has no version number. `release.json`
   contains `"format": "ship-release"`.
2. Only add. Never remove a field, rename it, or change what it means. If a
   meaning has to change, add a new field next to the old one.
3. Readers ignore fields they don't know.
4. Every field added after the first contract is optional for readers, and this
   document says what its absence means. Producers always write every field they
   know.
5. A change that cannot follow these rules uses a new format name
   (`ship-release-2`). Avoid it.
6. `release.json` never contains a secret value: no setting values, passwords or
   tokens. Setting names are allowed.
7. Every list of names is open: roles, `framework`, `runtime`, and any added
   later. New values may appear at any time. This document states what a reader
   does with a value it doesn't know.

## The release folder

```text
<tag>/
├── release.json          # this contract; written last
├── docker-compose.yml    # final images, no build:, pulled images pinned by digest
├── images/<name>.tar     # docker save, one per unique project-owned image
├── .env                  # the production settings, 0600; absent when env.file is null
├── deploy-commands.sh    # generated from "deploy"; only when deploy is not null
└── README.md             # the manual deploy steps for this release
```

- The folder is `0700`, `.env` is `0600` and `deploy-commands.sh` is `0755` (on
  systems with POSIX permissions).
- The producer writes the folder under a temporary name and renames it into
  place once everything succeeded, with `release.json` written last. **A folder
  without `release.json` is incomplete** and must not be deployed.
- A producer never silently overwrites an existing release folder.

## `release.json`

```json
{
  "format": "ship-release",
  "app": "client",
  "tag": "1.5.0",
  "createdAt": "2026-11-17T10:00:00Z",
  "builder": { "package": "php-ship/ship", "version": "1.0.0" },
  "source": { "gitCommit": "3f9c2e1d…", "dirty": false },
  "platform": "linux/amd64",
  "compose": "docker-compose.yml",
  "images": [
    {
      "ref": "client-app:1.5.0",
      "id": "sha256:…",
      "file": "images/app.tar",
      "sha256": "…",
      "services": ["app", "horizon", "reverb"]
    },
    {
      "ref": "client-webserver:1.5.0",
      "id": "sha256:…",
      "file": "images/webserver.tar",
      "sha256": "…",
      "services": ["webserver"]
    }
  ],
  "pulledImages": [
    { "ref": "postgres:18-alpine@sha256:…", "services": ["pgsql"] }
  ],
  "http": [
    { "service": "webserver", "port": 80, "role": "site", "health": "/up" },
    { "service": "reverb", "port": 8080, "role": "websocket", "health": null }
  ],
  "services": {
    "app":       { "role": "app", "image": "client-app:1.5.0", "healthcheck": false, "volumes": [] },
    "horizon":   { "role": "process", "image": "client-app:1.5.0", "healthcheck": false, "volumes": [] },
    "pgsql":     { "role": "infrastructure", "image": "postgres:18-alpine@sha256:…", "healthcheck": true, "volumes": ["ship-pgsql-data"] },
    "reverb":    { "role": "process", "image": "client-app:1.5.0", "healthcheck": false, "volumes": [] },
    "webserver": { "role": "web", "image": "client-webserver:1.5.0", "healthcheck": false, "volumes": [] }
  },
  "framework": "laravel",
  "runtime": "php-fpm",
  "env": {
    "file": ".env",
    "keys": ["APP_KEY", "APP_URL", "DB_PASSWORD", "STRIPE_KEY"],
    "referenced": ["DB_DATABASE", "DB_PASSWORD", "DB_USERNAME"],
    "required": ["APP_KEY", "DB_PASSWORD"]
  },
  "deploy": {
    "service": "app",
    "needs": ["pgsql"],
    "commands": [["sh", "-c", "php artisan migrate --force"]]
  },
  "requires": { "networks": ["proxy"] }
}
```

Only `builder.package` differs between producers: `php-ship/ship` here.

### Fields

| Field | Type | Meaning |
|---|---|---|
| `format` | string | Always `"ship-release"`. |
| `app` | string | The application's name. It is the Compose project name (the Compose file's top-level `name:`) and the same for every release of the application, wherever it was built: it ties `1.0.0` and `2.0.0` together, and it prefixes the data volumes on the server. |
| `tag` | string | The release tag. Matches `^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$`. |
| `createdAt` | string | RFC 3339 timestamp in UTC (`Z`). |
| `builder.package`, `builder.version` | string | Which producer wrote the release, and its version. |
| `source.gitCommit` | string or null | The commit released (`git rev-parse HEAD`); null outside a git repository. |
| `source.dirty` | bool or null | True when the work tree had uncommitted changes when the release started; null outside a git repository. Files the producer regenerates on every run (its own generated Compose files and entrypoint) don't count. |
| `platform` | string | `os/arch[/variant]` of every project-owned image, e.g. `linux/amd64`, `linux/arm64`. A producer refuses to write a release whose images disagree. The images run only on that platform. |
| `compose` | string | Relative path of the Compose file. |
| `images[]` | array | One entry per unique project-owned image (built by the producer). |
| `images[].ref` | string | The image reference the Compose file uses. |
| `images[].id` | string | The image ID (`docker image inspect --format '{{.Id}}'`) at release time. Loading the archive gives an image with this ID. |
| `images[].file` | string | Relative path of the `docker save` archive. |
| `images[].sha256` | string | Lowercase hex SHA-256 of the archive file. It checks the transfer only: `docker save` output is not reproducible, so two archives of the same image differ. Use `id` to compare images. |
| `images[].services` | string[] | Every Compose service that runs this image. |
| `pulledImages[]` | array | Images not built by the producer (databases, caches, …). The server pulls them from their registry. |
| `pulledImages[].ref` | string | A digest-pinned reference (`name:tag@sha256:…`). The Compose file uses this same reference. |
| `pulledImages[].services` | string[] | The services that use it. |
| `http[]` | array | The services that receive HTTP traffic, which a reverse proxy routes to. Empty when nothing does. |
| `http[].service` | string | A Compose service name. |
| `http[].port` | int | The container port (not a host port). |
| `http[].role` | string | `site`: the application's web traffic. `websocket`: a WebSocket server the browser connects to directly. **Unknown role:** plain HTTP. |
| `http[].health` | string or null | A path that answers 2xx while the service works, e.g. `/up`; null when unknown. |
| `services` | object | Every Compose service in the release, keyed by name. |
| `services.*.role` | string | `app`: the application. `web`: its web server in front of the app. `process`: another long-running process of the application (queue worker, scheduler, WebSocket server). `infrastructure`: a supporting service (database, cache, storage). **Unknown role:** `process`. |
| `services.*.image` | string | Equal to one `images[].ref` or one `pulledImages[].ref`. |
| `services.*.healthcheck` | bool | Whether the service has a Compose healthcheck, i.e. whether `docker compose up --wait` waits for it to be healthy rather than only started. |
| `services.*.volumes` | string[] | The named volumes the service mounts, as declared in the Compose file (without the Compose project prefix). These hold data: back them up, and never remove them as part of a deploy. Empty when none. |
| `framework` | string or null | The application's framework, e.g. `laravel`, `symfony`; null when unknown. **Unknown value:** treat as null. |
| `runtime` | string | How the application serves HTTP, e.g. `php-fpm`, `octane-swoole`, `octane-roadrunner`, `octane-frankenphp`. **Unknown value:** no runtime-specific behaviour. |
| `env.file` | string or null | The settings file in the release (`.env`), or null when the release carries none. |
| `env.keys` | string[] | The name of every production setting. Names only, never values. |
| `env.referenced` | string[] | Every variable the Compose file interpolates (`${VAR}`, `$VAR`). |
| `env.required` | string[] | Settings the release can't start without. A subset of `keys`. |
| `deploy` | object or null | One-off commands to run once per deploy, before the new release starts. Null when there are none. |
| `deploy.service` | string | The service whose image runs the commands. |
| `deploy.needs` | string[] | Services that must be up and healthy before the commands run. |
| `deploy.commands` | string[][] | The commands, in order, each an argv. Each runs in a one-off container of `deploy.service`, with no other service started by it: `docker compose run --rm --no-deps -T <service> <argv…>`. A failing command stops the deploy. |
| `requires.networks` | string[] | External Docker networks the Compose file attaches to, which must exist before it starts. Every `http[]` service is attached to them. |

Every array is present, even when empty, and sorted, except `images`,
`pulledImages`, `http` and `deploy.commands`, whose order is meaningful or
stable. `framework`, `deploy`, `env.file`, `source.*` and `http[].health` are
null rather than absent.

### Settings

A release reads its settings in two places, and whoever deploys must provide
both:

- **Compose interpolation.** Compose fills in the variables in
  `env.referenced` when it reads `docker-compose.yml`, from the shell
  environment or a `.env` file next to it.
- **The containers.** The application's services load `.env` at start.

With `env.file` set, the release's `.env` serves both. With `env.file` null
(the producer was told to leave settings out, for platforms that keep them
themselves), the deployment tool supplies every variable in `env.referenced`
to Compose and every setting in `env.keys` to the containers, for instance by
writing a `.env` file into the folder. `env.required` lists the settings
without which the release won't start.

## Deploying a release

On a machine with Docker, from the release folder:

1. Create each network in `requires.networks` that doesn't exist yet.
2. `docker load -i <file>` for each `images[]` entry.
3. When `deploy` is not null, run `sh deploy-commands.sh`, or do what it does:
   `docker compose up -d --wait <needs…>`, then each `deploy.commands` argv as
   described above.
4. `docker compose up -d`.

## Notes for producers and readers

- `platform`: a release built on one architecture runs only on that
  architecture. Building for another platform (`--platform`) is a planned
  follow-up.
- Pinned `pulledImages` refs point at the registry's multi-platform digest, so
  the server pulls the variant for its own platform.
- `deploy-commands.sh` is generated from `deploy`; the two never disagree.

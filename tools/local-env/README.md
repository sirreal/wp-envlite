# envlite

A zero-daemon local environment for `wordpress-develop`. Brings a clean
checkout to a state where:

- `php tools/local-env/envlite.php serve` serves WordPress against a
  file-backed SQLite database, and
- `./vendor/bin/phpunit --group html-api` runs green on host PHP.

No system MySQL, no Docker, no MAMP. Unrelated to the `npm run env:*`
Docker stack that shares this directory.

---

## Requirements

Verified by Phase 0 (preflight). Failure here exits 3.

- PHP ≥ 7.4 with extensions: `pdo_sqlite`, `sqlite3`, `openssl`,
  `simplexml`, `zip`. Unix also requires `pcntl`.
- Node ≥ 20.10, npm ≥ 10.2.3 (matching `package.json` `engines`).
- Composer ≥ 2.

On Ubuntu/Debian, the SQLite/SimpleXML/Zip extensions are typically split
out: `apt install php-sqlite3 php-xml php-zip`. Homebrew's `php` and most
distro PHP builds include them by default.

The first `init` needs network access (Phase 5 plugin download, Phase 7
salt fetch). Subsequent runs are offline-friendly.

## Quick start

Run from the repo root:

```sh
php tools/local-env/envlite.php init     # one-shot setup
php tools/local-env/envlite.php serve    # foreground dev server
```

`init` is idempotent — safe to re-run. For an all-in-one:

```sh
php tools/local-env/envlite.php up       # init, then serve
```

To confirm setup, in one terminal:

```sh
php tools/local-env/envlite.php serve
```

…and in another:

```sh
./vendor/bin/phpunit --group html-api    # ~5 s, ~1365 tests
curl -sI "http://127.0.0.1:$(cat .envlite/port)/"
```

A green phpunit + a 2xx (not a 3xx redirect to `/wp-admin/install.php`)
means the environment is ready. Log in at `/wp-login.php` with `admin` /
`password` (admin email `admin@example.com`, fixed by Phase 8).

## Subcommands

| Subcommand | Behavior |
|---|---|
| `init` | Run all setup phases. Idempotent. |
| `up` | `init`, then `serve` in the foreground. |
| `serve` | Launch `php -S` on the cached port. Foreground; Ctrl-C shuts down. |
| `clean` | Remove envlite-managed files after a single confirmation prompt. Does not touch `node_modules/`, `vendor/`, build artifacts, or anything not in the manifest. |
| `help` (or no args, `-h`, `--help`) | Print usage. |

## Flags

- `--force` (global) — answer `y` to every prompt envlite would
  otherwise raise this invocation. Required for non-interactive use
  (CI, scripts) and for `clean` without a prompt. You won't need it on
  a normal first run.
- `init [--port=N] [--no-build]`
  - `--port=N` overrides Phase 1 discovery and rewrites `.envlite/port`.
    N may be any 1–65535. envlite still bind-probes; aborts if N is
    taken.
  - `--no-build` skips `npm run build:dev` (Phase 3). Use only when you
    know your changes don't affect build outputs.
- `up` accepts the same `--port` / `--no-build` as `init`.
- `serve` takes no flags. The cached port is the source of truth — to
  change it, run `init --port=N` (or delete `.envlite/port` and re-run).

## State

envlite tracks every file it writes in `.envlite/manifest`. Anything not
in the manifest is not envlite's. The state directory `.envlite/` is
gitignored upstream.

| File | Purpose |
|---|---|
| `.envlite/port` | Cached site port (8100–8899 by default, deterministically derived from the checkout's canonical path so two checkouts rarely collide). |
| `.envlite/manifest` | One line per managed path: `<sha256>  <relative path>`. |

Files written by envlite during `init` and removed by `clean`:

```
.envlite/port
.envlite/manifest
src/wp-content/plugins/sqlite-database-integration/
src/wp-content/db.php
wp-tests-config.php
src/wp-config.php
```

`src/wp-content/database/.ht.sqlite` is created by the SQLite drop-in
the first time WordPress loads (Phase 8 triggers that load). It is *not*
in the manifest after the first `init`. envlite records it on the next
`init` or `clean` via an observation hook, so a `clean` invoked after
`serve` (no intervening `init`) still prompts before deleting the live
DB rather than silently orphaning user content.

Side effects of `init` that envlite does **not** track and `clean` does
**not** remove: `node_modules/`, `vendor/`, build outputs under `src/`,
and `src/wp-content/database/.ht.test.sqlite` (created by phpunit; not
managed because phpunit drops every WP table on every run, so there's
no user content to protect). Use `git clean -fdx` to nuke those.

Writes are atomic: write-temp + fsync + rename in binary mode, with the
hash computed from the in-memory bytes before rename. A SIGINT
mid-write leaves either fully-pre or fully-post state on disk — never a
half-written file with a stale hash.

`wp-tests-config.php` carries an extra
`define( 'DB_FILE', '.ht.test.sqlite' )` so phpunit's "drop everything"
bootstrap can't reach the live `.ht.sqlite`. `src/wp-config.php` stays
free of any `DB_FILE` and uses the drop-in's default.

## Idempotency

Every file-producing phase consults the manifest:

- **Path absent** → write, record.
- **In manifest, hash matches** → silent re-stamp; envlite owns it.
- **In manifest, hash drifted** → user has modified envlite's output;
  prompt before overwriting. The prompt previews both hashes.
- **Not in manifest** → user authored it; prompt before overwriting.

`--force` answers yes to every prompt. In a non-interactive context (no
TTY) without `--force`, envlite exits 5 with an actionable message
rather than failing silently.

`init` is safe to re-run. `wp_install()` short-circuits via
`is_blog_installed()`, and **envlite never drops tables** — your local
posts/pages/uploads survive any number of re-`init`s.

## Exit codes

| Code | Meaning |
|---|---|
| 0 | Success. |
| 1 | A phase failed. The phase number and one-line cause are on stderr. |
| 2 | Unknown subcommand or invalid argument. |
| 3 | Preflight (Phase 0) failed — environment doesn't satisfy preconditions. |
| 5 | User declined a destructive prompt, or non-interactive context without `--force`. |

All diagnostic output goes to stderr, prefixed `envlite: …` or
`envlite <subcommand>: …`. No timestamps, no log levels, no ANSI color.

## Conventions

- **`127.0.0.1`, never `localhost`.** envlite binds, redirects, and
  cookies on the literal IPv4. `php -S` is IPv4-only, but `localhost`
  resolves to `::1` first on modern macOS/Linux — a browser hitting
  `http://localhost:<port>/` can get `ECONNREFUSED` before any IPv4
  fallback. Use `127.0.0.1` everywhere or admin login will break on
  cookie-domain mismatch.
- **Phase 4 resolves Composer fresh against runtime PHP** — no
  `composer.lock`, no `--platform-php`, no `config.platform.php`. This
  is intentional and mirrors WP CI; do not "fix" it.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `phase 0: not in a wordpress-develop checkout` | invoked from the wrong CWD | `cd` to the repo root |
| `phase 0: extension X not loaded` | host PHP missing the extension | install it (Ubuntu/Debian: `apt install php-sqlite3 php-xml php-zip`; Homebrew's `php` already bundles them) |
| `phase 0: <tool> below minimum` | node/npm/composer too old | upgrade |
| `phase 5: SHA256 mismatch on plugin zip` | the SQLite plugin release was re-cut, or the download was corrupted | retry once; if persistent, the SHA pin in `envlite.php` needs a deliberate update |
| `phase 8: ... DB error` | corrupt `.ht.sqlite` from an interrupted previous run | delete `src/wp-content/database/.ht.sqlite`, re-run `init` |
| phpunit error `ABSPATH constant ... non-existent path` | ran `init --no-build` on a fresh checkout | re-run `init` without `--no-build` |
| phpunit fails with deprecation-as-exception in a non-`html-api` group | `phpunit.xml.dist` sets `convertDeprecationsToExceptions=true`; only `--group html-api` is the green-bar contract on PHP 8.5 | per-group fix; not envlite's contract |
| `serve: failed to bind 127.0.0.1:<port>` | another process is on the port (the cached port is intentionally trusted across re-runs, so this is a real collision, not envlite re-using its own) | `lsof -nP -iTCP:<port> -sTCP:LISTEN`; kill the holder, or `init --port=N` to relocate |
| browser hangs / `ECONNREFUSED` on `http://localhost:<port>/` | IPv6 resolution beat IPv4 | use `http://127.0.0.1:<port>/` |

## What envlite does not do

- Run anything in the background. `serve` is foreground; Ctrl-C is the
  shutdown.
- Install or upgrade global tools. Phase 0 only verifies them. envlite
  does not touch `PATH`, `COMPOSER_HOME`, or your shell profile.
- Manage `node_modules/`, `vendor/`, or build artifacts. envlite invokes
  `npm ci`, `composer install`, and `npm run build:dev` during `init`
  but treats their outputs as ordinary dev-tool artifacts.
- Refresh the pinned SQLite plugin. Bumping the SHA is a deliberate
  envlite revision, not an automatic update.
- Configure HTTPS, a reverse proxy, or any production-shaped stack.
- Manage worktrees. envlite operates on whatever directory it is
  invoked in.

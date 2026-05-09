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

envlite checks these at startup; missing requirements abort early.

- PHP ≥ 7.4 with extensions: `pdo_sqlite`, `sqlite3`, `openssl`,
  `simplexml`, `zip`. Unix also requires `pcntl`.
- Node ≥ 20.10, npm ≥ 10.2.3 (matching `package.json` `engines`).
- Composer ≥ 2.

On Ubuntu/Debian, the SQLite/SimpleXML/Zip extensions are typically split
out: `apt install php-sqlite3 php-xml php-zip`. Homebrew's `php` and most
distro PHP builds include them by default.

The first `init` needs network access. Subsequent runs are
offline-friendly.

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
`password`.

## Subcommands

| Subcommand | Behavior |
|---|---|
| `init` | Set up the environment. Idempotent. |
| `up` | `init`, then `serve` in the foreground. |
| `serve` | Launch `php -S` on the cached port. Foreground; Ctrl-C shuts down. |
| `clean` | Remove envlite's files after a single confirmation prompt. Does not touch `node_modules/`, `vendor/`, build artifacts, or anything envlite didn't create. |
| `help` (or no args, `-h`, `--help`) | Print usage. |

## Flags

- `--force` (global) — answer `y` to every prompt envlite would
  otherwise raise this invocation. Required for non-interactive use
  (CI, scripts) and for `clean` without a prompt. You won't need it on
  a normal first run.
- `init [--port=N] [--no-build]`
  - `--port=N` picks a specific port (1–65535) and caches it. envlite
    still bind-probes; aborts if N is taken.
  - `--no-build` skips `npm run build:dev`. Use only when you know your
    changes don't affect build outputs.
- `up` accepts the same `--port` / `--no-build` as `init`.
- `serve` takes no flags. The cached port is the source of truth — to
  change it, run `init --port=N`.

## Re-runs and your data

`init` is safe to re-run on a half-configured repo. envlite **never
drops tables** — local posts, pages, uploads, and uninstalled plugins
under `src/wp-content/` survive any number of re-`init`s. If envlite
would touch a file you've modified, it prompts first; `--force`
answers yes.

`clean` removes only the files envlite created, after a single
confirmation prompt. The live SQLite DB
(`src/wp-content/database/.ht.sqlite`) is included in that prompt
because it may hold your work. To wipe everything else (`node_modules/`,
`vendor/`, build artifacts), use `git clean -fdx`.

## State directory

`.envlite/` at the repo root holds envlite's state. It's gitignored
upstream; you don't normally need to touch it.

| File | Purpose |
|---|---|
| `.envlite/port` | The site port. Auto-picked from 8100–8899 the first time, then reused. |
| `.envlite/manifest` | Internal — tracks files envlite owns. Don't hand-edit. |

## Exit codes

| Code | Meaning |
|---|---|
| 0 | Success. |
| 1 | Setup failed. The cause is on stderr. |
| 2 | Unknown subcommand or invalid argument. |
| 3 | Environment doesn't satisfy requirements (see above). |
| 5 | A prompt was declined, or non-interactive context without `--force`. |

All diagnostic output goes to stderr, prefixed `envlite: …` or
`envlite <subcommand>: …`.

## Use `127.0.0.1`, not `localhost`

envlite binds, redirects, and cookies on the literal IPv4. `php -S` is
IPv4-only, but `localhost` resolves to `::1` first on modern
macOS/Linux — a browser hitting `http://localhost:<port>/` can get
`ECONNREFUSED` before any IPv4 fallback. Use `127.0.0.1` everywhere or
admin login will break on cookie-domain mismatch.

## Troubleshooting

| Symptom | Fix |
|---|---|
| `not in a wordpress-develop checkout` | `cd` to the repo root and re-run. |
| `extension X not loaded` | Install it. Ubuntu/Debian: `apt install php-sqlite3 php-xml php-zip`. Homebrew's `php` already bundles them. |
| `<tool> below minimum` | Upgrade node/npm/composer. |
| `SHA256 mismatch on plugin zip` | Retry once. If persistent, the pinned SQLite drop-in needs a deliberate update — file an issue. |
| Corrupt-DB error after an interrupted run | Delete `src/wp-content/database/.ht.sqlite` and re-run `init`. |
| phpunit error `ABSPATH constant ... non-existent path` | You ran `init --no-build` on a fresh checkout. Re-run `init` without `--no-build`. |
| phpunit fails with deprecation-as-exception in a non-`html-api` group | Only `--group html-api` is the green-bar contract; other groups may surface deprecations on newer PHP. Per-group fix, not envlite's contract. |
| `failed to bind 127.0.0.1:<port>` | Another process holds the port. `lsof -nP -iTCP:<port> -sTCP:LISTEN` to find it; kill it, or run `init --port=N` to relocate. |
| Browser hangs / `ECONNREFUSED` on `http://localhost:<port>/` | IPv6 resolution won. Use `http://127.0.0.1:<port>/`. |

## What envlite does not do

- Run anything in the background. `serve` is foreground; Ctrl-C is the
  shutdown.
- Install or upgrade global tools, or modify `PATH`, `COMPOSER_HOME`, or
  your shell profile.
- Manage `node_modules/`, `vendor/`, or build artifacts.
- Auto-update the pinned SQLite drop-in.
- Configure HTTPS, a reverse proxy, or any production-shaped stack.
- Manage worktrees. envlite operates on whatever directory it's invoked
  in.

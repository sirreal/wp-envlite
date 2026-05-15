# envlite

A zero-daemon local environment for `wordpress-develop`. Runs WordPress
on SQLite via PHP's built-in server, with phpunit pointed at a separate
SQLite test DB in the same checkout so it can drop tables without
touching your dev content. No MySQL, no Docker, no MAMP.

## Quickstart

Download `envlite.phar` from the
[latest release](https://github.com/sirreal/wp-envlite/releases/latest),
make it executable, and put it on your `PATH`:

```sh
curl -fSL -o envlite \
  https://github.com/sirreal/wp-envlite/releases/latest/download/envlite.phar
chmod +x envlite
mv envlite ~/.local/bin/   # any directory on your PATH
```

Then, from the [wordpress-develop](https://github.com/wordpress/wordpress-develop/) repo root:

```sh
envlite up
```

That sets up the environment and starts the dev server in the
foreground at `http://127.0.0.1:<port>`, where `<port>` is auto-picked
from 8100–8899 on first run and cached at `.cache/envlite/port` for reuse.
Open the URL it prints; log in at `/wp-login.php` with `admin` /
`password`. Ctrl-C shuts it down.

The first run needs network access (npm + Composer deps, plus a
pinned SQLite drop-in plugin). Subsequent runs are offline.

Re-runs are safe. envlite skips work that's already done, prompts
before touching anything you've changed, and **never drops tables** —
your local content survives.

### Running from source

Instead of the phar you can run the script straight from a checkout of
this repo — useful when working on envlite itself. From the
wordpress-develop repo root:

```sh
php /path/to/wp-envlite/envlite.php up
```

`envlite.php` uses the current working directory as the
wordpress-develop checkout and loads `router.php` from beside itself,
so this repo can live anywhere.

## Requirements

- PHP ≥ 7.4 with `gd`, `pdo_sqlite`, `sqlite3`, `openssl`, `simplexml`,
  `zip`, and `allow_url_fopen=1`. On Unix only, also `pcntl`.
- Node ≥ 20.10, npm ≥ 10.2.3.
- Composer ≥ 2.

envlite checks these at startup and aborts with a clear error if
anything is missing.

## Other commands

```sh
envlite up --no-serve   # setup only, no server (CI)
envlite clean           # remove envlite-created files
```

`up` accepts:
- `--port=N` — pick a specific port (1–65535) and cache it.
- `--no-build` — skip `npm run build:dev`. Don't use this on a fresh
  checkout; phpunit will fail with `ABSPATH constant ... non-existent path`.
- `--no-serve` — run setup phases only; don't launch the dev server.
- `--rebuild` — re-run every setup phase, ignoring cached skip-state.
  Use when state is suspect or to validate a fresh install.
- `--force` — skip prompts (envlite prompts before overwriting files
  you've modified). Required for non-interactive contexts.

Re-running `up` is cheap. envlite hashes `package-lock.json` after a
successful `npm ci` and `PHP_VERSION + composer.json` after a
successful `composer install` (the PHP-version mix re-runs Composer
when you switch PHP, since wordpress-develop ships no `composer.lock`).
If those hashes haven't changed and the output directories are
present, the install phases skip. `npm run build:dev` skips only when
both deps phases skipped AND its sentinel directory
`src/wp-includes/js/dist/` is present (gitignored, created by
`build:dev` — its presence proves a build has succeeded at least
once). To force a re-install of deps without nuking the directories,
use `--rebuild`; to force from scratch, `rm -rf node_modules/ vendor/`
and re-run.

`clean` removes envlite's config files (`src/wp-config.php`,
`wp-tests-config.php`, `src/wp-content/db.php`), the bundled SQLite
plugin directory, the cached port, the skip-state file, and — on a
single confirmation prompt — the live SQLite DB at
`src/wp-content/database/.ht.sqlite`. It does not touch
`node_modules/`, `vendor/`, or build artifacts under `src/`. For
those, use `git clean -fdx`.

## Use `127.0.0.1`, not `localhost`

envlite binds IPv4 only. `localhost` resolves to `::1` first on modern
macOS/Linux, so a browser hitting `http://localhost:<port>/` can get
`ECONNREFUSED`. Use `127.0.0.1` and admin cookies will work too.

## Troubleshooting

| Symptom | Fix |
|---|---|
| `not in a wordpress-develop checkout` | `cd` to the repo root. |
| `extension X not loaded` | Install it. Ubuntu/Debian: `apt install php-sqlite3 php-xml php-zip`. Homebrew's `php` already bundles them. |
| `<tool> below minimum` | Upgrade node/npm/composer. |
| `SHA256 mismatch on plugin zip` | Retry once. If persistent, the pinned SQLite drop-in needs a deliberate update — file an issue. |
| `failed to bind 127.0.0.1:<port>` | Another process holds the port. `lsof -nP -iTCP:<port> -sTCP:LISTEN`; kill the holder, or `up --port=N` to relocate. |
| phpunit fails with deprecation-as-exception | wordpress-develop sets `convertDeprecationsToExceptions=true`; newer PHP may surface deprecations from core code as exceptions. Per-group fix, not envlite's. |
| Corrupt-DB error after an interrupted run | Delete `src/wp-content/database/.ht.sqlite` and re-run. |

## Automated setup (for agents)

Deterministic, non-interactive setup for an automated agent. envlite uses the **current working directory** as the wordpress-develop checkout, so the phar can live anywhere. Run every command below from the **wordpress-develop checkout root** unless noted.

### Pre-flight

Abort if any check fails. envlite re-checks all of this in its Phase 0 and exits `3` on failure; running the checks first fails faster, before the download and the multi-minute install.

1. The working directory is a wordpress-develop checkout:

   ```sh
   for p in package.json composer.json wp-config-sample.php \
            wp-tests-config-sample.php tests/phpunit/includes/bootstrap.php; do
     [ -f "$p" ] || { echo "not a wordpress-develop checkout: missing $p"; exit 1; }
   done
   [ -d src/wp-includes ] || { echo "missing src/wp-includes/"; exit 1; }
   ```

2. PHP ≥ 7.4 with the required extensions and `allow_url_fopen`:

   ```sh
   php -r '
     $err = [];
     if (PHP_VERSION_ID < 70400) $err[] = "PHP >= 7.4 required, have " . PHP_VERSION;
     $ext = ["gd", "pdo_sqlite", "sqlite3", "openssl", "simplexml", "zip"];
     if (PHP_OS_FAMILY !== "Windows") $ext[] = "pcntl";
     foreach ($ext as $e) if (!extension_loaded($e)) $err[] = "missing extension: $e";
     if (!filter_var(ini_get("allow_url_fopen"), FILTER_VALIDATE_BOOLEAN))
       $err[] = "allow_url_fopen must be enabled";
     if ($err) { fwrite(STDERR, implode("\n", $err) . "\n"); exit(1); }
   '
   ```

3. Tooling on `PATH` at minimum versions — Node ≥ 20.10, npm ≥ 10.2.3,
   Composer ≥ 2:

   ```sh
   node --version && npm --version && composer --version
   ```

4. Network access — the first run fetches npm and Composer dependencies and a pinned SQLite drop-in plugin over HTTPS. Later runs are offline.

### Install

Download the tool, then run `up` **as a background task** — it sets up the environment and then serves, blocking in the foreground:

```sh
curl -fSL -o /tmp/envlite.phar \
  https://github.com/sirreal/wp-envlite/releases/latest/download/envlite.phar

php /tmp/envlite.phar up --force
```

- `--force` answers every prompt; required in non-interactive contexts. Without it, envlite prompts before overwriting modified files and exits `5` when it cannot read a prompt.
- The first run takes several minutes; re-runs skip completed phases.
- If a setup phase fails, the task exits non-zero before serving and stderr names the failing phase. Exit codes: `2` bad arguments, `3` pre-flight failure, `5` prompt declined or non-interactive without `--force`, any other non-zero = a phase failed.

### Verify

Setup has succeeded once the task's output shows envlite's line — take the dev-server URL from it:

```
envlite up: environment ready, starting dev server on http://127.0.0.1:<port>/ (admin / password)
```

1. Confirm envlite wrote every file it owns — all must exist:

   ```sh
   for p in src/wp-config.php wp-tests-config.php src/wp-content/db.php \
            src/wp-content/plugins/sqlite-database-integration \
            src/wp-content/database/.ht.sqlite; do
     [ -e "$p" ] || { echo "missing expected file: $p"; exit 1; }
   done
   ```

2. Wait until the output contains a line matching `Development Server (http://127.0.0.1:<port>) started` — PHP's built-in server prints it once the socket is bound and accepting. Confirm the homepage responds:

   ```sh
   curl -fsS -o /dev/null "http://127.0.0.1:<port>/"   # expect HTTP 200
   ```

Installation is correct when the task reached `environment ready`, every file in step 1 exists, and the homepage returns HTTP 200. Leave the background task running for follow-up work; when finished, stop the server by ending that background task.

### Notes

- Re-running `php /tmp/envlite.phar up --force` is safe and idempotent; it skips completed phases.
- To undo everything envlite created, run `php /tmp/envlite.phar clean --force` from the checkout root.
- `failed to bind 127.0.0.1:<port>` means the port is taken. Re-run with `up --port=N` to relocate.
- See [Troubleshooting](#troubleshooting) for other failure modes.

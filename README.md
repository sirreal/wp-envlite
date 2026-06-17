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

envlite does **not** install dependencies or build assets — run
`npm ci && composer install && npm run build:dev` (or your usual
equivalent) yourself before running phpunit and to ensure the served site has built assets (e.g. the editor).
envlite's own first run needs network access only to download the
pinned SQLite drop-in plugin; subsequent runs are offline.

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

**Hard-required** (envlite uses these itself; it aborts at startup with
a clear error if any is missing):

- PHP ≥ 7.4 with `pdo_sqlite`, `sqlite3`, `openssl`, `zip`, and
  `allow_url_fopen=1`. On Unix only, also `pcntl`.

**Developer prerequisites** (envlite does not install dependencies or
build assets — you do; it only **warns** at startup if these look
unusable, then continues):

- Node ≥ 20.10, npm ≥ 10.2.3, Composer ≥ 2 — to run
  `npm ci && composer install && npm run build:dev`.
- The `gd` PHP extension (WordPress core test bootstrap) and
  `simplexml` (the PHPStan/PHPCS linting toolchain).

## Other commands

```sh
envlite up --no-serve   # setup only, no server (CI)
envlite clean           # remove envlite-created files
```

`up` accepts:
- `--port=N` — pick a specific port (1–65535) and cache it.
- `--no-serve` — run setup phases only; don't launch the dev server.
- `--force` — skip prompts (envlite prompts before overwriting files
  you've modified). Required for non-interactive contexts.

Remember that envlite does not install dependencies or build assets —
run `npm ci && composer install && npm run build:dev` (or your usual
equivalent) yourself; envlite never does.

Re-running `up` is cheap. The SQLite drop-in download/extract is skipped
when its pinned tree is already installed (manifest entry present,
`db.copy` on disk, and the recorded pin SHA matches the one in
`envlite.php`), and the config files (`src/wp-config.php`,
`wp-tests-config.php`, `src/wp-content/db.php`) are silently re-stamped
when envlite already owns them. There is no flag to force a drop-in
re-download — bump the SHA256 pin in `envlite.php`, or run `clean` then
`up`.

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
| `required extension X missing` (hard fail) vs. `recommended extension X missing` (warning) | Install it. Ubuntu/Debian: `apt install php-sqlite3 php-xml php-zip`. Homebrew's `php` already bundles them. `pdo_sqlite`/`sqlite3`/`openssl`/`zip` are hard-required; `gd`/`simplexml` (php-xml) are only a warning — needed for your phpunit/linting, which envlite leaves to you. |
| `<tool> below minimum` | Now a warning, not a hard stop. Upgrade node/npm/composer so your `npm ci`/`composer install`/`build:dev` work. |
| `SHA256 mismatch on plugin zip` | Retry once. If persistent, the pinned SQLite drop-in needs a deliberate update — file an issue. |
| `failed to bind 127.0.0.1:<port>` | Another process holds the port. `lsof -nP -iTCP:<port> -sTCP:LISTEN`; kill the holder, or `up --port=N` to relocate. |
| phpunit fails with deprecation-as-exception | wordpress-develop sets `convertDeprecationsToExceptions=true`; newer PHP may surface deprecations from core code as exceptions. Per-group fix, not envlite's. |
| Corrupt-DB error after an interrupted run | Delete `src/wp-content/database/.ht.sqlite` and re-run. |

## Automated setup (for agents)

Deterministic, non-interactive setup for an automated agent. envlite uses the **current working directory** as the wordpress-develop checkout, so the phar can live anywhere. Run every command below from the **wordpress-develop checkout root** unless noted.

### Pre-flight

Abort if any check fails. The agent will install dependencies and build assets, so it makes sense to require the full toolchain up front — but note that **envlite itself only hard-fails (exit `3`) on a subset**: the checkout markers, PHP ≥ 7.4, the `pdo_sqlite`/`sqlite3`/`openssl`/`zip` (+ `pcntl` on Unix) extensions, `allow_url_fopen`, `proc_open`, and `pcntl_exec`. envlite only **warns** (and continues) on node/npm/composer versions and the `gd`/`simplexml` extensions. Running the checks below first fails faster, before the agent spends minutes on `npm ci`/`composer install`/`build:dev`.

1. The working directory is a wordpress-develop checkout:

   ```sh
   for p in package.json composer.json wp-config-sample.php \
            wp-tests-config-sample.php tests/phpunit/includes/bootstrap.php; do
     [ -f "$p" ] || { echo "not a wordpress-develop checkout: missing $p"; exit 1; }
   done
   [ -d src/wp-includes ] || { echo "missing src/wp-includes/"; exit 1; }
   ```

2. PHP ≥ 7.4 with the required extensions and `allow_url_fopen`. The
   first four extensions are what envlite itself hard-requires; `gd` and
   `simplexml` are only an envlite **warning**, but the agent needs them
   too (the WP core test bootstrap and the linting toolchain it will
   run), so require them here:

   ```sh
   php -r '
     $err = [];
     if (PHP_VERSION_ID < 70400) $err[] = "PHP >= 7.4 required, have " . PHP_VERSION;
     $ext = ["pdo_sqlite", "sqlite3", "openssl", "zip", "gd", "simplexml"];
     if (PHP_OS_FAMILY !== "Windows") $ext[] = "pcntl";
     foreach ($ext as $e) if (!extension_loaded($e)) $err[] = "missing extension: $e";
     if (!filter_var(ini_get("allow_url_fopen"), FILTER_VALIDATE_BOOLEAN))
       $err[] = "allow_url_fopen must be enabled";
     if ($err) { fwrite(STDERR, implode("\n", $err) . "\n"); exit(1); }
   '
   ```

3. Tooling on `PATH` at minimum versions — Node ≥ 20.10, npm ≥ 10.2.3,
   Composer ≥ 2. envlite only warns on these, but the agent installs
   dependencies and builds, so require them:

   ```sh
   node --version && npm --version && composer --version
   ```

4. Network access — the agent's `npm ci`/`composer install` fetch dependencies, and envlite's own first run fetches a pinned SQLite drop-in plugin over HTTPS. Later envlite runs are offline.

### Install

envlite does **not** install dependencies or build assets — the agent must do that itself. Install the toolchain output before (or around) `up`, since the served site and phpunit need it:

```sh
npm ci && composer install && npm run build:dev
```

Then download the tool and run `up` **as a background task** — it sets up the environment and then serves, blocking in the foreground:

```sh
curl -fSL -o /tmp/envlite.phar \
  https://github.com/sirreal/wp-envlite/releases/latest/download/envlite.phar

php /tmp/envlite.phar up --force
```

- `--force` answers every prompt; required in non-interactive contexts. Without it, envlite prompts before overwriting modified files and exits `5` when it cannot read a prompt.
- envlite itself is fast — its only first-run network cost is downloading the pinned SQLite drop-in; re-runs skip that too. The multi-minute cost is the separate `npm ci`/`composer install`/`npm run build:dev` above.
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

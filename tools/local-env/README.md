# envlite

A zero-daemon local environment for `wordpress-develop`. Runs WordPress
on SQLite via PHP's built-in server, and gets
`./vendor/bin/phpunit --group html-api` running green. No MySQL, no
Docker, no MAMP.

## Quickstart

From the repo root:

```sh
php tools/local-env/envlite.php up
```

That sets up the environment and starts the dev server in the
foreground at `http://127.0.0.1:<port>`, where `<port>` is auto-picked
from 8100–8899 on first run and cached at `.envlite/port` for reuse.
Open the URL it prints; log in at `/wp-login.php` with `admin` /
`password`. Ctrl-C shuts it down.

The first run needs network access (npm + Composer deps, plus a
pinned SQLite drop-in plugin). Subsequent runs are offline.

Re-runs are safe. envlite skips work that's already done, prompts
before touching anything you've changed, and **never drops tables** —
your local content survives.

## Requirements

- PHP ≥ 7.4 with `pdo_sqlite`, `sqlite3`, `openssl`, `simplexml`, `zip`.
  On Unix only, also `pcntl`.
- Node ≥ 20.10, npm ≥ 10.2.3.
- Composer ≥ 2.

envlite checks these at startup and aborts with a clear error if
anything is missing.

## Other commands

```sh
php tools/local-env/envlite.php init     # setup only, no server
php tools/local-env/envlite.php serve    # server only (after init)
php tools/local-env/envlite.php clean    # remove envlite-created files
```

`init` and `up` accept:
- `--port=N` — pick a specific port (1–65535) and cache it.
- `--no-build` — skip `npm run build:dev`. Don't use this on a fresh
  checkout; phpunit will fail with `ABSPATH constant ... non-existent path`.
- `--force` — skip prompts (envlite prompts before overwriting files
  you've modified). Required for non-interactive contexts.

`clean` removes envlite's config files (`src/wp-config.php`,
`wp-tests-config.php`, `src/wp-content/db.php`), the bundled SQLite
plugin directory, the cached port, and — on a single confirmation
prompt — the live SQLite DB at `src/wp-content/database/.ht.sqlite`.
It does not touch `node_modules/`, `vendor/`, or build artifacts under
`src/`. For those, use `git clean -fdx`.

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
| `failed to bind 127.0.0.1:<port>` | Another process holds the port. `lsof -nP -iTCP:<port> -sTCP:LISTEN`; kill the holder, or `init --port=N` to relocate. |
| phpunit fails with deprecation-as-exception in a non-`html-api` group | Only `--group html-api` is the green-bar contract. Other groups may surface deprecations on newer PHP — per-group fix, not envlite's. |
| Corrupt-DB error after an interrupted run | Delete `src/wp-content/database/.ht.sqlite` and re-run. |

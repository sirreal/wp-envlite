# envlite

A zero-daemon local environment for `wordpress-develop`. Runs WordPress
on SQLite via PHP's built-in server. No MySQL, no Docker, no MAMP.
Unrelated to the `npm run env:*` Docker stack that shares this
directory.

## Quickstart

From the repo root:

```sh
php tools/local-env/envlite.php up
```

That sets up the environment and starts the dev server in the
foreground. Open the URL it prints; log in at `/wp-login.php` with
`admin` / `password`. Ctrl-C shuts it down.

The first run needs network access. Subsequent runs are offline. Re-run
`up` any time — envlite **never drops tables**, so your local content
survives.

## Requirements

- PHP ≥ 7.4 with `pdo_sqlite`, `sqlite3`, `openssl`, `simplexml`, `zip`.
  Unix also requires `pcntl`.
- Node ≥ 20.10, npm ≥ 10.2.3.
- Composer ≥ 2.

Ubuntu/Debian extensions are usually split out:
`apt install php-sqlite3 php-xml php-zip`. Homebrew's `php` bundles
them.

## Other commands

```sh
php tools/local-env/envlite.php init     # setup only, no server
php tools/local-env/envlite.php serve    # server only (after init)
php tools/local-env/envlite.php clean    # remove envlite-created files
```

`init` and `up` accept `--port=N` (1–65535) and `--no-build` (skip
`npm run build:dev`). The chosen port is cached and reused. Pass
`--force` to skip prompts in non-interactive contexts.

`./vendor/bin/phpunit --group html-api` runs the green-bar test contract
(~5 s, ~1365 tests). Other groups may surface deprecations on newer PHP.

## Use `127.0.0.1`, not `localhost`

envlite binds IPv4 only. `localhost` resolves to `::1` first on modern
macOS/Linux, so a browser hitting `http://localhost:<port>/` can get
`ECONNREFUSED`. Use `127.0.0.1` and admin cookies will work too.

## Troubleshooting

| Symptom | Fix |
|---|---|
| `not in a wordpress-develop checkout` | `cd` to the repo root. |
| `extension X not loaded` | Install it. Ubuntu/Debian: `apt install php-sqlite3 php-xml php-zip`. |
| `<tool> below minimum` | Upgrade node/npm/composer. |
| `SHA256 mismatch on plugin zip` | Retry once; if persistent, the pinned SQLite drop-in needs an update — file an issue. |
| `failed to bind 127.0.0.1:<port>` | Another process holds the port. `lsof -nP -iTCP:<port> -sTCP:LISTEN`; kill the holder, or `init --port=N` to relocate. |
| Browser hangs / `ECONNREFUSED` on `localhost` | Use `http://127.0.0.1:<port>/`. |
| phpunit `ABSPATH constant ... non-existent path` | You ran with `--no-build` on a fresh checkout. Re-run without it. |
| Corrupt-DB error after an interrupted run | Delete `src/wp-content/database/.ht.sqlite` and re-run. |

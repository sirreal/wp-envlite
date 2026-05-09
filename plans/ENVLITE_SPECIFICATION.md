# envlite — wordpress-develop repo setup specification

**Goal:** Take a clean checkout of `WordPress/wordpress-develop` and bring it
to a state where (1) PHP's built-in server can serve a working WordPress
site against a SQLite database, and (2)
`./vendor/bin/phpunit --group html-api` runs green on host PHP — without
starting any global services (no system MySQL, no Docker, no MAMP).

**Non-goals:** worktree creation, background process management, HTTPS,
production-shaped stacks. envlite operates on whatever directory it is
invoked in, and leaves no daemons behind. Multisite support is not
prioritized for the initial version but is not excluded from envlite's
charter.

**Tech stack:**

- host PHP ≥ 7.4 (matching WordPress's own supported floor), with
  `pdo_sqlite`, `sqlite3`, `openssl`, `simplexml`, `zip`, and `hash`
  extensions loaded. On Unix, `pcntl` is also required so
  `envlite serve` / `envlite up` can call `pcntl_exec` into `php -S`.
  Phase 0 verifies the full set; the brief here just names the
  unavoidable ones.
- host `node` ≥ 20.10, `npm` ≥ 10.2.3 (matching `package.json` `engines`).
- host `composer` ≥ 2.
- the SQLite Database Integration plugin from wordpress.org, pinned by
  SHA256: `44be096a14ebcea424b5e4bf764436ec85fb067f74ab47822c4c5346df21591e`.

**No assumed availability** of `python`, `sed`, `awk`, `jq`, `unzip`,
`shasum`, `curl`, or any other host CLI. envlite is implemented in PHP and
performs all file operations, hashing, HTTP fetches, and zip extraction
through PHP's standard library (`file_get_contents` with stream context,
`hash_file`, `ZipArchive`, `preg_replace`, `str_replace`, `proc_open`).
Subprocesses spawned by envlite are limited to `node`/`npm`/`composer`,
plus the host `php` itself in two places: launching the dev server
(`envlite serve` / `envlite up`) and running the Phase 8 site install
(script piped to the subprocess via stdin). On Unix, the dev-server
launch is a `pcntl_exec` (process replacement) rather than a proper
subprocess; on Windows it is a `proc_open` because `pcntl` is
unavailable.

---

## CLI interface

### Invocation

envlite is implemented as a PHP script at
`tools/local-env/envlite.php` in the wordpress-develop checkout, with
a small router asset at `tools/local-env/router.php` that
`envlite serve` loads into PHP's built-in dev server. The canonical
(and only supported) invocation form is:

```
$ php tools/local-env/envlite.php <subcommand> [args...]
```

PATH-based forms (`envlite <subcommand>` via a user-installed symlink
or shebang execution) are out of scope; envlite does not install
itself onto `PATH`, and the spec assumes the explicit `php …` form
above. Throughout the rest of this document, `envlite <subcommand>` is
shorthand for the full command line.

### Subcommands

| Subcommand | Purpose |
|---|---|
| (no args), `help`, `--help`, `-h` | Print usage and exit 0. |
| `init` | Run all setup phases. Leaves the repo ready to `serve` and to run tests. |
| `up` | Run all setup phases, then start the dev server in the foreground. Equivalent to `init` followed by `serve`. |
| `serve` | Exec the dev server on the discovered/cached port. Foreground; respond to Ctrl-C. |
| `clean` | Remove envlite-managed files (manifest entries). Does not touch `node_modules/`, `vendor/`, or build artifacts under `src/`. |

`port` is intentionally not a subcommand; the cached port lives at
`.envlite/port` and is one `cat` away.

### Global flags

- `--force` — disable all interactive y/N prompts (see "Destructive
  operations and prompts" below). Honors the prompt-rule's *yes* answer
  for every prompt envlite would otherwise raise during this invocation.

### Subcommand flags

- `init [--port=N] [--no-build]`
  - `--port=N` skips Phase 1 discovery and uses the given port. Updates
    `.envlite/port` to N.
  - `--no-build` skips Phase 3. Useful when iterating on PHP-only changes.
- `up [--port=N] [--no-build]`
  - Same flag semantics as `init`. After all phases succeed, `up`
    re-probes the resolved port and runs `php -S` in the foreground —
    the same invocation `serve` uses. On Unix, the launch is a
    `pcntl_exec(PHP_BINARY, …)` so the envlite process is replaced in
    place by `php -S`; on Windows, `proc_open` is used because `pcntl`
    is unavailable. See "`envlite serve` runtime" below for details.
- `serve` (no flags; the cached port is the source of truth)
- `clean` (no flags)

### How to confirm setup works

envlite has no `verify` subcommand. `phpunit` is a multi-second
operation users will run anyway during normal development; wrapping it
in envlite would just charge that cost on every invocation without
adding signal. After `init`, run whichever of these you actually
care about:

```sh
./vendor/bin/phpunit --group html-api      # ~5 s, ~1365 tests
envlite serve  &  curl -sI http://127.0.0.1:$(cat .envlite/port)/
```

A green phpunit + a 2xx HTTP status (not a 3xx redirect to
`/wp-admin/install.php`) proves the same thing the old `verify` did,
with less ceremony. Phase 8 has already run `wp_install()`, so the
site responds with the homepage on first hit. Log in at
`/wp-login.php` with `admin` / `password`.

### Exit codes

| Code | Meaning |
|---|---|
| 0 | Success. |
| 1 | A phase failed. The phase number and a one-line cause are written to stderr. |
| 2 | Unknown subcommand or invalid argument. |
| 3 | Preflight (Phase 0) failed — environment does not satisfy envlite's preconditions. |
| 5 | User declined a destructive prompt. envlite aborted cleanly. |

### Diagnostic output

All diagnostic output goes to stderr. Stdout is reserved for content
that is meaningful as data (currently: nothing; envlite has no
data-producing subcommand). Every stderr line uses one of two prefixes:

- `envlite: <message>` — for top-level errors before a subcommand has
  taken control (unknown subcommand, missing CWD checks, preflight
  failures).
- `envlite <subcommand>: <message>` — once a subcommand is running, all
  errors and warnings carry the subcommand name (e.g.
  `envlite init: phase 5: SHA256 mismatch on plugin zip`,
  `envlite serve: failed to bind 127.0.0.1:8421`).

Phase failures inside `init` use `envlite init: phase N: <cause>`.
Prompts (interactive, on stderr) and the non-TTY abort line both follow
the `envlite <subcommand>: ...` form. envlite never writes timestamps,
log levels, or ANSI color codes to stderr — the convention is plain
single-line messages an aggregator can grep.

### `envlite serve` runtime

`serve` reads the port from `.envlite/port` and launches
`php -S 127.0.0.1:<port> -t src tools/local-env/router.php` in the
foreground.

On Unix, the launch uses `pcntl_exec(PHP_BINARY, …)`: the envlite PHP
process is replaced in place by `php -S`, so there is no parent-child
relay, the PID stays the same, and signals (notably SIGINT from
Ctrl-C) reach `php -S` directly. The `envlite up` subcommand uses the
same launch path after its init phases finish.

On Windows, `pcntl` is unavailable. `serve` falls back to `proc_open`
with stdio inherited from envlite's own STDIN/STDOUT/STDERR. Behavior
is functionally equivalent for the user — foreground server, Ctrl-C
shuts it down — but the process tree shows envlite as the parent of
`php -S`.

The router is committed at `tools/local-env/router.php` alongside
`envlite.php`; it is not installed into the repo, the manifest does
not track it, and `clean` does not remove it. It has no inputs (the
port is a `php -S` argument, not baked into the file) and no
user-tunable knobs.

The router resolves the repo's `src/` via
`dirname(__DIR__, 2) . '/src'`, returns `false` for files that exist
on disk so `php -S` serves them directly, and otherwise routes to
`src/index.php`. WordPress's index.php → wp-blog-header.php →
wp-load.php → wp-settings.php chain handles the rest, including
`wp-admin/install.php` on first hit and pretty-permalink fallback
once installed. The port is consumed only when `serve` runs, never
at `init` time.

**Bind failure.** If `php -S` exits because the port is already
bound (another `envlite serve` running, or any other process on
`<port>`), envlite exits 1 with a single stderr line:
`envlite serve: failed to bind 127.0.0.1:<port>`. No manifest
mutation occurs. Note that on Unix the envlite process has already
been replaced by the time `php -S` reports the bind failure, so the
exit code surfaced to the shell is `php -S`'s, not envlite's;
envlite's pre-flight `port_is_free` probe (in both `serve` and `up`)
is the path that emits the named log line above.

---

## Phase 0 — Preflight

> envlite tracks every file it writes in `.envlite/manifest` and never
> overwrites or deletes anything it doesn't demonstrably own without
> prompting first. See the **State and ownership** section below the
> phases for the full contract — it shapes Phases 5–7 and `clean`.

**Purpose:** abort early if the environment cannot satisfy envlite's
assumptions. Cheap to run and informative on failure.

**Inputs:** the current working directory; the `PATH`.

**Checks (all required):**

1. CWD is the root of a wordpress-develop checkout. Detect by the
   simultaneous presence of: `package.json`, `composer.json`,
   `wp-config-sample.php`, `wp-tests-config-sample.php`,
   `src/wp-includes/`, `tests/phpunit/includes/bootstrap.php`. If any are
   missing, abort with exit code 3.
2. `PHP_VERSION` ≥ 7.4. envlite is run by PHP itself, so `PHP_VERSION_ID`
   is the authoritative check.
3. The following PHP extensions are loaded
   (`extension_loaded(...)` returns true for each):
   - `pdo_sqlite`, `sqlite3` — for the SQLite drop-in (Phase 5) and the
     runtime/test database paths.
   - `openssl` — required by PHP's HTTPS stream wrapper (used by
     `file_get_contents` in Phases 5 and 7). Without it the spec's
     network fetches fail with "Unable to find the wrapper 'https'".
   - `simplexml` — required by the PHPStan/PHPCS toolchain that Phase 4
     installs. Phase 4 passes `--ignore-platform-req=ext-simplexml` to
     Composer because Composer's resolver flags this requirement even
     when the extension is loaded; that flag is what makes
     `composer install` succeed. The Phase 0 check exists so the
     `--ignore-platform-req` flag does not also paper over a genuinely
     missing extension — when simplexml is absent, `composer install`
     would still appear to succeed but `vendor/bin/phpstan` and PHPCS
     ruleset loading would fail at runtime.
   - `zip` — required by `ZipArchive` for Phase 5.
   - `pcntl` (Unix only) — required so `envlite serve` and
     `envlite up` can call `pcntl_exec(PHP_BINARY, …)` into the dev
     server, replacing envlite's PHP process in place. The check is
     gated on `PHP_OS_FAMILY !== 'Windows'`; Windows PHP has no
     `pcntl` and uses a `proc_open` fallback.

   `hash` is non-disable-able since PHP 7.4 and is not checked.
4. `node`, `npm`, and `composer` are present and meet minimum versions:
   `node` ≥ 20.10, `npm` ≥ 10.2.3, `composer` ≥ 2. The `npm` floor matches
   `package.json`'s `engines.npm` so preflight catches the same constraint
   `npm ci` would otherwise hit later. Each is verified by a
   single `proc_open` call passing the binary as a command **array**
   with its version flag — `['node', '--version']`, `['npm', '--version']`,
   `['composer', '--version']` — and reading stdout. Passing an array
   (rather than a string) avoids shell invocation entirely; the OS's
   exec semantics handle binary lookup, including `PATHEXT` resolution
   on Windows (`node.exe`, `npm.cmd`, `composer.bat`) and `PATH`
   resolution on Unix. A non-zero exit or a "command not found" failure
   from `proc_open` means the tool is missing — abort with exit 3 and
   name the missing tool. A successful spawn whose parsed version
   string falls below the minimum also aborts with exit 3.

**Outputs:** none. On failure, exit 3 with the failed check identified.

**Why this matters:** the recipe was validated under a specific stack.
Most of the gotchas (the SQLite drop-in's loading mechanism, the
composer simplexml workaround, the `convertDeprecationsToExceptions=true`
caveat) are tied to known versions. Don't silently degrade.

---

## Phase 1 — Port discovery

**Purpose:** select a single TCP port on `127.0.0.1` for the dev server,
deterministically derived from the checkout's filesystem path so that
two unrelated checkouts almost never collide, and stable across
invocations so that bookmarks/links don't rot.

**Constraints on the port:**

- Must be in the IANA user/registered range, away from the OS's
  ephemeral allocation pool. Pool: **8100–8899**.
- Must not be currently bound by another process **at first
  discovery**. Once cached, envlite trusts the cache and does not
  re-probe (the user may have envlite's own server running on it).
- Must be picked deterministically from the absolute checkout path so
  that re-running `envlite init` after `envlite clean` returns the same
  port whenever possible.

**Cache location:** `.envlite/port`. See "envlite state directory"
above for the broader contract.

**Algorithm (pseudocode):**

```
function discover_port(repoRoot):
    cacheFile = repoRoot + "/.envlite/port"
    if file_exists(cacheFile):
        cached = (int) trim(read(cacheFile))
        if 8100 <= cached <= 8899:
            return cached            # trust the cache; do not re-probe
        # else: cache out of range, fall through to re-pick

    POOL_LOW  = 8100
    POOL_SIZE = 800

    # Deterministic seed: stable hash of the absolute, canonical path.
    # Uses hash('crc32b', ...) — returns an 8-char hex string of the
    # unsigned 32-bit CRC. Avoids PHP's signed-int crc32() which can
    # return negatives on 32-bit builds (still common on Windows).
    digest = hash('crc32b', realpath(repoRoot))     # e.g. "1a2b3c4d"
    seed   = hexdec(substr(digest, -7))             # low 28 bits, fits int
    start  = POOL_LOW + (seed mod POOL_SIZE)

    for i in 0 .. POOL_SIZE-1:
        candidate = POOL_LOW + ((start - POOL_LOW + i) mod POOL_SIZE)
        if port_is_free(candidate):
            ensure_dir(repoRoot + "/.envlite")
            write(cacheFile, str(candidate))
            record_in_manifest(".envlite/port")
            return candidate

    error "no free port in 8100-8899"

function port_is_free(port):
    # Try to bind a server socket to 127.0.0.1:<port>. If bind succeeds
    # the port was free; close immediately and return true.
    sock = stream_socket_server("tcp://127.0.0.1:" + port, suppress errors)
    if sock == false: return false
    close(sock)
    return true
```

**Notes:**

- The CRC32 of the canonical path is intentional, not cryptographic. It
  needs to spread checkouts across the 800-port pool roughly uniformly.
  With ~800 candidates the birthday-paradox 50% collision threshold is
  ~33 concurrent checkouts on the same machine, well above realistic
  use. Taking the low 28 bits (rather than the full 32) loses no
  meaningful entropy at this pool size.
- No blacklist. Round-thousand ports are not meaningfully more contended
  than their neighbors, and a blacklist that ages with the dev-tool
  ecosystem is more bug surface than benefit.
- `realpath` on macOS canonicalizes `/var` → `/private/var`,
  `/tmp` → `/private/tmp`. The chosen port is therefore tied to the
  canonical absolute path of the checkout; moving the checkout
  re-derives a new port.
- The probe binds and closes; it does not "reserve" the port. A racy
  external process could grab the port between Phase 1 and the user
  starting `envlite serve`, but on a developer laptop this race is
  negligible. `serve` will surface the bind failure if it happens.
- `init --port=N` bypasses hash-based discovery but **still probes**:
  envlite calls `port_is_free(N)`; if N is currently bound, abort with
  exit 1 and a one-line message naming the port and suggesting
  `lsof -nP -iTCP:N -sTCP:LISTEN` to identify the occupant. Only on a
  successful probe does N get written to the cache. The user is then
  expected to pass a different `--port` if they really want one.
- There is no `serve --port=N`; the cache is the source of truth. To
  pick a different port, either run `init --port=N` or delete
  `.envlite/port` and re-run.

**Outputs:** `.envlite/port` (text file, single integer); manifest entry.

---

## Phase 2 — JavaScript dependencies

**Purpose:** install the build toolchain (grunt, webpack, sass, the
WordPress build scripts).

**Operation:** spawn `npm ci` in the repo root and stream its output to
the user's terminal. Exit non-zero if `npm` exits non-zero.

**Inputs:** `package-lock.json` (committed to wordpress-develop).
**Outputs:** `node_modules/` populated.

**Idempotency:** safe to re-run; `npm ci` itself is idempotent. envlite
does not gate this phase on `node_modules/` existing — let `npm ci`
decide whether work is needed.

**Failure modes:**

| Symptom | Cause | Remediation |
|---|---|---|
| `npm ERR! engines` | node version below 20.10 | upgrade node |
| network errors | offline / proxy | retry |

The verb is `npm ci`, not `npm install`. envlite must respect the
committed lockfile.

---

## Phase 3 — Build artifacts

**Purpose:** populate the generated files under `src/` that the runtime
and the phpunit bootstrap need.

**Operation:** spawn `npm run build:dev`. This invokes the wordpress-
develop Gruntfile's `build:dev` target.

**Inputs:** populated `node_modules/`, the sources under `src/`.
**Outputs (as defined by upstream Gruntfile):** generated
`src/wp-includes/version.php`, compiled CSS under `src/wp-includes/css/`,
compiled blocks under `src/wp-includes/blocks/`, vendored JS, etc. envlite
does not enumerate these; it trusts the upstream target.

**Why this is not optional:** phpunit's bootstrap loads
`src/wp-load.php` → `src/wp-settings.php`, which references generated
files (notably `src/wp-includes/version.php`). Without a build, phpunit
exits with the cryptic message "ABSPATH constant ... non-existent path".

**Idempotency:** `build:dev` is incremental; safe to re-run. The
`init --no-build` flag exists for users who know their changes do not
affect build outputs.

---

## Phase 4 — PHP dependencies

**Purpose:** install `phpunit`, `yoast/phpunit-polyfills`, the WP
coding standards, PHPStan.

**Operation:** spawn `composer install` with these flags:

- `--no-interaction`.
- `--ignore-platform-req=ext-simplexml`.

envlite does not set `COMPOSER_HOME`; Composer uses its default
(`~/.composer` or `~/.config/composer`, per Composer's own resolution).
Composer's cache layout is Composer's concern, not envlite's.

**Inputs:** `composer.json`. wordpress-develop intentionally ships
**without** a `composer.lock` (`config.lock = false`). Each install
resolves fresh.
**Outputs:** `vendor/`, autoload files, `phpcs` `installed_paths`
configured. No lockfile is created.

**Why `--ignore-platform-req=ext-simplexml`:** the PHPStan/PHPCS
toolchain in `composer.json` declares `ext-simplexml` in a way that
Composer's resolver flags even when the extension is loaded. The flag
is load-bearing on every PHP version, not "defensive on older ones".
Phase 0 already verified `simplexml` is present, so the flag here only
silences the resolver — it does not paper over a missing extension. If
someone bypasses Phase 0 on a PHP build genuinely lacking simplexml,
`composer install` succeeds but `vendor/bin/phpstan` (and ruleset
loading in PHPCS) fails at runtime. Fail-fast belongs in Phase 0.

**Idempotency:** safe to re-run.

---

## Phase 5 — SQLite Database Integration drop-in

**Purpose:** make WordPress and phpunit use a file-backed SQLite database
instead of MySQL.

**Operation:**

All file writes in this phase follow the standard prompt rule (see
"Destructive operations and prompts"): an unowned destination prompts
before being overwritten; `--force` answers yes to every such prompt.

1. If `src/wp-content/plugins/sqlite-database-integration/` is recorded
   in the manifest (envlite-owned `dir` entry) **and** its `db.copy` is
   present locally, skip steps 2–4 and proceed to step 5. The pinned
   plugin tree from a prior `init` is reusable as-is; there is no value
   in re-downloading it.

   Otherwise (no manifest entry, or `db.copy` missing) proceed to
   step 2.
2. Download the plugin zip via PHP HTTP (`file_get_contents` with a
   stream context that follows redirects, sets a User-Agent, and
   times out at 30 s) from
   `https://downloads.wordpress.org/plugin/sqlite-database-integration.zip`
   to a temp file under `sys_get_temp_dir()`.
3. Verify the downloaded **zip's** SHA256 with `hash_file('sha256', ...)`
   against the pinned value
   `44be096a14ebcea424b5e4bf764436ec85fb067f74ab47822c4c5346df21591e`.
   Mismatch is fatal; abort with exit 1. Re-pinning to a newer release
   is an explicit envlite revision, not an automatic fall-through.
4. Extract using PHP's `ZipArchive` into `src/wp-content/plugins/`.
   This produces `src/wp-content/plugins/sqlite-database-integration/`.
   Delete the temp zip.

   If the destination directory exists and is **not** in the manifest
   (a user-installed plugin), prompt before overwriting. `--force`
   bypasses the prompt and the extract proceeds, overlaying envlite's
   pinned tree on top of whatever was there. Record the directory in
   the manifest as a `dir` entry once extraction succeeds.
5. Copy `src/wp-content/plugins/sqlite-database-integration/db.copy` to
   `src/wp-content/db.php` (byte-for-byte). This is the activation step —
   `wp-settings.php` autoloads `wp-content/db.php` when present.

   The standard manifest contract applies: if `db.php` exists and is
   not in the manifest (or is in the manifest with a drifted hash),
   prompt before overwriting. `--force` bypasses. Record `db.php` in
   the manifest with the hash of the bytes written.
6. Post-condition tripwire: assert that `db.copy` contains the literal
   string `{SQLITE_IMPLEMENTATION_FOLDER_PATH}`. The plugin's fallback
   `realpath()` (see below) depends on this placeholder being present
   and unsubstituted. If a future plugin pin removes it, envlite's
   "no substitution needed" assumption silently breaks — abort here
   so the implementer is forced to revisit.

**Inputs:** network access on first install only.
**Outputs:**
- `src/wp-content/plugins/sqlite-database-integration/` — recorded as a
  single `dir` manifest entry. Internal files (including `db.copy`) are
  not individually hash-tracked; the contents come from a SHA-pinned zip
  and the step-6 tripwire is a one-shot install-time check, not ongoing
  drift detection.
- `src/wp-content/db.php` — recorded as a file entry with content hash;
  drift-detected on subsequent `init` runs.

Both are removed by `clean`.

**Why this is sufficient:** `tests/phpunit/includes/install.php` does
`require_once ABSPATH . 'wp-settings.php'` *before* issuing any DB
queries. `wp-settings.php` autoloads `wp-content/db.php` if present.
The drop-in is therefore active by the time `wp_install()` runs. The
`SET default_storage_engine = InnoDB` and `SET foreign_key_checks` calls
that follow are translated to no-ops by the drop-in.

**Why the `{SQLITE_IMPLEMENTATION_FOLDER_PATH}` placeholder needs no
substitution:** the plugin's `db.copy` checks `file_exists()` on the
placeholder string and falls back to
`realpath(__DIR__ . '/plugins/sqlite-database-integration')` when the
check fails. The placeholder is a literal that never names a real path,
so the fallback always activates. Substitution would be dead code.

**Idempotency:** anchored on local presence of
`src/wp-content/plugins/sqlite-database-integration/db.copy` (step 1).
A corrupt or partial plugin tree from a prior failed run will fail the
step-6 tripwire on re-install; the user can resolve by deleting the
plugin tree and re-running `init`.

---

## Phase 6 — phpunit configuration

**Purpose:** create `wp-tests-config.php` at the repo root from the
shipped sample. The phpunit bootstrap reads this file to learn `ABSPATH`
and DB constants.

**Operation:** in PHP, read `wp-tests-config-sample.php`, replace the
following three literal substrings (each appears exactly once in the
sample), and write the result to `wp-tests-config.php`:

| Sample placeholder | envlite value |
|---|---|
| `youremptytestdbnamehere` | `wordpress_test` |
| `yourusernamehere` | `wp` |
| `yourpasswordhere` | `wp` |

(Use `str_replace` or `strtr` over the file contents; do not invoke any
external command.) After the write, assert that each of the three
placeholders is no longer present in the output (catches an upstream
sample reshape). DB_HOST is left as the sample's `localhost` — the
SQLite drop-in ignores it, but `wpdb` still requires it to be defined.

**Inputs:** `wp-tests-config-sample.php`.
**Outputs:** `wp-tests-config.php` at the repo root.

**Notes:**

- The DB constants are placeholders from the SQLite drop-in's
  perspective — it ignores them — but `wpdb` requires them to be
  defined as something, so the patched values stay in.
- The sample's salt block ships with accept-anything strings ("put your
  unique phrase here"). Test runs do not need real salts; envlite leaves
  them as-is here. (Real salts are still injected into `src/wp-config.php`
  in Phase 7 because that file *is* used by an HTTP runtime.)
- ABSPATH in the sample resolves to `dirname(__FILE__) . '/src/'`, which
  is correct for envlite's layout.

**Idempotency:** anchored on the manifest.

- Path absent → write, record in manifest.
- Path present, in manifest, hash matches → silent re-stamp (envlite
  owns this file; pick up any upstream sample changes for free).
- Path present, in manifest, hash drifted → user has modified envlite's
  output; prompt before overwriting (`--force` to skip the prompt).
- Path present, **not** in manifest → user authored this; prompt
  before overwriting.

---

## Phase 7 — Runtime configuration (`src/wp-config.php`)

**Purpose:** create the runtime config that the dev server will load.
Distinct from Phase 6: `src/wp-config.php` is loaded by `wp-load.php`;
`wp-tests-config.php` is loaded only by phpunit's bootstrap.

**Operation:** in PHP:

1. Read `wp-config-sample.php` into a string `$cfg`.
2. Replace the three DB-related placeholders (each appears exactly once):

   | Sample placeholder | envlite value |
   |---|---|
   | `database_name_here` | `wordpress` |
   | `username_here` | `wp` |
   | `password_here` | `wp` |

3. Best-effort fetch of fresh salts from
   `https://api.wordpress.org/secret-key/1.1/salt/` via PHP HTTP, with a
   short timeout (≤ 5 s). If the fetch fails, log a warning and skip
   step 4 — the sample's "put your unique phrase here" placeholders
   remain. Acceptable for a dev box; cookies will not survive across
   `envlite init` re-runs.
4. If salts were fetched, locate and replace the eight contiguous
   `define()` lines for `AUTH_KEY` through `NONCE_SALT` with the salts
   payload. Use a multi-line regex anchored on the opening `define( 'AUTH_KEY'`
   line and the closing `define( 'NONCE_SALT'` line; assert exactly one
   match. Abort if zero or multiple.
5. Locate the literal marker
   `/* That's all, stop editing! Happy publishing. */` (appears exactly
   once in the sample) and inject the following two lines immediately
   *before* it, separated by a blank line:

   ```
   define( 'WP_HOME',    'http://127.0.0.1:<PORT>' );
   define( 'WP_SITEURL', 'http://127.0.0.1:<PORT>' );
   ```

   `<PORT>` is the value from Phase 1.

6. Write the result to `src/wp-config.php`.

**Inputs:** `wp-config-sample.php`, the Phase 1 port, optional network.
**Outputs:** `src/wp-config.php`.

**Why `WP_HOME` / `WP_SITEURL` matter:** WordPress generates absolute
URLs in markup (admin links, redirects, REST endpoints). If they don't
match the listening address (`http://127.0.0.1:<port>`), `wp-admin`
redirects loop and asset URLs break. They go in the runtime config; the
phpunit config doesn't care.

**Idempotency:** same manifest-anchored rule as Phase 6.

- Path absent → write, record.
- Path present, in manifest, hash matches → silent re-stamp. Note that
  the re-stamp picks up any change to the Phase 1 port automatically
  (the port is interpolated at write time), so `WP_HOME`/`WP_SITEURL`
  always match the cache.
- Path present, in manifest, hash drifted → prompt before overwriting.
- Path present, not in manifest → prompt before overwriting.

---

## Phase 8 — Site install

**Purpose:** run `wp_install()` so the site is immediately browsable
on first visit. Without this phase WordPress sees no DB tables and
redirects to `wp-admin/install.php`, forcing the user through a
manual install flow that envlite already has all the inputs to
script.

**Operation:** envlite spawns a fresh `php` subprocess
(`proc_open([PHP_BINARY], …)`) and pipes the install script via
stdin — no second committed asset alongside `router.php`, and full
process isolation from `wp-settings.php`'s many side effects
(constants, autoloaders, shutdown handlers, `wp_die`). The script
template is a nowdoc inside `envlite.php`; `$repoRoot` and `$port`
are interpolated via `strtr()` with `var_export()`'d literals so
unusual paths cannot break the script.

The script body:

1. Sets `$_SERVER['HTTP_HOST']` to `127.0.0.1:<port>` (and a few
   companions). Required because `wp_install()` calls
   `wp_guess_url()` which reads `$_SERVER`; without this, WP would
   write a CLI-derived URL into the `siteurl` option. (Functionally
   moot at runtime — `WP_SITEURL` from Phase 7 is a defined constant
   and overrides the option — but belt-and-suspenders.)
2. `define('WP_INSTALLING', true)` before loading WP.
3. `require_once src/wp-load.php` — picks up `src/wp-config.php`
   (and through it the SQLite drop-in via `wp-content/db.php`).
4. `require_once ABSPATH . 'wp-admin/includes/upgrade.php'`.
5. If `is_blog_installed()` is true → `exit(0)` (idempotent re-run).
6. Otherwise call `wp_install('WordPress Develop Envlite', 'admin',
   'admin@example.com', false, '', 'password')` and assert
   `$result['user_id']` is non-empty (writes to STDERR and
   `exit(1)` otherwise).

A non-zero subprocess exit causes the parent (`envlite_phase8_install_site`)
to throw with the first non-empty stderr line as the cause; the
existing `envlite_init_phase_guard()` converts that into
`envlite init: phase 8: install subprocess: <cause>` + exit 1.

**Inputs:** `src/wp-config.php` (Phase 7), `.envlite/port` (Phase 1),
populated `vendor/` (Phase 4), populated build outputs (Phase 3 —
`wp-load.php` requires `src/wp-includes/version.php`).

**Outputs:** DB tables, default options/roles, single admin user
inside `src/wp-content/database/.ht.sqlite`. The DB file itself is
not added to the manifest by this phase; envlite's existing
observation hook records it on the next `init` or `clean`.

**Fixed credentials:** the username, email, password, and site title
above are deliberately not configurable. envlite is a dev-only tool;
configurability would just mean per-checkout drift with no benefit.
Match the test bootstrap conventions
(`tests/phpunit/includes/install.php` uses the same `admin` / `password`).

**Idempotency:** anchored on `is_blog_installed()`.

- DB tables absent → install.
- DB tables present (e.g. user already ran `init` once, or wiped
  `.ht.sqlite` and re-installed manually) → silent no-op.
- envlite **never** drops tables. User-authored posts/pages/uploads
  survive any number of `envlite init` re-runs. The test bootstrap
  pattern of "drop everything and re-install" is appropriate for
  CI's clean-slate semantics but wrong for a dev tool.

**Failure modes:**

| Symptom | Cause | Remediation |
|---|---|---|
| phase 8 fails with "version.php" or "ABSPATH" error | `init --no-build` on a fresh checkout | re-run `init` without `--no-build` |
| phase 8 fails with a DB error | corrupt `.ht.sqlite` from a prior interrupted run | delete `src/wp-content/database/.ht.sqlite`, re-run `init` |
| phase 8 fails with a salt-related notice | rare; salt fetch in Phase 7 left placeholder strings | not a real failure mode; placeholders are accepted |

**`--force` interaction:** none. The phase is non-destructive (it
only writes into an empty DB) and asks no prompts.

---

## State and ownership

These two sections describe envlite's contract with the filesystem.
They are policy for what the phases above do, not phases themselves;
the placement here is so the reader has the concrete file-by-file
picture from Phases 0–7 in mind before evaluating the abstract rules.

### Destructive operations and prompts

envlite must not silently overwrite or delete a file it does not
demonstrably own (see the manifest below for the ownership mechanism).
Any operation that would do so prompts the user interactively before
proceeding.

**Prompt format:** a one-line `[y/N]` prompt naming the operation and the
file(s) involved, with `N` as the default. Reading a non-y/Y response or
EOF counts as `N` and aborts that operation with exit code 5. TTY
detection uses `stream_isatty(STDIN)` (built-in since PHP 7.2; no
extension dependency).

**Drift prompts include a hash preview:** when the manifest records a
hash for a path but the current content hashes differently, the prompt
includes the first 8 hex chars of each side, e.g.
`envlite owns wp-tests-config.php but content has drifted (recorded a3f1c8b2…, current 9e07d44a…). Overwrite? [y/N]`.
Path-only ("not in manifest") prompts skip the hash preview.

**Non-interactive contexts (no TTY) without `--force`:** envlite writes a
single line to stderr —
`envlite: non-interactive context and --force not given; aborting at <operation> on <path>` —
and exits 5. CI runners that omitted `--force` get an immediately
actionable signal, not silent failure.

**Operations that prompt unless `--force` is passed:**

- Overwriting a file that exists on disk and is **not** recorded in the
  manifest as envlite-owned. (Phases 5–7.)
- Overwriting a file that **is** in the manifest but whose current
  content hash has drifted from the recorded hash.
- Deleting any file or directory in `clean`. The default form prompts
  once with the full list; declining aborts the cleanup.

**Operations that never prompt:**

- Re-creating files envlite owns (recorded in the manifest with a
  matching content hash). These are silent overwrites — envlite is
  updating its own output.
- Adding new files that don't exist yet.
- Reading anything.

**`--force` semantics:** answer `y` to every prompt envlite would
otherwise raise during this invocation. Required for non-interactive
use (CI, scripts). It is the user's responsibility to know what they're
forcing.

### envlite state directory (`.envlite/`)

`.envlite/` at the repo root holds envlite's private state. envlite does
**not** modify `.gitignore`, `.git/info/exclude`, or any other git
configuration. Adding `.envlite/` to wordpress-develop's `.gitignore` is
an upstream concern; in the meantime users can git-ignore the directory
locally with `echo '/.envlite/' >> .git/info/exclude` themselves.

Files inside:

| File | Purpose | Schema |
|---|---|---|
| `port` | Cached site port (Phase 1). | A single integer line. |
| `manifest` | Records every file/directory envlite has written, with the content hash at the time of writing. | One entry per line: `<sha256>  <relative path>`. The hash is sha256 of the **bytes envlite is about to write**, computed before the temp file is renamed into place — never re-read from disk afterwards. `dir` in the hash field denotes a directory entry. |

**Path canonicalization.** Paths in the manifest are stored relative to
the repo root with `/` (POSIX-style) separators. On Windows,
`realpath()` returns `\`-separated paths; convert to `/` with
`str_replace` before writing to or comparing against the manifest. PHP
accepts `/` on Windows for all file APIs, so a single in-memory
convention keeps comparisons reliable. envlite does not promise that a
manifest written on one OS is interpretable on another — within-platform
consistency is the only contract. Other canonicalization details
(duplicate handling, which directories get `dir` entries) are
implementation-defined.

**Manifest immutability.** The manifest is envlite-managed. Hand-editing
it (reordering lines, rewriting hashes) produces undefined behavior on
the next `init` or `clean`. Users who need to "forget" an envlite-owned
path should run `envlite clean` and re-`init`. (`clean` doesn't touch
`node_modules/`, `vendor/`, or build artifacts, so the slow-to-rebuild
parts survive a clean+init cycle.)

**Atomic writes.** Every file envlite writes — whether content
(`wp-config.php`, `wp-tests-config.php`, etc.) or the manifest itself — uses the
write-temp + fsync + rename pattern: hash the in-memory bytes
(`hash('sha256', $bytes)`), write them to a sibling `.tmp` path, fsync,
`rename()` over the final path. The manifest entry update uses the
already-computed hash and happens after the content rename, also
atomic-replace. envlite **never** calls `hash_file()` on the renamed
target to populate the manifest — that would race with any subsequent
writer. A SIGINT mid-operation leaves either fully-pre-write or
fully-post-write state on disk; no half-written file claims a hash for
content that wasn't durable.

**File-write conventions.** All envlite-authored text files
(`src/wp-config.php`, `wp-tests-config.php`, `src/wp-content/db.php`,
`.envlite/port`, `.envlite/manifest`) are written as raw bytes with:

- LF (`\n`) line endings only — never CRLF, even on Windows. Hard-code
  `"\n"` in source; never use `PHP_EOL` for envlite-authored content.
- No UTF-8 BOM.
- A single trailing newline.

Use `file_put_contents()` (which writes raw bytes by default) on the
`.tmp` path. Never open a stream in PHP's text mode (`'t'` flag);
binary mode is the default and the only correct mode here. This keeps
content hashes byte-identical across platforms, so a re-run or a
checkout opened on a different OS does not see spurious manifest
drift.

**Ownership decisions** (consulted by Phases 5–7):

- Path in manifest **and** current content hash matches → envlite owns
  it; safe to silently re-stamp.
- Path in manifest **but** current hash has drifted → envlite created
  it, the user (or another tool) has modified it; prompt before
  overwriting (drift prompt includes hash preview).
- Path **not** in manifest → not envlite-owned; prompt before
  overwriting.

`clean` walks the manifest in reverse insertion order and (after
prompting) removes each entry, then removes `.envlite/` itself. Manifest
order is the order envlite wrote things; since users are not supposed
to edit the manifest, that order is well-defined.

---

## Outputs (final repo state)

After a successful `envlite init`, the repo has:

**envlite-managed (in manifest, removed by `clean`):**

```
.envlite/port                                            (Phase 1)
.envlite/manifest                                        (all phases)
src/wp-content/plugins/sqlite-database-integration/      (Phase 5)
src/wp-content/db.php                                    (Phase 5)
wp-tests-config.php                                      (Phase 6)
src/wp-config.php                                        (Phase 7)
src/wp-content/database/.ht.sqlite                       (populated by Phase 8; observation-recorded — see below)
```

**Side effects of `init` (not envlite-managed; remove with your usual tooling):**

```
node_modules/                                            (Phase 2 — `npm ci`)
vendor/                                                  (Phase 4 — `composer install`)
src/wp-includes/version.php and other build outputs      (Phase 3 — `npm run build:dev`)
```

`.ht.sqlite` is created by the SQLite drop-in the first time
WordPress is loaded — Phase 8 is now that first load, so the file
exists by the time `init` returns. The file may hold user-authored
content (posts, settings, uploads).

**Observation point:** at the start of every `init` and every `clean`,
envlite checks whether `src/wp-content/database/.ht.sqlite` exists on
disk and is not yet in the manifest; if so, envlite adds an entry
recording the file's hash at that moment. The `init` recording
persists in the manifest as ongoing ownership. The `clean` recording is
transient — it exists only so the file appears in *this* invocation's
removal prompt; the manifest is wiped at the end of `clean` regardless.
Either way the guarantee is the same: a `clean` invoked after `serve`
(without an intervening `init`) treats the DB as envlite-tracked
content and prompts before removing it, rather than silently leaving an
orphan or silently deleting user data.

**`clean` semantics:** walk the manifest in reverse insertion order,
present the full list of paths to be removed in a single prompt, then
delete each entry on confirmation (skipped with `--force`). After the
batch, remove `.envlite/` itself. Anything **not** in the manifest is
preserved — `clean` never touches `node_modules/`, `vendor/`, build
artifacts under `src/`, a user-authored plugin checkout under
`src/wp-content/plugins/`, a hand-rolled `wp-config.php`, or any other
off-manifest content. To remove the side-effect dependency trees, use
`git clean -fdx` or your usual tooling.

---

## Phase ordering and parallelism

Strict dependency graph:

- Phase 0 → all subsequent phases.
- Phase 1 → Phase 7 (port is consumed by `WP_HOME`, `WP_SITEURL`).
- Phase 2 → Phase 3 (`build:dev` needs `node_modules/`).
- Phase 5 → Phase 6 and Phase 5 → Phase 7. Both config files assume
  the SQLite drop-in is the active DB layer at any moment between
  phases. Violating either edge (running 6 or 7 first) is harmless to
  the final state but breaks the "internally consistent at every
  step" invariant.
- Phase 3 → Phase 8 (Phase 8 loads `wp-load.php` which requires
  `src/wp-includes/version.php`, generated by `build:dev`).
- Phase 4 → Phase 8 (Phase 8 loads `wp-settings.php` which requires
  composer's autoload for some included libs).
- Phase 5 → Phase 8 (Phase 8 issues DB queries; the SQLite drop-in
  must be active).
- Phase 7 → Phase 8 (Phase 8 loads `src/wp-config.php`).

Phases 1, 2, 4, 5, 6 are mutually independent and could be run in
parallel. envlite v1 runs them serially: the wall-time savings (~5 s)
are not worth the output-interleaving and error-handling complexity in
the initial implementation. A future revision may parallelize. Phase
8 must always run last in `init` — it has the most predecessors.

`up` runs the same Phase 0–8 sequence as `init`, then performs the same
bind-probe + foreground `php -S` invocation as `serve`. It introduces no
new phases.

---

## Idempotency rules (summary)

All file-producing phases consult the manifest. The contract is uniform:

- **Path absent** → write, record in manifest with content hash.
- **Path in manifest, content hash matches** → silent re-stamp;
  envlite owns this file and is updating its own output. Picks up any
  upstream sample changes for free.
- **Path in manifest, content hash drifted** → user (or another tool)
  has modified envlite's output; prompt before overwriting.
  `--force` answers yes.
- **Path not in manifest** → user authored this; prompt before
  overwriting. `--force` answers yes.

Phase-specific notes:

| Phase | Re-run behavior |
|---|---|
| 0 (preflight) | Always runs. |
| 1 (port) | Re-uses the cached port if the cache exists and is in `[8100, 8899]`. Otherwise re-discovers. |
| 2 (npm ci) | Always spawns `npm ci`; npm decides whether work is needed. |
| 3 (build:dev) | Always spawns `build:dev` unless `--no-build`. |
| 4 (composer install) | Always spawns `composer install`; the operation is idempotent. |
| 5 (SQLite drop-in) | Skips download if the local plugin's `db.copy` is present; copies `db.copy` → `db.php` either way. |
| 6 (`wp-tests-config.php`) | Manifest contract above. |
| 7 (`src/wp-config.php`) | Manifest contract above. Re-stamp interpolates the current Phase 1 port. |
| 8 (site install) | Always spawns the install subprocess; the subprocess short-circuits via `is_blog_installed()`. envlite never drops tables. |

`envlite init` is safe to re-run on a half-configured repo: paths
envlite owns get refreshed silently, paths it doesn't own require
explicit user assent. Users who want a fully clean slate run
`envlite clean` first.

---

## Non-obvious decisions, recorded once

1. **PHP 7.4 floor.** envlite is run by PHP itself; the floor matches
   WordPress core's own supported floor at the time of writing.
2. **PHP 8.5 + `convertDeprecationsToExceptions=true`.** wordpress-
   develop's `phpunit.xml.dist` opts every deprecation into a thrown
   exception. The `--group html-api` subset still passes clean on PHP
   8.5.5 against the SQLite drop-in. Other groups may surface
   deprecations; that's a per-group fix, not envlite's problem.
3. **No `composer.lock`, by upstream design.** Every Phase 4 run
   resolves fresh from `composer.json`. envlite does not generate or
   check in a lock; doing so would diverge from upstream.
4. **The SQLite plugin path placeholder is dead.** Documented in Phase 5.
5. **Two distinct config files.** `wp-tests-config.php` (Phase 6) and
   `src/wp-config.php` (Phase 7) are loaded by different bootstrap paths
   and serve different purposes. Both are needed; do not consolidate.
6. **Pin the plugin SHA, not the version number.** Plugin version
   numbers can be reused. The SHA is the honest pin. Update intentionally.
7. **Port stability over freshness.** Once cached, the port is reused
   unconditionally. The user may have envlite's own server running on
   it; re-probing would falsely report "in use". `envlite clean`
   forgets the port; `envlite init --port=N` is the in-place
   re-pick.
8. **PHP-only implementation surface.** All file ops, hashing, HTTP,
   and zip extraction go through PHP standard library. Subprocesses
   are limited to `node`/`npm`/`composer`/`php` — tools envlite already
   requires for setup. No `sed`/`awk`/`curl`/`unzip`/`shasum`/`python`
   dependencies, even when those are commonly present. The dev-server
   launch on Unix uses `pcntl_exec` rather than `proc_open` so the
   envlite PHP process is replaced in place by `php -S` (same PID,
   shallower process tree, direct signal delivery); Windows lacks
   `pcntl` and falls back to `proc_open` with inherited stdio.
9. **Manifest, not file presence, is the ownership signal.** Earlier
   drafts gated idempotency on "does the file exist". That conflated
   "envlite created it" with "anyone created it" and made `clean` a
   blast-radius hazard. The manifest cleanly separates the two cases.
10. **Destructive-by-default is forbidden.** envlite never overwrites
    or deletes a file it doesn't demonstrably own without asking.
    `--force` exists for CI; humans get a prompt every time.
11. **Phase 8 pipes its install script via stdin to a fresh `php`.**
    The two natural alternatives both lose: (a) loading WP
    in-process couples envlite's exit semantics to `wp_die` and any
    side effect of `wp-settings.php`; (b) shipping a second committed
    asset alongside `router.php` adds repository surface area for a
    one-off bootstrap. The stdin pipe gets full subprocess
    isolation without an extra file — the install script is a
    nowdoc heredoc inside `envlite.php`, with `$repoRoot` / `$port`
    substituted via `strtr()` of `var_export()`'d literals so the
    template body needs no escaping. `PHP_BINARY` is used so the
    subprocess is the same PHP that's running envlite.
12. **Phase 8 never drops tables.** The test bootstrap drops and
    re-creates on every run because CI wants clean-slate semantics;
    envlite is a dev tool and the same behavior would silently
    delete posts/pages/uploads on every `init`. envlite gates on
    `is_blog_installed()` and skips if true. Users who want a clean
    slate run `envlite clean` (which prompts for `.ht.sqlite`).
13. **`127.0.0.1` everywhere, never `localhost`.** `php -S` binds
    IPv4-only, but `localhost` resolves to `::1` first on modern
    macOS/Linux — a browser hitting `http://localhost:<port>/` can get
    `ECONNREFUSED` before any IPv4 fallback. Pinning the literal IPv4
    in every place a host appears (`php -S` bind, `WP_HOME`,
    `WP_SITEURL`, `$_SERVER['HTTP_HOST']` in Phase 8, Phase 1
    bind-probe) also keeps the cookie origin invariant: WordPress
    bakes `WP_HOME` into redirects and cookie domains, so a mismatch
    between the constant and the address the user typed breaks admin
    login. `localhost` would also depend on `/etc/hosts` and the
    system resolver; `127.0.0.1` is a literal address with no
    surprises.

---

## What envlite explicitly does NOT do

- Allocate ports for *external* tooling (database GUIs, Xdebug, etc.) —
  Phase 1 picks one port for the dev web server only.
- Start or stop the web server in the background. `envlite serve` runs
  in the foreground and respects Ctrl-C.
- Manage the SQLite database file itself. The drop-in creates
  `src/wp-content/database/.ht.sqlite` when WordPress first loads;
  Phase 8 triggers that load by running `wp_install()`, but envlite
  does not own the file's bytes. envlite records the file in the
  manifest the first time it observes the file's existence; `clean`
  then prompts for it explicitly (the file may hold user-authored
  content).
- Install global tools (PHP, node, composer) — Phase 0 just verifies.
- Configure HTTPS or a production-shaped reverse proxy.
- Perform any `composer update` or `npm update`. envlite is reproducible
  from `package-lock.json` and `composer.json`; updates are an explicit
  human action.
- Manage `node_modules/`, `vendor/`, or build artifacts under `src/`.
  envlite invokes `npm ci`, `composer install`, and `npm run build:dev`
  as a convenience during `init`, but treats their outputs as ordinary
  dev-tool artifacts: not tracked in the manifest, not removed by
  `clean`. Use `git clean -fdx` or your usual tooling.
- Override Composer's cache or home directory. envlite does not set
  `COMPOSER_HOME`; Composer's default applies.
- Refresh the pinned SQLite drop-in. There is no `envlite update`
  subcommand. To pick up a newer plugin release, edit the SHA256 pin
  (and any associated logic) in `tools/local-env/envlite.php`, then
  run `envlite clean && envlite init`. The pin is intentional: bumping
  it is a deliberate envlite revision, reviewed and committed
  alongside any code adjustments the new release requires.
- Manage worktrees. envlite operates on whatever directory it is
  invoked in.

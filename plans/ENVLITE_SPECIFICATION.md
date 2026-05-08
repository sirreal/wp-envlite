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
  extensions loaded. Phase 0 verifies the full set; the brief here
  just names the unavoidable ones.
- host `node` ≥ 20.10, `npm` ≥ 10.2.
- host `composer` ≥ 2.
- the SQLite Database Integration plugin from wordpress.org, pinned by
  SHA256: `44be096a14ebcea424b5e4bf764436ec85fb067f74ab47822c4c5346df21591e`.

**No assumed availability** of `python`, `sed`, `awk`, `jq`, `unzip`,
`shasum`, `curl`, or any other host CLI. envlite is implemented in PHP and
performs all file operations, hashing, HTTP fetches, and zip extraction
through PHP's standard library (`file_get_contents` with stream context,
`hash_file`, `ZipArchive`, `preg_replace`, `str_replace`, `proc_open`).
Subprocesses spawned by envlite are limited to `node`/`npm`/`composer`,
plus the host `php` itself when launching the dev server.

---

## CLI interface

### Invocation

envlite is a single PHP file located at
`tools/local-env/envlite.php` in the wordpress-develop checkout.
Both invocations are equivalent and must produce identical results:

```
$ php tools/local-env/envlite.php <subcommand> [args...]
$ envlite <subcommand> [args...]      # when on PATH (e.g. via symlink)
```

When invoked as `envlite` directly, the script's shebang
(`#!/usr/bin/env php`) selects the runtime; when invoked as
`php tools/local-env/envlite.php`, the user's chosen PHP runs the file.
Either path yields the same behavior. The script must not depend on
`__FILE__` resolution that breaks under either form.

### Subcommands

| Subcommand | Purpose |
|---|---|
| (no args), `help`, `--help`, `-h` | Print usage and exit 0. |
| `version`, `--version`, `-V` | Print envlite's version string and exit 0. |
| `init` | Run all setup phases. Leaves the repo ready to `serve` and to run tests. |
| `serve` | Exec the dev server on the discovered/cached port. Foreground; respond to Ctrl-C. |
| `clean` | Remove envlite-generated artifacts. `--keep-deps` preserves `node_modules/`, `vendor/`, and `.composer-home/`. |

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
- `serve` (no flags; the cached port is the source of truth)
- `clean [--keep-deps]`

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

A green phpunit + a 2xx/3xx HTTP status proves the same thing the old
`verify` did, with less ceremony.

### Exit codes

| Code | Meaning |
|---|---|
| 0 | Success. |
| 1 | A phase failed. The phase number and a one-line cause are written to stderr. |
| 2 | Unknown subcommand or invalid argument. |
| 3 | Preflight (Phase 0) failed — environment does not satisfy envlite's preconditions. |
| 5 | User declined a destructive prompt. envlite aborted cleanly. |

---

## Destructive operations and prompts

envlite must not silently overwrite or delete a file it does not
demonstrably own (see "envlite state directory" below for the ownership
mechanism). Any operation that would do so prompts the user
interactively before proceeding.

**Prompt format:** a one-line `[y/N]` prompt naming the operation and the
file(s) involved, with `N` as the default. Reading a non-y/Y response,
EOF, or a non-TTY stdin counts as `N` and aborts that operation with
exit code 5.

**Operations that prompt unless `--force` is passed:**

- Overwriting a file that exists on disk and is **not** recorded in the
  manifest as envlite-owned. (Phases 5–8.)
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

---

## envlite state directory (`.envlite/`)

`.envlite/` at the repo root holds envlite's private state. It is not
tracked by git: on first write, envlite appends `.envlite/` to
`.git/info/exclude` (or no-ops if already present), since wordpress-
develop's `.gitignore` is upstream-controlled.

Files inside:

| File | Purpose | Schema |
|---|---|---|
| `port` | Cached site port (Phase 1). | A single integer line. |
| `manifest` | Records every file/directory envlite has written, with the content hash at the time of writing. | One entry per line: `<sha256>  <relative path>` (sha256 over the file's content at write time, or `dir` for a directory). |

The manifest is the authoritative record of "envlite owns this". When
considering an existing file:

- If the path is in the manifest **and** its current content hash matches
  the manifest's recorded hash → envlite owns it; safe to silently
  re-stamp.
- If the path is in the manifest **but** its current hash has drifted →
  envlite created it but the user (or another tool) has modified it;
  prompt before overwriting.
- If the path is **not** in the manifest → not envlite-owned; prompt
  before overwriting.

After every write or delete, envlite updates the manifest in place.
`clean` walks the manifest in reverse insertion order and (after
prompting) removes each entry, then removes `.envlite/` itself.

---

## Phase 0 — Preflight

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
     installs. This is also the extension whose composer platform
     requirement Phase 4 ignores; verifying it here means the ignore
     flag is decorative, not load-bearing.
   - `zip` — required by `ZipArchive` for Phase 5.
   - `hash` — required by `hash_file` for Phase 5's pinned-zip check.
     (`hash` is bundled and enabled by default since PHP 7.4 but a
     `--disable-hash` build is theoretically possible; check anyway.)
4. `node`, `npm`, `composer` resolve via `PATH` (use `proc_open` with
   `command -v` or PHP's own equivalent — but ultimately use a
   PHP-native `PATH` search to avoid depending on a shell).
5. The reported versions of `node` (≥ 20.10) and `npm` (≥ 10.2). Composer
   ≥ 2.

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
    start = POOL_LOW + (crc32(realpath(repoRoot)) mod POOL_SIZE)

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

- `crc32(realpath(...))` is intentional, not cryptographic. It needs to
  spread checkouts across the 800-port pool roughly uniformly. With ~800
  candidates the birthday-paradox 50% collision threshold is ~33
  concurrent checkouts on the same machine, well above realistic use.
- No blacklist. Round-thousand ports are not meaningfully more contended
  than their neighbors, and a blacklist that ages with the dev-tool
  ecosystem is more bug surface than benefit.
- `realpath` on macOS canonicalizes `/var` → `/private/var`,
  `/tmp` → `/private/tmp`. iCloud-synced directories may produce
  different canonicalizations across systems; the chosen port is not
  guaranteed identical across reboots in such cases.
- The probe binds and closes; it does not "reserve" the port. A racy
  external process could grab the port between Phase 1 and the user
  starting `envlite serve`, but on a developer laptop this race is
  negligible. `serve` will surface the bind failure if it happens.
- `init --port=N` bypasses discovery and writes N to the cache. There
  is no `serve --port=N`; the cache is the source of truth. To pick a
  different port, either run `init --port=N` or delete `.envlite/port`
  and re-run.

**Outputs:** `.envlite/port` (text file, single integer); manifest entry.

---

## Phase 2 — JavaScript dependencies

**Purpose:** install the build toolchain (grunt, webpack, sass, the
WordPress build scripts).

**Operation:** spawn `npm ci` in the repo root and stream its output to
the user's terminal. Exit non-zero if `npm` exits non-zero.

**Inputs:** `package-lock.json` (committed to wordpress-develop).
**Outputs:** `node_modules/` populated.
**Wall time:** ~12 s warm npm cache, up to ~60 s cold.

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

**Wall time:** ~16 s.

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

**Operation:** spawn `composer install` with the following environment
and flags:

- `COMPOSER_HOME` set to `<repoRoot>/.composer-home` (an absolute path).
- `--no-interaction`.
- `--ignore-platform-req=ext-simplexml`.

**Inputs:** `composer.json`. wordpress-develop intentionally ships
**without** a `composer.lock` (`config.lock = false`). Each install
resolves fresh.
**Outputs:** `vendor/`, autoload files, `phpcs` `installed_paths`
configured. No lockfile is created.
**Wall time:** ~7 s warm.

**Why per-checkout `COMPOSER_HOME`:** Composer's *cache* (under
`$COMPOSER_HOME/cache/`) is not safe under concurrent writers. Two
envlite invocations against a shared `~/.composer/cache/` regularly
produce partial-zip extracts that fail randomly. Per-checkout
`COMPOSER_HOME` removes the race at the cost of ~80 MB local cache
duplication. envlite manages no global Composer state.

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

1. If `src/wp-content/plugins/sqlite-database-integration/db.copy`
   already exists locally, skip steps 2–4 and proceed to step 5
   (re-copy the local `db.copy`). The pinned plugin tree from a prior
   `init` is reusable as-is; there is no value in re-downloading it.
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
5. Copy `src/wp-content/plugins/sqlite-database-integration/db.copy` to
   `src/wp-content/db.php` (byte-for-byte). This is the activation step —
   `wp-settings.php` autoloads `wp-content/db.php` when present.
6. Post-condition tripwire: assert that `db.copy` contains the literal
   string `{SQLITE_IMPLEMENTATION_FOLDER_PATH}`. The plugin's fallback
   `realpath()` (see below) depends on this placeholder being present
   and unsubstituted. If a future plugin pin removes it, envlite's
   "no substitution needed" assumption silently breaks — abort here
   so the implementer is forced to revisit.

**Inputs:** network access on first install only.
**Outputs:** `src/wp-content/plugins/sqlite-database-integration/` and
`src/wp-content/db.php`. Both recorded in the manifest.

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
following four literal substrings (each appears exactly once in the
sample), and write the result to `wp-tests-config.php`:

| Sample placeholder | envlite value |
|---|---|
| `youremptytestdbnamehere` | `wordpress_test` |
| `yourusernamehere` | `wp` |
| `yourpasswordhere` | `wp` |

(Use `str_replace` or `strtr` over the file contents; do not invoke any
external command.) After the write, assert that each of the four
placeholders is no longer present in the output (catches an upstream
sample reshape).

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

**Purpose:** create the runtime config that the dev server (Phase 8)
will load. Distinct from Phase 6: `src/wp-config.php` is loaded by
`wp-load.php`; `wp-tests-config.php` is loaded only by phpunit's
bootstrap.

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

## Phase 8 — Web server router

**Purpose:** provide the routing script that `envlite serve` (and `php -S`
generally) needs to make `src/` runnable with WordPress's pretty-permalink
semantics intact.

**Operation:** write `router.php` at the repo root. Content (verbatim):

```php
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . '/src' . $path;
if ($path !== '/' && file_exists($file) && !is_dir($file)) {
    return false;
}
require __DIR__ . '/src/index.php';
```

**Inputs:** none. (The port is not baked into `router.php`; it's a
runtime argument to `php -S`, supplied by `envlite serve`.)
**Outputs:** `router.php`.

**How `envlite serve` uses it:** the subcommand `proc_open`s
`php -S 127.0.0.1:<port> -t src router.php` in the foreground, where
`<port>` is read from `.envlite/port`. The router is a fixed file; the
port lives in the cache.

**How the router works:** `php -S`'s built-in static-file handling
returns `false` from the router for files that exist on disk (letting
the server serve them), and otherwise routes to `src/index.php`.
WordPress's index.php → wp-blog-header.php → wp-load.php →
wp-settings.php chain handles the rest, including
`wp-admin/install.php` redirects on first hit and pretty-permalink
fallback once installed.

**Idempotency:** `router.php` has no user-tunable knob in it; preserving
manual edits would mean a user who tweaked it for an unrelated experiment
never gets envlite's bug fixes.

- Path absent → write, record.
- Path present, in manifest → silent overwrite (always pick up the
  current canonical content).
- Path present, **not** in manifest → prompt before overwriting.

---

## Outputs (final repo state)

After a successful `envlite init`, the repo has:

```
.envlite/port                                            (Phase 1)
.envlite/manifest                                        (all phases)
node_modules/                                            (Phase 2)
vendor/                                                  (Phase 4)
.composer-home/                                          (Phase 4)
src/wp-includes/version.php  (and other build artifacts) (Phase 3)
src/wp-content/plugins/sqlite-database-integration/      (Phase 5)
src/wp-content/db.php                                    (Phase 5)
wp-tests-config.php                                      (Phase 6)
src/wp-config.php                                        (Phase 7)
router.php                                               (Phase 8)
```

…and a SQLite database file under `src/wp-content/database/.ht.sqlite`,
created on demand by the drop-in the first time WordPress is loaded.
This file may hold user-authored content (posts, settings, uploads).

**Observation point:** at the start of every `init` and every `clean`,
envlite checks whether `src/wp-content/database/.ht.sqlite` exists on
disk and is not yet in the manifest; if so, envlite adds an entry
recording the file's hash at that moment. This guarantees that a
`clean` invoked after `serve` (without an intervening `init`) still
treats the DB as envlite-tracked content and prompts before removing
it, rather than silently leaving an orphan or silently deleting user
data.

**`clean` semantics:** walk the manifest in reverse insertion order,
present the full list of paths to be removed in a single prompt, then
delete each entry on confirmation (skipped with `--force`). After the
batch, remove `.envlite/` itself. Anything **not** in the manifest is
preserved — `clean` will never touch a user-authored plugin checkout
under `src/wp-content/plugins/`, a hand-rolled `wp-config.php`, or any
other off-manifest content.

`envlite clean --keep-deps` removes everything in the manifest *except*
`node_modules/`, `vendor/`, and `.composer-home/`.

---

## Phase ordering and parallelism

Strict dependency graph:

- Phase 0 → all subsequent phases.
- Phase 1 → Phase 7 (port is consumed by `WP_HOME`, `WP_SITEURL`).
  Phase 8 does not consume the port; only `envlite serve` does, at
  invocation time.
- Phase 2 → Phase 3 (`build:dev` needs `node_modules/`).
- Phase 5 → Phase 7 (the SQLite drop-in must be in place before
  `src/wp-config.php` is generated, so the assumption that the runtime
  uses SQLite is internally consistent at any moment).

Phases 1, 2, 4, 5, 6 are mutually independent and could be run in
parallel. envlite v1 runs them serially: the wall-time savings (~5 s)
are not worth the output-interleaving and error-handling complexity in
the initial implementation. A future revision may parallelize.

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
| 8 (`router.php`) | Manifest contract above. |

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
   dependencies, even when those are commonly present.
9. **Manifest, not file presence, is the ownership signal.** Earlier
   drafts gated idempotency on "does the file exist". That conflated
   "envlite created it" with "anyone created it" and made `clean` a
   blast-radius hazard. The manifest cleanly separates the two cases.
10. **Destructive-by-default is forbidden.** envlite never overwrites
    or deletes a file it doesn't demonstrably own without asking.
    `--force` exists for CI; humans get a prompt every time.

---

## What envlite explicitly does NOT do

- Allocate ports for *external* tooling (database GUIs, Xdebug, etc.) —
  Phase 1 picks one port for the dev web server only.
- Start or stop the web server in the background. `envlite serve` runs
  in the foreground and respects Ctrl-C.
- Manage the SQLite database file itself. The drop-in creates
  `src/wp-content/database/.ht.sqlite` on demand. envlite records the
  file in the manifest the first time it observes the file's
  existence; `clean` then prompts for it explicitly (the file may hold
  user-authored content).
- Install global tools (PHP, node, composer) — Phase 0 just verifies.
- Configure HTTPS or a production-shaped reverse proxy.
- Perform any `composer update` or `npm update`. envlite is reproducible
  from `package-lock.json` and `composer.json`; updates are an explicit
  human action.
- Manage worktrees. envlite operates on whatever directory it is
  invoked in.

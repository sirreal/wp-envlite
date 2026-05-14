# envlite — wordpress-develop repo setup specification

**Goal:** Take a clean checkout of `WordPress/wordpress-develop` and bring it
to a state where (1) PHP's built-in server can serve a working WordPress
site against a SQLite database, and (2) `./vendor/bin/phpunit` runs against
that SQLite database on host PHP — without starting any global services (no
system MySQL, no Docker, no MAMP).

**Non-goals:** worktree creation, background process management, HTTPS,
production-shaped stacks. envlite operates on whatever directory it is
invoked in, and leaves no daemons behind. Multisite support is not
prioritized for the initial version but is not excluded from envlite's
charter.

**Tech stack:**

- host PHP ≥ 7.4 (matching WordPress's own supported floor), with
  `gd`, `pdo_sqlite`, `sqlite3`, `openssl`, `simplexml`, `zip`, and
  `hash` extensions loaded. On Unix, `pcntl` is also required so
  `envlite up` can call `pcntl_exec` into `php -S`.
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
plus the host `php` itself in two places: launching the dev server at
the end of `envlite up` and running the Phase 8 site install (script
piped to the subprocess via stdin). On Unix, the dev-server launch
uses `pcntl_exec` (process replacement) rather than a proper
subprocess; on Windows it is a `proc_open` because `pcntl` is
unavailable.

---

## CLI interface

### Invocation

envlite is implemented as a PHP script at
`tools/local-env/envlite.php` in the wordpress-develop checkout, with
a small router asset at `tools/local-env/router.php` that `envlite up`
loads into PHP's built-in dev server. The canonical (and only
supported) invocation form is:

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
| `up` | Run setup phases as needed (see "Skip semantics"), then start the dev server in the foreground. The primary command. |
| `clean` | Remove envlite-managed files (manifest entries). Does not touch `node_modules/`, `vendor/`, or build artifacts under `src/`. |

There is no `init`, no `serve`, no `verify`. `up` is what users run.
The cached port lives at `.cache/envlite/port` and is one `cat` away.

### Global flags

- `--force` — disable all interactive y/N prompts (see "Destructive
  operations and prompts" below). Honors the prompt-rule's *yes* answer
  for every prompt envlite would otherwise raise during this invocation.
  Orthogonal to `--rebuild`: `--force` only governs prompts.

### Subcommand flags

- `up [--port=N] [--no-build] [--no-serve] [--rebuild]`
  - `--port=N` skips Phase 1 discovery and uses the given port. Updates
    `.cache/envlite/port` to N.
  - `--no-build` skips Phase 3 (`npm run build:dev`) even when the skip
    rule would otherwise have run it. Useful when iterating on PHP-only
    changes.
  - `--no-serve` runs every setup phase that's needed and then exits 0
    without launching the dev server. The CI / automation form. The
    setup phases are identical to a normal `up` — same skip rules, same
    state writes — only the trailing `php -S` launch is suppressed.
  - `--rebuild` discards the `.cache/envlite/state` file's recorded skip
    state for this invocation only. Every phase runs as if envlite had
    never observed its inputs before. Successful phases re-record state
    normally. Use when state is suspect or when validating a fresh
    install.

  The flags are independent. `--rebuild --no-build` re-runs phases
  2 and 4 from scratch but skips phase 3. `--no-serve --rebuild` is
  the CI release-gate validation form.

  After all setup phases succeed (and `--no-serve` was not passed),
  `up` re-probes the resolved port and runs `php -S` in the
  foreground. On Unix, the launch replaces the envlite process via
  `pcntl_exec(PHP_BINARY, …)`; on Windows, `proc_open` is used
  because `pcntl` is unavailable. See "Dev-server launch" below.
- `clean` (no flags)

### How to confirm setup works

envlite has no `verify` subcommand. `phpunit` is a multi-second
operation users will run anyway during normal development; wrapping it
in envlite would just charge that cost on every invocation without
adding signal. After `up` (or `up --no-serve`), two quick checks
confirm the env is wired up:

```sh
./vendor/bin/phpunit
curl -sI http://127.0.0.1:$(cat .cache/envlite/port)/
```

Phpunit booting against the SQLite drop-in + a 2xx HTTP status (not a
3xx redirect to `/wp-admin/install.php`) proves the env is sound.
Phase 8 has already run `wp_install()`, so the site responds with the
homepage on first hit. Log in at `/wp-login.php` with `admin` / `password`.

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
  `envlite up: phase 5: SHA256 mismatch on plugin zip`,
  `envlite up: failed to bind 127.0.0.1:8421`).

Phase failures inside `up` use `envlite up: phase N: <cause>`.
Prompts (interactive, on stderr) and the non-TTY abort line both follow
the `envlite <subcommand>: ...` form. envlite never writes timestamps,
log levels, or ANSI color codes to stderr — the convention is plain
single-line messages an aggregator can grep.

Subprocess output (npm, composer) is **buffered** during the parallel
install pair: while running, envlite prints a single status line
`envlite up: installing dependencies…`. On success, no further output
from the subprocesses is shown. On failure of either or both, envlite
waits for both to complete, then dumps each captured buffer to stderr
under labeled separators:

```
--- npm ci ---
<captured stdout+stderr>
--- composer install ---
<captured stdout+stderr>
```

followed by `envlite up: phase N: <cause>` and exit 1. Phase 3
(`build:dev`) and any other lone subprocesses stream their output
directly to envlite's stderr — buffering is reserved for the parallel
case where interleaving would be unreadable.

### Dev-server launch

After all setup phases succeed (and `--no-serve` was not passed), `up`
launches `php -S 127.0.0.1:<port> -t src tools/local-env/router.php`
in the foreground using the resolved port.

On Unix, the launch uses `pcntl_exec(PHP_BINARY, …)`: the envlite PHP
process is replaced in place by `php -S`, so there is no parent-child
relay, the PID stays the same, and signals (notably SIGINT from
Ctrl-C) reach `php -S` directly.

On Windows, `pcntl` is unavailable. envlite falls back to `proc_open`
with stdio inherited from envlite's own STDIN/STDOUT/STDERR. Behavior
is functionally equivalent for the user — foreground server, Ctrl-C
shuts it down — but the process tree shows envlite as the parent of
`php -S`.

**Worker pool.** Before the launch (Unix or Windows), envlite calls
`putenv('PHP_CLI_SERVER_WORKERS=3')` so the built-in server forks
three worker processes and one slow request does not block every
other one behind it. `PHP_CLI_SERVER_WORKERS` is the only knob —
PHP exposes no CLI flag — and the variable was introduced in PHP
7.4.0, matching envlite's preflight floor (no version gating
needed). On Windows the variable is documented as unsupported and
silently ignored, so setting it there is harmless. If the user has
already exported `PHP_CLI_SERVER_WORKERS` in their environment,
envlite leaves it alone (`getenv()` check before `putenv()`). The
SQLite drop-in serializes writes through SQLite's file lock, so
concurrent workers cannot corrupt `.ht.sqlite`; the worst case is a
short serialization wait under contention, which is the same
behavior a single worker would have produced sequentially.

**`--no-serve` short-circuit.** When `--no-serve` was passed, `up`
omits the bind probe and the launch entirely; it exits 0 once setup
phases complete. The port is still discovered/cached and
`src/wp-config.php` still encodes that port — the only difference is
that `php -S` is never started.

The router is committed at `tools/local-env/router.php` alongside
`envlite.php`; it is not installed into the repo, the manifest does
not track it, and `clean` does not remove it. Its only request-time
inputs are the request URI and `$_SERVER['DOCUMENT_ROOT']` — the
absolute path PHP's built-in server resolved from its `-t` argument
— so the router file's own filesystem location is deliberately
irrelevant. The port is a `php -S` argument, never baked into the
file, and the router has no user-tunable knobs.

The router uses `$_SERVER['DOCUMENT_ROOT']` to locate both static
files and the front controller: it returns `false` for paths that
exist on disk under the docroot so `php -S` serves them directly,
and otherwise `require`s `<DOCUMENT_ROOT>/index.php`. WordPress's
index.php → wp-blog-header.php → wp-load.php → wp-settings.php
chain handles the rest, including `wp-admin/install.php` on first
hit and pretty-permalink fallback once installed. The port is
consumed only by the dev-server launch at the end of `up`, never
during the setup phases or under `up --no-serve`.

The router applies `rawurldecode()` to the URI path before its
filesystem and `.ht` checks. `php -S` decodes percent-encoding
internally when mapping a URL to a file, so the router must too —
otherwise (a) uploads with encoded characters (e.g. `my%20photo.jpg`
for `my photo.jpg`) fail the `file_exists` check and fall through to
WordPress as 404s, and (b) an encoded `.ht` segment (e.g.
`/%2Eht.sqlite` for the SQLite DB) bypasses the raw-URI `.ht` regex
and reaches `php -S`, which then resolves it to the real file.

**Bind failure.** envlite's pre-flight `port_is_free` probe detects an
already-bound port and exits 1 with a single stderr line:
`envlite up: failed to bind 127.0.0.1:<port>`. No manifest mutation
occurs. If the port becomes bound in the race window between the probe
and the launch, the Unix path's envlite process has already been
replaced by the time `php -S` reports the failure, so the exit code
surfaced to the shell is `php -S`'s, not envlite's.

---

## Phase 0 — Preflight

> envlite tracks every file it writes in `.cache/envlite/manifest` and never
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
   - `gd` — required by the WordPress core test bootstrap. `phpunit.xml.dist`
     sets `WP_RUN_CORE_TESTS=1`, and `tests/phpunit/includes/bootstrap.php`
     aborts before any test group filter applies when `gd` is missing.
     Checking it at preflight stops envlite from claiming success while
     `phpunit --group html-api` would still fail.
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
   - `pcntl` (Unix only) — required so `envlite up` can call
     `pcntl_exec(PHP_BINARY, …)` into the dev server, replacing
     envlite's PHP process in place. The check is gated on
     `PHP_OS_FAMILY !== 'Windows'`; Windows PHP has no `pcntl` and
     uses a `proc_open` fallback.

   `hash` is non-disable-able since PHP 7.4 and is not checked.

   In addition, Phase 0 verifies `allow_url_fopen=1`. Phase 5 fetches
   the SQLite plugin zip and Phase 7 fetches WordPress salts via
   `file_get_contents()` against `https://` URLs; with the directive
   disabled those calls fail much later, after npm/composer/build have
   already run. A preflight check makes the failure mode "fix php.ini
   and re-run" rather than "lose minutes of install work first".

   Phase 0 also verifies `function_exists('proc_open')`. Every
   subprocess envlite spawns (node, npm, composer, php) goes through
   `proc_open`; hardened php.ini configurations sometimes list it in
   `disable_functions`, and hitting that via the version probe below
   would surface a raw PHP error rather than the documented preflight
   exit 3.
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

- Auto-discovered ports come from a fixed pool: **8100–8899**, in the
  IANA user/registered range and away from the OS's ephemeral
  allocation pool. The pool only governs auto-discovery; an explicit
  `--port=N` accepts any 1–65535 (the user owns the choice).
- Must not be currently bound by another process **at first
  discovery**. Once cached, envlite trusts the cache and does not
  re-probe (the user may have envlite's own server running on it).
- Must be picked deterministically from the absolute checkout path so
  that re-running `envlite up` after `envlite clean` returns the same
  port whenever possible.

**Cache location:** `.cache/envlite/port`. See "envlite state directory"
above for the broader contract.

**Algorithm (pseudocode):**

```
function discover_port(repoRoot):
    cacheFile = repoRoot + "/.cache/envlite/port"
    if file_exists(cacheFile):
        cached = (int) trim(read(cacheFile))
        if 1 <= cached <= 65535:
            return cached            # trust the cache; do not re-probe
        # else: cache corrupt / out of any sane range, fall through to re-pick

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
            ensure_dir(repoRoot + "/.cache/envlite")
            write(cacheFile, str(candidate))
            record_in_manifest(".cache/envlite/port")
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
  external process could grab the port between Phase 1 and the
  dev-server launch at the end of `up`, but on a developer laptop
  this race is negligible. The pre-launch bind probe surfaces the
  failure if it happens.
- `up --port=N` bypasses hash-based discovery but **still probes**:
  envlite calls `port_is_free(N)`; if N is currently bound, abort with
  exit 1 and a one-line message naming the port and suggesting
  `lsof -nP -iTCP:N -sTCP:LISTEN` to identify the occupant. Only on a
  successful probe does N get written to the cache. N may be any
  1–65535 — the auto-discovery pool is not enforced on explicit ports,
  so familiar choices like `8080` or `3000` are honored.
- To pick a different port without specifying one, delete
  `.cache/envlite/port` and re-run `up`.

**Outputs:** `.cache/envlite/port` (text file, single integer); manifest entry.

---

## Phase 2 — JavaScript dependencies

**Purpose:** install the build toolchain (grunt, webpack, sass, the
WordPress build scripts).

**Operation:** spawn `npm ci` in the repo root. Output is buffered (see
"Phase ordering and parallelism" — phase 2 runs in parallel with
phase 4 and the bundled status line replaces direct streaming). Exit
non-zero if `npm` exits non-zero.

**Inputs:** `package-lock.json` (committed to wordpress-develop).
**Outputs:** `node_modules/` populated.

**Skip rule:** envlite skips Phase 2 if **all three** are true:

1. `node_modules/` exists on disk.
2. `.cache/envlite/state` records a `phase2.input_hash` whose value equals
   `hash_file('sha256', 'package-lock.json')`.
3. `--rebuild` was not passed.

After a successful `npm ci`, envlite records the current hash of
`package-lock.json` to `phase2.input_hash`. Recording happens **only
on subprocess exit 0**, and any pre-existing `phase2.input_hash` is
**dropped before** envlite spawns the install — otherwise a mid-run
failure could leave `node_modules/` (re-created by `npm ci` early)
paired with the still-matching previous hash, and the next `up` would
skip a broken install. The invalidate-before-run + record-on-success
sequence guarantees: an interrupted (Ctrl-C'd) `npm ci` leaves the
directory partially populated but no recorded hash, so the next `up`
re-runs the install. Worst case is one redundant `npm ci`; never a
false-positive skip.

The skip is deliberately blind to the *contents* of `node_modules/`
once the directory exists. Hashing that tree on every `up` would cost
multiple seconds and defeat the purpose. A user who has manually
mutated files inside `node_modules/` is out of supported territory
regardless of envlite. The `rm -rf node_modules/` escape hatch always
forces a re-install on the next `up`.

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
develop Gruntfile's `build:dev` target. Output streams directly to
envlite's stderr (Phase 3 runs serially after the parallel
composer/npm pair, so interleaving is not a concern).

**Inputs:** populated `node_modules/`, populated `vendor/`, the sources
under `src/`. The dependency on `vendor/` is non-obvious: some
build-time certificate files used by `build:dev` come out of the
composer install. Phase 3 must therefore wait for **both** Phase 2 and
Phase 4 before running.

**Outputs (as defined by upstream Gruntfile):** compiled CSS under
`src/wp-includes/css/`, compiled blocks under `src/wp-includes/blocks/`,
vendored JS under `src/wp-includes/js/dist/`, etc. envlite does not
enumerate these; it trusts the upstream target. All of these paths are
gitignored — a clean checkout has none of them.

**Why this is not optional:** phpunit's bootstrap loads
`src/wp-load.php` → `src/wp-settings.php`, which references generated
assets (compiled scripts, styles, and blocks). Without a build, phpunit
fails to bootstrap WordPress.

**Skip rule:** envlite skips Phase 3 if **all four** are true:

1. Phase 2 was skipped this run (i.e. `node_modules/` is current).
2. Phase 4 was skipped this run (i.e. `vendor/` is current).
3. `src/wp-includes/js/dist/` exists on disk (sentinel for "build
   has succeeded at least once"). This directory is gitignored and is
   created by `build:dev`, so its presence is a reliable proxy for
   build outputs being on disk.
4. `.cache/envlite/state` records `phase3.recorded_npm_hash` matching the
   current `package-lock.json` hash, AND `phase3.recorded_composer_hash`
   matching the current `composer.json` hash.

The verbose recorded hashes (rather than a single concat hash) are
deliberately readable: `cat .cache/envlite/state` shows an implementer
exactly which dependency state was current the last time `build:dev`
succeeded.

After a successful `npm run build:dev`, envlite records both hashes.
As with Phases 2 and 4, any pre-existing `phase3.recorded_*` entries
are dropped before the build runs — `build:dev` writes incrementally
into `src/wp-includes/{js,css,blocks}`, so a partial run can leave
the sentinel directory in place, and the next `up` would otherwise
skip Phase 3 against stale recorded hashes. Recording happens only
on subprocess exit 0.

`--no-build` forces the skip even when the rule would otherwise have
run the phase. Useful when iterating on PHP-only changes after a
dependency bump that hasn't actually invalidated build outputs.
`--rebuild` overrides the skip in the other direction: the recorded
hashes are ignored, and `build:dev` runs unconditionally.

---

## Phase 4 — PHP dependencies

**Purpose:** install `phpunit`, `yoast/phpunit-polyfills`, the WP
coding standards, PHPStan.

**Operation:** spawn `composer install` with these flags:

- `--no-interaction`.
- `--ignore-platform-req=ext-simplexml`.

Output is buffered (Phase 4 runs in parallel with Phase 2; see "Phase
ordering and parallelism").

envlite does not set `COMPOSER_HOME`; Composer uses its default
(`~/.composer` or `~/.config/composer`, per Composer's own resolution).
Composer's cache layout is Composer's concern, not envlite's.

**Inputs:** `composer.json`. wordpress-develop intentionally ships
**without** a `composer.lock` (`config.lock = false`). Each install
resolves fresh.
**Outputs:** `vendor/`, autoload files, `phpcs` `installed_paths`
configured. No lockfile is created.

**Skip rule:** mirrors Phase 2, but keyed on `composer.json`:

1. `vendor/` exists on disk.
2. `.cache/envlite/state` records `phase4.input_hash` matching
   `hash_file('sha256', 'composer.json')`.
3. `--rebuild` was not passed.

After a successful `composer install`, envlite records the current
hash of `composer.json` to `phase4.input_hash`. As with Phase 2, any
pre-existing `phase4.input_hash` is dropped before the install runs
so a mid-run failure cannot leave a populated `vendor/` paired with
a still-matching hash. Recording happens only on exit 0.

The hash is **not** a pure `hash_file('sha256', composer.json)`: the
running `PHP_VERSION` is prepended so the hash changes whenever the
PHP binary changes. wordpress-develop intentionally ships no
`composer.lock`, so Composer resolves dependencies fresh on every
install and can pick a different set when the platform changes
(packages with PHP-version constraints). Without the version mix-in
the next `up` after switching PHP would skip Phase 4 against a
`vendor/` resolved for the previous PHP — an incompatibility that
surfaces as runtime errors in Phase 8 / phpunit.

The skip is blind to the contents of `vendor/` once the directory
exists, on the same reasoning as Phase 2. Note the absence of a
lockfile: envlite is not detecting whether the *resolved* set of
packages would change (composer might pick a newer compatible release
on a fresh install) — only whether the user has changed `composer.json`
itself. If a user wants to force composer to re-resolve against
upstream Packagist, `--rebuild` is the lever, or `rm -rf vendor/`.

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
   present locally **and** `.cache/envlite/state` records a
   `phase5.recorded_pin_sha` matching the literal
   `ENVLITE_SQLITE_PLUGIN_SHA256` in `envlite.php`, skip steps 2–4 and
   proceed to step 5. The pinned plugin tree from a prior `up` is
   reusable as-is; there is no value in re-downloading it.

   Otherwise (no manifest entry, `db.copy` missing, or recorded pin
   SHA differs from the current code literal) proceed to step 2.
   `--rebuild` also forces re-entry into steps 2–4 unconditionally.
2. Download the plugin zip via PHP HTTP (`file_get_contents` with a
   stream context that follows redirects, sets a User-Agent, and
   times out at 30 s) from a versioned wordpress.org URL of the form
   `https://downloads.wordpress.org/plugin/sqlite-database-integration.<version>.zip`
   to a temp file under `sys_get_temp_dir()`. The version segment is
   required: the unsuffixed `.zip` URL is a moving "latest" pointer,
   so pairing it with a fixed SHA256 pin would break fresh installs
   on every upstream release.
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
   the manifest as a `dir` entry once extraction succeeds. Record
   the current pin literal to `phase5.recorded_pin_sha` in
   `.cache/envlite/state` once extraction succeeds — subsequent `up` runs
   compare against this to detect a code-level pin bump.
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
  drift-detected on subsequent `up` runs.

Both are removed by `clean`. The `phase5.recorded_pin_sha` entry in
`.cache/envlite/state` is also removed by `clean` (whole state file is wiped).

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

**Idempotency:** anchored on the combination of (a) manifest entry for
the plugin directory, (b) local presence of `db.copy`, and (c)
recorded pin SHA matching the code literal. A corrupt or partial
plugin tree from a prior failed run will fail the step-6 tripwire on
re-install; the user can resolve by deleting the plugin tree and
re-running `up`. A code-level pin bump (someone edits
`ENVLITE_SQLITE_PLUGIN_SHA256` in `envlite.php`) re-triggers the
download/extract automatically on the next `up`.

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
sample reshape).

Replace the sample's `define( 'WP_PHP_BINARY', 'php' );` line with
`define( 'WP_PHP_BINARY', <PHP_BINARY> );`, where `<PHP_BINARY>` is the
PHP that is running envlite (the `PHP_BINARY` constant) passed through
`escapeshellarg()` and then var_export()'d as a PHP literal. PHPUnit's
bootstrap shells out as `system( WP_PHP_BINARY . ' ' . escapeshellarg(...) )`
— it escapes the args but not the binary path itself, so a raw value
breaks when `PHP_BINARY` contains spaces or shell metacharacters
(Windows `C:\Program Files\PHP\php.exe`). Pre-escaping makes the
constant a shell-safe single token. Leaving the sample's bare `'php'`
in place would also resolve through `PATH` and could pick up a
different build than the one envlite preflight-checked. The
substitution is anchored on the exact sample literal — a mismatch
aborts with
`envlite up: phase 6: WP_PHP_BINARY sample literal not found exactly once; envlite assumption broken`.

Then assert that the substituted bytes do not already contain a
`DB_FILE` define (regex: `define\s*\(\s*['"]DB_FILE['"]`); a
match means upstream's `wp-tests-config-sample.php` has grown its own
`DB_FILE` and envlite's append assumption no longer holds — abort with
`envlite up: phase 6: DB_FILE already defined in wp-tests-config-sample.php; envlite assumption broken`.
Finally, ensure the bytes end in `\n` (append one if not) and append the
literal line `define( 'DB_FILE', '.ht.test.sqlite' );\n`. Write the
result to `wp-tests-config.php`. DB_HOST is left as the sample's
`localhost` — the SQLite drop-in ignores it, but `wpdb` still requires
it to be defined.

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
- The appended `DB_FILE` define isolates the phpunit test DB at
  `src/wp-content/database/.ht.test.sqlite` from the live runtime
  DB at `src/wp-content/database/.ht.sqlite`. The phpunit
  bootstrap's `tests/phpunit/includes/install.php` drops every WP
  table on every run; sharing the drop-in's default `FQDB` between
  the two configs would silently wipe the dev site Phase 8
  installs, contradicting Phase 8's "envlite never drops tables"
  invariant via phpunit's bootstrap. `src/wp-config.php` (Phase 7)
  remains free of any `DB_FILE` define so the live runtime keeps
  the drop-in's default `FQDB`.

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
   `envlite up` re-runs.
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
existing `envlite_phase_guard()` converts that into
`envlite up: phase 8: install subprocess: <cause>` + exit 1.

**Inputs:** `src/wp-config.php` (Phase 7), `.cache/envlite/port` (Phase 1),
populated `vendor/` (Phase 4), populated build outputs (Phase 3 — the
WordPress bootstrap loads generated assets under `src/wp-includes/`).

**Outputs:** DB tables, default options/roles, single admin user
inside `src/wp-content/database/.ht.sqlite`. The DB file itself is
not added to the manifest by this phase; envlite's existing
observation hook records it on the next `up` or `clean`.

**Fixed credentials:** the username, email, password, and site title
above are deliberately not configurable. envlite is a dev-only tool;
configurability would just mean per-checkout drift with no benefit.
Match the test bootstrap conventions
(`tests/phpunit/includes/install.php` uses the same `admin` / `password`).

**Idempotency:** anchored on `is_blog_installed()`.

- DB tables absent → install.
- DB tables present (e.g. user already ran `up` once, or wiped
  `.ht.sqlite` and re-installed manually) → silent no-op.
- envlite **never** drops tables. User-authored posts/pages/uploads
  survive any number of `envlite up` re-runs. The test bootstrap
  pattern of "drop everything and re-install" is appropriate for
  CI's clean-slate semantics but wrong for a dev tool.

**Failure modes:**

| Symptom | Cause | Remediation |
|---|---|---|
| phase 8 fails with "version.php" or "ABSPATH" error | `up --no-build` on a fresh checkout where build outputs were missing and the skip rule fired | re-run `up` without `--no-build`, or `up --rebuild` |
| phase 8 fails with a DB error | corrupt `.ht.sqlite` from a prior interrupted run | delete `src/wp-content/database/.ht.sqlite`, re-run `up` |
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

### envlite state directory (`.cache/envlite/`)

`.cache/envlite/` at the repo root holds envlite's private state.
wordpress-develop's `.gitignore` already lists `.cache/*`, so envlite's
state files are ignored out of the box without a dedicated entry.
envlite itself does **not** modify `.gitignore`, `.git/info/exclude`,
or any other git configuration at runtime — the ignore rule is
committed to the repo, not written by the tool. envlite owns
`.cache/envlite/` only; the `.cache/` parent is a shared scratch dir
(used by phpcs and other tools), so `clean` removes `.cache/envlite/`
and leaves `.cache/` itself in place.

Files inside:

| File | Purpose | Schema |
|---|---|---|
| `port` | Cached site port (Phase 1). | A single integer line. |
| `manifest` | Records every file/directory envlite has written, with the content hash at the time of writing. | One entry per line: `<sha256>  <relative path>`. The hash is sha256 of the **bytes envlite is about to write**, computed before the temp file is renamed into place — never re-read from disk afterwards. `dir` in the hash field denotes a directory entry. |
| `state` | Per-phase skip metadata (input hashes, pin SHAs). Read by `up` to decide which phases can be skipped. | One entry per line: `<key>\t<value>\n`. Keys are bare ASCII (`phase2.input_hash`, `phase4.input_hash`, `phase3.recorded_npm_hash`, `phase3.recorded_composer_hash`, `phase5.recorded_pin_sha`). Values are 64-char lowercase hex. Unknown keys are ignored on read; missing expected keys are treated as "phase has never succeeded" → run the phase. |

**State file vs. manifest.** The two files have different write
triggers and different contracts:

- The manifest records **outputs envlite owns** with their content
  hashes — drift-detected on every re-run, walked by `clean`.
- The state file records **inputs envlite observed** when each
  skip-able phase last succeeded — used solely to decide whether the
  next `up` can skip work. Not consulted by `clean` (the file is wiped
  with the rest of `.cache/envlite/`).

State entries are written **after** their phase's subprocess exits 0,
never before. An interrupted phase leaves the previous state value in
place (or no entry, on first run); the next `up` therefore re-runs
that phase. False-positive re-runs are acceptable; false-positive
skips are not.

The `--rebuild` flag causes `up` to read `.cache/envlite/state` as if it
were empty for that invocation only. Successful phases re-record state
normally during the same run.

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
the next `up` or `clean`. The same applies to `.cache/envlite/state`: do
not hand-edit it; use `--rebuild` to ignore its contents for one
invocation, or `clean` to wipe it. Users who need to "forget" an
envlite-owned path should run `envlite clean` and re-run `up`.
(`clean` doesn't touch `node_modules/`, `vendor/`, or build artifacts,
so the slow-to-rebuild parts survive a clean+`up` cycle.)

**Atomic writes.** Every file envlite writes — whether content
(`wp-config.php`, `wp-tests-config.php`, etc.) or the manifest itself — uses the
write-temp + fsync + rename pattern: hash the in-memory bytes
(`hash('sha256', $bytes)`), write them to a sibling `.tmp` path in
binary mode (`'wb'` or `file_put_contents()`; never PHP's text mode
`'t'`, which translates `\n` to `\r\n` on Windows and would make the
on-disk bytes diverge from the hash), fsync, `rename()` over the final
path. The manifest entry update uses the already-computed hash and
happens after the content rename, also atomic-replace. envlite
**never** calls `hash_file()` on the renamed target to populate the
manifest — that would race with any subsequent writer. A SIGINT
mid-operation leaves either fully-pre-write or fully-post-write state
on disk; no half-written file claims a hash for content that wasn't
durable.

**Ownership decisions** (consulted by Phases 5–7):

- Path in manifest **and** current content hash matches → envlite owns
  it; safe to silently re-stamp.
- Path in manifest **but** current hash has drifted → envlite created
  it, the user (or another tool) has modified it; prompt before
  overwriting (drift prompt includes hash preview).
- Path **not** in manifest → not envlite-owned; prompt before
  overwriting.

`clean` walks the manifest in reverse insertion order and (after
prompting) removes each entry, then removes `.cache/envlite/` itself. Manifest
order is the order envlite wrote things; since users are not supposed
to edit the manifest, that order is well-defined.

The final `.cache/envlite/` removal is **recursive** (rrmdir, not a
single `rmdir`). Atomic writes can leave `.tmp` siblings of
`manifest`/`state`/`port` behind on an interrupted run, and an
unconditional rmdir would silently fail against a non-empty directory.
envlite owns the whole `.cache/envlite/` subtree per the contract above,
so recursive removal is safe. If the recursive removal still leaves the
directory in place (permission denied, an external process holding a
file open on Windows), `clean` reports `could not remove
.cache/envlite/` and exits 1 so the user does not see a false success.

---

## Outputs (final repo state)

After a successful `envlite up`, the repo has:

**envlite-managed (in manifest, removed by `clean`):**

```
.cache/envlite/port                                            (Phase 1)
.cache/envlite/manifest                                        (all phases)
.cache/envlite/state                                           (Phases 2/3/4/5 — skip metadata)
src/wp-content/plugins/sqlite-database-integration/      (Phase 5)
src/wp-content/db.php                                    (Phase 5)
wp-tests-config.php                                      (Phase 6)
src/wp-config.php                                        (Phase 7)
src/wp-content/database/.ht.sqlite                       (populated by Phase 8; observation-recorded — see below)
```

`.cache/envlite/state` is removed by `clean` along with the rest of
`.cache/envlite/`, but is **not** tracked by the manifest itself — it is
operational metadata, not envlite-owned output (see "envlite state
directory" above).

**Side effects of `up` (not envlite-managed; remove with your usual tooling):**

```
node_modules/                                            (Phase 2 — `npm ci`)
vendor/                                                  (Phase 4 — `composer install`)
src/wp-includes/js/, css/, blocks/ build outputs         (Phase 3 — `npm run build:dev`)
src/wp-content/database/.ht.test.sqlite                  (created on first phpunit run; not envlite-managed)
```

`.ht.sqlite` is created by the SQLite drop-in the first time
WordPress is loaded — Phase 8 is now that first load, so the file
exists by the time `up` returns (or, with `--no-serve`, by the time
the setup phases complete). The file may hold user-authored content
(posts, settings, uploads).

**Observation point:** at the start of every `up` and every `clean`,
envlite checks whether `src/wp-content/database/.ht.sqlite` exists on
disk and is not yet in the manifest; if so, envlite adds an entry
recording the file's hash at that moment. The `up` recording persists
in the manifest as ongoing ownership. The `clean` recording is
transient — it exists only so the file appears in *this* invocation's
removal prompt; the manifest is wiped at the end of `clean` regardless.
Either way the guarantee is the same: a `clean` invoked after a prior
`up` treats the DB as envlite-tracked content and prompts before
removing it, rather than silently leaving an orphan or silently
deleting user data.

**`clean` semantics:** walk the manifest in reverse insertion order,
present the full list of paths to be removed in a single prompt, then
delete each entry on confirmation (skipped with `--force`). After the
batch, remove `.cache/envlite/` itself. Anything **not** in the manifest is
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
- Phase 4 → Phase 3 (`build:dev` consumes certificate files installed
  by `composer install`; not obvious from the Gruntfile, but observed
  empirically — running `build:dev` before `composer install` fails on
  a fresh checkout).
- Phase 5 → Phase 6 and Phase 5 → Phase 7. Both config files assume
  the SQLite drop-in is the active DB layer at any moment between
  phases. Violating either edge (running 6 or 7 first) is harmless to
  the final state but breaks the "internally consistent at every
  step" invariant.
- Phase 3 → Phase 8 (Phase 8 loads `wp-load.php` → `wp-settings.php`,
  which references generated assets under `src/wp-includes/`).
- Phase 4 → Phase 8 (Phase 8 loads `wp-settings.php` which requires
  composer's autoload for some included libs).
- Phase 5 → Phase 8 (Phase 8 issues DB queries; the SQLite drop-in
  must be active).
- Phase 7 → Phase 8 (Phase 8 loads `src/wp-config.php`).

**Concrete schedule:** envlite runs Phases 2 and 4 **in parallel**
(they are mutually independent), waits for both, then runs Phase 3
serially. The shape is `composer install & npm ci & wait; npm run
build:dev`. Phases 5, 6, 7 run serially after that (they're cheap and
their config-file dependencies are easier to reason about in
sequence). Phase 8 is always last.

The parallel phase 2/4 launch is what motivates the buffered output
contract: with two long-running subprocesses streaming to the same
terminal, raw stdio interleaving would be unreadable. envlite captures
each subprocess's combined stdout+stderr to a per-process buffer,
prints a single status line `envlite up: installing dependencies…`
while they run, and on success discards the buffers without printing
them. On failure of either or both, envlite waits for both to
complete (no kill-the-partner machinery), then dumps both buffers to
stderr under labeled separators (`--- npm ci ---`, `--- composer
install ---`) before reporting the phase failure and exiting 1. Phase
3 streams its output directly to envlite's stderr in the normal
serial fashion.

The wall-time savings of parallel 2/4 vs. serial run are
substantial on a fresh checkout (~10–30 s); on a re-run where both
phases skip, the parallel launch costs nothing.

---

## Idempotency rules (summary)

Two parallel mechanisms govern re-run behavior:

1. **The manifest** governs file-producing phases (output ownership):
   - **Path absent** → write, record in manifest with content hash.
   - **Path in manifest, content hash matches** → silent re-stamp;
     envlite owns this file and is updating its own output. Picks up
     any upstream sample changes for free.
   - **Path in manifest, content hash drifted** → user (or another
     tool) has modified envlite's output; prompt before overwriting.
     `--force` answers yes.
   - **Path not in manifest** → user authored this; prompt before
     overwriting. `--force` answers yes.

2. **The `.cache/envlite/state` file** governs subprocess-running phases
   (skip eligibility):
   - Recorded input hash matches current input AND output sentinel
     present → skip the phase.
   - Recorded hash differs, sentinel missing, or no recorded value →
     run the phase. On exit 0, record the new hash.
   - `--rebuild` ignores recorded values for one invocation.

Phase-specific notes:

| Phase | Re-run behavior |
|---|---|
| 0 (preflight) | Always runs. |
| 1 (port) | Re-uses the cached port if the cache exists and is in `[1, 65535]`. Otherwise re-discovers from the 8100–8899 pool. |
| 2 (npm ci) | Skips if `node_modules/` exists AND `.cache/envlite/state` records `phase2.input_hash` matching `package-lock.json`. Otherwise spawns `npm ci`; on success, records the current hash. |
| 3 (build:dev) | Skips if Phases 2 and 4 both skipped this run AND `src/wp-includes/js/dist/` exists AND recorded `phase3.recorded_npm_hash` / `phase3.recorded_composer_hash` match current. `--no-build` forces skip; `--rebuild` forces run. |
| 4 (composer install) | Skips if `vendor/` exists AND `.cache/envlite/state` records `phase4.input_hash` matching `composer.json`. Otherwise spawns `composer install`; on success, records the current hash. |
| 5 (SQLite drop-in) | Skips download/extract if the plugin dir is in the manifest, `db.copy` is present, AND `phase5.recorded_pin_sha` matches the current code literal. Always copies `db.copy` → `db.php` (manifest contract governs the write). |
| 6 (`wp-tests-config.php`) | Manifest contract above. |
| 7 (`src/wp-config.php`) | Manifest contract above. Re-stamp interpolates the current Phase 1 port. |
| 8 (site install) | Always spawns the install subprocess; the subprocess short-circuits via `is_blog_installed()`. envlite never drops tables. |

`envlite up` is safe to re-run on a half-configured repo: paths
envlite owns get refreshed silently, paths it doesn't own require
explicit user assent, subprocess-running phases skip themselves when
their inputs are unchanged. Users who want a fully clean slate run
`envlite clean` first; users who want to redo the work without
deleting outputs pass `--rebuild`.

---

## Non-obvious decisions, recorded once

1. **PHP 7.4 floor.** envlite is run by PHP itself; the floor matches
   WordPress core's own supported floor at the time of writing.
2. **PHP 8.5 + `convertDeprecationsToExceptions=true`.** wordpress-
   develop's `phpunit.xml.dist` opts every deprecation into a thrown
   exception. On newer PHP some test groups will fail purely on
   surfaced deprecations from core code; that's a per-group fix, not
   envlite's problem.
3. **No `composer.lock`, by upstream design.** Every Phase 4 run
   resolves fresh from `composer.json`. envlite does not generate or
   check in a lock; doing so would diverge from upstream. For the same
   reason Phase 4 does **not** pass `--platform-php` and does not set
   `config.platform.php`: the resolver evaluates against runtime PHP,
   not the 7.4 floor. Pinning to the floor would be a half-measure
   without a lockfile (Composer still picks "latest compatible" each
   run) and would penalize devs on newer PHP for no benefit — phpunit
   runs against host PHP, which is exactly what runtime-resolved deps
   target. WP CI also resolves against its matrix PHP, so envlite
   mirrors CI rather than masking it.
4. **The SQLite plugin path placeholder is dead.** Documented in Phase 5.
5. **Two distinct config files.** `wp-tests-config.php` (Phase 6) and
   `src/wp-config.php` (Phase 7) are loaded by different bootstrap paths
   and serve different purposes. Both are needed; do not consolidate.
6. **Pin the plugin SHA, not the version number.** Plugin version
   numbers can be reused. The SHA is the honest pin. Update intentionally.
7. **Port stability over freshness.** Once cached, the port is reused
   unconditionally. The user may have envlite's own server running on
   it; re-probing would falsely report "in use". `envlite clean`
   forgets the port; `envlite up --port=N` is the in-place re-pick.
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
    delete posts/pages/uploads on every `up`. envlite gates on
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
14. **`PHP_CLI_SERVER_WORKERS=3` on `php -S` launch.** PHP's built-in
    server is single-threaded by default — one slow request (a WP
    admin page, a long REST call) blocks everything behind it,
    including the parallel admin-ajax calls (heartbeat, autosave) a
    single page can fire. The env var is the only knob; there is no
    CLI flag. Available since PHP 7.4 (matches envlite's floor, so
    Phase 0 already guards this) and silently ignored on Windows where
    it is documented as unsupported — setting it there is harmless,
    so envlite's launch path is platform-uniform (`putenv()` before
    `pcntl_exec`/`proc_open`). Three workers covers typical WP-admin
    concurrency (the page request plus one or two parallel admin-ajax
    calls) without meaningful memory overhead, and SQLite's file lock
    serializes writes so the multi-worker model can't corrupt the DB.
    A user-exported `PHP_CLI_SERVER_WORKERS` is respected (envlite
    only `putenv()`s when the variable is unset).
15. **Test DB is isolated via `DB_FILE` in the test config only.**
    phpunit's `tests/phpunit/includes/install.php` drops every WP
    table on every run; without isolation it would wipe the dev
    site Phase 8 installs. The split is one `define( 'DB_FILE',
    '.ht.test.sqlite' )` appended to `wp-tests-config.php`;
    `src/wp-config.php` stays untouched and the live runtime keeps
    the drop-in's default `FQDB`. Same-directory + filename suffix
    beats a separate `database-test/` (no path-resolution surprises
    in the drop-in's `FQDBDIR` machinery) and beats putting it
    under `.cache/envlite/` (preserves envlite's own-state-only convention
    for that directory). The test DB is not observation-tracked
    because the rationale for tracking the live DB — possible
    user-authored content — does not apply to a file phpunit drops
    every run.
16. **Router resolves paths via `$_SERVER['DOCUMENT_ROOT']`, not
    from `__DIR__`.** `php -S -t <dir>` populates `DOCUMENT_ROOT`
    with the absolute resolution of `-t`; `envlite_run_dev_server`
    chdirs into the target repo and passes `-t src` before launch,
    so `$_SERVER['DOCUMENT_ROOT']` always equals `<target-repo>/src`
    at request time. Resolving from there decouples the router's
    *resolution behavior* from the router file's *filesystem
    location*: the router file can be loaded from a sibling envlite
    checkout, a vendored copy, or a symlink without serving the
    wrong repo. An earlier draft used `dirname(__DIR__, 2) . '/src'`
    and silently broke this property — invoking envlite from one
    worktree against a different worktree's repo loaded the
    invoker's `wp-config.php` (with its `WP_HOME`/`WP_SITEURL`) and
    triggered a canonical-URL 301 to the wrong port.
    `tools/local-env/tests/test_router.php` is the regression test;
    it boots `php -S` against a fixture docroot wholly outside the
    router file's tree.
17. **`up` is the only setup command.** Earlier drafts had `init`
    (setup, no serve) and `serve` (serve, no setup) alongside `up`
    (both). Two pain points motivated the consolidation: (a) users
    were running `init` followed by `up`, paying for the same setup
    twice on every workflow start; (b) the help text had to explain
    three commands that did overlapping things. With per-phase skip
    rules in place, `up` is fast on a current repo (no subprocess
    re-runs when inputs are unchanged) and `serve` adds nothing — the
    bind probe + `php -S` launch is already what `up` does at the end.
    `--no-serve` covers the CI / "set up but don't launch" niche that
    `init` owned. The simplification removes a subcommand surface and
    a bullet from the help text without losing capability.
18. **Skip via input hashes, not directory presence.** The natural
    cheap check is "if `node_modules/` exists, skip `npm ci`." That
    rule is wrong after `git pull` updates `package-lock.json` —
    `node_modules/` is still on disk but its contents are stale, and
    `up` would silently boot the dev server against the wrong deps.
    Hashing the lockfile costs ~50 µs per phase; the staleness
    detection it buys is worth it. Phase 4 mirrors the rule on
    `composer.json`. Phase 3 keys on both phases plus a sentinel
    output (`src/wp-includes/js/dist/` — a gitignored directory created
    by `build:dev`). Phase 5 keys on the SHA
    pin literal in `envlite.php`. State is recorded only on subprocess
    exit 0, so an interrupted phase always re-runs — false-positive
    re-runs are acceptable, false-positive skips are not.
19. **`--rebuild` is distinct from `--force`.** `--force` answers yes
    to file-overwrite prompts (its existing meaning); `--rebuild`
    discards `.cache/envlite/state` for one invocation and re-runs every
    skip-able phase. Conflating them would make CI's
    prompt-bypass (`--force`) silently incur the full re-run cost on
    every PR build — exactly the slowness the skip rules are designed
    to eliminate.
20. **Parallel composer ‖ npm with serial build:dev.** Phases 2 and 4
    are mutually independent and the wall-time savings on a fresh
    install are substantial. Phase 3 cannot join the parallel pair:
    `build:dev` consumes certificate files installed by `composer
    install`, observed empirically. Output buffering with a bundled
    status line is the readable form of two long-running concurrent
    subprocesses; on failure, both buffers are dumped under labeled
    separators (no partner-kill machinery).

---

## What envlite explicitly does NOT do

- Allocate ports for *external* tooling (database GUIs, Xdebug, etc.) —
  Phase 1 picks one port for the dev web server only.
- Start or stop the web server in the background. `envlite up` runs
  the dev server in the foreground and respects Ctrl-C.
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
  as a convenience during `up`, but treats their outputs as ordinary
  dev-tool artifacts: not tracked in the manifest, not removed by
  `clean`. Use `git clean -fdx` or your usual tooling. (Note: envlite
  *does* track its skip metadata about these directories in
  `.cache/envlite/state`, but the directories themselves remain
  user-owned.)
- Override Composer's cache or home directory. envlite does not set
  `COMPOSER_HOME`; Composer's default applies.
- Refresh the pinned SQLite drop-in. There is no `envlite update`
  subcommand. To pick up a newer plugin release, edit the SHA256 pin
  (and any associated logic) in `tools/local-env/envlite.php`. The
  next `envlite up` detects the pin change via
  `phase5.recorded_pin_sha` and re-downloads automatically; no manual
  `clean` is required. The pin is intentional: bumping it is a
  deliberate envlite revision, reviewed and committed alongside any
  code adjustments the new release requires.
- Manage worktrees. envlite operates on whatever directory it is
  invoked in.

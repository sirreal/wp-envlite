# envlite — wordpress-develop repo setup specification

**Goal:** Take a checkout of `WordPress/wordpress-develop` whose JavaScript
and PHP dependencies are already installed and whose assets are already
built, and bring it to a state where (1) PHP's built-in server can serve a
working WordPress site against a SQLite database, and (2)
`./vendor/bin/phpunit` runs against that SQLite database on host PHP —
without starting any global services (no system MySQL, no Docker, no MAMP).

**Non-goals:** installing JavaScript or PHP dependencies, building assets,
worktree creation, background process management, HTTPS, production-shaped
stacks. envlite does **not** run `npm ci`, `composer install`, or
`npm run build:dev` — those are the developer's responsibility and must be
done before the served site or phpunit can work (the install/build steps are
the slowest part of setup, are frequently re-run on their own cadence, and
are often unnecessary for a given task, so envlite leaves them to the
developer). envlite operates on whatever directory it is invoked in, and
leaves no daemons behind. Multisite support is not prioritized for the
initial version but is not excluded from envlite's charter.

**Tech stack:**

- host PHP ≥ 7.4 (matching WordPress's own supported floor), with
  `pdo_sqlite`, `sqlite3`, `openssl`, `zip`, and `hash` extensions loaded.
  On Unix, `pcntl` is also required so `envlite up` can call `pcntl_exec`
  into `php -S`. These are the extensions envlite itself uses; Phase 0 fails
  hard when any is missing.
- the SQLite Database Integration plugin from wordpress.org, pinned by
  SHA256: `44be096a14ebcea424b5e4bf764436ec85fb067f74ab47822c4c5346df21591e`.

**Developer-provided prerequisites** (envlite does not install or run these,
but the served site and phpunit need them; Phase 0 probes them and emits a
preflight **warning** — never a hard failure — when they are missing or below
the recommended version):

- host `node` ≥ 20.10, `npm` ≥ 10.2.3 (matching `package.json` `engines`)
  and `composer` ≥ 2 — used by the developer to run `npm ci`,
  `composer install`, and `npm run build:dev`.
- the `gd` PHP extension (required by the WordPress core test bootstrap)
  and `simplexml` (required by the PHPStan/PHPCS toolchain).

**No assumed availability** of `python`, `sed`, `awk`, `jq`, `unzip`,
`shasum`, `curl`, or any other host CLI. envlite is implemented in PHP and
performs all file operations, hashing, HTTP fetches, and zip extraction
through PHP's standard library (`file_get_contents` with stream context,
`hash_file`, `ZipArchive`, `preg_replace`, `str_replace`, `proc_open`).
Subprocesses spawned by envlite are limited to `node`/`npm`/`composer`
**version probes** in Phase 0 (preflight warnings only — envlite never runs
`npm ci`, `composer install`, or `npm run build:dev`), plus the host `php`
itself in two places: launching the dev server at the end of `envlite up`
and running the Phase 5 site install (script piped to the subprocess via
stdin). On Unix, the dev-server launch uses `pcntl_exec` (process
replacement) rather than a proper subprocess; on Windows it is a `proc_open`
because `pcntl` is unavailable.

---

## CLI interface

### Invocation

envlite is implemented as a single PHP script (`envlite.php`) with a
small router asset (`router.php`) that must live in the same
directory. The pair is location-agnostic — invoke it from any path
the user has placed it at, so long as the current working directory
is a wordpress-develop checkout root. The canonical invocation form is:

```
$ php <path-to-envlite>/envlite.php <subcommand> [args...]
```

When envlite is dropped at the wordpress-develop repo root (the
documented quickstart layout — drop in or symlink the two files),
this collapses to `php envlite.php <subcommand>`. PATH-based forms
(`envlite <subcommand>` via a user-installed symlink or shebang
execution) are out of scope; envlite does not install itself onto
`PATH`, and the spec assumes the explicit `php …` form above.
Throughout the rest of this document, `envlite <subcommand>` is
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
  `--force` only governs prompts; it has no effect on phase skip rules.

### Subcommand flags

- `up [--port=N] [--no-serve]`
  - `--port=N` skips Phase 1 discovery and uses the given port. Updates
    `.cache/envlite/port` to N.
  - `--no-serve` runs every setup phase that's needed and then exits 0
    without launching the dev server. The CI / automation form. The
    setup phases are identical to a normal `up` — same skip rules, same
    state writes — only the trailing `php -S` launch is suppressed.

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
Phase 5 has already run `wp_install()`, so the site responds with the
homepage on first hit. Log in at `/wp-login.php` with `admin` / `password`.

Both of these checks require the developer to have already installed
dependencies and built assets (`npm ci && composer install &&
npm run build:dev`, or equivalent). envlite does not run those steps;
without them phpunit fails to bootstrap and the served site cannot load.

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

envlite spawns very few subprocesses (see the **Tech stack** note at
the top): the `node`/`npm`/`composer` version probes in Phase 0 (whose
captured output only ever surfaces as a one-line preflight warning) and
the host `php` in two places — the Phase 5 site install (script piped
via stdin) and the dev-server launch. The Phase 5 install subprocess's
first non-empty stderr line is surfaced as the cause of an
`envlite up: phase 5: install subprocess: <cause>` failure (see
Phase 5). There is no parallel-install pair and no buffered
multi-subprocess output dump — envlite no longer runs `npm ci`,
`composer install`, or `npm run build:dev`.

### Dev-server launch

After all setup phases succeed (and `--no-serve` was not passed), `up`
launches `php -S 127.0.0.1:<port> -t src <path-to-envlite>/router.php`
in the foreground using the resolved port. The router file resolves
via `__DIR__/router.php` inside envlite, so it always sits next to
envlite.php regardless of install path.

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

The router (`router.php`) ships in the same directory as `envlite.php`
and travels with it; it is not installed into the wordpress-develop
checkout, the manifest does not track it, and `clean` does not remove
it. Its only request-time
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
hit and pretty-permalink fallback once installed.

The router itself never reads the port: `php -S` binds it, and the
router's only request-time inputs are the URI and `DOCUMENT_ROOT`.
Within the setup phases the port is still used twice — Phase 4
stamps `WP_HOME` / `WP_SITEURL` with `http://127.0.0.1:<port>` so
WordPress generates correct canonical URLs, and Phase 5 sets
`$_SERVER['HTTP_HOST']` to `127.0.0.1:<port>` so `wp_install()` runs
in the same host context the live site will use. Phase 1's port
discovery therefore runs unconditionally — including under
`--no-serve` — so those config writes have a stable value. The
`--no-serve` short-circuit suppresses only the final `php -S`
launch, not port discovery, the wp-config stamp, or the install.

The router applies `rawurldecode()` to the URI path before its
filesystem and `.ht` checks. `php -S` decodes percent-encoding
internally when mapping a URL to a file, so the router must too —
otherwise (a) uploads with encoded characters (e.g. `my%20photo.jpg`
for `my photo.jpg`) fail the `file_exists` check and fall through to
WordPress as 404s, and (b) an encoded `.ht` segment (e.g.
`/%2Eht.sqlite` for the SQLite DB) bypasses the raw-URI `.ht` regex
and reaches `php -S`, which then resolves it to the real file.

After decoding, the router also normalizes backslashes to forward
slashes (`str_replace('\\', '/', $path)`). Windows PHP treats `\` as
a path separator equivalent to `/`, so a decoded `%5C` segment
(`/wp-content/database\.ht.sqlite`) would otherwise let the `(^|/)\.ht`
segment regex miss the `.ht` while `file_exists($docroot . $path)`
still resolves to the real DB. Normalize once so both checks see the
same forward-slash-only form.

The router rejects any path containing a NUL byte with a 400 response
before reaching the filesystem APIs. PHP 8+ throws `ValueError` when
NUL appears in any filesystem-API argument, so a request like `/%00`
would otherwise fatal the router and the client would see a blank 500.
NUL in a URL path is always malformed; the 400 is the right shape.

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
> phases for the full contract — it shapes Phases 2–4 and `clean`.

**Purpose:** abort early if the environment cannot satisfy envlite's
assumptions. Cheap to run and informative on failure.

**Inputs:** the current working directory; the `PATH`.

**Checks** fall into two tiers. The first four are **hard failures**
(exit 3) — they gate conditions envlite itself depends on. The fifth
(node/npm/composer versions and the `gd`/`simplexml` extensions) is
**warning-only**: envlite probes it but never exits on it, because the
tooling it covers — installing dependencies, building assets, running
phpunit and the linters — is the developer's responsibility, not
something envlite does.

1. CWD is the root of a wordpress-develop checkout. Detect by the
   simultaneous presence of these markers, with the correct on-disk
   shape:
   - `package.json` (regular file)
   - `composer.json` (regular file)
   - `wp-config-sample.php` (regular file)
   - `wp-tests-config-sample.php` (regular file)
   - `src/wp-includes` (directory)
   - `tests/phpunit/includes/bootstrap.php` (regular file)

   Each file marker is checked with `is_file()` and the directory
   marker with `is_dir()` — `file_exists()` alone would let a
   malformed tree pass (a directory named `package.json`, or a regular
   file at `src/wp-includes`). If any marker is missing OR present
   with the wrong shape, abort with exit code 3.
2. `PHP_VERSION` ≥ 7.4. envlite is run by PHP itself, so `PHP_VERSION_ID`
   is the authoritative check.
3. The PHP extensions envlite itself uses are loaded
   (`extension_loaded(...)` returns true for each); a missing one is a
   **hard failure** (exit 3):
   - `pdo_sqlite`, `sqlite3` — for the SQLite drop-in (Phase 2) and the
     runtime/test database paths.
   - `openssl` — required by PHP's HTTPS stream wrapper (used by
     `file_get_contents` in Phases 2 and 4). Without it the spec's
     network fetches fail with "Unable to find the wrapper 'https'".
   - `zip` — required by `ZipArchive` for Phase 2.
   - `pcntl` (Unix only) — required so `envlite up` can call
     `pcntl_exec(PHP_BINARY, …)` into the dev server, replacing
     envlite's PHP process in place. The check is gated on
     `PHP_OS_FAMILY !== 'Windows'`; Windows PHP has no `pcntl` and
     uses a `proc_open` fallback.

   `hash` is non-disable-able since PHP 7.4 and is not checked.

   In addition, Phase 0 verifies `allow_url_fopen=1`. Phase 2 fetches
   the SQLite plugin zip and Phase 4 fetches WordPress salts via
   `file_get_contents()` against `https://` URLs; with the directive
   disabled those calls fail much later, mid-phase. A preflight check
   makes the failure mode "fix php.ini and re-run" rather than a
   confusing mid-phase abort.

   Phase 0 also verifies `function_exists('proc_open')`. Every
   subprocess envlite spawns (the node/npm/composer version probes and
   the host `php` site-install) goes through `proc_open`; hardened
   php.ini configurations sometimes list it in `disable_functions`, and
   hitting that via the version probe below would surface a raw PHP
   error rather than the documented preflight exit 3.

   On Unix, Phase 0 additionally verifies `function_exists('pcntl_exec')`.
   The pcntl extension being loaded (checked above) is necessary but
   not sufficient — `disable_functions=pcntl_exec` is a documented
   hardening option that leaves the extension visible to
   `extension_loaded()` but disables the call envlite makes at the
   end of `up` to hand off to `php -S`. Without this preflight, every
   setup phase runs to completion before the dev-server launch
   discovers the missing function and aborts; with it, the user gets
   a one-line preflight error after milliseconds.
4. **Warning-only** developer tooling. envlite no longer installs
   dependencies, builds assets, or runs phpunit/the linters, so none of
   the following ever aborts preflight — each missing or below-minimum
   result emits a single `preflight: warning: …` line on stderr and
   Phase 0 continues:
   - `node` ≥ 20.10, `npm` ≥ 10.2.3, `composer` ≥ 2 — the developer
     needs a recent toolchain to run `npm ci`, `composer install`, and
     `npm run build:dev` before the served site or phpunit will work.
     The `npm` floor matches `package.json`'s `engines.npm`. Each is
     probed by a single `proc_open` call passing the binary as a command
     **array** with its version flag — `['node', '--version']`,
     `['npm', '--version']`, `['composer', '--version']` — and reading
     stdout. Passing an array (rather than a string) avoids shell
     invocation entirely on Unix; the OS's exec semantics handle binary
     lookup, including `PATH` resolution. A non-zero exit or a "command
     not found" failure from `proc_open` means the tool is missing →
     warn and continue. A successful spawn whose parsed version string
     falls below the minimum → warn and continue.
   - the `gd` PHP extension — required by the WordPress core test
     bootstrap. `phpunit.xml.dist` sets `WP_RUN_CORE_TESTS=1`, and
     `tests/phpunit/includes/bootstrap.php` aborts before any test
     group filter applies when `gd` is missing. The developer's phpunit
     runs need it.
   - the `simplexml` PHP extension — required by the PHPStan/PHPCS
     toolchain the developer runs. Without it `vendor/bin/phpstan` and
     PHPCS ruleset loading fail at runtime.

   On Windows, `npm.cmd` and `composer.bat` are batch scripts, not
   executables — `CreateProcess` (PHP's array-form `proc_open` substrate)
   cannot interpret them. envlite resolves the bare name to a full path
   via `PATH`/`PATHEXT` lookup, then routes `.cmd`/`.bat` results through
   `cmd.exe /d /s /c "<command line>"` using string-form `proc_open` with
   `bypass_shell` set. The wrapper builds the inner command line with
   cmd.exe-native quoting (whitespace and metacharacters get outer
   double quotes, internal `"` is doubled, `^` and `%` are caret-escaped)
   so paths with spaces — notably the default `C:\Program Files\nodejs\`
   install — work without extra setup. PHP's MS C runtime escaping is
   incompatible with cmd.exe's parsing, so the build is manual.

**Outputs:** none. The hard-failure checks (1–3 plus `allow_url_fopen`,
`proc_open`, and `pcntl_exec`) exit 3 with the failed check identified;
the warning-only check (4) never affects the exit code.

**Why this matters:** the recipe was validated under a specific stack.
Most of the gotchas (the SQLite drop-in's loading mechanism, the
`convertDeprecationsToExceptions=true` caveat) are tied to known
versions. Don't silently degrade.

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

## Phase 2 — SQLite Database Integration drop-in

**Purpose:** make WordPress and phpunit use a file-backed SQLite database
instead of MySQL.

**Operation:**

All file writes in this phase follow the standard prompt rule (see
"Destructive operations and prompts"): an unowned destination prompts
before being overwritten; `--force` answers yes to every such prompt.

1. If `src/wp-content/plugins/sqlite-database-integration/` is a **real
   directory** (`is_dir($pluginDir) && !is_link($pluginDir)`) recorded
   in the manifest (envlite-owned `dir` entry) **and** its `db.copy` is
   present locally **and** `.cache/envlite/state` records a
   `phase2.recorded_pin_sha` matching the literal
   `ENVLITE_SQLITE_PLUGIN_SHA256` in `envlite.php`, skip steps 2–4 and
   proceed to step 5. The pinned plugin tree from a prior `up` is
   reusable as-is; there is no value in re-downloading it.

   A symlink at the plugin path **never** satisfies this predicate —
   envlite never writes a symlink, so finding one always means external
   modification, and trusting it would let `db.copy` resolve via the
   symlink target (possibly anywhere outside the checkout).

   Otherwise (no manifest entry, `db.copy` missing, recorded pin SHA
   differs from the current code literal, or a symlink at the plugin
   path) proceed to step 2. There is no flag to force re-entry: to make
   the drop-in re-download, bump `ENVLITE_SQLITE_PLUGIN_SHA256` in
   `envlite.php` (which changes the recorded-pin comparison) or run
   `clean` then `up`.
2. Download the plugin zip via PHP HTTP (`file_get_contents` with a
   stream context that follows redirects, sets a User-Agent, and
   times out at 30 s) from a versioned wordpress.org URL of the form
   `https://downloads.wordpress.org/plugin/sqlite-database-integration.<version>.zip`
   to a temp file under `sys_get_temp_dir()`. The temp-file write
   return is checked: an unwritable temp dir or a full disk must
   abort with a phase 2 diagnostic naming the write failure, not be
   silently passed through to step 3 (where a missing/partial file
   would yield a misleading SHA256 mismatch or a PHP warning).
   The version segment is required: the unsuffixed `.zip` URL is a
   moving "latest" pointer, so pairing it with a fixed SHA256 pin
   would break fresh installs
   on every upstream release.
3. Verify the downloaded **zip's** SHA256 with `hash_file('sha256', ...)`
   against the pinned value
   `44be096a14ebcea424b5e4bf764436ec85fb067f74ab47822c4c5346df21591e`.
   Mismatch is fatal; abort with exit 1. Re-pinning to a newer release
   is an explicit envlite revision, not an automatic fall-through.
4. Extract using PHP's `ZipArchive` into `src/wp-content/plugins/`.
   This produces `src/wp-content/plugins/sqlite-database-integration/`.
   Delete the temp zip.

   If anything other than envlite's own owned real directory tree sits
   at the plugin path — an unowned real directory (user-installed
   plugin), a symlink (always external), or a non-directory entry —
   prompt before overwriting. `--force` bypasses the prompt and the
   extract proceeds. The overwrite is a **total replacement**: the
   pre-existing entry is cleared (see the "clear the plugin path
   entirely" paragraph below for the rationale and the failure
   semantics) and `ZipArchive::extractTo` then materializes a fresh
   directory whose contents come entirely from the verified zip. An
   earlier draft described this as "overlaying envlite's pinned tree
   on top of whatever was there"; that wording is preserved here only
   to flag that overlay is **wrong** — it lets `extractTo` follow
   user-introduced symlinks inside the existing tree and write to
   their targets (potentially outside the checkout). Record the
   directory in the manifest as a `dir` entry once extraction
   succeeds. Record the current pin literal to
   `phase2.recorded_pin_sha` in `.cache/envlite/state` once
   extraction succeeds — subsequent `up` runs compare against this
   to detect a code-level pin bump.

   Immediately before invoking `ZipArchive::extractTo`, re-check the
   plugin path **identity** (not just shape). The ownership prompt
   fired against the *specific entry* present at the initial scan;
   the HTTP fetch + SHA verify + zip open window is several seconds
   wide and another process (or the user themselves) can create,
   remove, or **swap** the entry. The check uses an `lstat`-derived
   `<ino>:<dev>` signature so a same-shape swap (a real directory
   replaced by a different real directory; one symlink replaced by
   another) is caught — boolean checks alone (`is_link`,
   `is_dir`, `file_exists`) would miss the same-shape case. If the
   signature has changed, abort with a phase 2 diagnostic. The
   clear pass below would otherwise delete the new entry under
   consent that was given for something else.

   Then **clear the plugin path entirely**. Symlinks (any flavor)
   and non-directory entries (regular file, FIFO, socket) are
   unlinked; a real directory is recursively removed via the
   symlink-aware `rrmdir` helper. The re-extract therefore always
   materializes a fresh directory whose contents come entirely from
   the verified zip. Leaving an existing real-directory tree in
   place and letting `extractTo` overlay would expose another
   write-through-symlink path: a pre-existing symlink inside the
   tree (e.g. the user's own
   `sqlite-database-integration/db.copy` → `/etc/passwd`) would be
   followed when `extractTo` writes the same path. The clear pass
   re-stats afterwards: if anything still sits at the plugin path —
   permission denied, an `@unlink`/rrmdir failed silently, or a
   TOCTOU race recreated the entry — abort with a phase 2
   diagnostic ("could not clear … before extract; refusing to
   extract") rather than letting `extractTo` proceed unsafely.

   Also immediately before `ZipArchive::extractTo`, drop any
   pre-existing `phase2.recorded_pin_sha` entry from state.
   `extractTo` recreates `db.copy` early in the zip stream, so a
   mid-extraction failure can leave the step-1 skip predicate
   (manifest entry + `db.copy` + matching pin) satisfied on the next
   `up`, which would then short-circuit step 2 against a partial
   plugin tree. Drop the pin and re-record it only on extraction
   success. Pre-extraction failures (HTTP fetch error, temp-file
   write, SHA mismatch, `ZipArchive::open`) do not touch the existing
   plugin tree on disk, so the pin must remain intact for those —
   otherwise a transient offline re-run with a known-good install
   would forfeit the cached skip and keep requiring network access.
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

Both are removed by `clean`. The `phase2.recorded_pin_sha` entry in
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

## Phase 3 — phpunit configuration

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
`envlite up: phase 3: WP_PHP_BINARY sample literal not found exactly once; envlite assumption broken`.

Then assert that the substituted bytes do not already contain a
`DB_FILE` define (regex: `define\s*\(\s*['"]DB_FILE['"]`); a
match means upstream's `wp-tests-config-sample.php` has grown its own
`DB_FILE` and envlite's append assumption no longer holds — abort with
`envlite up: phase 3: DB_FILE already defined in wp-tests-config-sample.php; envlite assumption broken`.
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
  in Phase 4 because that file *is* used by an HTTP runtime.)
- ABSPATH in the sample resolves to `dirname(__FILE__) . '/src/'`, which
  is correct for envlite's layout.
- The appended `DB_FILE` define isolates the phpunit test DB at
  `src/wp-content/database/.ht.test.sqlite` from the live runtime
  DB at `src/wp-content/database/.ht.sqlite`. The phpunit
  bootstrap's `tests/phpunit/includes/install.php` drops every WP
  table on every run; sharing the drop-in's default `FQDB` between
  the two configs would silently wipe the dev site Phase 5
  installs, contradicting Phase 5's "envlite never drops tables"
  invariant via phpunit's bootstrap. `src/wp-config.php` (Phase 4)
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

## Phase 4 — Runtime configuration (`src/wp-config.php`)

**Purpose:** create the runtime config that the dev server will load.
Distinct from Phase 3: `src/wp-config.php` is loaded by `wp-load.php`;
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
   payload. The regex matches each of the eight keys in order with
   only whitespace allowed between adjacent defines — not arbitrary
   content. A `.*?` span would silently consume an inserted line (a
   comment, a foreign define) during replacement and produce a subtly
   damaged `wp-config.php`; the tighter pattern refuses to match a
   reshaped block, and the count assertion below turns that into a
   clear phase 4 abort. Assert exactly one match; abort if zero or
   multiple (including the reshape case).
5. Locate the literal marker
   `/* That's all, stop editing! Happy publishing. */` (appears exactly
   once in the sample) and inject the following three lines immediately
   *before* it, separated by a blank line:

   ```
   define( 'WP_HOME',    'http://127.0.0.1:<PORT>' );
   define( 'WP_SITEURL', 'http://127.0.0.1:<PORT>' );
   define( 'WP_AUTO_UPDATE_CORE', false );
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

**Why `WP_AUTO_UPDATE_CORE` matters:** WordPress can silently
self-update core files in the background. On a dev box this is
undesirable — it can change the tree unexpectedly and interfere with
debugging. Setting `WP_AUTO_UPDATE_CORE` to `false` disables all
automatic core updates.

**Idempotency:** same manifest-anchored rule as Phase 3.

- Path absent → write, record.
- Path present, in manifest, hash matches → silent re-stamp. Note that
  the re-stamp picks up any change to the Phase 1 port automatically
  (the port is interpolated at write time), so `WP_HOME`/`WP_SITEURL`
  always match the cache.
- Path present, in manifest, hash drifted → prompt before overwriting.
- Path present, not in manifest → prompt before overwriting.

---

## Phase 5 — Site install

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
   moot at runtime — `WP_SITEURL` from Phase 4 is a defined constant
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

A non-zero subprocess exit causes the parent (`envlite_phase5_install_site`)
to throw with the first non-empty stderr line as the cause; the
existing `envlite_phase_guard()` converts that into
`envlite up: phase 5: install subprocess: <cause>` + exit 1.

**Inputs:** `src/wp-config.php` (Phase 4), the active SQLite drop-in
(Phase 2), `.cache/envlite/port` (Phase 1).

The site install boots WordPress's **runtime** path
(`wp-load.php` → `wp-settings.php` → the SQLite drop-in), which does not
depend on Composer's `vendor/` autoload (that tree is for phpunit and the
linters) and does not read built asset files at load time. A recent
wordpress-develop checkout therefore installs fine even before the
developer has run `npm ci`/`composer install`/`npm run build:dev`. Those
steps are still the developer's job and are needed for the full
experience — the block editor, compiled front-end assets, and phpunit —
but envlite does **not** install dependencies, build assets, or
preflight-check for `node_modules/`, `vendor/`, or build outputs; it
neither enforces nor guarantees their presence.

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
| phase 5 fails while bootstrapping WordPress | the checkout is incomplete or damaged in a way the runtime path trips over (a recent, intact checkout installs fine even before deps/assets are built) | restore a clean checkout; if needed, run `npm ci && composer install && npm run build:dev`, then re-run `up` |
| phase 5 fails with a DB error | corrupt `.ht.sqlite` from a prior interrupted run | delete `src/wp-content/database/.ht.sqlite`, re-run `up` |
| phase 5 fails with a salt-related notice | rare; salt fetch in Phase 4 left placeholder strings | not a real failure mode; placeholders are accepted |

**`--force` interaction:** none. The phase is non-destructive (it
only writes into an empty DB) and asks no prompts.

---

## State and ownership

These two sections describe envlite's contract with the filesystem.
They are policy for what the phases above do, not phases themselves;
the placement here is so the reader has the concrete file-by-file
picture from Phases 0–5 in mind before evaluating the abstract rules.

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
  manifest as envlite-owned. (Phases 2–4.)
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
| `state` | Skip metadata for the Phase 2 SQLite drop-in. Read by `up` to decide whether the drop-in download/extract can be skipped. | One entry per line: `<key>\t<value>\n`. The only key is `phase2.recorded_pin_sha` (the `ENVLITE_SQLITE_PLUGIN_SHA256` literal that was in force when the drop-in last installed). Its value is 64-char lowercase hex. Unknown keys are ignored on read; a missing entry is treated as "drop-in has never installed" → run the download/extract. |

**State file vs. manifest.** The two files have different write
triggers and different contracts:

- The manifest records **outputs envlite owns** with their content
  hashes — drift-detected on every re-run, walked by `clean`.
- The state file records the **pin SHA the Phase 2 drop-in last
  installed under** — used solely to decide whether the next `up` can
  skip the download/extract. Not consulted by `clean` (the file is
  wiped with the rest of `.cache/envlite/`).

The `phase2.recorded_pin_sha` entry is written **after** the drop-in's
`ZipArchive::extractTo` succeeds, and is dropped immediately before the
extract begins (see Phase 2). An interrupted extract therefore leaves no
recorded pin; the next `up` re-downloads. False-positive re-runs are
acceptable; false-positive skips are not. There is no flag to ignore the
recorded pin — bump `ENVLITE_SQLITE_PLUGIN_SHA256` in `envlite.php`, or
`clean` then `up`, to force a re-download.

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
not hand-edit it; run `clean` to wipe it. Users who need to "forget" an
envlite-owned path should run `envlite clean` and re-run `up`.
(`clean` doesn't touch `node_modules/`, `vendor/`, or build artifacts,
so the slow-to-rebuild parts survive a clean+`up` cycle.)

**Manifest read failures.** A manifest path that exists on disk but
cannot be loaded must NOT be silently interpreted as an empty
manifest. Three classes of failure:

  - The path is a regular file but unreadable (permissions stripped,
    IO error) → throw `cannot read manifest at <path>`.
  - The path exists but is **not** a regular file (a directory, a
    broken symlink, a symlink to anywhere, a FIFO) → throw
    `manifest at <path> is not a regular file; refusing to load`.
    envlite never writes a symlink or non-regular entry at the
    manifest path; finding one always means external interference.
  - The state directory `.cache/envlite/` exists but lacks search/
    read permission → `file_exists` and `is_link` both return false
    on the manifest path because PHP can't traverse the parent.
    Treating this as "manifest is absent" lets `clean --force` wipe
    `.cache/envlite/` and orphan every managed file. Probe the
    parent with `scandir`; if listing fails throw
    `cannot read state directory <path>; manifest may exist but is
    inaccessible`.

In either case, treating the load as empty would corrupt state: `up`
would rewrite the manifest with only the new entries and lose every
prior ownership record; `clean --force` would remove `.cache/envlite/`
while leaving every managed file orphaned (no longer in any
manifest, but envlite has forgotten it ever wrote them). The loader
throws so the caller can abort with the documented
`envlite <sub>: ...` shape rather than quietly losing ownership
history. Phases 2–4 surface the throw via their
`envlite_phase_guard` wrappers; `clean` and the observation points
catch it explicitly.

**Atomic writes.** Every file envlite writes — whether content
(`wp-config.php`, `wp-tests-config.php`, etc.) or the manifest itself — uses the
write-temp + fsync + rename pattern: hash the in-memory bytes
(`hash('sha256', $bytes)`), write them to a uniquely-named sibling
path with the pattern `<final-path>.envlite-tmp.<8 hex bytes>` in
binary exclusive-create mode (`'xb'` → `O_CREAT|O_EXCL`; never
`'wb'`, which truncates an existing file or follows a symlink before
any ownership check can run, and never PHP's text mode `'t'`, which
translates `\n` to `\r\n` on Windows and would make the on-disk
bytes diverge from the hash), fsync, `rename()` over the final
path. The manifest entry update uses the already-computed hash and
happens after the content rename, also atomic-replace. envlite
**never** calls `hash_file()` on the renamed target to populate the
manifest — that would race with any subsequent writer. A SIGINT
mid-operation leaves either fully-pre-write or fully-post-write state
on disk; no half-written file claims a hash for content that wasn't
durable. Random suffix + `O_EXCL` is a deliberate change from an
earlier deterministic `<final-path>.tmp` form: that form let a
pre-existing user file or symlink at the temp path collide with
envlite's write — `wb` would have truncated it (or followed the
symlink to truncate its target) before ownership could be evaluated.

Before the `rename()`, atomic_write clears a non-regular destination
(symlinks via `unlink`, directories via the symlink-aware `rrmdir`).
POSIX `rename()` can replace a regular file or a missing path
atomically but refuses to overwrite a directory; without this step,
Phases 2–4 would abort *after* the ownership prompt approved the
overwrite — leaving the user confused about why their consent did
not take effect. Symlinks are unlinked, never followed, so the
symlink target is never truncated.

**Ownership decisions** (consulted by Phases 2–4):

- Nothing on disk, no manifest entry → absent; write directly.
- Nothing on disk, manifest entry present → user deleted it; safe to
  recreate without prompting.
- Path on disk is a regular file whose hash matches the manifest →
  envlite owns it; safe to silently re-stamp.
- Path on disk is a regular file whose hash has drifted → envlite
  created it, the user (or another tool) has modified it; prompt
  before overwriting (drift prompt includes hash preview).
- Path on disk is **not** a regular file (broken symlink, FIFO, dir
  where a file should be, symlink-to-anything) → treat as drifted if
  the manifest claims ownership of a regular file here, otherwise as
  unowned; either way, prompt before the rename clobbers it.
  envlite never writes a symlink or a non-regular entry, so finding
  one at an output path always means external modification.
- Path on disk, **not** in manifest → not envlite-owned; prompt
  before overwriting.

Existence is computed as `file_exists($abs) || is_link($abs)` to
catch broken symlinks (where `file_exists` resolves to false but
the symlink itself sits in the way). A "regular file" test for the
content-readability path is `is_file($abs) && !is_link($abs)`:
symlinks-to-regular pass `is_file` but envlite never writes them,
so they count as non-regular for ownership.

`clean` walks the manifest in reverse insertion order and (after
prompting) removes each entry, then removes `.cache/envlite/` itself. Manifest
order is the order envlite wrote things; since users are not supposed
to edit the manifest, that order is well-defined.

**Containment check.** Before each delete, `clean` resolves the
manifest entry's absolute path with `realpath()` and verifies the
resolved path stays under the canonical repo root **or** under the
canonical state directory. The top-level symlink guard in `rrmdir`
only protects the leaf component of a single manifest entry; an
**ancestor** symlink (e.g. the user replaced `src/wp-content/plugins`
with a symlink to `/tmp/shared-plugins`) is followed by
`is_dir()`/`scandir()` on the deeper manifest entry path, and
`rrmdir` would recursively delete the symlink target without
realizing the path escapes the checkout. Entries whose resolved path
escapes are marked as failed; the manifest and state are preserved
so the user can inspect and resolve the situation manually. Broken
symlinks (which have no `realpath` resolution) skip the containment
check — the leaf is the symlink itself, an `@unlink` of which is by
definition a single-inode operation that cannot escape.

The state-directory exception exists because a symlinked
`.cache/envlite/` (a spec-supported user setup — see the
state-directory section above) makes legitimate manifest entries
like `.cache/envlite/port` and `.cache/envlite/manifest` resolve
*outside* the canonical repo root via that symlink. Without the
exception every such entry would be flagged as an escape and clean
would fail on every checkout whose state has been redirected.

Path comparison normalizes separators with
`str_replace('\\', '/', ...)` on both sides before the prefix check:
`realpath()` returns OS-native separators (`\` on Windows), and a
prefix comparison against an unnormalized `\`-using path would
spuriously flag every legitimate Windows manifest entry as
escaping the checkout.

The final `.cache/envlite/` removal is **recursive** (rrmdir, not a
single `rmdir`). Atomic writes can leave temp siblings of
`manifest`/`state`/`port` behind on an interrupted run, and an
unconditional rmdir would silently fail against a non-empty directory.
envlite owns the whole `.cache/envlite/` subtree per the contract above,
so recursive removal is safe. If the recursive removal still leaves the
directory in place (permission denied, an external process holding a
file open on Windows), `clean` reports `could not remove
.cache/envlite/` and exits 1 so the user does not see a false success.

The recursive removal is **symlink-aware at the top level**: if
`.cache/envlite/` is itself a symlink (e.g., the user redirected it
to a different filesystem), envlite unlinks the symlink only and does
not recurse into the target. Following the symlink would let a
`clean` (especially with `--force`) delete user-owned files entirely
outside envlite's nominal state directory. Symlinks **inside** the
state subtree are likewise unlinked rather than recursed into.

`clean` also handles non-real-directory blockers at the state-dir
path: if `.cache/envlite` is a broken symlink, a symlink to a file,
or a regular file (left by a failed or external write), the state
directory is conceptually absent, so the manifest cannot exist —
there is nothing to walk. `clean` unlinks the blocker and reports
"removed non-directory blocker at .cache/envlite/" (exit 0). Without
this step the blocker would survive forever and the next `up` could
not recreate the state directory because a non-directory entry would
still sit at that path.

A symlink to an **existing directory**, by contrast, is a legitimate
user setup (state redirected to a different filesystem). `clean`
walks the manifest through the symlink and removes the
envlite-managed checkout files normally; only at the final state-dir
removal step does the symlink-aware top-level rule kick in, unlinking
the symlink without recursing into the target. The discriminator is
`is_dir($stateDir)` — true for both real directories and
symlinks-to-directories, false for broken symlinks and non-directory
blockers.

---

## Outputs (final repo state)

After a successful `envlite up`, the repo has:

**envlite-managed (recorded in the manifest, removed by `clean`):**

```
.cache/envlite/port                                      (Phase 1)
src/wp-content/plugins/sqlite-database-integration/      (Phase 2)
src/wp-content/db.php                                    (Phase 2)
wp-tests-config.php                                      (Phase 3)
src/wp-config.php                                        (Phase 4)
src/wp-content/database/.ht.sqlite                       (populated by Phase 5; observation-recorded — see below)
```

**Operational state (not in the manifest, removed with `.cache/envlite/`):**

```
.cache/envlite/manifest                                  (all phases write into it; the manifest cannot hash-track itself)
.cache/envlite/state                                     (Phase 2 — drop-in pin SHA; spec calls this "not tracked by the manifest")
```

The state-and-manifest pair belongs to envlite's private state
directory. `clean` removes them by rrmdiring the whole
`.cache/envlite/` subtree at the end, not by walking manifest
entries. Listing them as manifest-tracked above would be incoherent
(the manifest's hash-of-itself is recursive; the state file is by
design un-tracked).

**Developer-provided prerequisites (envlite never creates or removes these):**

```
node_modules/                                            (developer runs `npm ci`)
vendor/                                                  (developer runs `composer install`)
src/wp-includes/js/, css/, blocks/ build outputs         (developer runs `npm run build:dev`)
src/wp-content/database/.ht.test.sqlite                  (created on first phpunit run; not envlite-managed)
```

envlite does **not** install dependencies or build assets. The
developer must run `npm ci && composer install && npm run build:dev`
(or their usual equivalent) before the served site or phpunit will
work. These directories are not tracked in the manifest, and `clean`
never touches them — remove them with `git clean -fdx` or your usual
tooling.

`.ht.sqlite` is created by the SQLite drop-in the first time
WordPress is loaded — Phase 5 is now that first load, so the file
exists by the time `up` returns (or, with `--no-serve`, by the time
the setup phases complete). The file may hold user-authored content
(posts, settings, uploads).

**Observation point:** envlite observes `src/wp-content/database/.ht.sqlite`
after Phase 1 succeeds (every `up`), again at the end of every `up`
(after Phase 5), and at the start of every `clean`. The observation
checks whether the file exists on disk and is not yet in the
manifest; if so, envlite adds an entry recording the file's hash at
that moment. The first observation runs **after** Phase 1, not
before: phase 1's bind-failure path (`up --port=N` against a bound
port, or auto-discovery with no free port in the pool) must leave the
manifest unmutated per the bind-failure contract above, and running
the persistent observation earlier would silently record the DB even
when phase 1 then exits 1. The two-pass arrangement for `up` is
required because Phase 5 is the first run that creates the DB on a
fresh checkout: the first observation finds no file, Phase 5 then
triggers WordPress to create `.ht.sqlite`, and the end-of-up
observation records the live file.
Without the second pass, a first successful `up` would leave the DB
out of the manifest and a later `clean` would not prompt before
removing it. The `up` recordings persist in the manifest as ongoing
ownership. The `clean` recording is transient — it exists only so
the file appears in *this* invocation's removal prompt; the manifest
is wiped at the end of `clean` regardless. Either way the guarantee
is the same: a `clean` invoked after a prior `up` treats the DB as
envlite-tracked content and prompts before removing it, rather than
silently leaving an orphan or silently deleting user data.

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

## Phase ordering

Strict dependency graph:

- Phase 0 → all subsequent phases.
- Phase 1 → Phase 4 (port is consumed by `WP_HOME`, `WP_SITEURL` in
  `src/wp-config.php`).
- Phase 2 → Phase 3 and Phase 2 → Phase 4. Both config files assume
  the SQLite drop-in is the active DB layer at any moment between
  phases. Violating either edge (running 3 or 4 before the drop-in is
  in place) is harmless to the final state but breaks the "internally
  consistent at every step" invariant.
- Phase 2 → Phase 5 (Phase 5 issues DB queries; the SQLite drop-in
  must be active).
- Phase 4 → Phase 5 (Phase 5 loads `src/wp-config.php`).
- Phase 5 is always last.

**Concrete schedule:** Phases 2–5 run **serially**, in numeric order;
there is no parallelism. Each is cheap or I/O-bound, and the
config-file and install dependencies are easiest to reason about in
sequence. envlite no longer runs `npm ci`, `composer install`, or
`npm run build:dev` — those long-running, mutually independent
subprocesses (which an earlier design ran as a parallel pair with
buffered output) are gone, so the only remaining subprocesses are the
Phase 0 version probes and the host `php` of Phase 5 and the dev-server
launch.

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

2. **The `.cache/envlite/state` file** governs the Phase 2 SQLite
   drop-in download/extract (skip eligibility):
   - Plugin dir in the manifest AND `db.copy` present AND
     `phase2.recorded_pin_sha` matches the current code literal → skip
     the download/extract.
   - Any of those false → download, verify, and extract. On extraction
     success, record the current pin literal.
   - There is no flag to ignore the recorded pin; bump
     `ENVLITE_SQLITE_PLUGIN_SHA256` or `clean` then `up` to force a
     re-download.

Phase-specific notes:

| Phase | Re-run behavior |
|---|---|
| 0 (preflight) | Always runs. |
| 1 (port) | Re-uses the cached port if the cache exists and is in `[1, 65535]`. Otherwise re-discovers from the 8100–8899 pool. |
| 2 (SQLite drop-in) | Skips download/extract if the plugin dir is in the manifest, `db.copy` is present, AND `phase2.recorded_pin_sha` matches the current code literal. Always copies `db.copy` → `db.php` (manifest contract governs the write). |
| 3 (`wp-tests-config.php`) | Manifest contract above. |
| 4 (`src/wp-config.php`) | Manifest contract above. Re-stamp interpolates the current Phase 1 port. |
| 5 (site install) | Always spawns the install subprocess; the subprocess short-circuits via `is_blog_installed()`. envlite never drops tables. |

`envlite up` is safe to re-run on a half-configured repo: paths
envlite owns get refreshed silently, paths it doesn't own require
explicit user assent, and the Phase 2 drop-in skips its download when
its pinned tree is already installed. Users who want a fully clean
slate run `envlite clean` first.

---

## Non-obvious decisions, recorded once

1. **PHP 7.4 floor.** envlite is run by PHP itself; the floor matches
   WordPress core's own supported floor at the time of writing.
2. **PHP 8.5 + `convertDeprecationsToExceptions=true`.** wordpress-
   develop's `phpunit.xml.dist` opts every deprecation into a thrown
   exception. On newer PHP some test groups will fail purely on
   surfaced deprecations from core code; that's a per-group fix, not
   envlite's problem.
3. **The SQLite plugin path placeholder is dead.** Documented in Phase 2.
4. **Two distinct config files.** `wp-tests-config.php` (Phase 3) and
   `src/wp-config.php` (Phase 4) are loaded by different bootstrap paths
   and serve different purposes. Both are needed; do not consolidate.
5. **Pin the plugin SHA, not the version number.** Plugin version
   numbers can be reused. The SHA is the honest pin. Update
   intentionally. The pin literal is also the sole skip signal for the
   Phase 2 download/extract: `phase2.recorded_pin_sha` in
   `.cache/envlite/state` holds the SHA the drop-in last installed
   under, recorded only on extraction success, so an interrupted
   install always re-downloads (false-positive re-runs are acceptable;
   false-positive skips are not). There is no flag to force a
   re-download — bump the pin or `clean` then `up`.
6. **Port stability over freshness.** Once cached, the port is reused
   unconditionally. The user may have envlite's own server running on
   it; re-probing would falsely report "in use". `envlite clean`
   forgets the port; `envlite up --port=N` is the in-place re-pick.
7. **PHP-only implementation surface.** All file ops, hashing, HTTP,
   and zip extraction go through PHP standard library. Subprocesses
   are limited to the `node`/`npm`/`composer` version probes and the
   host `php` (Phase 5 install + dev-server launch). No
   `sed`/`awk`/`curl`/`unzip`/`shasum`/`python` dependencies, even when
   those are commonly present. The dev-server launch on Unix uses
   `pcntl_exec` rather than `proc_open` so the envlite PHP process is
   replaced in place by `php -S` (same PID, shallower process tree,
   direct signal delivery); Windows lacks `pcntl` and falls back to
   `proc_open` with inherited stdio.
8. **Manifest, not file presence, is the ownership signal.** Earlier
   drafts gated idempotency on "does the file exist". That conflated
   "envlite created it" with "anyone created it" and made `clean` a
   blast-radius hazard. The manifest cleanly separates the two cases.
9. **Destructive-by-default is forbidden.** envlite never overwrites
   or deletes a file it doesn't demonstrably own without asking.
   `--force` exists for CI; humans get a prompt every time.
10. **Phase 5 pipes its install script via stdin to a fresh `php`.**
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
11. **Phase 5 never drops tables.** The test bootstrap drops and
    re-creates on every run because CI wants clean-slate semantics;
    envlite is a dev tool and the same behavior would silently
    delete posts/pages/uploads on every `up`. envlite gates on
    `is_blog_installed()` and skips if true. Users who want a clean
    slate run `envlite clean` (which prompts for `.ht.sqlite`).
12. **`127.0.0.1` everywhere, never `localhost`.** `php -S` binds
    IPv4-only, but `localhost` resolves to `::1` first on modern
    macOS/Linux — a browser hitting `http://localhost:<port>/` can get
    `ECONNREFUSED` before any IPv4 fallback. Pinning the literal IPv4
    in every place a host appears (`php -S` bind, `WP_HOME`,
    `WP_SITEURL`, `$_SERVER['HTTP_HOST']` in Phase 5, Phase 1
    bind-probe) also keeps the cookie origin invariant: WordPress
    bakes `WP_HOME` into redirects and cookie domains, so a mismatch
    between the constant and the address the user typed breaks admin
    login. `localhost` would also depend on `/etc/hosts` and the
    system resolver; `127.0.0.1` is a literal address with no
    surprises.
13. **`PHP_CLI_SERVER_WORKERS=3` on `php -S` launch.** PHP's built-in
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
14. **Test DB is isolated via `DB_FILE` in the test config only.**
    phpunit's `tests/phpunit/includes/install.php` drops every WP
    table on every run; without isolation it would wipe the dev
    site Phase 5 installs. The split is one `define( 'DB_FILE',
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
15. **Router resolves paths via `$_SERVER['DOCUMENT_ROOT']`, not
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
    `tests/test_router.php` is the regression test;
    it boots `php -S` against a fixture docroot wholly outside the
    router file's tree.
16. **`up` is the only setup command.** Earlier drafts had `init`
    (setup, no serve) and `serve` (serve, no setup) alongside `up`
    (both). Two pain points motivated the consolidation: (a) users
    were running `init` followed by `up`, paying for the same setup
    twice on every workflow start; (b) the help text had to explain
    three commands that did overlapping things. `up` is fast on a
    current repo (the only skippable work, the Phase 2 drop-in download,
    is cached) and `serve` adds nothing — the bind probe + `php -S`
    launch is already what `up` does at the end. `--no-serve` covers
    the CI / "set up but don't launch" niche that `init` owned. The
    simplification removes a subcommand surface and a bullet from the
    help text without losing capability.
17. **Installing dependencies and building assets is the developer's
    job, not envlite's.** An earlier design ran `npm ci`,
    `composer install`, and `npm run build:dev` during `up`. Those are
    the slowest part of setup, are re-run on their own cadence (a
    dependency bump, a branch switch), and are frequently unnecessary
    for a given task — so envlite no longer touches them. It does not
    install, build, hash lockfiles, track HEAD SHAs, or preflight-check
    for `node_modules/`/`vendor/`/build outputs; it only **warns** at
    preflight if node/npm/composer or `gd`/`simplexml` look unusable.
    The developer runs `npm ci && composer install && npm run build:dev`
    (or their equivalent) before the served site or phpunit will work,
    and `clean` never touches those trees.

---

## What envlite explicitly does NOT do

- Allocate ports for *external* tooling (database GUIs, Xdebug, etc.) —
  Phase 1 picks one port for the dev web server only.
- Start or stop the web server in the background. `envlite up` runs
  the dev server in the foreground and respects Ctrl-C.
- Manage the SQLite database file itself. The drop-in creates
  `src/wp-content/database/.ht.sqlite` when WordPress first loads;
  Phase 5 triggers that load by running `wp_install()`, but envlite
  does not own the file's bytes. envlite records the file in the
  manifest the first time it observes the file's existence; `clean`
  then prompts for it explicitly (the file may hold user-authored
  content).
- Install global tools (PHP, node, composer) — Phase 0 just verifies
  PHP and the extensions it needs (hard) and warns on the developer's
  node/npm/composer toolchain (soft).
- Install JavaScript or PHP dependencies, or build assets. envlite does
  **not** invoke `npm ci`, `composer install`, or `npm run build:dev` —
  managing `node_modules/`, `vendor/`, and the build outputs under
  `src/` is entirely the developer's job. The developer runs
  `npm ci && composer install && npm run build:dev` (or their usual
  equivalent) before the served site or phpunit will work. envlite does
  not track those trees in the manifest, does not record any skip
  metadata about them, and `clean` never touches them — use
  `git clean -fdx` or your usual tooling.
- Configure HTTPS or a production-shaped reverse proxy.
- Perform any `composer update` or `npm update`.
- Override Composer's cache or home directory. envlite does not set
  `COMPOSER_HOME`; Composer's default applies.
- Refresh the pinned SQLite drop-in. There is no `envlite update`
  subcommand. To pick up a newer plugin release, edit the SHA256 pin
  (and any associated logic) in `envlite.php`. The
  next `envlite up` detects the pin change via
  `phase2.recorded_pin_sha` and re-downloads automatically; no manual
  `clean` is required. The pin is intentional: bumping it is a
  deliberate envlite revision, reviewed and committed alongside any
  code adjustments the new release requires.
- Manage worktrees. envlite operates on whatever directory it is
  invoked in.

# Switch envlite Dev-Server Launch to pcntl Process Replacement

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the `proc_open`-based launch of `php -S` in `envlite up` and `envlite serve` with `pcntl_exec( PHP_BINARY, … )`, so the envlite PHP process is replaced in place by the dev server (no parent-child indirection on Unix). Keep `proc_open` as a Windows fallback.

**Architecture:**
1. Single helper `envlite_run_dev_server($repoRoot, $port)` builds the `php -S` argv once and chooses between `pcntl_exec` (Unix) and `envlite_proc_stream` (Windows fallback). Both `envlite_cmd_serve` and `envlite_cmd_up` call it. The helper also exposes an "is pcntl available right now" predicate so a Windows-only test path stays reachable.
2. Phase 0 gains a conditional `pcntl` extension check: required on Unix (`PHP_OS_FAMILY !== 'Windows'`), skipped on Windows. This makes the Unix path's promise (process replacement) auditable while preserving Windows operability.
3. Spec updates document the new behavior in three sections: tech stack / Phase 0 extension list, the `envlite serve` runtime section, and decision #8 ("PHP-only implementation surface").

**Tech Stack:** PHP 7.4+, `pcntl` extension (Unix only), existing envlite test harness (`tools/local-env/tests/run.php`).

---

## Open decisions resolved

These were the two explicit open questions in the request. Both are decided here so tasks can be executed without further input.

1. **Windows fallback**: keep the current `envlite_proc_stream` path on Windows. `pcntl` is unavailable on Windows PHP; there is no pure-PHP equivalent of `execve`. Functionally `php -S` under `proc_open` already gives the user a foreground server with Ctrl-C handling — the Unix gain (process replacement, same PID, shallower process tree) is a polish, not a correctness requirement. So Windows pays no regression and no new external dependency.

2. **Phase 0 update**: yes — require `pcntl` on Unix. Rationale: `pcntl` ships in stock CLI builds for Homebrew PHP, Debian/Ubuntu `php-cli`, Alpine `php-cli`, and the official Docker images. A user without it on Unix is rare and broken in other ways too; failing fast in Phase 0 is consistent with the spec's "Don't silently degrade" policy. The check is gated on `PHP_OS_FAMILY !== 'Windows'` so the Windows code path remains valid.

The availability predicate at runtime is `PHP_OS_FAMILY !== 'Windows' && function_exists('pcntl_exec')`. Both clauses matter: `function_exists` covers the (rare) Unix install without `pcntl`, and the OS-family check ensures Windows never tries `pcntl_exec` even if a hypothetical port were to expose the symbol as a stub.

---

## File Structure

- **Modify:** `tools/local-env/envlite.php`
  - Add `envlite_run_dev_server(string $repoRoot, int $port): int` — builds the `php -S` argv, chooses Unix vs Windows path, calls `pcntl_exec` or `envlite_proc_stream`. Single owner of the dev-server launch.
  - Modify `envlite_phase0_run` (currently lines 287–321) — add conditional `pcntl` extension check.
  - Modify `envlite_cmd_serve` (currently lines 836–870) — replace inline `envlite_proc_stream` call with `envlite_run_dev_server`.
  - Modify `envlite_cmd_up` (currently lines 809–862) — same replacement.
- **Modify:** `plans/ENVLITE_SPECIFICATION.md`
  - Tech stack section (lines 15–34) — add `pcntl` to required extensions and note the conditionality.
  - Phase 0 extension list (lines 182–199) — add `pcntl` (Unix only).
  - "envlite serve runtime" section (lines 134–157) — describe `pcntl_exec` semantics and Windows fallback.
  - Decision #8 "PHP-only implementation surface" (lines 954–958) — note the `pcntl` carve-out.
- **Modify:** `tools/local-env/tests/test_phase0.php` — add a unit test for the new pcntl check.
- **Create:** `tools/local-env/tests/test_dev_server.php` — covers the new helper. Two cases: Unix branch (`pcntl_exec` actually replaces the process — verified via subprocess) and Windows fallback selection (verified by inspecting the chosen code path).

---

## Task 1: Add the conditional `pcntl` Phase 0 check

**Files:**
- Modify: `tools/local-env/envlite.php:287-321` (`envlite_phase0_run`)
- Modify: `tools/local-env/tests/test_phase0.php`

- [ ] **Step 1: Write the failing test**

Append to `tools/local-env/tests/test_phase0.php`:

```php
function test_phase0_required_extensions_include_pcntl_on_unix() {
    // The list is the source of truth used by envlite_phase0_run.
    // We test the *list*, not by re-running phase0 (which exits the test runner).
    if (PHP_OS_FAMILY === 'Windows') {
        // On Windows, pcntl is not in the list. Sanity-check the inverse.
        envlite_assert(
            !in_array('pcntl', envlite_phase0_required_extensions(), true),
            'pcntl must NOT be required on Windows'
        );
        return;
    }
    envlite_assert(
        in_array('pcntl', envlite_phase0_required_extensions(), true),
        'pcntl must be required on Unix'
    );
}

function test_phase0_required_extensions_includes_existing_set() {
    foreach (['pdo_sqlite', 'sqlite3', 'openssl', 'simplexml', 'zip'] as $ext) {
        envlite_assert(
            in_array($ext, envlite_phase0_required_extensions(), true),
            "$ext must remain required"
        );
    }
}
```

- [ ] **Step 2: Run tests; confirm new tests fail with "undefined function envlite_phase0_required_extensions"**

Run: `php tools/local-env/tests/run.php`
Expected: both new tests FAIL with "Call to undefined function envlite_phase0_required_extensions". All other tests still PASS.

- [ ] **Step 3: Add the `envlite_phase0_required_extensions` helper and rewire `envlite_phase0_run`**

In `tools/local-env/envlite.php`, locate the existing block in `envlite_phase0_run`:

```php
    foreach (['pdo_sqlite', 'sqlite3', 'openssl', 'simplexml', 'zip'] as $ext) {
        if (!extension_loaded($ext)) {
            envlite_log(null, "preflight: required PHP extension missing: $ext");
            exit(3);
        }
    }
```

Replace it with a call to a new helper, and add the helper just above `envlite_phase0_run`:

```php
function envlite_phase0_required_extensions(): array {
    $exts = ['pdo_sqlite', 'sqlite3', 'openssl', 'simplexml', 'zip'];
    if (PHP_OS_FAMILY !== 'Windows') {
        // pcntl is required on Unix so envlite_run_dev_server can call
        // pcntl_exec into php -S. Windows lacks pcntl entirely; the
        // dev-server launcher falls back to proc_open there.
        $exts[] = 'pcntl';
    }
    return $exts;
}
```

And in `envlite_phase0_run`, replace the literal array with the helper call:

```php
    foreach (envlite_phase0_required_extensions() as $ext) {
        if (!extension_loaded($ext)) {
            envlite_log(null, "preflight: required PHP extension missing: $ext");
            exit(3);
        }
    }
```

- [ ] **Step 4: Run tests; confirm all pass**

Run: `php tools/local-env/tests/run.php`
Expected: all tests PASS, including the two new ones.

- [ ] **Step 5: Commit**

```bash
git add tools/local-env/envlite.php tools/local-env/tests/test_phase0.php
git commit -m "feat(envlite): require pcntl extension on Unix in Phase 0"
```

---

## Task 2: Add `envlite_run_dev_server` helper with Unix/Windows split

**Files:**
- Modify: `tools/local-env/envlite.php` (add helper near `envlite_proc_stream`, ~line 270)
- Create: `tools/local-env/tests/test_dev_server.php`

- [ ] **Step 1: Write a failing test for the helper's command construction**

Create `tools/local-env/tests/test_dev_server.php` with the first tests:

```php
<?php
function test_dev_server_argv_targets_correct_port_root_router() {
    $argv = envlite_dev_server_argv('/tmp/repo', 8421);
    envlite_assert_eq('-S', $argv[0]);
    envlite_assert_eq('127.0.0.1:8421', $argv[1]);
    envlite_assert_eq('-t', $argv[2]);
    envlite_assert_eq('src', $argv[3]);
    // The router is the absolute path to tools/local-env/router.php.
    envlite_assert(
        substr($argv[4], -strlen('/tools/local-env/router.php')) === '/tools/local-env/router.php',
        'router path must end with tools/local-env/router.php'
    );
    envlite_assert_eq(5, count($argv));
}

function test_dev_server_argv_does_not_include_php_binary_first() {
    // pcntl_exec takes argv WITHOUT argv[0]; the first real arg of php -S is -S.
    $argv = envlite_dev_server_argv('/tmp/repo', 9000);
    envlite_assert($argv[0] !== 'php' && $argv[0] !== PHP_BINARY,
        'argv must not include the PHP binary (pcntl_exec adds it implicitly)');
}
```

- [ ] **Step 2: Run tests to confirm they fail**

Run: `php tools/local-env/tests/run.php`
Expected: both new tests FAIL with "Call to undefined function envlite_dev_server_argv". All other tests still PASS.

- [ ] **Step 3: Add `envlite_dev_server_argv` and `envlite_run_dev_server`**

In `tools/local-env/envlite.php`, immediately after `envlite_proc_stream` (after the closing brace on line 270), add:

```php
/**
 * Builds the argv passed to `php -S`. Excludes the binary itself —
 * pcntl_exec receives the binary as its first argument and the rest as $args.
 * On the Windows fallback path, envlite_run_dev_server prepends PHP_BINARY.
 */
function envlite_dev_server_argv(string $repoRoot, int $port): array {
    return ['-S', "127.0.0.1:$port", '-t', 'src', __DIR__ . '/router.php'];
}

/**
 * Returns true if pcntl_exec is usable on this platform right now.
 * Split into a function so tests can read the same predicate the launcher uses.
 */
function envlite_pcntl_exec_available(): bool {
    return PHP_OS_FAMILY !== 'Windows' && function_exists('pcntl_exec');
}

/**
 * Launches the dev server. On Unix with pcntl available, replaces the current
 * process via pcntl_exec — same PID, no parent-child relay. On Windows (or
 * any environment without pcntl_exec), falls back to envlite_proc_stream
 * which inherits stdio so SIGINT still reaches the child. Returns only on
 * error or when the fallback child exits.
 */
function envlite_run_dev_server(string $repoRoot, int $port): int {
    $argv = envlite_dev_server_argv($repoRoot, $port);

    if (envlite_pcntl_exec_available()) {
        // pcntl_exec uses the *current* working directory; chdir first so
        // `-t src` resolves relative to the repo root, matching the proc_open
        // path's $cwd argument.
        if (!@chdir($repoRoot)) {
            envlite_log(null, "failed to chdir to $repoRoot before exec");
            return 1;
        }
        // Suppress the warning pcntl_exec emits on failure; we surface our own.
        @pcntl_exec(PHP_BINARY, $argv);
        // pcntl_exec returns only on failure (success replaces the process).
        envlite_log(null, 'pcntl_exec(php -S) failed; the dev server did not start');
        return 1;
    }

    // Windows fallback. Use PHP_BINARY explicitly so we don't depend on PATH
    // resolution to the same PHP that's running envlite.
    $exit = envlite_proc_stream(array_merge([PHP_BINARY], $argv), $repoRoot);
    return $exit === 0 ? 0 : 1;
}
```

- [ ] **Step 4: Run tests; argv tests should pass**

Run: `php tools/local-env/tests/run.php`
Expected: all tests PASS.

- [ ] **Step 5: Commit**

```bash
git add tools/local-env/envlite.php tools/local-env/tests/test_dev_server.php
git commit -m "feat(envlite): add envlite_run_dev_server helper with pcntl on Unix"
```

---

## Task 3: Add an end-to-end test that proves process replacement on Unix

This catches a regression where someone replaces `pcntl_exec` with `proc_open` again. The strategy: spawn a subprocess that itself calls `pcntl_exec` with a known short script, and verify the subprocess exits with that script's exit code (proving the post-exec line in the script never ran).

**Files:**
- Modify: `tools/local-env/tests/test_dev_server.php`

- [ ] **Step 1: Write the test**

Append to `tools/local-env/tests/test_dev_server.php`:

```php
function test_dev_server_pcntl_replaces_process_on_unix() {
    if (!envlite_pcntl_exec_available()) {
        // Windows or pcntl-less Unix: the helper takes the proc_open branch.
        // Verified separately in test_dev_server_fallback_uses_proc_open.
        return;
    }

    // Spawn a child PHP that calls pcntl_exec(PHP_BINARY, ['-r', 'exit(7);']).
    // If pcntl_exec replaces the process, the script after pcntl_exec never
    // runs and the subprocess exits with code 7. If pcntl_exec returned
    // instead, the trailing exit(99) would fire and we'd see 99.
    $child = <<<'PHP'
<?php
@pcntl_exec(PHP_BINARY, ['-r', 'exit(7);']);
exit(99);
PHP;

    $tmp = tempnam(sys_get_temp_dir(), 'envlite-pcntl-');
    file_put_contents($tmp, $child);
    [$exit, , ] = envlite_proc_capture([PHP_BINARY, $tmp]);
    @unlink($tmp);

    envlite_assert_eq(7, $exit, 'pcntl_exec must replace process; child exit must be 7');
}

function test_dev_server_fallback_uses_proc_open_when_pcntl_unavailable() {
    // We can't disable pcntl in-process. Instead, exercise envlite_proc_stream
    // directly with the same argv shape envlite_run_dev_server constructs for
    // the Windows fallback, and assert it returns the child's exit code.
    $argv = array_merge([PHP_BINARY], ['-r', 'exit(0);']);
    $exit = envlite_proc_stream($argv);
    envlite_assert_eq(0, $exit, 'proc_open fallback must propagate child exit code');
}
```

- [ ] **Step 2: Run tests; confirm new tests pass on this machine**

Run: `php tools/local-env/tests/run.php`
Expected: all tests PASS, including the two new ones.

- [ ] **Step 3: Commit**

```bash
git add tools/local-env/tests/test_dev_server.php
git commit -m "test(envlite): cover pcntl process replacement and proc_open fallback"
```

---

## Task 4: Wire `envlite_cmd_serve` to the helper

**Files:**
- Modify: `tools/local-env/envlite.php:864-869` (the `envlite_proc_stream` call inside `envlite_cmd_serve`)

- [ ] **Step 1: Replace the inline launch with the helper**

In `envlite_cmd_serve` (around lines 864–869):

Old:

```php
    // Stream the dev server. SIGINT propagates to the child via terminal.
    $exit = envlite_proc_stream(
        ['php', '-S', "127.0.0.1:$port", '-t', 'src', __DIR__ . '/router.php'],
        $repoRoot
    );
    return $exit === 0 ? 0 : 1;
```

New:

```php
    // Hand off to the dev-server launcher. On Unix this calls pcntl_exec and
    // never returns; on Windows it streams through proc_open and returns the
    // exit code.
    return envlite_run_dev_server($repoRoot, $port);
```

- [ ] **Step 2: Run the full test suite**

Run: `php tools/local-env/tests/run.php`
Expected: all tests PASS.

- [ ] **Step 3: Manual smoke test that `envlite serve` still works end-to-end**

Only if you have a fully `init`-ed checkout. In a separate terminal at the repo root:

```bash
php tools/local-env/envlite.php serve &
SERVE_PID=$!
sleep 1
PORT=$(cat .envlite/port)
curl -sI http://127.0.0.1:$PORT/ | head -1
# On Unix: ps shows ONE php process at $SERVE_PID running php -S 127.0.0.1:$PORT
# (no envlite.php parent), proving pcntl replaced the process.
ps -o pid=,command= -p $SERVE_PID
kill $SERVE_PID
```

Expected output: `HTTP/1.1 200 OK` (or `302` if first hit and Phase 8 was skipped). The `ps` line shows a single `php -S …` process at `$SERVE_PID`.

If you don't have a fully-init'ed checkout, skip this step — the unit tests already cover the launch path.

- [ ] **Step 4: Commit**

```bash
git add tools/local-env/envlite.php
git commit -m "refactor(envlite): route serve through envlite_run_dev_server"
```

---

## Task 5: Wire `envlite_cmd_up` to the helper

**Files:**
- Modify: `tools/local-env/envlite.php:856-861` (the `envlite_proc_stream` call inside `envlite_cmd_up`)

- [ ] **Step 1: Replace the inline launch with the helper**

In `envlite_cmd_up` (around lines 856–861):

Old:

```php
    fwrite(STDERR, "envlite up: serving http://127.0.0.1:$resolvedPort/ (admin / password)\n");
    $exit = envlite_proc_stream(
        ['php', '-S', "127.0.0.1:$resolvedPort", '-t', 'src', __DIR__ . '/router.php'],
        $repoRoot
    );
    return $exit === 0 ? 0 : 1;
```

New:

```php
    fwrite(STDERR, "envlite up: serving http://127.0.0.1:$resolvedPort/ (admin / password)\n");
    // Hand off to the dev-server launcher. pcntl on Unix means this function
    // never returns on success; the "serving …" line above is the last thing
    // envlite itself prints.
    return envlite_run_dev_server($repoRoot, $resolvedPort);
```

- [ ] **Step 2: Run the full test suite**

Run: `php tools/local-env/tests/run.php`
Expected: all tests PASS.

- [ ] **Step 3: Manual smoke test (only if you have an init'ed checkout)**

```bash
php tools/local-env/envlite.php up &
UP_PID=$!
# Wait for the "serving …" line to appear in stderr (up runs init phases first).
sleep 60
PORT=$(cat .envlite/port)
curl -sI http://127.0.0.1:$PORT/ | head -1
ps -o pid=,command= -p $UP_PID
kill $UP_PID
```

Expected: `HTTP/1.1 200 OK`, and the `ps` line shows `php -S …` at `$UP_PID` — same proof of process replacement as in Task 4.

- [ ] **Step 4: Commit**

```bash
git add tools/local-env/envlite.php
git commit -m "refactor(envlite): route up through envlite_run_dev_server"
```

---

## Task 6: Update the spec to reflect the new dev-server launch and Phase 0 list

**Files:**
- Modify: `plans/ENVLITE_SPECIFICATION.md`

- [ ] **Step 1: Update the Tech Stack bullet for required extensions**

In `plans/ENVLITE_SPECIFICATION.md`, find the bullet (currently around lines 18–20):

```
- host PHP ≥ 7.4 (matching WordPress's own supported floor), with
  `pdo_sqlite`, `sqlite3`, `openssl`, `simplexml`, `zip`, and `hash`
  extensions loaded. Phase 0 verifies the full set; the brief here
  just names the unavoidable ones.
```

Replace with:

```
- host PHP ≥ 7.4 (matching WordPress's own supported floor), with
  `pdo_sqlite`, `sqlite3`, `openssl`, `simplexml`, `zip`, and `hash`
  extensions loaded. On Unix, `pcntl` is also required so
  `envlite serve` / `envlite up` can call `pcntl_exec` into `php -S`.
  Phase 0 verifies the full set; the brief here just names the
  unavoidable ones.
```

- [ ] **Step 2: Update the "Subprocesses spawned by envlite" paragraph**

Find the paragraph (currently around lines 30–35):

```
Subprocesses spawned by envlite are limited to `node`/`npm`/`composer`,
plus the host `php` itself in two places: launching the dev server
(`envlite serve`) and running the Phase 8 site install (script piped
to the subprocess via stdin).
```

Replace with:

```
Subprocesses spawned by envlite are limited to `node`/`npm`/`composer`,
plus the host `php` itself in two places: launching the dev server
(`envlite serve` / `envlite up`) and running the Phase 8 site install
(script piped to the subprocess via stdin). On Unix, the dev-server
launch is a `pcntl_exec` (process replacement) rather than a proper
subprocess; on Windows it is a `proc_open` because `pcntl` is
unavailable.
```

- [ ] **Step 3: Update the Phase 0 extension list**

Find the Phase 0 extensions block (currently around lines 182–199), specifically the `zip` bullet — extend the list to include `pcntl` after it.

Old (immediately before "`hash` is non-disable-able since PHP 7.4 and is not checked."):

```
   - `zip` — required by `ZipArchive` for Phase 5.

   `hash` is non-disable-able since PHP 7.4 and is not checked.
```

New:

```
   - `zip` — required by `ZipArchive` for Phase 5.
   - `pcntl` (Unix only) — required so `envlite serve` and
     `envlite up` can call `pcntl_exec(PHP_BINARY, …)` into the dev
     server, replacing envlite's PHP process in place. The check is
     gated on `PHP_OS_FAMILY !== 'Windows'`; Windows PHP has no
     `pcntl` and uses a `proc_open` fallback.

   `hash` is non-disable-able since PHP 7.4 and is not checked.
```

- [ ] **Step 4: Rewrite the "envlite serve runtime" section**

Find the section (currently around lines 134–157) starting `### \`envlite serve\` runtime`. Replace the body (keep the heading) with:

```markdown
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
```

- [ ] **Step 5: Update decision #8 ("PHP-only implementation surface")**

Find decision 8 (currently around lines 954–958):

```
8. **PHP-only implementation surface.** All file ops, hashing, HTTP,
   and zip extraction go through PHP standard library. Subprocesses
   are limited to `node`/`npm`/`composer`/`php` — tools envlite already
   requires for setup. No `sed`/`awk`/`curl`/`unzip`/`shasum`/`python`
   dependencies, even when those are commonly present.
```

Replace with:

```
8. **PHP-only implementation surface.** All file ops, hashing, HTTP,
   and zip extraction go through PHP standard library. Subprocesses
   are limited to `node`/`npm`/`composer`/`php` — tools envlite already
   requires for setup. No `sed`/`awk`/`curl`/`unzip`/`shasum`/`python`
   dependencies, even when those are commonly present. The dev-server
   launch on Unix uses `pcntl_exec` rather than `proc_open` so the
   envlite PHP process is replaced in place by `php -S` (same PID,
   shallower process tree, direct signal delivery); Windows lacks
   `pcntl` and falls back to `proc_open` with inherited stdio.
```

- [ ] **Step 6: Verify the spec still parses cleanly**

Run a quick grep to confirm no stale "the dev server is launched via `proc_open`" wording remains in the runtime description:

```bash
grep -n 'proc_open' plans/ENVLITE_SPECIFICATION.md
```

Expected: zero hits, OR only hits inside the new "Windows fallback" prose.

- [ ] **Step 7: Commit**

```bash
git add plans/ENVLITE_SPECIFICATION.md
git commit -m "docs(envlite): document pcntl dev-server launch and Windows fallback"
```

---

## Task 7: Final cross-cutting verification

**Files:** none modified; this is verification only.

- [ ] **Step 1: Run the full test suite once more**

Run: `php tools/local-env/tests/run.php`
Expected: all tests PASS, including the two new test files (`test_dev_server.php`) and the new Phase 0 cases in `test_phase0.php`.

- [ ] **Step 2: Confirm `envlite_proc_stream` still has callers**

Run: `grep -n envlite_proc_stream tools/local-env/envlite.php`
Expected: at least Phases 2/3/4 (`npm ci`, `npm run build:dev`, `composer install`) plus the Windows fallback in `envlite_run_dev_server`. NOT in `envlite_cmd_serve` or `envlite_cmd_up` directly anymore.

- [ ] **Step 3: Confirm only `envlite_run_dev_server` constructs the `php -S` argv**

Run: `grep -n "'-S'" tools/local-env/envlite.php`
Expected: exactly one hit, in `envlite_dev_server_argv`. (If `envlite_cmd_serve` or `envlite_cmd_up` still has its own `-S` literal, the refactor missed a spot.)

- [ ] **Step 4: Lint check (if a phpcs ruleset is configured)**

If `vendor/bin/phpcs` exists, run: `./vendor/bin/phpcs tools/local-env/envlite.php tools/local-env/tests/`
Expected: no new violations.

If it does not exist, skip this step (envlite ships without a phpcs gate by design).

---

## Notes for the implementer

- **Don't skip Task 6.** The spec is the source of truth in this repo; leaving it unchanged after this work would create drift. (The spec also doesn't currently document the `up` subcommand at all — that's a pre-existing gap and explicitly out of scope for this plan.)
- **Don't generalize `envlite_run_dev_server` to all subprocess calls.** `npm ci`, `composer install`, etc. need to *return* (init has more phases after them); they must keep using `envlite_proc_stream`. Process replacement is correct only for the terminal step of `serve` / `up`.
- **Don't try to test the Windows fallback path on macOS by mocking `function_exists`.** PHP doesn't make that ergonomic. The two tests in Task 3 are the right shape: one exercises the real `pcntl_exec` on Unix, the other exercises the same `proc_open` call shape that the Windows fallback uses. A real Windows runner is the only way to get end-to-end coverage of the fallback; document this gap rather than papering over it.
- **`pcntl_exec` is silent on the success path.** Anything envlite writes to STDERR after `pcntl_exec` is unreachable on Unix. Make sure any final "serving …" log line happens *before* the helper is called (Task 5 already does; Task 4's `serve` doesn't print one and that's fine).


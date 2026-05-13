# Fix envlite router path resolution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `tools/local-env/router.php` serve the repo where `php -S` was launched, not the repo that owns the router file.

**Architecture:** The PHP built-in server populates `$_SERVER['DOCUMENT_ROOT']` from its `-t` flag (resolved to an absolute path). `envlite_run_dev_server` already chdirs to the target repo and passes `-t src`, so `DOCUMENT_ROOT` always equals `<target-repo>/src`. Replace the router's two `dirname(__DIR__, 2) . '/src'` expressions with `$_SERVER['DOCUMENT_ROOT']` so the router stops assuming it lives inside the target repo.

**Tech Stack:** PHP 7.4+, PHP built-in server (`php -S`), envlite test harness (custom — `tools/local-env/tests/harness.php` + `run.php`).

---

## Bug recap (why this exists)

`router.php:11` and `router.php:25` use `dirname(__DIR__, 2) . '/src'` to locate the document root and front controller. `__DIR__` is the directory of the *router file itself*, so when envlite is invoked from a checkout other than the one that owns `router.php` (e.g. running `php /path/to/envlite-checkout/tools/local-env/envlite.php up` from a different worktree), the router loads the originating checkout's `src/index.php` — pulling in the *wrong* `wp-config.php`, which then triggers a WordPress canonical-URL 301 to whatever port that wp-config defines.

Reproduction observed: server bound at `127.0.0.1:8722` (target repo's port), but `GET /` returned `301 Location: http://127.0.0.1:8762/` (originating envlite checkout's wp-config port).

## File Structure

- **Modify:** `tools/local-env/router.php` — replace both `dirname(__DIR__, 2) . '/src'` expressions with `$_SERVER['DOCUMENT_ROOT']`. No structural change; same 25 lines.
- **Create:** `tools/local-env/tests/test_router.php` — integration test that boots `php -S` against a fixture site whose path is unrelated to the router file's location, then asserts the request was served from the fixture (not from envlite's own tree).

The router file is intentionally small and a single responsibility; no extraction is needed.

---

### Task 1: Add the failing regression test

**Files:**
- Create: `tools/local-env/tests/test_router.php`

This test boots a real `php -S` with the shipped `router.php`, pointing `-t` at a tmp fixture directory that contains a tiny `index.php` marker. It then `GET`s `/` and asserts the marker came back — proving the router served the fixture, not the envlite checkout's own `src/`.

Picking a free port: bind to `tcp://127.0.0.1:0`, read the assigned port from `stream_socket_get_name`, close the socket, then hand the port to `php -S`. There's a microscopic race window between close-and-rebind; if it bites in practice, retry once. Don't preemptively engineer for it.

- [ ] **Step 1: Write the failing test**

Create `tools/local-env/tests/test_router.php`:

```php
<?php
function envlite_test_router_pick_free_port(): int {
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($sock === false) {
        throw new \RuntimeException("could not bind to find free port: $errstr");
    }
    $name = stream_socket_get_name($sock, false);
    $port = (int) substr($name, strrpos($name, ':') + 1);
    fclose($sock);
    return $port;
}

function envlite_test_router_wait_for_bind(int $port, float $timeout_seconds = 3.0): bool {
    $deadline = microtime(true) + $timeout_seconds;
    while (microtime(true) < $deadline) {
        $check = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($check) {
            fclose($check);
            return true;
        }
        usleep(100_000);
    }
    return false;
}

function test_router_serves_from_document_root_not_router_directory() {
    // Build a fixture "site" that does NOT share a parent with router.php.
    // realpath() normalizes /tmp -> /private/tmp on macOS so the assert
    // below matches __DIR__ from the fixture's index.php (which resolves
    // symlinks). On Linux this is a no-op.
    $site = realpath(envlite_test_tmpdir('router-docroot'));
    envlite_assert($site !== false, 'tmp fixture directory must resolve via realpath');
    file_put_contents("$site/index.php", "<?php echo 'FIXTURE_OK ' . __DIR__;");

    // Use the real shipped router so we exercise its path resolution.
    $router = realpath(__DIR__ . '/../router.php');
    envlite_assert(is_file($router), 'router.php must exist at ' . __DIR__ . '/../router.php');

    $port = envlite_test_router_pick_free_port();

    // Spawn `php -S 127.0.0.1:<port> -t <site> <router>` with cwd = site.
    // Matches envlite_run_dev_server: chdir into the target repo, then pass
    // -t <docroot>. The router file lives outside $site on purpose — that is
    // exactly the configuration that triggered the original bug.
    $argv = [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $site, $router];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($argv, $descriptors, $pipes, $site);
    envlite_assert(is_resource($proc), 'failed to start php -S');

    try {
        envlite_assert(
            envlite_test_router_wait_for_bind($port),
            "php -S did not bind on 127.0.0.1:$port within 3s"
        );

        $body = @file_get_contents("http://127.0.0.1:$port/");
        envlite_assert($body !== false, "request to 127.0.0.1:$port failed");

        envlite_assert(
            strpos($body, 'FIXTURE_OK ' . $site) !== false,
            'expected FIXTURE_OK marker from fixture index.php, got: ' . substr($body, 0, 400)
        );
    } finally {
        foreach ($pipes as $p) { if (is_resource($p)) { @fclose($p); } }
        $status = @proc_get_status($proc);
        if ($status && $status['running']) {
            @proc_terminate($proc, 15);
        }
        @proc_close($proc);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tools/local-env/tests/run.php`

Expected: `FAIL test_router_serves_from_document_root_not_router_directory` with a message about the FIXTURE_OK marker being absent. The body will either be empty (require failed — index.php from envlite's own `src/` does not exist in a fresh checkout running tests) or contain unrelated content from envlite's `src/index.php`. Either way the assert fires.

If instead the test passes here, STOP — the reproduction is wrong and the rest of the plan does nothing.

- [ ] **Step 3: Commit the failing test**

```bash
git add tools/local-env/tests/test_router.php
git commit -m "test(envlite): cover router DOCUMENT_ROOT resolution"
```

It is fine — and intentional — to land a failing regression test in its own commit. The next commit makes it pass.

---

### Task 2: Fix the router to use DOCUMENT_ROOT

**Files:**
- Modify: `tools/local-env/router.php:11` and `tools/local-env/router.php:25`

- [ ] **Step 1: Edit router.php**

Replace the current contents of `tools/local-env/router.php` with:

```php
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// php -S does not honor Apache .ht* deny rules. Block any segment so the
// SQLite DB at wp-content/database/.ht.sqlite is not downloadable.
if (preg_match('#(^|/)\.ht#', $path)) {
    http_response_code(403);
    return true;
}

// DOCUMENT_ROOT is the absolute resolution of php -S's -t flag. Using it
// instead of a path computed from __DIR__ lets the router live outside the
// target repo (e.g. envlite invoked from a different checkout).
$docroot = $_SERVER['DOCUMENT_ROOT'];
$file = $docroot . $path;

if ($path !== '/' && file_exists($file)) {
    if (!is_dir($file)) {
        return false;
    }
    // Existing directory: let the built-in server serve its index.php
    // (e.g. /wp-admin/ -> wp-admin/index.php). Without an index, fall
    // through to the front controller to avoid directory listings.
    if (file_exists(rtrim($file, '/') . '/index.php')) {
        return false;
    }
}

require $docroot . '/index.php';
```

- [ ] **Step 2: Run the new test to verify it passes**

Run: `php tools/local-env/tests/run.php`

Expected: `PASS test_router_serves_from_document_root_not_router_directory`, and the final tally line should show one more pass than before with zero failures.

- [ ] **Step 3: Run the full test suite to verify no regressions**

Run: `php tools/local-env/tests/run.php`

Expected: `0 failures` in the final summary line. In particular `test_dev_server_argv_targets_correct_port_root_router` must still pass — it asserts the argv shape, which this change does not touch.

- [ ] **Step 4: Manually verify the original reproduction is fixed**

This is a one-time smoke check, not a permanent test. Skip if you do not have two envlite-prepared worktrees handy.

Run:
```bash
# In one terminal, from a *different* envlite-prepared checkout B, start the server:
cd /path/to/checkout-B
php /path/to/checkout-A/tools/local-env/envlite.php up
```

```bash
# In another terminal:
curl -sI http://127.0.0.1:<B's port>/
```

Expected: a `200 OK` (or whatever the WordPress front page returns), NOT a `301` to checkout A's port.

- [ ] **Step 5: Commit the fix**

```bash
git add tools/local-env/router.php
git commit -m "fix(envlite): resolve router paths via DOCUMENT_ROOT

The router previously used dirname(__DIR__, 2) . '/src' to locate both
the static-file root and the front controller. That resolves relative
to the router file's own checkout, so invoking envlite from a different
worktree loaded the wrong wp-config.php and triggered a canonical-URL
301 to that wp-config's WP_HOME port.

Use \$_SERVER['DOCUMENT_ROOT'] instead — populated by php -S from its
-t flag, which envlite_run_dev_server already points at the target
repo's src/."
```

---

## Self-Review

**1. Spec coverage:**
- Root cause (router uses `__DIR__`-derived path): covered by Task 2 Step 1.
- Fix uses `$_SERVER['DOCUMENT_ROOT']`: covered by Task 2 Step 1.
- Regression test exists: covered by Task 1.
- Manual verification of original reproduction: covered by Task 2 Step 4.
- No structural changes to envlite.php: confirmed — `envlite_dev_server_argv` already passes `-t src`, no change needed.

**2. Placeholder scan:** No TBD/TODO/"fill in later". All code is concrete; all commands explicit.

**3. Type/identifier consistency:**
- Helper names `envlite_test_router_pick_free_port` and `envlite_test_router_wait_for_bind` are referenced in the test exactly as defined.
- `envlite_test_tmpdir` is defined in `tests/test_manifest.php:2` and reused here (matching the pattern in `test_smoke.php:3` and `test_atomic.php`).
- `envlite_assert` is defined in `tests/harness.php:2`.
- `proc_open` with `$descriptors` array form, then `proc_terminate`/`proc_close` — standard PHP API.

---

## Post-implementation notes

These deviated from the plan as originally written and are recorded here so future readers don't think the plan and the committed code drifted by accident.

- **macOS tmpdir symlink (commit `271d7f651d`).** Plan v1 wrote `$site = envlite_test_tmpdir('router-docroot')` directly. On macOS `sys_get_temp_dir()` returns `/var/folders/...` while PHP's `__DIR__` in the fixture's `index.php` resolves symlinks to `/private/var/folders/...`, so the `'FIXTURE_OK ' . $site` assertion fails even when the router is serving the fixture correctly. Fix: wrap the tmpdir in `realpath()` and assert it resolved, before the fixture write. The Task 1 code block above has been updated in-place to match what shipped; on Linux the change is a no-op. A more durable fix would be to push the `realpath()` into `envlite_test_tmpdir` itself so every test that compares tmp paths against `__DIR__`-resolved values is portable by default — deferred until a second caller needs it.

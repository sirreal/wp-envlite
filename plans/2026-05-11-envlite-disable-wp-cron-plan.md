# envlite — disable WP-Cron by default — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** After `envlite init`, the rendered `src/wp-config.php` defines `DISABLE_WP_CRON` as `true`, so `spawn_cron()` no longer fires a loopback request on every front-end hit served by `envlite serve` / `envlite up`.

**Architecture:** One additional line injected by `envlite_phase7_render()` in the existing inject block, anchored on the same `/* That's all, stop editing! Happy publishing. */` marker. No new files, no new flags, no manifest schema changes. Spec text in `plans/ENVLITE_SPECIFICATION.md` updated to match.

**Tech Stack:** PHP 7.4+, the existing test harness at `tools/local-env/tests/`, `envlite_phase7_render` / `envlite_phase7_install` in `tools/local-env/envlite.php`.

**Design doc:** `plans/2026-05-11-envlite-disable-wp-cron-design.md`.

---

## Background for an engineer with zero context

- envlite is a PHP CLI at `tools/local-env/envlite.php` that brings a clean wordpress-develop checkout to a runnable state — see `plans/ENVLITE_SPECIFICATION.md` for the full spec.
- Phase 7 (`envlite_phase7_render`, ~line 650) reads `wp-config-sample.php`, replaces DB constants, optionally swaps in fresh salts, injects two `define()` lines (`WP_HOME` / `WP_SITEURL`) immediately before the `/* That's all, stop editing! */` marker, then writes the result to `src/wp-config.php`.
- WordPress's pseudo-cron mechanism fires `spawn_cron()` on every front-end HTTP request: a non-blocking loopback POST to `wp-cron.php`. Because `php -S` (envlite's runtime) serializes requests by default, that loopback is paid for on every page load with no benefit on a dev box, and creates head-of-line stalls when it is finally serviced. The fix is to define `DISABLE_WP_CRON` as `true` in the runtime config.
- Phase 6 (`wp-tests-config.php`) is intentionally **not** changed — phpunit does not run inside an HTTP request lifecycle, so `spawn_cron()` is never invoked from a test run, and defining `DISABLE_WP_CRON` there would only risk interfering with cron-related tests.

The codebase ships its own tiny test harness — there's no PHPUnit for envlite itself. Each test is a global function whose name starts with `test_` in `tools/local-env/tests/test_*.php`; `php tools/local-env/tests/run.php` discovers and runs them. Assertions are `envlite_assert` / `envlite_assert_eq` (defined in `tools/local-env/tests/harness.php`).

---

## File structure

| File | Action | Responsibility |
|---|---|---|
| `tools/local-env/envlite.php` | Modify (function `envlite_phase7_render` ~line 650) | Add `DISABLE_WP_CRON` to the inject block. |
| `tools/local-env/tests/test_phase7.php` | Modify | New unit test asserting the line is present and positioned correctly. |
| `plans/ENVLITE_SPECIFICATION.md` | Modify | Update Phase 7 step 5 inject block and add a "Why `DISABLE_WP_CRON` matters" paragraph. |

No new files. No file splits.

---

## Task 1: Pre-implementation sanity checks

Two read-only checks the design depends on. Each is a 30-second confirmation.

**Files:** read-only.

- [ ] **Step 1: Confirm `wp-config-sample.php` does not already define `DISABLE_WP_CRON`.**

Run:
```
grep -nE "DISABLE_WP_CRON|ALTERNATE_WP_CRON|WP_CRON" wp-config-sample.php
```

Expected: no output. (If the upstream sample has gained a cron-related define, the plan needs a rethink — the new inject would create a duplicate. Stop and reassess.)

- [ ] **Step 2: Confirm the marker still appears exactly once in `wp-config-sample.php`.**

Run:
```
grep -c "That's all, stop editing" wp-config-sample.php
```

Expected: `1`. (`envlite_phase7_render` already asserts this at runtime, but a hard divergence at the sample level would force a redesign of Phase 7's anchor before this plan can proceed.)

No commit for this task — it's read-only verification.

---

## Task 2: Add the failing test, then implement the inject

Standard TDD: write the assertion, run the suite to confirm it fails, change `envlite_phase7_render`, run the suite to confirm it passes, commit. The whole task is a single commit — test plus implementation — because the test asserts a property of `envlite_phase7_render`'s output and there is nothing for the test to anchor to until the implementation lands.

**Files:**
- Modify: `tools/local-env/tests/test_phase7.php`
- Modify: `tools/local-env/envlite.php` (function `envlite_phase7_render` ~line 696–697)

- [ ] **Step 1: Write the failing test.**

Append to `tools/local-env/tests/test_phase7.php`:

```php
function test_phase7_render_injects_disable_wp_cron_before_marker() {
    $sample = file_get_contents(dirname(__DIR__, 3) . '/wp-config-sample.php');
    $out = envlite_phase7_render($sample, 8421, null);
    $cron   = "define( 'DISABLE_WP_CRON', true );";
    $site   = "define( 'WP_SITEURL', 'http://127.0.0.1:8421' );";
    $marker = "/* That's all, stop editing! Happy publishing. */";
    // Exactly one occurrence of the new define.
    envlite_assert_eq(1, substr_count($out, $cron));
    // Positioned after WP_SITEURL and before the marker.
    envlite_assert(strpos($out, $cron) > strpos($out, $site), 'DISABLE_WP_CRON must be after WP_SITEURL');
    envlite_assert(strpos($out, $cron) < strpos($out, $marker), 'DISABLE_WP_CRON must be before marker');
}
```

- [ ] **Step 2: Run the suite to verify the new test fails and nothing else regresses.**

Run:
```
php tools/local-env/tests/run.php
```

Expected: every other test still passes; the new test fails with an assertion message from the `envlite_assert_eq(1, substr_count(...))` line because the rendered output does not yet contain the `DISABLE_WP_CRON` define.

- [ ] **Step 3: Implement the inject in `envlite_phase7_render`.**

In `tools/local-env/envlite.php`, locate the existing inject block in `envlite_phase7_render` (currently around lines 696–697):

```php
    $inject = "define( 'WP_HOME',    'http://127.0.0.1:$port' );\n"
            . "define( 'WP_SITEURL', 'http://127.0.0.1:$port' );\n\n";
```

Replace it with:

```php
    $inject = "define( 'WP_HOME',    'http://127.0.0.1:$port' );\n"
            . "define( 'WP_SITEURL', 'http://127.0.0.1:$port' );\n"
            . "define( 'DISABLE_WP_CRON', true );\n\n";
```

The trailing `\n\n` (the blank line separating the inject block from the marker) is preserved exactly as before.

- [ ] **Step 4: Run the suite to verify everything passes.**

Run:
```
php tools/local-env/tests/run.php
```

Expected: every test passes, including the new `test_phase7_render_injects_disable_wp_cron_before_marker`. The existing `test_phase7_render_injects_wp_home_siteurl_before_marker`, `test_phase7_render_substitutes_db_constants`, `test_phase7_render_replaces_salts_when_provided`, `test_phase7_render_keeps_sample_salts_when_null_provided`, `test_phase7_render_treats_salts_as_literal_not_backreferences`, and `test_phase7_render_normalizes_crlf_in_sample` must all still pass — the change is purely additive.

- [ ] **Step 5: Commit.**

```bash
git add tools/local-env/envlite.php tools/local-env/tests/test_phase7.php
git commit -m "feat(envlite): disable WP-Cron by default in Phase 7 runtime config"
```

---

## Task 3: Update the specification

The spec is the source of truth for envlite's behavior. The change to Phase 7's inject block and the "Why ... matters" paragraph need to land alongside the code so the spec doesn't drift.

**Files:**
- Modify: `plans/ENVLITE_SPECIFICATION.md` (Phase 7 section, ~lines 627–648)

- [ ] **Step 1: Update Phase 7 step 5's inject block.**

In `plans/ENVLITE_SPECIFICATION.md`, find this passage (Phase 7, step 5):

```
5. Locate the literal marker
   `/* That's all, stop editing! Happy publishing. */` (appears exactly
   once in the sample) and inject the following two lines immediately
   *before* it, separated by a blank line:

   ```
   define( 'WP_HOME',    'http://127.0.0.1:<PORT>' );
   define( 'WP_SITEURL', 'http://127.0.0.1:<PORT>' );
   ```

   `<PORT>` is the value from Phase 1.
```

Replace it with:

```
5. Locate the literal marker
   `/* That's all, stop editing! Happy publishing. */` (appears exactly
   once in the sample) and inject the following three lines immediately
   *before* it, separated by a blank line:

   ```
   define( 'WP_HOME',    'http://127.0.0.1:<PORT>' );
   define( 'WP_SITEURL', 'http://127.0.0.1:<PORT>' );
   define( 'DISABLE_WP_CRON', true );
   ```

   `<PORT>` is the value from Phase 1.
```

- [ ] **Step 2: Add a "Why `DISABLE_WP_CRON` matters" paragraph.**

In `plans/ENVLITE_SPECIFICATION.md`, find this paragraph (immediately after the `**Outputs:** src/wp-config.php.` line of Phase 7):

```
**Why `WP_HOME` / `WP_SITEURL` matter:** WordPress generates absolute
URLs in markup (admin links, redirects, REST endpoints). If they don't
match the listening address (`http://127.0.0.1:<port>`), `wp-admin`
redirects loop and asset URLs break. They go in the runtime config; the
phpunit config doesn't care.
```

Insert immediately after it, as a new paragraph:

```
**Why `DISABLE_WP_CRON` matters:** WordPress runs pseudo-cron via
`spawn_cron()` on every front-end HTTP request — a non-blocking
loopback POST to `wp-cron.php`. envlite's runtime is PHP's built-in
dev server (`php -S`), which serializes requests by default, so every
front-end hit pays the cost of opening the loopback connection plus
`spawn_cron()`'s send/recv timeout, and the loopback itself stalls
the next browser request while it runs. `DISABLE_WP_CRON = true`
suppresses `spawn_cron()` entirely; cron is not needed on a dev box.
The phpunit config does not set this — phpunit runs outside an HTTP
request lifecycle, so `spawn_cron()` never fires from tests.
```

- [ ] **Step 3: Commit.**

```bash
git add plans/ENVLITE_SPECIFICATION.md
git commit -m "docs(envlite): document Phase 7 DISABLE_WP_CRON in spec"
```

---

## Task 4: End-to-end verification on this checkout

Manual sequence (~2 minutes). Confirms the new define lands in the actual rendered file and that re-running `init` is a silent re-stamp on a previously-envlite-owned `src/wp-config.php`.

**Files:** none modified.

**Prerequisite:** working `node` ≥ 20.10 / `npm` ≥ 10.2.3 / `composer` ≥ 2 / PHP ≥ 7.4 with `pdo_sqlite`, `sqlite3`, `openssl`, `simplexml`, `zip`, `pcntl` (Unix). If any are missing, Phase 0 will fail-fast and tell you which.

- [ ] **Step 1: Reset to a known-clean state.**

Run:
```
php tools/local-env/envlite.php clean --force
```

Expected: exit 0, `.envlite/` and envlite-managed files removed. If the checkout has never been `init`-ed, this is a no-op.

- [ ] **Step 2: Run a fresh `init`.**

Run:
```
php tools/local-env/envlite.php init
```

Expected: exit 0, all 8 phases complete.

- [ ] **Step 3: Confirm the define lands exactly once in the rendered file.**

Run:
```
grep -c "DISABLE_WP_CRON" src/wp-config.php
```

Expected: `1`.

- [ ] **Step 4: Confirm the define sits between `WP_SITEURL` and the marker.**

Run:
```
awk '/WP_SITEURL/{s=NR} /DISABLE_WP_CRON/{c=NR} /That.s all, stop editing/{m=NR} END{print s, c, m}' src/wp-config.php
```

Expected: three ascending line numbers. (`s < c < m`.)

- [ ] **Step 5: Confirm re-running `init` is a silent re-stamp.**

Run:
```
php tools/local-env/envlite.php init 2>&1 | grep -iE "overwrite|drift|prompt" || echo "no prompts"
```

Expected: `no prompts`. The previous `init` recorded the manifest hash for the rendered output; the second `init` re-renders the same bytes and finds the manifest entry matches the file, so it silently re-stamps without prompting.

- [ ] **Step 6: Smoke-test the dev site.**

Start the dev server in one terminal:
```
php tools/local-env/envlite.php serve
```

In another terminal:
```
PORT=$(cat .envlite/port) && curl -fsS "http://127.0.0.1:$PORT/" -o /dev/null && echo "front page OK"
```

Expected: `front page OK`. (The point of disabling cron is to remove a per-request penalty; serving the front page proves the runtime still boots with the new constant.)

Stop the server with Ctrl-C when done.

No commit for this task — it is verification.

---

## Done criteria

- `php tools/local-env/tests/run.php` exits 0 with all tests (existing + new) passing.
- A fresh `envlite init` produces a `src/wp-config.php` containing exactly one `define( 'DISABLE_WP_CRON', true );` line, positioned between `WP_SITEURL` and the marker.
- The spec's Phase 7 section reflects the new inject block and the new "Why `DISABLE_WP_CRON` matters" paragraph.
- Re-running `envlite init` on a previously-envlite-owned checkout silently re-stamps `src/wp-config.php` (no overwrite prompt).
- The dev server still starts and serves the front page on the cached port.

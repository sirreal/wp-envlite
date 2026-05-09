# envlite test DB isolation — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `phpunit` use a separate SQLite file (`.ht.test.sqlite`) from the dev site (`.ht.sqlite`), so test runs no longer wipe the dev site Phase 8 just installed.

**Architecture:** Append one `define( 'DB_FILE', '.ht.test.sqlite' );` to the bytes envlite writes for `wp-tests-config.php` in Phase 6. The SQLite drop-in's `constants.php` reads `DB_FILE` to compute `FQDB`; defining it only in the test config (and not in `src/wp-config.php`) gives per-bootstrap-path isolation. No other phase changes.

**Tech Stack:** PHP 7.4+, the existing test harness at `tools/local-env/tests/`, `envlite_phase6_render` / `envlite_phase6_install` in `tools/local-env/envlite.php`.

**Design doc:** `plans/2026-05-09-envlite-test-db-isolation-design.md` (commit `4344f0b3a6`).

---

## Background for an engineer with zero context

- envlite is a PHP CLI at `tools/local-env/envlite.php` that brings a clean wordpress-develop checkout to a runnable state — see `plans/ENVLITE_SPECIFICATION.md` for the full spec.
- It writes a phpunit-only config at `wp-tests-config.php` (Phase 6) and a separate runtime config at `src/wp-config.php` (Phase 7). Both bootstrap a SQLite drop-in plugin at `src/wp-content/db.php`.
- The drop-in (`src/wp-content/plugins/sqlite-database-integration/constants.php`, lines 33–51) computes its DB file path (`FQDB`) from optional `DB_DIR` / `DB_FILE` constants, falling back to `WP_CONTENT_DIR . '/database/.ht.sqlite'`.
- Today neither config defines `DB_FILE`, so the same SQLite file backs both the dev site and the phpunit test run. The test bootstrap (`tests/phpunit/includes/install.php:66-79`) drops every WP table on every run — so any `phpunit` invocation wipes the dev site.
- The fix is one line in the bytes envlite writes for `wp-tests-config.php`. That's it.

The codebase ships its own tiny test harness — there's no PHPUnit for envlite itself. Each test is a global function whose name starts with `test_` in `tools/local-env/tests/test_*.php`; `php tools/local-env/tests/run.php` discovers and runs them.

---

## File structure

| File | Action | Responsibility |
|---|---|---|
| `tools/local-env/envlite.php` | Modify (functions `envlite_phase6_render` ~line 580 and surrounding) | Append the `DB_FILE` define and add a "DB_FILE not in upstream sample" tripwire. |
| `tools/local-env/tests/test_phase6.php` | Modify | New unit tests for the append and the tripwire. |
| `plans/ENVLITE_SPECIFICATION.md` | Modify | Update Phase 6 prose, "Outputs" side-effects bullet, "Non-obvious decisions" item. |

No new files. No file splits. Keep the change confined to Phase 6's render function.

---

## Task 0: Pre-implementation verification

The design doc lists three risk-surface items that should be confirmed before writing any code. Each is a 30-second check.

**Files:** read-only.

- [ ] **Step 1: Verify the drop-in reads `DB_FILE` lazily (no early bind to default path).**

Run:
```
grep -nE "FQDB|DB_FILE|DB_DIR|FQDBDIR" src/wp-content/plugins/sqlite-database-integration/constants.php src/wp-content/plugins/sqlite-database-integration/db.copy
```

Expected: `constants.php` defines `FQDBDIR` and `FQDB` only inside `if ( ! defined( ... ) )` guards, reading `DB_FILE` / `DB_DIR` if defined. `db.copy` does not define `FQDB` itself before `constants.php` runs. Stop and revisit the design if either assumption fails.

- [ ] **Step 2: Confirm `install.php` loads the test config before `wp-settings.php`.**

Run:
```
grep -nE "config_file_path|wp-settings" tests/phpunit/includes/install.php
```

Expected: a `require_once $config_file_path;` line that runs *before* `require_once ABSPATH . 'wp-settings.php';`. The `DB_FILE` constant defined in `wp-tests-config.php` therefore lands before the drop-in's `constants.php` runs (the drop-in is loaded by `wp-settings.php` via `wp-content/db.php`).

- [ ] **Step 3: Confirm no other config pins the test DB path.**

Run:
```
grep -rnE "DB_FILE|DB_DIR|FQDB[^I]|FQDBDIR" tests/phpunit/ phpunit.xml.dist 2>/dev/null
```

Expected: no hits. (If anything turns up, the design's "single source of truth" assumption is wrong; pause and reassess.)

- [ ] **Step 4: Confirm the upstream sample does not already contain a `DB_FILE` define.**

Run:
```
grep -nE "DB_FILE" wp-tests-config-sample.php
```

Expected: no output. If the sample already defines `DB_FILE`, the design's append+tripwire approach needs a rethink; stop here.

No commit for this task — it's read-only verification.

---

## Task 1: Add a tripwire for `DB_FILE` in the upstream sample

A failing test first, then the implementation. This task adds the assertion only — the `define` append comes in Task 2 — so the failing-test step runs against the current `envlite_phase6_render` and confirms the tripwire isn't there yet.

**Files:**
- Modify: `tools/local-env/tests/test_phase6.php`
- Modify: `tools/local-env/envlite.php` (function `envlite_phase6_render` ~line 580)

- [ ] **Step 1: Write the failing test.**

Append to `tools/local-env/tests/test_phase6.php`:

```php
function test_phase6_render_throws_when_db_file_already_defined() {
    $sample = "define( 'DB_NAME', 'youremptytestdbnamehere' );\n"
            . "define( 'DB_USER', 'yourusernamehere' );\n"
            . "define( 'DB_PASSWORD', 'yourpasswordhere' );\n"
            . "define( 'DB_FILE', 'something.sqlite' );\n";
    try {
        envlite_phase6_render($sample);
        throw new \RuntimeException('expected exception');
    } catch (\RuntimeException $e) {
        envlite_assert(strpos($e->getMessage(), 'DB_FILE') !== false);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails.**

Run: `php tools/local-env/tests/run.php`

Expected: `FAIL test_phase6_render_throws_when_db_file_already_defined: expected exception`. Other tests still pass.

- [ ] **Step 3: Implement the tripwire in `envlite_phase6_render`.**

In `tools/local-env/envlite.php`, modify `envlite_phase6_render` (the function that currently substitutes the three placeholders and returns the rendered string). Add the tripwire check just before the function's `return $out;`:

```php
    if (preg_match("/define\\s*\\(\\s*['\"]DB_FILE['\"]/", $out)) {
        throw new \RuntimeException(
            "phase 6: DB_FILE already defined in wp-tests-config-sample.php; envlite assumption broken"
        );
    }
    return $out;
```

- [ ] **Step 4: Run all envlite unit tests.**

Run: `php tools/local-env/tests/run.php`

Expected: all tests pass, including the new `test_phase6_render_throws_when_db_file_already_defined`.

- [ ] **Step 5: Commit.**

```bash
git add tools/local-env/envlite.php tools/local-env/tests/test_phase6.php
git commit -m "feat(envlite): assert DB_FILE absent from wp-tests-config sample"
```

---

## Task 2: Append the `DB_FILE` define

Now the actual isolation. TDD again: assertion test, run-fail, implement, run-pass, commit.

**Files:**
- Modify: `tools/local-env/tests/test_phase6.php`
- Modify: `tools/local-env/envlite.php` (function `envlite_phase6_render`)

- [ ] **Step 1: Write the failing test.**

Append to `tools/local-env/tests/test_phase6.php`:

```php
function test_phase6_render_appends_db_file_define() {
    $sample = "define( 'DB_NAME', 'youremptytestdbnamehere' );\n"
            . "define( 'DB_USER', 'yourusernamehere' );\n"
            . "define( 'DB_PASSWORD', 'yourpasswordhere' );\n";
    $out = envlite_phase6_render($sample);
    envlite_assert(preg_match("/define\\(\\s*'DB_FILE'\\s*,\\s*'\\.ht\\.test\\.sqlite'\\s*\\)\\s*;/", $out) === 1);
    // Output must end with a single trailing newline.
    envlite_assert(substr($out, -1) === "\n");
    envlite_assert(substr($out, -2) !== "\n\n");
}

function test_phase6_render_appends_db_file_when_sample_has_no_trailing_newline() {
    $sample = "define( 'DB_NAME', 'youremptytestdbnamehere' );\n"
            . "define( 'DB_USER', 'yourusernamehere' );\n"
            . "define( 'DB_PASSWORD', 'yourpasswordhere' );"; // no \n
    $out = envlite_phase6_render($sample);
    envlite_assert(preg_match("/define\\(\\s*'DB_FILE'\\s*,\\s*'\\.ht\\.test\\.sqlite'\\s*\\)\\s*;/", $out) === 1);
    envlite_assert(substr($out, -1) === "\n");
}
```

- [ ] **Step 2: Run the tests to verify they fail.**

Run: `php tools/local-env/tests/run.php`

Expected: both new tests fail (the rendered output does not yet contain `define( 'DB_FILE', ... )`).

- [ ] **Step 3: Implement the append in `envlite_phase6_render`.**

In `tools/local-env/envlite.php`, modify `envlite_phase6_render` so the function (after the placeholder-elimination loop and the new tripwire from Task 1) appends the `DB_FILE` define before returning. The full function should read:

```php
function envlite_phase6_render(string $sample): string {
    $replacements = [
        'youremptytestdbnamehere' => 'wordpress_test',
        'yourusernamehere'        => 'wp',
        'yourpasswordhere'        => 'wp',
    ];
    foreach ($replacements as $placeholder => $value) {
        if (substr_count($sample, $placeholder) !== 1) {
            throw new \RuntimeException("phase 6: placeholder '$placeholder' must appear exactly once");
        }
    }
    $out = strtr($sample, $replacements);
    foreach (array_keys($replacements) as $placeholder) {
        if (strpos($out, $placeholder) !== false) {
            throw new \RuntimeException("phase 6: placeholder '$placeholder' still present after substitution");
        }
    }
    if (preg_match("/define\\s*\\(\\s*['\"]DB_FILE['\"]/", $out)) {
        throw new \RuntimeException(
            "phase 6: DB_FILE already defined in wp-tests-config-sample.php; envlite assumption broken"
        );
    }
    if (substr($out, -1) !== "\n") {
        $out .= "\n";
    }
    $out .= "define( 'DB_FILE', '.ht.test.sqlite' );\n";
    return $out;
}
```

- [ ] **Step 4: Run all envlite unit tests.**

Run: `php tools/local-env/tests/run.php`

Expected: every test passes — the existing three Phase 6 tests, the Task 1 tripwire test, and the two new append tests.

- [ ] **Step 5: Commit.**

```bash
git add tools/local-env/envlite.php tools/local-env/tests/test_phase6.php
git commit -m "feat(envlite): isolate phpunit DB by appending DB_FILE to wp-tests-config"
```

---

## Task 3: End-to-end verification on a real checkout

This task is the regression test for the bug the design fixes. It uses the actual envlite tool against this checkout (no automation needed — it's a manual sequence that takes ~3 minutes). If any step fails, fix forward; do not commit a broken state.

**Files:** none modified.

**Prerequisite:** working node ≥ 20.10 / npm ≥ 10.2.3 / composer ≥ 2 / PHP ≥ 7.4 with `pdo_sqlite`, `sqlite3`, `openssl`, `simplexml`, `zip`, `pcntl` (Unix). If any of these are missing, Phase 0 will fail-fast and tell you which.

- [ ] **Step 1: Reset to a known-clean state.**

Run:
```
php tools/local-env/envlite.php clean --force
```

Expected: exit 0, `.envlite/` removed, no `wp-tests-config.php` / `src/wp-config.php` / `src/wp-content/db.php` / `src/wp-content/plugins/sqlite-database-integration/` left from a prior run.

(If you've never run `init` before in this checkout, this is a no-op; that's fine.)

- [ ] **Step 2: Run a fresh init.**

Run:
```
php tools/local-env/envlite.php init
```

Expected: exits 0. `wp-tests-config.php` exists at the repo root, ends with `define( 'DB_FILE', '.ht.test.sqlite' );` followed by a single newline:

```
tail -2 wp-tests-config.php
```

Should print:
```
define( 'DB_FILE', '.ht.test.sqlite' );
```

(Plus a trailing newline.)

- [ ] **Step 3: Confirm Phase 8 created the live DB only.**

Run:
```
ls -la src/wp-content/database/
```

Expected: `.ht.sqlite` present, `.ht.test.sqlite` absent (phpunit hasn't run yet).

- [ ] **Step 4: Hit the dev site and capture a marker.**

In one shell:
```
php tools/local-env/envlite.php serve
```

In another shell, with `<port>` replaced by the contents of `.envlite/port`:
```
curl -sI "http://127.0.0.1:<port>/" | head -1
```

Expected: `HTTP/1.1 200 OK` (not a 3xx redirect to `wp-admin/install.php`). Now insert a marker post via `wp-admin/edit.php` in a browser (`admin` / `password`) — title it `MARKER PRE-PHPUNIT`, publish.

Stop the dev server with Ctrl-C.

- [ ] **Step 5: Run phpunit.**

Run:
```
./vendor/bin/phpunit --group html-api
```

Expected: green run, ~1300+ tests pass.

- [ ] **Step 6: Confirm both DB files now exist.**

Run:
```
ls -la src/wp-content/database/
```

Expected: both `.ht.sqlite` and `.ht.test.sqlite` present. The test DB's mtime should be newer than the live DB's (phpunit just touched it; nothing has touched the live DB since Step 4).

- [ ] **Step 7: Confirm the marker post survived.**

Restart `serve`:
```
php tools/local-env/envlite.php serve
```

In a browser, hit `/wp-admin/edit.php` and confirm `MARKER PRE-PHPUNIT` is still listed. Without the fix, this post would have been wiped by the phpunit run.

Stop the dev server.

- [ ] **Step 8: Confirm `clean` removes only the live DB.**

Run:
```
php tools/local-env/envlite.php clean --force
ls -la src/wp-content/database/ 2>/dev/null
```

Expected: `.ht.sqlite` is gone (observation-tracked, removed by `clean`); `.ht.test.sqlite` is still present (untracked, preserved). The directory itself may or may not exist depending on whether other tracked entries triggered its removal — both are acceptable.

- [ ] **Step 9: Confirm a follow-up `init` does not prompt about the leftover.**

Run:
```
php tools/local-env/envlite.php init
```

Expected: exit 0, no prompt, no warning about `.ht.test.sqlite`. The orphan is invisible to envlite — that's the whole point of leaving it untracked.

No commit for this task — it's manual verification.

---

## Task 4: Update the spec document

With the implementation green and end-to-end-verified, fold the change into `plans/ENVLITE_SPECIFICATION.md`.

**Files:**
- Modify: `plans/ENVLITE_SPECIFICATION.md`

- [ ] **Step 1: Update Phase 6 — operation step.**

Find the `## Phase 6 — phpunit configuration` section, then the `**Operation:**` block. The current text describes a 3-substitution flow that ends with "After the write, assert that each of the three placeholders is no longer present in the output". After that sentence, add:

```
Then assert that the substituted bytes do not already contain a
`DB_FILE` define (regex: `define\s*\(\s*['\"]DB_FILE['\"]`); a
match means upstream's `wp-tests-config-sample.php` has grown its
own `DB_FILE` and envlite's append assumption no longer holds —
abort with `envlite init: phase 6: DB_FILE already defined in
wp-tests-config-sample.php; envlite assumption broken`. Finally,
ensure the bytes end in `\n` (append one if not) and append the
literal line `define( 'DB_FILE', '.ht.test.sqlite' );\n`. Write
the result to `wp-tests-config.php`.
```

- [ ] **Step 2: Add a Phase 6 rationale paragraph.**

In the same Phase 6 section, find the `**Notes:**` block. Add a new bullet at the end of the existing Notes list:

```
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
```

- [ ] **Step 3: Update "Outputs (final repo state)".**

Find the `**Side effects of `init` (not envlite-managed; remove with your usual tooling):**` block. Add a new line under the existing three:

```
src/wp-content/database/.ht.test.sqlite                  (created on first phpunit run; not envlite-managed)
```

- [ ] **Step 4: Add a new entry to "Non-obvious decisions, recorded once".**

After the existing item 13 (`127.0.0.1` everywhere), add:

```
14. **Test DB is isolated via `DB_FILE` in the test config only.**
    phpunit's `tests/phpunit/includes/install.php` drops every WP
    table on every run; without isolation it would wipe the dev
    site Phase 8 installs. The split is one `define( 'DB_FILE',
    '.ht.test.sqlite' )` appended to `wp-tests-config.php`;
    `src/wp-config.php` stays untouched and the live runtime keeps
    the drop-in's default `FQDB`. Same-directory + filename suffix
    beats a separate `database-test/` (no path-resolution surprises
    in the drop-in's `FQDBDIR` machinery) and beats putting it
    under `.envlite/` (preserves envlite's own-state-only convention
    for that directory). The test DB is not observation-tracked
    because the rationale for tracking the live DB — possible
    user-authored content — does not apply to a file phpunit drops
    every run.
```

- [ ] **Step 5: Commit.**

```bash
git add plans/ENVLITE_SPECIFICATION.md
git commit -m "docs(envlite): record DB_FILE isolation in Phase 6 spec"
```

---

## Self-review (run by the plan author, not the implementer)

- **Spec coverage:** every change called out in the design doc — Phase 6 step, Phase 6 tripwire, Phase 6 rationale paragraph, "Side effects" bullet, non-obvious decision item — has a task. The 3 risk-surface items are Task 0. The end-to-end test plan from the design is Task 3. ✓
- **Placeholder scan:** no TBDs, no "implement appropriately", no "similar to Task N" — every code block is complete. ✓
- **Type consistency:** all references to `envlite_phase6_render` match the existing function signature `(string $sample): string`. The constant name (`DB_FILE`), filename (`.ht.test.sqlite`), and error message string (`"phase 6: DB_FILE already defined in wp-tests-config-sample.php; envlite assumption broken"`) are identical across Tasks 1, 2, and 4. ✓

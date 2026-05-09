# envlite — phpunit test DB isolation

**Status:** design.
**Relates to:** `plans/ENVLITE_SPECIFICATION.md` (Phases 5–8, "State and ownership", "Outputs", "Non-obvious decisions").

## Problem

Today, an `envlite init` followed by `./vendor/bin/phpunit` silently wipes
the dev site that `init` just installed. The chain:

- Phase 8 runs `wp_install()` against
  `src/wp-content/database/.ht.sqlite`, the SQLite drop-in's default
  `FQDB` (drop-in `constants.php:33-51`).
- `tests/phpunit/includes/bootstrap.php:261` shells out to
  `tests/phpunit/includes/install.php` on every phpunit run.
- `install.php:66-79` issues `DROP TABLE IF EXISTS` for every table in
  `$wpdb->tables()`.
- Phase 6's `wp-tests-config.php` does not define `DB_DIR` or `DB_FILE`,
  so the drop-in resolves the same `FQDB` for both bootstraps. One
  file, two writers, one of which truncates on every run.

The spec is explicit that envlite "never drops tables" (Phase 8,
non-obvious decision 12). The single-file collision quietly violates
that contract via phpunit instead of via envlite.

## Goal

Run `phpunit` against a separate SQLite file from the one
`envlite serve` reads, with no change to the live runtime, no new
state surface, and no observable difference for `serve` /
`up` / `clean`.

## Non-goals

- Configurable test DB path. envlite is a dev-only tool; one
  hardcoded path keeps cross-checkout drift at zero.
- Isolating phpunit invocations from each other. The test bootstrap
  already drops and recreates tables on every run; per-run isolation
  is its job, not envlite's.
- Changing the live runtime DB path or filename.
- Touching the SQLite drop-in. The drop-in already exposes the
  control surface we need.

## Mechanism

Single new `define` in `wp-tests-config.php`:

```php
define( 'DB_FILE', '.ht.test.sqlite' );
```

`DB_DIR` stays unset. `FQDBDIR` keeps its drop-in default
(`WP_CONTENT_DIR . '/database/'`), so the test DB lives next to the
live DB, both in `src/wp-content/database/`:

| File | Owner | Lifecycle |
|---|---|---|
| `.ht.sqlite` | live runtime (Phase 8 + `envlite serve`) | observation-recorded in manifest, prompts on `clean` |
| `.ht.test.sqlite` | phpunit `install.php` | not envlite-managed; `git clean -fdx` removes |

The constants are scoped per bootstrap path: `wp-tests-config.php` is
loaded only by phpunit's bootstrap, `src/wp-config.php` only by
`wp-load.php`. Defining `DB_FILE` in the test config is invisible to
the live runtime.

### Why same dir, different filename

- Smallest delta to the spec — Phase 6 grows by one append, no other
  phase touches.
- The drop-in's `FQDBDIR` machinery stays on its default; no risk of
  path-resolution surprises in `WP_SQLite_DB::ensure_database_directory`.
- `clean`'s reverse-manifest walk is indifferent to a sibling file in
  a directory it doesn't own.

Considered and rejected: a separate `database-test/` dir (buys
nothing concrete, costs a `DB_DIR` define and an extra mkdir
codepath); placing the test DB under `.envlite/` (mixes envlite's own
state — port, manifest — with WP-managed file bytes, blurring the
ownership story documented in "envlite state directory").

### Why untracked

The observation hook exists *because* the live `.ht.sqlite` may hold
user-authored content (admin posts, settings). That rationale does
not apply to a file phpunit drops every run. Treating the test DB as
a phpunit side effect — same category as `vendor/`, `node_modules/`,
and build outputs — is consistent with the existing pattern: envlite
invokes the tool, envlite does not own the tool's artifacts.

`envlite clean` therefore does not prompt for `.ht.test.sqlite` and
does not remove it. The user removes it the same way they remove
`vendor/`: `git clean -fdx` or equivalent.

## Spec changes (Phase 6)

The phase's existing 3-substitution flow gains one append step:

> 4. After the substitutions and the placeholder-elimination assertion,
>    append the line `define( 'DB_FILE', '.ht.test.sqlite' );` to the
>    substituted contents (with a leading newline if the sample does
>    not end with one). Then write the result to `wp-tests-config.php`.

Tripwire (mirrors Phase 5's `{SQLITE_IMPLEMENTATION_FOLDER_PATH}`
post-condition):

> 5. Post-condition tripwire: assert that the substituted sample does
>    not already contain `DB_FILE`. The append step assumes upstream
>    has not added a `DB_FILE` define of its own; if a future sample
>    reshape introduces one, the assumption silently breaks. On match,
>    abort with `envlite init: phase 6: DB_FILE already defined in
>    wp-tests-config-sample.php; envlite assumption broken`.

Phase 6's idempotency contract is unchanged. The hash recorded in the
manifest covers the post-append bytes; user edits to the appended
`define` show up as drift on the next `init` and prompt.

Rationale paragraph added to Phase 6 (positioned with the "Why two
distinct config files" notes):

> The `DB_FILE` define isolates the test DB from the live one. The
> phpunit bootstrap's `install.php` drops every WP table on every
> run; sharing the drop-in's default `FQDB` with `src/wp-config.php`
> would silently wipe the dev site after every test invocation,
> contradicting Phase 8's "envlite never drops tables" contract via
> phpunit's bootstrap.

## Spec changes (other sections)

- **"Outputs (final repo state)" → "Side effects of `init`":** add bullet
  ```
  src/wp-content/database/.ht.test.sqlite                  (created on first phpunit run)
  ```
- **"Non-obvious decisions, recorded once":** add item 14:
  > **Test DB is isolated via `DB_FILE` in the test config only.**
  > phpunit's `tests/phpunit/includes/install.php` drops every WP
  > table on every run; without isolation it would wipe the dev
  > site Phase 8 installs. The split is one `define( 'DB_FILE',
  > '.ht.test.sqlite' )` in `wp-tests-config.php`; `src/wp-config.php`
  > stays untouched and the live runtime keeps the drop-in's default
  > `FQDB`. Same-directory + filename suffix beats a separate
  > `database-test/` (no path-resolution surprises) and beats putting
  > it under `.envlite/` (preserves envlite's own-state-only
  > convention for that directory). The test DB is not
  > observation-tracked because the rationale for tracking the live
  > DB — possible user-authored content — does not apply to a file
  > phpunit drops every run.

## What does NOT change

- Phase 0 preflight checks.
- Phase 1 port discovery, `.envlite/port`, the cache contract.
- Phase 2 `npm ci`, Phase 3 `build:dev`, Phase 4 `composer install`.
- Phase 5 SQLite drop-in install (zip download, SHA pin, `db.copy`
  → `db.php` activation, `{SQLITE_IMPLEMENTATION_FOLDER_PATH}`
  tripwire).
- Phase 7 `src/wp-config.php` (no `DB_FILE` define added; live
  runtime keeps the drop-in's default `FQDB`).
- Phase 8 `wp_install()` flow, fixed credentials, idempotency,
  observation hook for `.ht.sqlite`.
- Manifest contract, atomic-write rules, `clean` semantics.
- `envlite serve` / `up` behavior, the `pcntl_exec` Unix path,
  the `proc_open` Windows fallback, the bind-failure pre-flight.
- All exit codes, all stderr prefixes.

## Risk surface

Implementation-time due-diligence items (not blockers; verified in
the implementation plan, not the design):

1. **`DB_FILE` is read at the right time.** The drop-in's
   `constants.php` reads `DB_FILE` to compute `FQDB`. That file
   executes when `wp-content/db.php` is autoloaded by
   `wp-settings.php`. The phpunit bootstrap chain is:
   `bootstrap.php` → `wp-tests-config.php` (defines `DB_FILE`) →
   spawns `install.php` subprocess → `install.php` re-loads
   `wp-tests-config.php` *before* `require_once ABSPATH . 'wp-settings.php'`.
   Both processes (`bootstrap.php`'s and `install.php`'s) define
   `DB_FILE` before `wp-settings.php` runs, so the drop-in sees it.
   Verify by reading `wp-settings.php`'s db.php load order and the
   `install.php` config-load order.
2. **Drop-in creates the DB file on first use.** The drop-in's
   `WP_SQLite_DB` opens (and creates) `FQDB` on first query; same
   code path as the live DB. No filename-pattern check pins
   `.ht.sqlite` specifically. Quick grep over the drop-in's
   `wp-includes/` directory confirms.
3. **No other config pins the test DB path.** `phpunit.xml.dist`,
   `phpunit/multisite.xml`, the bootstrap, and `install.php` use
   `$wpdb` exclusively after `wp-tests-config.php` loads. Spec
   already treats `wp-tests-config.php` as the single source of
   truth for test DB config. Grep confirms no `define` of
   `DB_DIR`/`DB_FILE`/`FQDB`/`FQDBDIR` lives elsewhere in the
   wordpress-develop test tree.

Each is a 30-second check; the design proceeds on the assumption that
all three pass.

## Test plan

Manually verifiable post-implementation:

1. `php tools/local-env/envlite.php init` → Phase 8 succeeds; visit
   `http://127.0.0.1:<port>/` → 2xx homepage (not a 3xx redirect to
   `/wp-admin/install.php`, which would mean the DB has no tables).
2. `./vendor/bin/phpunit --group html-api` → green.
3. Visit `http://127.0.0.1:<port>/` again → still a 2xx homepage,
   not a redirect to install.php.
4. `ls src/wp-content/database/` → both `.ht.sqlite` and
   `.ht.test.sqlite` present.
5. `php tools/local-env/envlite.php clean` (with the implicit
   `--force` for non-interactive runs, or `y` at the prompt) →
   `.ht.sqlite` removed (observation-tracked), `.ht.test.sqlite`
   preserved (untracked).
6. Re-run step 1 → succeeds without any prompt about the leftover
   `.ht.test.sqlite`.

Step 3 is the regression test for the bug this design fixes; without
the change, the dev site is wiped between steps 2 and 3.

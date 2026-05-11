# envlite — disable WP-Cron by default in the runtime config

**Status:** design.
**Relates to:** `plans/ENVLITE_SPECIFICATION.md` (Phase 7 — Runtime configuration (`src/wp-config.php`)).

## Problem

WordPress runs pseudo-cron via `spawn_cron()` on every front-end HTTP
request: a non-blocking loopback POST to `wp-cron.php` initiated from
the request that is currently being served.

envlite's runtime is PHP's built-in dev server (`php -S`), which
serializes requests by default. Every front-end hit pays the cost of
opening the loopback connection plus `spawn_cron()`'s short
send/recv timeout, regardless of whether anything is due. When the
loopback request *is* finally serviced (after the foreground request
returns), it blocks the dev server from servicing the next browser
request until cron finishes — turning what would be background work
into a head-of-line stall. On a dev box where nothing depends on
cron firing promptly, the net effect is added per-request latency
and unpredictable stalls for no benefit.

The current Phase 7 output leaves the WordPress default intact, so a
fresh `envlite init` ships a dev site that exhibits this behavior on
every page load.

## Goal

After `envlite init`, the rendered `src/wp-config.php` defines
`DISABLE_WP_CRON` as `true`, so `spawn_cron()` is suppressed on every
HTTP request served by `envlite serve` / `envlite up`. No change to
the test config, no new flags, no new state.

## Non-goals

- Configurability. envlite is a dev-only tool; an
  `--enable-cron` / `--disable-cron` knob would just create
  cross-checkout drift. If a user genuinely needs cron, they edit the
  rendered file; envlite's existing owned-drifted prompt covers the
  next `init`.
- Changing `wp-tests-config.php` (Phase 6). phpunit does not run
  inside an HTTP request lifecycle, so `spawn_cron()` is never
  invoked from a test run, and defining `DISABLE_WP_CRON` there
  would only risk interfering with cron-related tests that expect
  default behavior.
- Replacing cron with WP-CLI's `cron event run` or a system cron
  shim. Out of scope; envlite leaves no daemons behind.
- Using `ALTERNATE_WP_CRON`. That mechanism runs cron in-band on the
  same single-threaded server, which makes the latency problem
  worse, not better.

## Design

### Where the change lives

In `envlite_phase7_render()` (`tools/local-env/envlite.php:650`), the
existing inject block that lands immediately before the
`/* That's all, stop editing! Happy publishing. */` marker grows by
one line.

Before:

```php
define( 'WP_HOME',    'http://127.0.0.1:<PORT>' );
define( 'WP_SITEURL', 'http://127.0.0.1:<PORT>' );
```

After:

```php
define( 'WP_HOME',    'http://127.0.0.1:<PORT>' );
define( 'WP_SITEURL', 'http://127.0.0.1:<PORT>' );
define( 'DISABLE_WP_CRON', true );
```

The value is the literal `true` (hardcoded). The ordering keeps the
URL constants together, then the runtime-behavior override, then the
trailing blank line and the marker — same anchoring, same single
substring operation.

### Spec edits

Update `plans/ENVLITE_SPECIFICATION.md` Phase 7:

- Step 5's injected block grows by the `DISABLE_WP_CRON` line.
- Add a "Why `DISABLE_WP_CRON` matters" sentence beneath the existing
  "Why `WP_HOME` / `WP_SITEURL` matter" paragraph, explaining the
  single-threaded `php -S` interaction with `spawn_cron()`.

No change to Phase 6, Phase 8, ownership rules, manifest schema, or
CLI surface.

### Idempotency and existing checkouts

Phase 7's existing rule applies unchanged:

- New checkout (path absent) → write, record. The new constant is
  present from the first `init`.
- Existing checkout that previously ran `envlite init`
  (path present, in manifest, hash matches) → silent re-stamp. The
  re-rendered output now contains `DISABLE_WP_CRON`; the manifest
  hash updates to the new render. No prompt; no user action.
- Path present, hash drifted (user edited the file) → existing
  prompt fires before overwrite, unchanged.
- Path present, not in manifest → existing prompt fires, unchanged.

The `envlite init` after the change is functionally equivalent to a
no-op silent re-stamp for any user who has not hand-edited
`src/wp-config.php`. Users who have hand-edited it will hit the
existing owned-drifted prompt — the correct path, since their custom
content needs to merge with the new line.

## Testing

- Unit-style check of `envlite_phase7_render()` output: the rendered
  string contains exactly one occurrence of
  `define( 'DISABLE_WP_CRON', true );`, positioned between the
  `WP_SITEURL` line and the `/* That's all, stop editing!` marker.
- End-to-end: on a fresh checkout, `php tools/local-env/envlite.php init`
  followed by `grep -c "DISABLE_WP_CRON" src/wp-config.php` returns
  `1`.
- Re-run `init`: silent re-stamp on a checkout whose previous
  `wp-config.php` was envlite-owned; existing drift prompt on a
  checkout whose `wp-config.php` was hand-edited.

## Open questions

None. Scope, anchor, literal, and spec edits are all settled.

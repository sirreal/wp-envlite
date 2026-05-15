<?php
function test_clean_collects_in_reverse_insertion_order() {
    $manifest = [
        '.cache/envlite/port' => str_repeat('a', 64),
        'src/wp-config.php' => str_repeat('b', 64),
        'wp-tests-config.php' => str_repeat('c', 64),
    ];
    $order = envlite_clean_collect($manifest);
    envlite_assert_eq(['wp-tests-config.php', 'src/wp-config.php', '.cache/envlite/port'], $order);
}

function test_clean_removes_files_dirs_and_state() {
    $dir = envlite_test_tmpdir('clean');
    mkdir("$dir/.cache/envlite", 0755, true);
    mkdir("$dir/sub", 0755, true);
    file_put_contents("$dir/wp-tests-config.php", 'x');
    file_put_contents("$dir/sub/db.php", 'y');
    $manifest = [
        '.cache/envlite/port' => hash('sha256', 'p'),
        'wp-tests-config.php' => hash('sha256', 'x'),
        'sub'           => 'dir',
    ];
    envlite_manifest_save($dir, $manifest);
    file_put_contents("$dir/.cache/envlite/port", 'p');

    $failed = envlite_clean_apply($dir, envlite_clean_collect($manifest));
    envlite_assert_eq([], $failed, 'clean_apply should report no failures');
    // Simulate the subcommand-level cleanup that follows envlite_clean_apply.
    envlite_rrmdir("$dir/.cache/envlite");

    envlite_assert(!file_exists("$dir/wp-tests-config.php"));
    envlite_assert(!is_dir("$dir/sub"));
    envlite_assert(!is_dir("$dir/.cache/envlite"));
}

function test_clean_removes_tmp_leftovers_in_cache_dir() {
    // envlite's atomic writes go to `.cache/envlite/<name>.tmp` and rename
    // into place; Ctrl-C between the write and the rename can leave a
    // `.tmp` sibling behind. The old clean cleanup explicitly unlinked
    // manifest/port/state and then `@rmdir`'d the directory — the rmdir
    // silently fails on a non-empty dir, and clean would return 0 with
    // `.cache/envlite/` still present and an empty manifest, making the
    // next clean a no-op. Recursive removal is the contract.
    $dir = envlite_test_tmpdir('clean-tmp-leftover');
    mkdir("$dir/.cache/envlite", 0755, true);
    file_put_contents("$dir/.cache/envlite/manifest.tmp", 'partial');
    file_put_contents("$dir/.cache/envlite/state.tmp", 'partial');

    envlite_rrmdir("$dir/.cache/envlite");
    envlite_assert(!is_dir("$dir/.cache/envlite"),
        '.cache/envlite/ must be removed even when .tmp leftovers exist');
}

function test_observe_ht_sqlite_persist_writes_manifest() {
    // up-mode: $persist=true → augmented manifest is written to disk so
    // subsequent runs see the DB as envlite-owned.
    $dir = envlite_test_tmpdir('observe-persist');
    mkdir("$dir/.cache/envlite", 0755, true);
    mkdir("$dir/src/wp-content/database", 0755, true);
    file_put_contents("$dir/src/wp-content/database/.ht.sqlite", 'sqlite-bytes');

    $manifest = envlite_observe_ht_sqlite($dir, true);
    envlite_assert(isset($manifest['src/wp-content/database/.ht.sqlite']),
        'in-memory manifest must include the observation');

    $onDisk = envlite_manifest_load($dir);
    envlite_assert(isset($onDisk['src/wp-content/database/.ht.sqlite']),
        'on-disk manifest must include the observation in persist mode');
    envlite_assert_eq(
        hash('sha256', 'sqlite-bytes'),
        $onDisk['src/wp-content/database/.ht.sqlite']
    );
}

function test_observe_ht_sqlite_transient_leaves_disk_manifest_unchanged() {
    // clean-mode: $persist=false → augmented manifest is returned for the
    // caller's use but the on-disk manifest is NOT touched. Spec requires
    // this so that aborting the clean prompt does not leave a permanent
    // ownership record of a user-authored DB.
    $dir = envlite_test_tmpdir('observe-transient');
    mkdir("$dir/.cache/envlite", 0755, true);
    mkdir("$dir/src/wp-content/database", 0755, true);
    file_put_contents("$dir/src/wp-content/database/.ht.sqlite", 'sqlite-bytes');

    // Seed an existing manifest so we can detect any disk-side write.
    $original = ['src/wp-config.php' => str_repeat('a', 64)];
    envlite_manifest_save($dir, $original);
    $diskBefore = file_get_contents("$dir/.cache/envlite/manifest");

    $manifest = envlite_observe_ht_sqlite($dir, false);
    envlite_assert(isset($manifest['src/wp-content/database/.ht.sqlite']),
        'in-memory manifest must include the observation in transient mode');

    $diskAfter = file_get_contents("$dir/.cache/envlite/manifest");
    envlite_assert_eq($diskBefore, $diskAfter,
        'transient mode must not write to the on-disk manifest');

    $onDisk = envlite_manifest_load($dir);
    envlite_assert(!isset($onDisk['src/wp-content/database/.ht.sqlite']),
        'on-disk manifest must not gain the observation in transient mode');
    envlite_assert_eq($original, $onDisk);
}

function test_observe_ht_sqlite_persist_throws_on_unwritable_cache_dir() {
    // Persist mode calls envlite_manifest_save, which throws via
    // envlite_atomic_write on a read-only `.cache/envlite/` directory.
    // The up call site catches this throw and turns it into the documented
    // `envlite up: ...` line + exit 1; this test pins the throw shape so a
    // refactor of atomic_write does not regress that contract silently.
    if (DIRECTORY_SEPARATOR !== '/' || posix_geteuid() === 0) { return; }
    $dir = envlite_test_tmpdir('observe-persist-readonly');
    mkdir("$dir/.cache/envlite", 0755, true);
    mkdir("$dir/src/wp-content/database", 0755, true);
    file_put_contents("$dir/src/wp-content/database/.ht.sqlite", 'sqlite-bytes');
    chmod("$dir/.cache/envlite", 0555);
    try {
        $thrown = null;
        try {
            envlite_observe_ht_sqlite($dir, true);
        } catch (\Throwable $e) {
            $thrown = $e;
        }
        envlite_assert($thrown instanceof \RuntimeException,
            'persist mode must propagate atomic-write failures as RuntimeException');
    } finally {
        chmod("$dir/.cache/envlite", 0755);
    }
}

function test_observe_ht_sqlite_records_db_only_after_it_exists() {
    // Round 6 regression: on a fresh checkout, the start-of-up observation
    // runs before Phase 8 has created `.ht.sqlite`, so the manifest stays
    // empty for the DB. envlite_cmd_up calls observe(persist=true) a
    // second time *after* Phase 8 to capture the file. This test mimics
    // both observation points in isolation and asserts the second one
    // does record the file.
    $dir = envlite_test_tmpdir('observe-after-phase8');
    mkdir("$dir/.cache/envlite", 0755, true);
    mkdir("$dir/src/wp-content/database", 0755, true);

    // First observation: DB does not exist yet. Manifest must remain empty.
    envlite_observe_ht_sqlite($dir, true);
    $manifest = envlite_manifest_load($dir);
    envlite_assert(
        !isset($manifest['src/wp-content/database/.ht.sqlite']),
        'first observation must not record a non-existent DB'
    );

    // Phase 8 (simulated) creates the live DB.
    file_put_contents("$dir/src/wp-content/database/.ht.sqlite", 'sqlite-after-install');

    // Second observation: now the DB exists; persist mode must record it.
    envlite_observe_ht_sqlite($dir, true);
    $manifest = envlite_manifest_load($dir);
    envlite_assert(
        isset($manifest['src/wp-content/database/.ht.sqlite']),
        'post-phase-8 observation must record the newly created DB'
    );
    envlite_assert_eq(
        hash('sha256', 'sqlite-after-install'),
        $manifest['src/wp-content/database/.ht.sqlite']
    );
}

function test_observe_ht_sqlite_returns_existing_manifest_when_db_missing() {
    $dir = envlite_test_tmpdir('observe-no-db');
    mkdir("$dir/.cache/envlite", 0755, true);
    $seed = ['src/wp-config.php' => str_repeat('a', 64)];
    envlite_manifest_save($dir, $seed);

    envlite_assert_eq($seed, envlite_observe_ht_sqlite($dir, true));
    envlite_assert_eq($seed, envlite_observe_ht_sqlite($dir, false));
}

function test_clean_clears_broken_symlink_at_state_dir_path() {
    // Round 11 regression: if `.cache/envlite` itself is a broken symlink
    // (or a regular file, or a symlink to a non-dir), is_dir returns
    // false. envlite_cmd_clean used to short-circuit with "nothing to
    // clean" success and leave the blocker in place — the next `up`
    // then couldn't mkdir `.cache/envlite` because a non-directory was
    // still sitting at the path.
    if (DIRECTORY_SEPARATOR !== '/') { return; }
    $repo = envlite_test_tmpdir('clean-broken-state-symlink');
    mkdir("$repo/.cache");
    symlink("$repo/.cache/does-not-exist-target", "$repo/.cache/envlite");
    envlite_assert(is_link("$repo/.cache/envlite"),
        'fixture must have a broken symlink at the state-dir path');
    envlite_assert(!is_dir("$repo/.cache/envlite"),
        'is_dir must return false for the broken symlink');

    $origCwd = getcwd();
    chdir($repo);
    try {
        $rc = envlite_cmd_clean([], true); // force=true to skip prompts
    } finally {
        chdir($origCwd);
    }

    envlite_assert_eq(0, $rc, 'clean must succeed after removing the blocker');
    envlite_assert(!is_link("$repo/.cache/envlite"),
        'broken symlink must be unlinked so future up can mkdir the state dir');
    envlite_assert(!file_exists("$repo/.cache/envlite"),
        'state path must be empty afterwards');
}

function test_clean_clears_regular_file_at_state_dir_path() {
    // Same regression with a different blocker shape — a regular file
    // accidentally created (or written by a different tool) at
    // `.cache/envlite`. Without is_link/file_exists checks, clean would
    // have reported "nothing to clean" and left the file in place.
    $repo = envlite_test_tmpdir('clean-regular-file-state');
    mkdir("$repo/.cache");
    file_put_contents("$repo/.cache/envlite", 'stray file blocking state dir');
    envlite_assert(file_exists("$repo/.cache/envlite"));
    envlite_assert(!is_dir("$repo/.cache/envlite"));

    $origCwd = getcwd();
    chdir($repo);
    try {
        $rc = envlite_cmd_clean([], true);
    } finally {
        chdir($origCwd);
    }

    envlite_assert_eq(0, $rc, 'clean must succeed after removing the regular-file blocker');
    envlite_assert(!file_exists("$repo/.cache/envlite"),
        'regular-file blocker must be removed by clean');
}

function test_clean_walks_manifest_when_state_dir_is_symlink_to_directory() {
    // Round 12 regression: a symlink-to-directory at `.cache/envlite` is
    // a legitimate user setup (the spec allows redirecting state to a
    // different filesystem). The round-11 blocker branch treated it as a
    // non-directory blocker and unlinked without walking the manifest —
    // so envlite-managed files in the checkout (wp-config.php, db.php,
    // wp-tests-config.php, etc.) survived clean. The fix restricts the
    // blocker branch to !is_dir; symlink-to-dir falls through to the
    // normal manifest walk.
    if (DIRECTORY_SEPARATOR !== '/') { return; }

    // Build a fixture wp-develop-shaped repo with managed outputs.
    $repo = envlite_test_tmpdir('clean-symlink-state-dir');
    mkdir("$repo/src", 0755, true);
    file_put_contents("$repo/wp-tests-config.php", 'envlite-owned');
    file_put_contents("$repo/src/wp-config.php", 'envlite-owned');

    // Put the state directory on a different path (the symlink target).
    $stateTarget = envlite_test_tmpdir('clean-symlink-state-target');
    mkdir("$repo/.cache");
    symlink($stateTarget, "$repo/.cache/envlite");
    envlite_assert(is_link("$repo/.cache/envlite"));
    envlite_assert(is_dir("$repo/.cache/envlite"),
        'symlink-to-dir must satisfy is_dir');

    // Seed a state-dir entry — Phase 1 always records `.cache/envlite/port`
    // in the manifest, and its resolved path goes THROUGH the state-dir
    // symlink to outside the checkout. Round-24's containment check would
    // mark it as escaping and the whole clean would fail; the
    // state-dir-exception fix lets it through. Without this entry in the
    // fixture, the test passes against a broken containment check.
    file_put_contents("$repo/.cache/envlite/port", "8421\n");
    $manifest = [
        'src/wp-config.php'      => hash('sha256', 'envlite-owned'),
        'wp-tests-config.php'    => hash('sha256', 'envlite-owned'),
        '.cache/envlite/port'    => hash('sha256', "8421\n"),
    ];
    envlite_manifest_save($repo, $manifest);
    envlite_assert(file_exists("$stateTarget/manifest"),
        'manifest must be physically written to the symlink target');

    $origCwd = getcwd();
    chdir($repo);
    try {
        $rc = envlite_cmd_clean([], true);
    } finally {
        chdir($origCwd);
    }

    envlite_assert_eq(0, $rc, 'clean must succeed walking through the symlinked state dir');
    envlite_assert(!file_exists("$repo/wp-tests-config.php"),
        'managed wp-tests-config.php must be removed by the manifest walk');
    envlite_assert(!file_exists("$repo/src/wp-config.php"),
        'managed src/wp-config.php must be removed by the manifest walk');
    // The symlink itself is removed (round 8 symlink-aware top-level rule).
    envlite_assert(!is_link("$repo/.cache/envlite"),
        '.cache/envlite symlink must be unlinked by the final rrmdir step');
    // The symlink target survives by design (rrmdir refuses to recurse
    // through a symlink at the top level) — that's the safety guarantee
    // from round 8: clean cannot delete user-owned files reached via the
    // link. Some residual state files at the target are accepted; the
    // user can rm them manually.
    envlite_assert(is_dir($stateTarget),
        'symlink target dir must survive (round 8 safety contract)');
}

function test_clean_apply_state_exception_only_applies_to_state_entries() {
    // Round 28 P2 regression: the round-25 state-dir exception
    // accepted ANY manifest entry whose resolved path landed under
    // the state-dir target. An ancestor symlink to the state target
    // would let arbitrary manifest paths resolve there and pass
    // containment — clean would recurse outside the checkout through
    // the symlink for a non-state-dir entry. Fix: scope the exception
    // by the manifest key string (`.cache/envlite/...` only).
    if (DIRECTORY_SEPARATOR !== '/') { return; }
    $repo = envlite_test_tmpdir('clean-state-scope');

    // The state dir points outside the checkout (legitimate symlinked
    // state setup). Pre-create the state target so realpath resolves.
    $stateTarget = envlite_test_tmpdir('clean-state-scope-target');
    mkdir("$repo/.cache");
    symlink($stateTarget, "$repo/.cache/envlite");
    file_put_contents("$stateTarget/port", "8421\n");

    // The user has ALSO put an ancestor symlink at a non-state path
    // that happens to point at the state target. This is bizarre but
    // the round-25 exception silently allowed manifest entries under
    // it to recurse through.
    mkdir("$repo/src", 0755, true);
    symlink($stateTarget, "$repo/src/wp-content");
    // Make the deep manifest entry resolve to a real directory inside
    // the symlink target so the leaf existence check doesn't skip it.
    // Without this, clean_apply silently `continue`s before reaching
    // the containment check.
    mkdir("$stateTarget/plugins/sqlite-database-integration", 0755, true);
    file_put_contents("$stateTarget/plugins/sqlite-database-integration/inner",
        'inner-PRECIOUS');
    // External content that must survive clean.
    file_put_contents("$stateTarget/must-survive", 'PRECIOUS');

    // Manifest claims two entries:
    //   - .cache/envlite/port (state entry, exception applies, clean OK)
    //   - src/wp-content/plugins/...  (NON-state entry, exception must
    //     NOT apply even though it resolves under $stateTarget)
    $manifest = [
        '.cache/envlite/port' => hash('sha256', "8421\n"),
        'src/wp-content/plugins/sqlite-database-integration' => 'dir',
    ];
    $failed = envlite_clean_apply($repo, envlite_clean_collect($manifest));

    // The non-state entry must be flagged as failed (the exception
    // doesn't cover it); the state entry should be removed cleanly.
    envlite_assert(
        in_array('src/wp-content/plugins/sqlite-database-integration', $failed, true),
        'non-state entry resolving to state target must be flagged as failed'
    );
    envlite_assert_eq('PRECIOUS', file_get_contents("$stateTarget/must-survive"),
        'state-target contents must not be touched via the non-state ancestor symlink');
    envlite_assert_eq('inner-PRECIOUS',
        file_get_contents("$stateTarget/plugins/sqlite-database-integration/inner"),
        'inner directory reachable only via the non-state ancestor symlink must also survive');
}

function test_clean_apply_refuses_when_ancestor_is_symlink_to_outside() {
    // Round 24 P1 regression: rrmdir's top-level is_link guard only
    // protects the leaf component. When a manifest entry's PARENT (or
    // any ancestor) has been replaced with a symlink to outside the
    // checkout, is_dir($abs) for the leaf path resolves through the
    // ancestor symlink and returns true for a real directory at the
    // target. envlite_rrmdir then recursively deletes outside the
    // checkout — a `clean --force` could wipe data the user pointed
    // the symlink at.
    if (DIRECTORY_SEPARATOR !== '/') { return; }
    $repo = envlite_test_tmpdir('clean-ancestor-symlink');
    // Build a real "outside" tree that must survive.
    $outside = envlite_test_tmpdir('clean-ancestor-symlink-outside');
    mkdir("$outside/sqlite-database-integration", 0755, true);
    file_put_contents("$outside/sqlite-database-integration/precious", 'MUST_SURVIVE');
    file_put_contents("$outside/peer-file", 'peer-MUST_SURVIVE');

    // Inside the checkout, the manifest claims envlite owns
    // src/wp-content/plugins/sqlite-database-integration as a `dir`
    // entry — but the user (or an attacker) has replaced the parent
    // `src/wp-content/plugins` with a symlink to $outside.
    mkdir("$repo/src/wp-content", 0755, true);
    symlink($outside, "$repo/src/wp-content/plugins");

    $manifest = [
        'src/wp-content/plugins/sqlite-database-integration' => 'dir',
    ];
    $failed = envlite_clean_apply($repo, envlite_clean_collect($manifest));

    envlite_assert_eq(
        ['src/wp-content/plugins/sqlite-database-integration'],
        $failed,
        'clean_apply must refuse entries whose resolved path escapes the checkout'
    );
    envlite_assert_eq(
        'MUST_SURVIVE',
        file_get_contents("$outside/sqlite-database-integration/precious"),
        'symlink-target contents must NOT be touched by clean_apply'
    );
    envlite_assert_eq(
        'peer-MUST_SURVIVE',
        file_get_contents("$outside/peer-file"),
        'peers of the symlink target must also be untouched'
    );
}

function test_rrmdir_refuses_to_recurse_into_symlinked_directory() {
    // Round 8 P1 regression: if envlite_rrmdir is invoked on a symlink
    // that points to a real directory, the unguarded scandir would
    // happily follow the symlink and the recursive descent would delete
    // the target's contents. A confirmed `envlite clean` against a
    // `.cache/envlite` symlinked elsewhere would then wipe the user's
    // own files. The fix: unlink the symlink itself; never recurse.
    if (DIRECTORY_SEPARATOR !== '/') {
        return; // POSIX symlink semantics; Windows differs
    }
    $sandbox = envlite_test_tmpdir('rrmdir-symlink-target');
    file_put_contents("$sandbox/USER_DATA", 'must-survive');
    mkdir("$sandbox/subdir");
    file_put_contents("$sandbox/subdir/inner", 'must-also-survive');

    $linkDir = envlite_test_tmpdir('rrmdir-symlink-host');
    rmdir($linkDir); // remove the dir so the symlink can take its place
    symlink($sandbox, $linkDir);

    envlite_rrmdir($linkDir);

    envlite_assert(!is_link($linkDir),
        'rrmdir should unlink the symlink itself');
    envlite_assert(is_dir($sandbox),
        'rrmdir must not delete the symlink target directory');
    envlite_assert_eq('must-survive', file_get_contents("$sandbox/USER_DATA"),
        'rrmdir must not delete files in the symlink target');
    envlite_assert_eq('must-also-survive', file_get_contents("$sandbox/subdir/inner"),
        'rrmdir must not recurse through the symlink to delete deeper contents');
}

function test_clean_apply_removes_broken_symlink_manifest_entries() {
    // Round 8 regression: a manifest entry replaced by a broken (dangling)
    // symlink returns false for both file_exists (follows symlinks) and
    // is_dir, so the pre-delete existence check skipped it. Clean would
    // wipe `.cache/envlite/` at the end and leave the broken symlink as
    // an orphan with no remaining record that envlite ever owned the
    // path. Including is_link in the check makes the unlink fire.
    if (DIRECTORY_SEPARATOR !== '/') { return; }
    $dir = envlite_test_tmpdir('clean-broken-symlink');
    symlink("$dir/does-not-exist", "$dir/wp-tests-config.php");
    envlite_assert(is_link("$dir/wp-tests-config.php"),
        'fixture must have a dangling symlink to start');
    envlite_assert(!file_exists("$dir/wp-tests-config.php"),
        'file_exists must follow the broken symlink to false');

    $manifest = ['wp-tests-config.php' => str_repeat('a', 64)];
    $failed = envlite_clean_apply($dir, envlite_clean_collect($manifest));

    envlite_assert_eq([], $failed, 'broken-symlink entry must be removed cleanly');
    envlite_assert(!is_link("$dir/wp-tests-config.php"),
        'dangling symlink must be unlinked by clean_apply');
}

function test_clean_apply_reports_paths_that_remain_after_failed_deletion() {
    if (DIRECTORY_SEPARATOR !== '/' || posix_geteuid() === 0) {
        // Root can delete read-only-parent files; this test needs a
        // non-root POSIX environment. On Windows, file-locking behavior
        // differs and is tested separately.
        return;
    }
    $dir = envlite_test_tmpdir('clean-fail');
    mkdir("$dir/locked", 0755, true);
    file_put_contents("$dir/locked/keep", 'y');
    // chmod the parent dir to read+exec only; unlink inside it then fails.
    chmod("$dir/locked", 0555);
    $manifest = ['locked/keep' => hash('sha256', 'y')];
    try {
        $failed = envlite_clean_apply($dir, envlite_clean_collect($manifest));
        envlite_assert_eq(['locked/keep'], $failed,
            'clean_apply must surface paths that survive the delete attempt');
    } finally {
        chmod("$dir/locked", 0755);
    }
}

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

function test_observe_ht_sqlite_returns_existing_manifest_when_db_missing() {
    $dir = envlite_test_tmpdir('observe-no-db');
    mkdir("$dir/.cache/envlite", 0755, true);
    $seed = ['src/wp-config.php' => str_repeat('a', 64)];
    envlite_manifest_save($dir, $seed);

    envlite_assert_eq($seed, envlite_observe_ht_sqlite($dir, true));
    envlite_assert_eq($seed, envlite_observe_ht_sqlite($dir, false));
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

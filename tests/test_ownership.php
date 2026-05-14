<?php
function test_ownership_path_absent_when_no_disk_no_manifest() {
    envlite_assert_eq(
        'absent',
        envlite_ownership([], 'src/wp-config.php', false, null)
    );
}

function test_ownership_owned_clean() {
    $bytes = "<?php\n";
    $hash = hash('sha256', $bytes);
    envlite_assert_eq(
        'owned_clean',
        envlite_ownership(['src/wp-config.php' => $hash], 'src/wp-config.php', true, $bytes)
    );
}

function test_ownership_owned_drifted() {
    envlite_assert_eq(
        'owned_drifted',
        envlite_ownership(
            ['src/wp-config.php' => str_repeat('a', 64)],
            'src/wp-config.php',
            true,
            'different bytes'
        )
    );
}

function test_ownership_unowned() {
    envlite_assert_eq(
        'unowned',
        envlite_ownership([], 'src/wp-config.php', true, "user-authored\n")
    );
}

function test_ownership_owned_file_missing_on_disk_is_recreatable() {
    // If the manifest records an envlite-owned file but the user deleted it,
    // there is nothing to overwrite. Treat as safe to recreate (no prompt).
    envlite_assert_eq(
        'owned_clean',
        envlite_ownership(
            ['src/wp-config.php' => str_repeat('a', 64)],
            'src/wp-config.php',
            false,
            null
        )
    );
}

function test_ownership_dir_entry_in_manifest() {
    // For directory entries, current bytes is null (we don't drift-check
    // directory contents); presence on disk makes it owned_clean.
    envlite_assert_eq(
        'owned_clean',
        envlite_ownership(
            ['src/wp-content/plugins/sqlite-database-integration' => 'dir'],
            'src/wp-content/plugins/sqlite-database-integration',
            true,
            null
        )
    );
}

function test_ownership_unowned_non_regular_entry_returns_unowned() {
    // Round 7 regression: an existing non-regular entry at an output path
    // (broken symlink, FIFO, dir-where-a-file-should-be) reports
    // existsOnDisk=true with currentBytes=null. Without an explicit
    // existence flag, the older signature treated currentBytes=null as
    // "absent" and atomic_write replaced the entry without prompting.
    // The new contract: an existing entry not in the manifest is unowned,
    // regardless of whether its content is readable as a regular file.
    envlite_assert_eq(
        'unowned',
        envlite_ownership([], 'src/wp-config.php', true, null)
    );
}

function test_ownership_owned_non_regular_entry_returns_drifted() {
    // If the manifest claims envlite owns a regular file at the path but
    // the on-disk entry is non-regular (a symlink replaced our file, a
    // user-created FIFO, etc.), classify as drifted so the user is
    // prompted before the rename clobbers it.
    envlite_assert_eq(
        'owned_drifted',
        envlite_ownership(
            ['src/wp-config.php' => str_repeat('a', 64)],
            'src/wp-config.php',
            true,
            null
        )
    );
}

function test_path_inspect_returns_false_null_for_missing_path() {
    $dir = envlite_test_tmpdir('inspect-missing');
    [$exists, $bytes] = envlite_path_inspect("$dir/no-such-file");
    envlite_assert_eq([false, null], [$exists, $bytes]);
}

function test_path_inspect_returns_true_and_bytes_for_regular_file() {
    $dir = envlite_test_tmpdir('inspect-regular');
    file_put_contents("$dir/file", "hello\n");
    [$exists, $bytes] = envlite_path_inspect("$dir/file");
    envlite_assert_eq(true, $exists);
    envlite_assert_eq("hello\n", $bytes);
}

function test_path_inspect_returns_true_null_for_broken_symlink() {
    if (DIRECTORY_SEPARATOR !== '/') {
        return; // symlink semantics differ on Windows; tested where they bite
    }
    $dir = envlite_test_tmpdir('inspect-broken-symlink');
    symlink("$dir/does-not-exist-target", "$dir/link");
    [$exists, $bytes] = envlite_path_inspect("$dir/link");
    envlite_assert_eq(true, $exists, 'broken symlink must be detected as existing');
    envlite_assert_eq(null, $bytes, 'broken symlink must yield null bytes');
}

function test_path_inspect_returns_true_null_for_symlink_to_dir() {
    if (DIRECTORY_SEPARATOR !== '/') { return; }
    $dir = envlite_test_tmpdir('inspect-symlink-to-dir');
    mkdir("$dir/target-dir");
    symlink("$dir/target-dir", "$dir/link-to-dir");
    [$exists, $bytes] = envlite_path_inspect("$dir/link-to-dir");
    envlite_assert_eq(true, $exists);
    envlite_assert_eq(null, $bytes,
        'symlink-to-directory must yield null bytes (we never wrote a symlink)');
}

function test_path_inspect_returns_true_null_for_symlink_to_regular_file() {
    if (DIRECTORY_SEPARATOR !== '/') { return; }
    // A symlink-to-regular-file is treated as non-regular for ownership:
    // envlite never writes a symlink, so finding one at our path is drift.
    $dir = envlite_test_tmpdir('inspect-symlink-to-file');
    file_put_contents("$dir/real", "target-bytes\n");
    symlink("$dir/real", "$dir/link");
    [$exists, $bytes] = envlite_path_inspect("$dir/link");
    envlite_assert_eq(true, $exists);
    envlite_assert_eq(null, $bytes,
        'symlink-to-regular must yield null bytes (envlite never wrote a symlink)');
}

function test_path_inspect_returns_true_null_for_directory_at_file_path() {
    $dir = envlite_test_tmpdir('inspect-dir-at-file-path');
    mkdir("$dir/should-be-a-file");
    [$exists, $bytes] = envlite_path_inspect("$dir/should-be-a-file");
    envlite_assert_eq(true, $exists);
    envlite_assert_eq(null, $bytes);
}

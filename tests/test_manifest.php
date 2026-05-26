<?php
function envlite_test_tmpdir(string $name): string {
    $dir = sys_get_temp_dir() . '/envlite-test-' . $name . '-' . bin2hex(random_bytes(4));
    // Fail fast if mkdir doesn't succeed. Returning a non-existent path
    // would cascade into the rest of the test as misleading false
    // failures or, worse, silently-passing tests that never exercise
    // the code under test (the fixture file_put_contents/mkdir calls
    // below would fail silently with @-prefixed errors elsewhere).
    if (!@mkdir($dir, 0700, true)) {
        throw new \RuntimeException("envlite_test_tmpdir: cannot create $dir");
    }
    return $dir;
}

function test_manifest_load_throws_when_path_is_a_directory() {
    // Round 14 regression: !is_file($path) used to return [] (empty
    // manifest) when the path was a directory, hiding the same
    // ownership-loss scenarios codex flagged in round 13: a `clean
    // --force` would wipe `.cache/envlite/` and orphan every managed
    // file. Now manifest_load throws on any non-regular existing path.
    $dir = envlite_test_tmpdir('manifest-as-directory');
    mkdir("$dir/.cache/envlite", 0755, true);
    mkdir("$dir/.cache/envlite/manifest"); // directory at the manifest path!
    $thrown = null;
    try {
        envlite_manifest_load($dir);
    } catch (\Throwable $e) {
        $thrown = $e;
    }
    envlite_assert($thrown !== null,
        'manifest_load must throw when the manifest path is a directory');
    envlite_assert(strpos($thrown->getMessage(), 'not a regular file') !== false,
        'error must explain why; got: ' . $thrown->getMessage());
}

function test_manifest_load_throws_when_path_is_broken_symlink() {
    if (DIRECTORY_SEPARATOR !== '/') { return; }
    $dir = envlite_test_tmpdir('manifest-broken-symlink');
    mkdir("$dir/.cache/envlite", 0755, true);
    symlink("$dir/.cache/envlite/does-not-exist", "$dir/.cache/envlite/manifest");
    $thrown = null;
    try {
        envlite_manifest_load($dir);
    } catch (\Throwable $e) {
        $thrown = $e;
    }
    envlite_assert($thrown !== null,
        'manifest_load must throw on a broken symlink at the manifest path');
    envlite_assert(strpos($thrown->getMessage(), 'not a regular file') !== false,
        'error must name the non-regular case; got: ' . $thrown->getMessage());
}

function test_manifest_load_throws_when_path_is_symlink_to_file() {
    // A symlink to a regular file is non-regular for envlite's purposes
    // — envlite never writes a symlink at the manifest path, so finding
    // one means external interference. Refusing to load is safer than
    // reading through it (especially because the symlink target could
    // point anywhere on disk).
    if (DIRECTORY_SEPARATOR !== '/') { return; }
    $dir = envlite_test_tmpdir('manifest-symlink-to-file');
    mkdir("$dir/.cache/envlite", 0755, true);
    $target = $dir . '/external-manifest';
    file_put_contents($target, str_repeat('a', 64) . "  some/path\n");
    symlink($target, "$dir/.cache/envlite/manifest");
    $thrown = null;
    try {
        envlite_manifest_load($dir);
    } catch (\Throwable $e) {
        $thrown = $e;
    }
    envlite_assert($thrown !== null,
        'manifest_load must throw on a symlink at the manifest path');
    envlite_assert(strpos($thrown->getMessage(), 'not a regular file') !== false,
        'error must name the non-regular case; got: ' . $thrown->getMessage());
}

function test_manifest_load_throws_when_file_exists_but_unreadable() {
    // Round 13 regression: file_get_contents returns false on read
    // failure, and the prior implementation foreach'd the false and
    // treated the manifest as empty. A clean --force then wiped
    // .cache/envlite/ while leaving every managed file orphaned; a
    // following up rewrote the manifest with only the new entries,
    // losing the historical ownership records. The fix throws a
    // RuntimeException — callers can choose to abort or guard it.
    if (DIRECTORY_SEPARATOR !== '/' || posix_geteuid() === 0) { return; }
    $dir = envlite_test_tmpdir('manifest-unreadable');
    mkdir("$dir/.cache/envlite", 0755, true);
    file_put_contents("$dir/.cache/envlite/manifest", str_repeat('a', 64) . "  some/path\n");
    chmod("$dir/.cache/envlite/manifest", 0000); // no read for owner
    try {
        $thrown = null;
        try {
            envlite_manifest_load($dir);
        } catch (\Throwable $e) {
            $thrown = $e;
        }
        envlite_assert($thrown !== null,
            'manifest_load must throw when an existing manifest is unreadable');
        envlite_assert(strpos($thrown->getMessage(), 'cannot read manifest') !== false,
            'error must name the unreadable manifest; got: ' . $thrown->getMessage());
    } finally {
        chmod("$dir/.cache/envlite/manifest", 0644);
    }
}

function test_manifest_load_missing_returns_empty() {
    $dir = envlite_test_tmpdir('manifest-missing');
    envlite_assert_eq([], envlite_manifest_load($dir));
}

function test_manifest_round_trip_preserves_order() {
    $dir = envlite_test_tmpdir('manifest-rt');
    mkdir($dir . '/.cache/envlite', 0755, true);
    $entries = [
        '.cache/envlite/port' => 'a3f1c8b2' . str_repeat('0', 56),
        'src/wp-config.php' => str_repeat('b', 64),
        'src/wp-content/plugins/sqlite-database-integration' => 'dir',
    ];
    envlite_manifest_save($dir, $entries);
    envlite_assert_eq($entries, envlite_manifest_load($dir));
    // Order must round-trip.
    envlite_assert_eq(array_keys($entries), array_keys(envlite_manifest_load($dir)));
}

function test_manifest_save_emits_lf_only() {
    $dir = envlite_test_tmpdir('manifest-lf');
    mkdir($dir . '/.cache/envlite', 0755, true);
    envlite_manifest_save($dir, ['src/wp-config.php' => str_repeat('a', 64)]);
    $bytes = file_get_contents($dir . '/.cache/envlite/manifest');
    envlite_assert(strpos($bytes, "\r") === false, 'manifest must not contain CR');
    envlite_assert(substr($bytes, -1) === "\n", 'manifest must end with LF');
}

function test_manifest_load_skips_blank_and_malformed_lines() {
    $dir = envlite_test_tmpdir('manifest-malformed');
    mkdir($dir . '/.cache/envlite', 0755, true);
    file_put_contents(
        $dir . '/.cache/envlite/manifest',
        str_repeat('a', 64) . "  src/wp-config.php\n" .
        "\n" .
        "garbage line\n" .
        "dir  some/dir\n"
    );
    $loaded = envlite_manifest_load($dir);
    envlite_assert_eq(['src/wp-config.php' => str_repeat('a', 64), 'some/dir' => 'dir'], $loaded);
}

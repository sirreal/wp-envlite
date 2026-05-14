<?php
function envlite_test_tmpdir(string $name): string {
    $dir = sys_get_temp_dir() . '/envlite-test-' . $name . '-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    return $dir;
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

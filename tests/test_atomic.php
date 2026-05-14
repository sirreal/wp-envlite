<?php
function test_atomic_write_returns_hash_of_bytes() {
    $dir = envlite_test_tmpdir('atomic');
    $path = $dir . '/foo.txt';
    $bytes = "hello world\n";
    $hash = envlite_atomic_write($path, $bytes);
    envlite_assert_eq(hash('sha256', $bytes), $hash);
    envlite_assert_eq($bytes, file_get_contents($path));
}

function test_atomic_write_overwrites_existing() {
    $dir = envlite_test_tmpdir('atomic-overwrite');
    $path = $dir . '/foo.txt';
    file_put_contents($path, 'old');
    envlite_atomic_write($path, 'new');
    envlite_assert_eq('new', file_get_contents($path));
}

function test_atomic_write_no_tmp_left_behind() {
    // After a successful write, no envlite-tmp sibling must remain
    // beside the destination. The match pattern accommodates the
    // unique-suffix scheme introduced to prevent user-file collisions.
    $dir = envlite_test_tmpdir('atomic-clean');
    $path = $dir . '/foo.txt';
    envlite_atomic_write($path, 'x');
    $leftovers = glob($dir . '/foo.txt.envlite-tmp.*');
    envlite_assert_eq([], $leftovers, 'no envlite-tmp sibling must remain');
    // Also confirm nothing at the old deterministic `.tmp` name (older
    // releases used `$path . '.tmp'`).
    envlite_assert(!file_exists($path . '.tmp'), 'no legacy .tmp must remain');
}

function test_atomic_write_creates_parent_dir() {
    $dir = envlite_test_tmpdir('atomic-parent');
    $path = $dir . '/sub/dir/foo.txt';
    envlite_atomic_write($path, 'x');
    envlite_assert_eq('x', file_get_contents($path));
}

function test_atomic_write_does_not_touch_preexisting_file_at_legacy_tmp_name() {
    // Round 7 regression: the previous deterministic `$path.'.tmp'` was a
    // destructive-write footgun — `fopen($tmp, 'wb')` would truncate a
    // user file at the legacy `.tmp` path (or follow a symlink there and
    // truncate the target). The new unique-suffix scheme makes the
    // collision space astronomically large, so a pre-existing file at
    // the old name is left untouched.
    $dir = envlite_test_tmpdir('atomic-preexisting-tmp');
    $path = $dir . '/foo.txt';
    $legacy = $path . '.tmp';
    file_put_contents($legacy, 'USER_DATA_THAT_MUST_SURVIVE');
    envlite_atomic_write($path, 'envlite-payload');
    envlite_assert_eq('envlite-payload', file_get_contents($path));
    envlite_assert_eq('USER_DATA_THAT_MUST_SURVIVE', file_get_contents($legacy),
        'legacy .tmp sibling must not be clobbered by the atomic write');
}

function test_atomic_write_does_not_follow_symlink_at_legacy_tmp_name() {
    // Companion to the above: a symlink at the legacy `.tmp` path
    // pointing to an external user file must be left intact (no
    // truncate-through-symlink) by the atomic write.
    if (DIRECTORY_SEPARATOR !== '/') { return; }
    $dir = envlite_test_tmpdir('atomic-preexisting-symlink');
    $path = $dir . '/foo.txt';
    $target = $dir . '/external-user-file';
    file_put_contents($target, 'EXTERNAL_DATA');
    symlink($target, $path . '.tmp');
    envlite_atomic_write($path, 'envlite-payload');
    envlite_assert_eq('envlite-payload', file_get_contents($path));
    envlite_assert_eq('EXTERNAL_DATA', file_get_contents($target),
        'symlink target must not be truncated/followed by the atomic write');
}

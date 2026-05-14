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
    $dir = envlite_test_tmpdir('atomic-clean');
    $path = $dir . '/foo.txt';
    envlite_atomic_write($path, 'x');
    envlite_assert(!file_exists($path . '.tmp'), '.tmp must not remain');
}

function test_atomic_write_creates_parent_dir() {
    $dir = envlite_test_tmpdir('atomic-parent');
    $path = $dir . '/sub/dir/foo.txt';
    envlite_atomic_write($path, 'x');
    envlite_assert_eq('x', file_get_contents($path));
}

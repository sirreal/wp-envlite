<?php
function test_path_to_posix_replaces_backslashes() {
    envlite_assert_eq('a/b/c', envlite_path_to_posix('a\\b\\c'));
    envlite_assert_eq('a/b/c', envlite_path_to_posix('a/b/c'));
    envlite_assert_eq('a/b/c', envlite_path_to_posix('a\\b/c'));
}

function test_path_relative_to_root() {
    $root = '/tmp/repo';
    envlite_assert_eq('foo.txt', envlite_path_relative_to($root, '/tmp/repo/foo.txt'));
    envlite_assert_eq('a/b.txt', envlite_path_relative_to($root, '/tmp/repo/a/b.txt'));
    // Trailing slash in root tolerated.
    envlite_assert_eq('foo.txt', envlite_path_relative_to('/tmp/repo/', '/tmp/repo/foo.txt'));
}

function test_path_relative_to_root_throws_for_outside() {
    try {
        envlite_path_relative_to('/tmp/repo', '/etc/passwd');
        throw new \RuntimeException('expected exception');
    } catch (\InvalidArgumentException $e) {
        envlite_assert(strpos($e->getMessage(), 'outside repo root') !== false);
    }
}

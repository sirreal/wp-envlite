<?php
function test_path_to_posix_replaces_backslashes_only_on_windows() {
    if (PHP_OS_FAMILY === 'Windows') {
        // Windows: `\` is a path separator; normalize to forward slashes.
        envlite_assert_eq('a/b/c', envlite_path_to_posix('a\\b\\c'));
        envlite_assert_eq('a/b/c', envlite_path_to_posix('a/b/c'));
        envlite_assert_eq('a/b/c', envlite_path_to_posix('a\\b/c'));
        return;
    }
    // Unix: `\` is a legal filename character. Rewriting it would corrupt
    // state-directory paths for checkouts sitting at e.g. `/tmp/wp\dev`,
    // and phases would write to the real cwd while envlite's `.cache/...`
    // paths landed at a different filesystem location.
    envlite_assert_eq('a\\b\\c', envlite_path_to_posix('a\\b\\c'));
    envlite_assert_eq('a/b/c', envlite_path_to_posix('a/b/c'));
    envlite_assert_eq('a\\b/c', envlite_path_to_posix('a\\b/c'));
}

function test_path_relative_to_preserves_backslashes_in_unix_root() {
    // Round 22 regression: round-0's path_relative_to ran every path
    // through envlite_path_to_posix which rewrote `\` even on Unix.
    // A checkout at `/tmp/wp\dev` would have its manifest paths
    // computed against a non-existent `/tmp/wp/dev` parent, and the
    // prefix-substring check would fail to recognize the real
    // checkout as the root. Confirm the helper now passes the legal
    // backslash through.
    if (PHP_OS_FAMILY === 'Windows') { return; }
    envlite_assert_eq(
        'foo.txt',
        envlite_path_relative_to('/tmp/wp\\dev', '/tmp/wp\\dev/foo.txt')
    );
    envlite_assert_eq(
        'sub/file',
        envlite_path_relative_to('/tmp/odd\\path', '/tmp/odd\\path/sub/file')
    );
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

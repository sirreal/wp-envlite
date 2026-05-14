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
    @unlink("$dir/.cache/envlite/manifest");
    @rmdir("$dir/.cache/envlite");

    envlite_assert(!file_exists("$dir/wp-tests-config.php"));
    envlite_assert(!is_dir("$dir/sub"));
    envlite_assert(!is_dir("$dir/.cache/envlite"));
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

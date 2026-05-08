<?php
function test_clean_collects_in_reverse_insertion_order() {
    $manifest = [
        '.envlite/port' => str_repeat('a', 64),
        'src/wp-config.php' => str_repeat('b', 64),
        'wp-tests-config.php' => str_repeat('c', 64),
    ];
    $order = envlite_clean_collect($manifest);
    envlite_assert_eq(['wp-tests-config.php', 'src/wp-config.php', '.envlite/port'], $order);
}

function test_clean_removes_files_dirs_and_state() {
    $dir = envlite_test_tmpdir('clean');
    mkdir("$dir/.envlite");
    mkdir("$dir/sub", 0755, true);
    file_put_contents("$dir/wp-tests-config.php", 'x');
    file_put_contents("$dir/sub/db.php", 'y');
    $manifest = [
        '.envlite/port' => hash('sha256', 'p'),
        'wp-tests-config.php' => hash('sha256', 'x'),
        'sub'           => 'dir',
    ];
    envlite_manifest_save($dir, $manifest);
    file_put_contents("$dir/.envlite/port", 'p');

    envlite_clean_apply($dir, envlite_clean_collect($manifest));
    // Simulate the subcommand-level cleanup that follows envlite_clean_apply.
    @unlink("$dir/.envlite/manifest");
    @rmdir("$dir/.envlite");

    envlite_assert(!file_exists("$dir/wp-tests-config.php"));
    envlite_assert(!is_dir("$dir/sub"));
    envlite_assert(!is_dir("$dir/.envlite"));
}

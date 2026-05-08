<?php
function test_ownership_path_absent_when_no_disk_no_manifest() {
    envlite_assert_eq(
        'absent',
        envlite_ownership([], 'src/wp-config.php', null)
    );
}

function test_ownership_owned_clean() {
    $bytes = "<?php\n";
    $hash = hash('sha256', $bytes);
    envlite_assert_eq(
        'owned_clean',
        envlite_ownership(['src/wp-config.php' => $hash], 'src/wp-config.php', $bytes)
    );
}

function test_ownership_owned_drifted() {
    envlite_assert_eq(
        'owned_drifted',
        envlite_ownership(
            ['src/wp-config.php' => str_repeat('a', 64)],
            'src/wp-config.php',
            'different bytes'
        )
    );
}

function test_ownership_unowned() {
    envlite_assert_eq(
        'unowned',
        envlite_ownership([], 'src/wp-config.php', "user-authored\n")
    );
}

function test_ownership_dir_entry_in_manifest() {
    // For directory entries, the "current bytes" is null; presence on disk
    // makes it owned_clean (we don't drift-check directory contents).
    envlite_assert_eq(
        'owned_clean',
        envlite_ownership(
            ['src/wp-content/plugins/sqlite-database-integration' => 'dir'],
            'src/wp-content/plugins/sqlite-database-integration',
            null
        )
    );
}

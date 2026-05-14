<?php
function envlite_test_wp_tests_config_sample(): string {
    // Inline mimic of wp-tests-config-sample.php carrying exactly what
    // envlite_phase6_render touches: the three test-db placeholders and
    // the bare WP_PHP_BINARY define that envlite pins to PHP_BINARY.
    return "<?php\n"
        . "define( 'DB_NAME',     'youremptytestdbnamehere' );\n"
        . "define( 'DB_USER',     'yourusernamehere' );\n"
        . "define( 'DB_PASSWORD', 'yourpasswordhere' );\n"
        . "define( 'DB_HOST',     'localhost' );\n"
        . "define( 'WP_PHP_BINARY', 'php' );\n";
}

function envlite_test_make_fixture_repo(): string {
    $dir = envlite_test_tmpdir('smoke');
    // Minimum tree to satisfy Phase 0's CWD check and Phases 5–7.
    mkdir("$dir/src/wp-includes", 0755, true);
    mkdir("$dir/tests/phpunit/includes", 0755, true);
    mkdir("$dir/src/wp-content/plugins/sqlite-database-integration", 0755, true);
    file_put_contents("$dir/package.json", '{}');
    file_put_contents("$dir/composer.json", '{}');
    file_put_contents("$dir/tests/phpunit/includes/bootstrap.php", '<?php');
    // Inline samples so the fixture is hermetic; substitutions are still
    // exercised against realistic placeholder content.
    file_put_contents("$dir/wp-config-sample.php",       envlite_test_wp_config_sample());
    file_put_contents("$dir/wp-tests-config-sample.php", envlite_test_wp_tests_config_sample());
    // Pre-stage the SQLite plugin so Phase 5 hits the skip-download branch.
    file_put_contents(
        "$dir/src/wp-content/plugins/sqlite-database-integration/db.copy",
        "<?php\n// {SQLITE_IMPLEMENTATION_FOLDER_PATH}\nreturn 'stub';\n"
    );
    return $dir;
}

function test_smoke_phases5_through_7_then_clean() {
    $dir = envlite_test_make_fixture_repo();

    // Pre-record the plugin tree as envlite-owned AND record the pin SHA so
    // Phase 5 takes the skip branch (manifest + db.copy + pin all required).
    // Without the pin, phase 5 falls through to the HTTP download path and
    // this test becomes network-dependent.
    $manifest = ['src/wp-content/plugins/sqlite-database-integration' => 'dir'];
    envlite_manifest_save($dir, $manifest);
    envlite_state_save($dir, ['phase5.recorded_pin_sha' => ENVLITE_SQLITE_PLUGIN_SHA256]);

    // Drive Phases 5–7 with --force (no TTY in test).
    envlite_phase5_install($dir, true);
    envlite_phase6_install($dir, true);
    envlite_phase7_install($dir, 8421, true);

    // Assert artifacts present.
    envlite_assert(is_file("$dir/src/wp-content/db.php"));
    envlite_assert(is_file("$dir/wp-tests-config.php"));
    envlite_assert(is_file("$dir/src/wp-config.php"));

    // Manifest contains all three file entries plus the plugin dir.
    $m = envlite_manifest_load($dir);
    envlite_assert(isset($m['src/wp-content/db.php']));
    envlite_assert(isset($m['wp-tests-config.php']));
    envlite_assert(isset($m['src/wp-config.php']));
    envlite_assert(isset($m['src/wp-content/plugins/sqlite-database-integration']));

    // wp-config.php picked up the port.
    envlite_assert(strpos(file_get_contents("$dir/src/wp-config.php"), 'http://127.0.0.1:8421') !== false);

    // Now drive clean (force, no TTY).
    $paths = envlite_clean_collect($m);
    envlite_clean_apply($dir, $paths);
    @unlink("$dir/.cache/envlite/manifest");
    @unlink("$dir/.cache/envlite/state");
    @rmdir("$dir/.cache/envlite");

    envlite_assert(!is_file("$dir/wp-tests-config.php"));
    envlite_assert(!is_file("$dir/src/wp-config.php"));
    envlite_assert(!is_file("$dir/src/wp-content/db.php"));
    envlite_assert(!is_dir("$dir/src/wp-content/plugins/sqlite-database-integration"));
    envlite_assert(!is_dir("$dir/.cache/envlite"));
}

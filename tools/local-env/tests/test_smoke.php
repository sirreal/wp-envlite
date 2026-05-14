<?php
function envlite_test_make_fixture_repo(): string {
    $dir = envlite_test_tmpdir('smoke');
    // Minimum tree to satisfy Phase 0's CWD check and Phases 5–7.
    mkdir("$dir/src/wp-includes", 0755, true);
    mkdir("$dir/tests/phpunit/includes", 0755, true);
    mkdir("$dir/src/wp-content/plugins/sqlite-database-integration", 0755, true);
    file_put_contents("$dir/package.json", '{}');
    file_put_contents("$dir/composer.json", '{}');
    file_put_contents("$dir/tests/phpunit/includes/bootstrap.php", '<?php');
    // Real samples, copied from the test repo so substitutions are exercised.
    $repoRoot = dirname(__DIR__, 3);
    copy("$repoRoot/wp-config-sample.php",       "$dir/wp-config-sample.php");
    copy("$repoRoot/wp-tests-config-sample.php", "$dir/wp-tests-config-sample.php");
    // Pre-stage the SQLite plugin so Phase 5 hits the skip-download branch.
    file_put_contents(
        "$dir/src/wp-content/plugins/sqlite-database-integration/db.copy",
        "<?php\n// {SQLITE_IMPLEMENTATION_FOLDER_PATH}\nreturn 'stub';\n"
    );
    return $dir;
}

function test_smoke_phases5_through_7_then_clean() {
    $dir = envlite_test_make_fixture_repo();

    // Pre-record the plugin tree as envlite-owned so Phase 5 takes the skip branch.
    $manifest = ['src/wp-content/plugins/sqlite-database-integration' => 'dir'];
    envlite_manifest_save($dir, $manifest);

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
    @unlink("$dir/.envlite/manifest");
    @unlink("$dir/.envlite/state");
    @rmdir("$dir/.envlite");

    envlite_assert(!is_file("$dir/wp-tests-config.php"));
    envlite_assert(!is_file("$dir/src/wp-config.php"));
    envlite_assert(!is_file("$dir/src/wp-content/db.php"));
    envlite_assert(!is_dir("$dir/src/wp-content/plugins/sqlite-database-integration"));
    envlite_assert(!is_dir("$dir/.envlite"));
}

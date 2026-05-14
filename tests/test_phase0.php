<?php
function test_phase0_cwd_check_passes_for_real_repo() {
    // The test runs inside the repo. Use the path that contains the runner.
    $root = dirname(__DIR__, 3); // tests/ -> local-env/ -> tools/ -> repo
    envlite_assert(envlite_phase0_is_wordpress_develop($root), "expected $root to be a WP-develop checkout");
}

function test_phase0_cwd_check_fails_for_random_dir() {
    $dir = envlite_test_tmpdir('phase0-bogus');
    envlite_assert(!envlite_phase0_is_wordpress_develop($dir));
}

function test_phase0_parse_version_node() {
    envlite_assert_eq([20, 10, 0], envlite_phase0_parse_version('v20.10.0'));
    envlite_assert_eq([22, 5, 1], envlite_phase0_parse_version('v22.5.1\n'));
}

function test_phase0_parse_version_npm() {
    envlite_assert_eq([10, 2, 4], envlite_phase0_parse_version('10.2.4'));
}

function test_phase0_parse_version_composer() {
    envlite_assert_eq([2, 7, 1], envlite_phase0_parse_version('Composer version 2.7.1 2024-02-09 15:26:28'));
}

function test_phase0_version_meets_minimum() {
    envlite_assert(envlite_phase0_version_ge([20, 10, 0], [20, 10, 0]));
    envlite_assert(envlite_phase0_version_ge([20, 10, 1], [20, 10, 0]));
    envlite_assert(envlite_phase0_version_ge([21, 0, 0], [20, 10, 0]));
    envlite_assert(!envlite_phase0_version_ge([20, 9, 0], [20, 10, 0]));
    envlite_assert(!envlite_phase0_version_ge([19, 99, 99], [20, 10, 0]));
}

function test_phase0_required_extensions_include_pcntl_on_unix() {
    // The list is the source of truth used by envlite_phase0_run.
    // We test the *list*, not by re-running phase0 (which exits the test runner).
    if (PHP_OS_FAMILY === 'Windows') {
        // On Windows, pcntl is not in the list. Sanity-check the inverse.
        envlite_assert(
            !in_array('pcntl', envlite_phase0_required_extensions(), true),
            'pcntl must NOT be required on Windows'
        );
        return;
    }
    envlite_assert(
        in_array('pcntl', envlite_phase0_required_extensions(), true),
        'pcntl must be required on Unix'
    );
}

function test_phase0_required_extensions_includes_existing_set() {
    // gd is required by the WP core test bootstrap (phpunit.xml.dist sets
    // WP_RUN_CORE_TESTS=1), so envlite must surface its absence at preflight.
    foreach (['gd', 'pdo_sqlite', 'sqlite3', 'openssl', 'simplexml', 'zip'] as $ext) {
        envlite_assert(
            in_array($ext, envlite_phase0_required_extensions(), true),
            "$ext must remain required"
        );
    }
}

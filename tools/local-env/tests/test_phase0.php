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

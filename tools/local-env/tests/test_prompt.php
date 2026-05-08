<?php
function test_format_prompt_path_only() {
    envlite_assert_eq(
        "envlite init: not envlite-owned: router.php. Overwrite? [y/N] ",
        envlite_format_prompt('init', 'overwrite', 'router.php', null, null)
    );
}

function test_format_prompt_drifted_includes_hash_preview() {
    $rec = 'a3f1c8b2' . str_repeat('0', 56);
    $cur = '9e07d44a' . str_repeat('0', 56);
    envlite_assert_eq(
        "envlite init: envlite owns wp-tests-config.php but content has drifted (recorded a3f1c8b2\u{2026}, current 9e07d44a\u{2026}). Overwrite? [y/N] ",
        envlite_format_prompt('init', 'overwrite', 'wp-tests-config.php', $rec, $cur)
    );
}

function test_prompt_force_returns_yes_without_io() {
    // Inject closed streams to prove no I/O happens under --force.
    $in = fopen('php://memory', 'r');
    $err = fopen('php://memory', 'w');
    envlite_assert_eq(true, envlite_prompt_io(
        true, false, 'init', 'overwrite', 'x', null, null, $in, $err
    ));
}

function test_prompt_yes_response() {
    $in = fopen('php://memory', 'r+'); fwrite($in, "y\n"); rewind($in);
    $err = fopen('php://memory', 'w');
    envlite_assert_eq(true, envlite_prompt_io(
        false, true, 'init', 'overwrite', 'x', null, null, $in, $err
    ));
}

function test_prompt_n_default_on_empty_response() {
    $in = fopen('php://memory', 'r+'); fwrite($in, "\n"); rewind($in);
    $err = fopen('php://memory', 'w');
    envlite_assert_eq(false, envlite_prompt_io(
        false, true, 'init', 'overwrite', 'x', null, null, $in, $err
    ));
}

function test_prompt_eof_returns_no() {
    $in = fopen('php://memory', 'r'); // empty -> immediate EOF
    $err = fopen('php://memory', 'w');
    envlite_assert_eq(false, envlite_prompt_io(
        false, true, 'init', 'overwrite', 'x', null, null, $in, $err
    ));
}

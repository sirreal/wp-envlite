<?php
function test_log_top_level_format() {
    envlite_assert_eq("envlite: hello\n", envlite_format_log(null, 'hello'));
}

function test_log_subcommand_format() {
    envlite_assert_eq("envlite init: hello\n", envlite_format_log('init', 'hello'));
}

function test_log_phase_format() {
    envlite_assert_eq(
        "envlite init: phase 5: SHA256 mismatch\n",
        envlite_format_log('init', 'phase 5: SHA256 mismatch')
    );
}

function test_log_no_trailing_newlines_doubled() {
    // Trailing newline in input must not be duplicated.
    envlite_assert_eq("envlite: hi\n", envlite_format_log(null, "hi\n"));
}

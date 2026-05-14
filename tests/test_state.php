<?php
function test_state_load_returns_empty_when_missing() {
    $dir = envlite_test_tmpdir('state-empty');
    envlite_assert_eq([], envlite_state_load($dir));
}

function test_state_save_then_load_round_trip() {
    $dir = envlite_test_tmpdir('state-roundtrip');
    $entries = [
        'phase2.input_hash' => str_repeat('a', 64),
        'phase4.input_hash' => str_repeat('b', 64),
        'phase5.recorded_pin_sha' => str_repeat('c', 64),
    ];
    envlite_state_save($dir, $entries);
    envlite_assert(is_file("$dir/.cache/envlite/state"));

    $loaded = envlite_state_load($dir);
    envlite_assert_eq($entries, $loaded);
}

function test_state_save_writes_tab_delimited_lines() {
    $dir = envlite_test_tmpdir('state-format');
    envlite_state_save($dir, ['phase2.input_hash' => str_repeat('a', 64)]);
    $bytes = file_get_contents("$dir/.cache/envlite/state");
    // One line, tab between key and value, trailing newline.
    envlite_assert_eq("phase2.input_hash\t" . str_repeat('a', 64) . "\n", $bytes);
}

function test_state_load_ignores_malformed_lines() {
    $dir = envlite_test_tmpdir('state-malformed');
    mkdir("$dir/.cache/envlite", 0755, true);
    file_put_contents(
        "$dir/.cache/envlite/state",
        "good\tvalue\nbadline-no-tab\n\nphase2.input_hash\thashvalue\n"
    );
    envlite_assert_eq(
        ['good' => 'value', 'phase2.input_hash' => 'hashvalue'],
        envlite_state_load($dir)
    );
}

function test_state_save_overwrites_atomically() {
    // Overwriting an existing state file must leave a valid file (no .tmp
    // residue, single coherent body).
    $dir = envlite_test_tmpdir('state-atomic');
    envlite_state_save($dir, ['k' => 'v1']);
    envlite_state_save($dir, ['k' => 'v2', 'k2' => 'v3']);
    envlite_assert_eq(['k' => 'v2', 'k2' => 'v3'], envlite_state_load($dir));
    envlite_assert(!file_exists("$dir/.cache/envlite/state.tmp"), 'no .tmp residue');
}

<?php
function test_proc_capture_returns_exit_zero_for_php_echo() {
    [$exit, $stdout, $stderr] = envlite_proc_capture(['php', '-r', 'echo "hello";']);
    envlite_assert_eq(0, $exit);
    envlite_assert_eq('hello', $stdout);
    envlite_assert_eq('', $stderr);
}

function test_proc_capture_propagates_nonzero_exit() {
    [$exit, , ] = envlite_proc_capture(['php', '-r', 'exit(7);']);
    envlite_assert_eq(7, $exit);
}

function test_proc_capture_returns_minus_one_for_missing_binary() {
    [$exit, , ] = envlite_proc_capture(['definitely-not-a-real-binary-xyz']);
    envlite_assert($exit !== 0, 'missing binary must surface as nonzero');
}

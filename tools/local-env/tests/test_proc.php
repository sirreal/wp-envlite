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

function test_proc_capture_drains_large_stderr_without_deadlock() {
    // Pipe buffers on Linux are ~64KB. Sequential drain (stdout then
    // stderr) deadlocks when the child writes more than one buffer to
    // stderr while stdout is still open. Emit ~256KB to stderr while
    // also writing to stdout, then exit normally.
    $code = 'fwrite(STDERR, str_repeat("e", 262144)); echo "ok";';
    [$exit, $stdout, $stderr] = envlite_proc_capture(['php', '-r', $code]);
    envlite_assert_eq(0, $exit);
    envlite_assert_eq('ok', $stdout);
    envlite_assert_eq(262144, strlen($stderr));
}

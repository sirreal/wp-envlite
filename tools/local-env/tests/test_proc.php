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

function test_resolve_windows_command_passthrough_on_non_windows() {
    if (PHP_OS_FAMILY === 'Windows') { return; }
    // On non-Windows, the resolver must not transform the command — Unix
    // proc_open already does PATH lookup correctly for bare names.
    envlite_assert_eq('npm', envlite_resolve_windows_command('npm'));
    envlite_assert_eq('/usr/bin/foo', envlite_resolve_windows_command('/usr/bin/foo'));
}

function test_resolve_cmd_preserves_args_and_first_element_type() {
    // The first element is the executable; the rest are arguments and must
    // pass through untouched regardless of platform.
    $resolved = envlite_resolve_cmd(['php', '-r', 'echo 1;']);
    envlite_assert_eq('-r',           $resolved[1]);
    envlite_assert_eq('echo 1;',      $resolved[2]);
    envlite_assert(is_string($resolved[0]));
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

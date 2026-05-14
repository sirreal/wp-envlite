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

function test_cmd_exe_wrap_string_simple_args_no_quoting() {
    // Simple alnum args with no special chars or whitespace are not quoted;
    // they appear inside the outer /c quotes verbatim.
    $out = envlite_cmd_exe_wrap_string(['npm', '--version']);
    envlite_assert_eq('cmd.exe /d /s /c "npm --version"', $out);
}

function test_cmd_exe_wrap_string_path_with_spaces_inner_quoted() {
    // The realistic scenario: nodejs installer's default install path is
    // `C:\Program Files\nodejs\npm.cmd`. The path-with-spaces argument
    // must be wrapped in inner double quotes so cmd.exe parses it as one
    // token after /s strips only the outer pair.
    $out = envlite_cmd_exe_wrap_string([
        'C:\\Program Files\\nodejs\\npm.cmd',
        '--version',
    ]);
    envlite_assert_eq(
        'cmd.exe /d /s /c ""C:\\Program Files\\nodejs\\npm.cmd" --version"',
        $out
    );
}

function test_cmd_exe_wrap_string_escapes_internal_double_quote_by_doubling() {
    // cmd.exe convention: inside a double-quoted argument, " is escaped
    // as "" (NOT as \" — that's the MS C runtime convention, which cmd.exe
    // does not recognize).
    $out = envlite_cmd_exe_wrap_string(['npm', 'install', 'a"b']);
    envlite_assert_eq(
        'cmd.exe /d /s /c "npm install "a""b""',
        $out
    );
}

function test_cmd_exe_wrap_string_escapes_caret_and_percent_with_caret() {
    // cmd.exe expands %VAR% even inside double quotes. Suppress with ^.
    // Bare ^ must also be escaped to keep its literal value.
    $out = envlite_cmd_exe_wrap_string(['echo', 'foo %BAR% ^']);
    envlite_assert_eq(
        'cmd.exe /d /s /c "echo "foo ^%BAR^% ^^""',
        $out
    );
}

function test_cmd_exe_wrap_string_does_not_quote_args_without_special_chars() {
    // The composer install flag `--ignore-platform-req=ext-simplexml` contains
    // `=` which is a cmd.exe argument separator. Quote it. But pure alphanum
    // dash forms stay bare.
    $out = envlite_cmd_exe_wrap_string([
        'composer', 'install', '--no-interaction', '--ignore-platform-req=ext-simplexml',
    ]);
    envlite_assert_eq(
        'cmd.exe /d /s /c "composer install --no-interaction "--ignore-platform-req=ext-simplexml""',
        $out
    );
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

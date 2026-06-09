<?php
function test_up_help_lists_up_subcommand() {
    $help = envlite_help_text();
    envlite_assert(strpos($help, 'up ') !== false, 'help text mentions up');
}

function test_up_unknown_subcommand_arg_returns_two() {
    envlite_assert_eq(2, envlite_main(['envlite.php', 'up', '--no-such-flag']));
}

function test_up_invalid_port_returns_two() {
    envlite_assert_eq(2, envlite_main(['envlite.php', 'up', '--port=notanumber']));
    envlite_assert_eq(2, envlite_main(['envlite.php', 'up', '--port=99999']));
}

function test_up_help_lists_no_serve_flag_and_drops_removed_flags() {
    $help = envlite_help_text();
    envlite_assert(strpos($help, '--no-serve') !== false, 'help text mentions --no-serve');
    // --no-build and --rebuild were removed when install/build moved out of
    // envlite's scope; the help text must not advertise them anymore.
    envlite_assert(strpos($help, '--no-build') === false, 'help text must not mention --no-build');
    envlite_assert(strpos($help, '--rebuild') === false, 'help text must not mention --rebuild');
}

function test_up_accepts_no_serve_without_arg_parse_error() {
    // If --no-serve were unrecognized, envlite_main would return 2 (unknown
    // argument). Returning Phase 0's exit 3 in a non-checkout cwd instead
    // proves the parser accepted the flag.
    //
    // envlite uses exit() inside its phase guards, so we run it in a child
    // process via envlite_proc_capture rather than calling envlite_main()
    // in-process. proc_open is available on every supported platform
    // (including Windows where pcntl_fork is missing).
    $tmp = sys_get_temp_dir() . '/envlite-up-flags-' . bin2hex(random_bytes(4));
    mkdir($tmp);
    try {
        $envlitePhp = dirname(__DIR__) . '/envlite.php';
        [$exit, , ] = envlite_proc_capture(
            [PHP_BINARY, $envlitePhp, 'up', '--no-serve'],
            $tmp
        );
        envlite_assert_eq(3, $exit, '--no-serve must parse cleanly; only Phase 0 should fail');
    } finally {
        @rmdir($tmp);
    }
}

function test_up_rejects_removed_flags() {
    // --no-build and --rebuild are now unknown arguments (exit 2).
    envlite_assert_eq(2, envlite_main(['envlite.php', 'up', '--no-build']));
    envlite_assert_eq(2, envlite_main(['envlite.php', 'up', '--rebuild']));
}

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

function test_up_help_lists_no_serve_and_rebuild_flags() {
    $help = envlite_help_text();
    envlite_assert(strpos($help, '--no-serve') !== false, 'help text mentions --no-serve');
    envlite_assert(strpos($help, '--rebuild')  !== false, 'help text mentions --rebuild');
}

function test_up_accepts_new_flags_without_arg_parse_error() {
    // If --no-serve or --rebuild were unrecognized, envlite_main would return 2
    // (unknown argument). Returning anything else (Phase 0's exit 3 in a
    // non-checkout cwd) proves the parser accepted the flags.
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
            [PHP_BINARY, $envlitePhp, 'up', '--no-serve', '--rebuild'],
            $tmp
        );
        envlite_assert_eq(3, $exit, '--no-serve and --rebuild must parse cleanly; only Phase 0 should fail');
    } finally {
        @rmdir($tmp);
    }
}

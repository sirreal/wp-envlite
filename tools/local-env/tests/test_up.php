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
    // non-checkout cwd, in this case) proves the parser accepted the flags.
    // Run from a tmpdir that is not a wordpress-develop checkout.
    $tmp = sys_get_temp_dir() . '/envlite-up-flags-' . bin2hex(random_bytes(4));
    mkdir($tmp);
    $prevCwd = getcwd();
    chdir($tmp);
    try {
        // --port=8421 keeps Phase 1 from probing or writing state.
        // Phase 0 will reject the empty cwd and exit(3).
        $observed = null;
        $pid = pcntl_fork();
        if ($pid === 0) {
            // Child: run main with the new flags. Exit code will be 3 (Phase 0).
            // We exit() since envlite uses exit() inside its phase guards.
            exit(envlite_main(['envlite.php', 'up', '--no-serve', '--rebuild']));
        }
        if ($pid > 0) {
            pcntl_waitpid($pid, $status);
            $observed = pcntl_wexitstatus($status);
        }
    } finally {
        chdir($prevCwd);
        @rmdir($tmp);
    }
    if ($pid > 0) {
        envlite_assert_eq(3, $observed, '--no-serve and --rebuild must parse cleanly; only Phase 0 should fail');
    }
}

<?php
function test_dispatch_help_returns_zero() {
    envlite_assert_eq(0, envlite_main(['envlite.php', 'help']));
    envlite_assert_eq(0, envlite_main(['envlite.php', '--help']));
    envlite_assert_eq(0, envlite_main(['envlite.php', '-h']));
    envlite_assert_eq(0, envlite_main(['envlite.php']));
}

function test_dispatch_unknown_subcommand_returns_two() {
    envlite_assert_eq(2, envlite_main(['envlite.php', 'bogus']));
}

function test_dispatch_force_flag_recognized() {
    envlite_assert_eq(2, envlite_main(['envlite.php', '--force', 'bogus']));
}

function test_dispatch_up_rejects_unknown_arg() {
    envlite_assert_eq(2, envlite_main(['envlite.php', 'up', '--bogus']));
}

function test_dispatch_up_rejects_bad_port() {
    envlite_assert_eq(2, envlite_main(['envlite.php', 'up', '--port=0']));
    envlite_assert_eq(2, envlite_main(['envlite.php', 'up', '--port=70000']));
}

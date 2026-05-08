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

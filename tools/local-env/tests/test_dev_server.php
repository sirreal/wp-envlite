<?php
function test_dev_server_argv_targets_correct_port_root_router() {
    $argv = envlite_dev_server_argv('/tmp/repo', 8421);
    envlite_assert_eq('-S', $argv[0]);
    envlite_assert_eq('127.0.0.1:8421', $argv[1]);
    envlite_assert_eq('-t', $argv[2]);
    envlite_assert_eq('src', $argv[3]);
    // The router is the absolute path to tools/local-env/router.php.
    envlite_assert(
        substr($argv[4], -strlen('/tools/local-env/router.php')) === '/tools/local-env/router.php',
        'router path must end with tools/local-env/router.php'
    );
    envlite_assert_eq(5, count($argv));
}

function test_dev_server_argv_does_not_include_php_binary_first() {
    // pcntl_exec takes argv WITHOUT argv[0]; the first real arg of php -S is -S.
    $argv = envlite_dev_server_argv('/tmp/repo', 9000);
    envlite_assert($argv[0] !== 'php' && $argv[0] !== PHP_BINARY,
        'argv must not include the PHP binary (pcntl_exec adds it implicitly)');
}

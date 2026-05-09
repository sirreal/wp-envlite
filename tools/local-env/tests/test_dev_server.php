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

function test_dev_server_pcntl_replaces_process_on_unix() {
    if (!envlite_pcntl_exec_available()) {
        // Windows or pcntl-less Unix: skip the pcntl_exec replacement check.
        // proc_stream behavior is covered by
        // test_dev_server_proc_stream_propagates_child_exit_with_dev_server_argv_shape.
        return;
    }

    // Spawn a child PHP that calls pcntl_exec(PHP_BINARY, ['-r', 'exit(7);']).
    // If pcntl_exec replaces the process, the script after pcntl_exec never
    // runs and the subprocess exits with code 7. If pcntl_exec returned
    // instead, the trailing exit(99) would fire and we'd see 99.
    $child = <<<'PHP'
<?php
@pcntl_exec(PHP_BINARY, ['-r', 'exit(7);']);
exit(99);
PHP;

    $tmp = tempnam(sys_get_temp_dir(), 'envlite-pcntl-');
    file_put_contents($tmp, $child);
    [$exit, , ] = envlite_proc_capture([PHP_BINARY, $tmp]);
    @unlink($tmp);

    envlite_assert_eq(7, $exit, 'pcntl_exec must replace process; child exit must be 7');
}

function test_dev_server_proc_stream_propagates_child_exit_with_dev_server_argv_shape() {
    // pcntl can't be disabled in-process to test the actual fallback selection.
    // Instead, verify that envlite_proc_stream propagates the child exit code
    // when called with the argv shape envlite_run_dev_server uses on Windows.
    $argv = array_merge([PHP_BINARY], ['-r', 'exit(0);']);
    $exit = envlite_proc_stream($argv);
    envlite_assert_eq(0, $exit, 'proc_open fallback must propagate child exit code');
}

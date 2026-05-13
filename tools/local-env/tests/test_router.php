<?php
function envlite_test_router_pick_free_port(): int {
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($sock === false) {
        throw new \RuntimeException("could not bind to find free port: $errstr");
    }
    $name = stream_socket_get_name($sock, false);
    $port = (int) substr($name, strrpos($name, ':') + 1);
    fclose($sock);
    return $port;
}

function envlite_test_router_wait_for_bind(int $port, float $timeout_seconds = 3.0): bool {
    $deadline = microtime(true) + $timeout_seconds;
    while (microtime(true) < $deadline) {
        $check = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($check) {
            fclose($check);
            return true;
        }
        usleep(100_000);
    }
    return false;
}

function test_router_serves_from_document_root_not_router_directory() {
    // Build a fixture "site" that does NOT share a parent with router.php.
    // realpath() normalizes /tmp -> /private/tmp on macOS so the assert
    // below matches __DIR__ from the fixture's index.php (which resolves
    // symlinks). On Linux this is a no-op.
    $site = realpath(envlite_test_tmpdir('router-docroot'));
    envlite_assert($site !== false, 'tmp fixture directory must resolve via realpath');
    file_put_contents("$site/index.php", "<?php echo 'FIXTURE_OK ' . __DIR__;");

    // Use the real shipped router so we exercise its path resolution.
    $router = realpath(__DIR__ . '/../router.php');
    envlite_assert(is_file($router), 'router.php must exist at ' . __DIR__ . '/../router.php');

    $port = envlite_test_router_pick_free_port();

    // Spawn `php -S 127.0.0.1:<port> -t <site> <router>` with cwd = site.
    // Matches envlite_run_dev_server: chdir into the target repo, then pass
    // -t <docroot>. The router file lives outside $site on purpose — that is
    // exactly the configuration that triggered the original bug.
    $argv = [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $site, $router];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($argv, $descriptors, $pipes, $site);
    envlite_assert(is_resource($proc), 'failed to start php -S');

    try {
        envlite_assert(
            envlite_test_router_wait_for_bind($port),
            "php -S did not bind on 127.0.0.1:$port within 3s"
        );

        $body = @file_get_contents("http://127.0.0.1:$port/");
        envlite_assert($body !== false, "request to 127.0.0.1:$port failed");

        envlite_assert(
            strpos($body, 'FIXTURE_OK ' . $site) !== false,
            'expected FIXTURE_OK marker from fixture index.php, got: ' . substr($body, 0, 400)
        );
    } finally {
        foreach ($pipes as $p) { if (is_resource($p)) { @fclose($p); } }
        $status = @proc_get_status($proc);
        if ($status && $status['running']) {
            @proc_terminate($proc, 15);
        }
        @proc_close($proc);
    }
}

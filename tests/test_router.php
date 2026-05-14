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

function test_router_blocks_ht_paths_case_insensitively() {
    // macOS and Windows default to case-insensitive filesystems, so a
    // request for /.HT-test resolves to a .ht-test file on disk. The
    // router's deny rule must reject the request before letting
    // php -S serve the underlying file.
    $site = realpath(envlite_test_tmpdir('router-ht-case'));
    envlite_assert($site !== false);
    file_put_contents("$site/index.php", "<?php echo 'FALLTHROUGH';");
    // Bait file: a real file matching the lowercase form. On case-insensitive
    // FS, requesting /.HT-test reaches this file; the router must 403 first.
    file_put_contents("$site/.ht-test", 'SECRET');

    $router = realpath(__DIR__ . '/../router.php');
    envlite_assert(is_file($router));
    $port = envlite_test_router_pick_free_port();
    $argv = [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $site, $router];
    $proc = proc_open(
        $argv,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $site
    );
    envlite_assert(is_resource($proc));

    try {
        envlite_assert(envlite_test_router_wait_for_bind($port));
        foreach (['/.ht-test', '/.HT-test', '/.Ht-test'] as $reqPath) {
            $ctx = stream_context_create(['http' => ['ignore_errors' => true]]);
            $body = @file_get_contents("http://127.0.0.1:$port$reqPath", false, $ctx);
            envlite_assert(strpos($http_response_header[0] ?? '', '403') !== false,
                "expected 403 for $reqPath, got: " . ($http_response_header[0] ?? 'no headers'));
            envlite_assert(strpos($body ?: '', 'SECRET') === false,
                "$reqPath leaked file bytes");
        }
    } finally {
        foreach ($pipes as $p) { if (is_resource($p)) { @fclose($p); } }
        $status = @proc_get_status($proc);
        if ($status && $status['running']) { @proc_terminate($proc, 15); }
        @proc_close($proc);
    }
}

function test_router_serves_static_file_with_percent_encoded_name() {
    // php -S decodes percent-encoded URIs before mapping them to files,
    // so the router must too. Without decoding, `file_exists($docroot .
    // '/my%20photo.jpg')` is false even when `my photo.jpg` exists on
    // disk, the router falls through to the front controller, and the
    // user gets WordPress's 404 instead of their upload.
    $site = realpath(envlite_test_tmpdir('router-pctenc'));
    envlite_assert($site !== false);
    file_put_contents("$site/index.php", "<?php echo 'FALLTHROUGH';");
    $payload = 'JPEGBYTES';
    file_put_contents("$site/my photo.jpg", $payload);

    $router = realpath(__DIR__ . '/../router.php');
    envlite_assert(is_file($router));
    $port = envlite_test_router_pick_free_port();
    $argv = [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $site, $router];
    $proc = proc_open(
        $argv,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $site
    );
    envlite_assert(is_resource($proc));

    try {
        envlite_assert(envlite_test_router_wait_for_bind($port));
        $ctx = stream_context_create(['http' => ['ignore_errors' => true]]);
        $body = @file_get_contents("http://127.0.0.1:$port/my%20photo.jpg", false, $ctx);
        envlite_assert(strpos($http_response_header[0] ?? '', '200') !== false,
            'expected 200 for percent-encoded static file, got: ' . ($http_response_header[0] ?? 'no headers'));
        envlite_assert($body === $payload,
            'expected upload bytes, got: ' . substr($body ?: '', 0, 100));
    } finally {
        foreach ($pipes as $p) { if (is_resource($p)) { @fclose($p); } }
        $status = @proc_get_status($proc);
        if ($status && $status['running']) { @proc_terminate($proc, 15); }
        @proc_close($proc);
    }
}

function test_router_blocks_percent_encoded_ht_paths() {
    // The .ht block must catch URL-encoded forms (e.g. `/%2Eht.sqlite`)
    // as well as raw `/.ht.sqlite`. Otherwise an attacker can side-step
    // the deny rule on the raw URI and php -S, which decodes before
    // resolving files, serves the SQLite DB.
    $site = realpath(envlite_test_tmpdir('router-pctenc-ht'));
    envlite_assert($site !== false);
    file_put_contents("$site/index.php", "<?php echo 'FALLTHROUGH';");
    file_put_contents("$site/.ht-test", 'SECRET');

    $router = realpath(__DIR__ . '/../router.php');
    envlite_assert(is_file($router));
    $port = envlite_test_router_pick_free_port();
    $argv = [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $site, $router];
    $proc = proc_open(
        $argv,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $site
    );
    envlite_assert(is_resource($proc));

    try {
        envlite_assert(envlite_test_router_wait_for_bind($port));
        foreach (['/%2Eht-test', '/%2eht-test', '/%2E%48%54-test'] as $reqPath) {
            $ctx = stream_context_create(['http' => ['ignore_errors' => true]]);
            $body = @file_get_contents("http://127.0.0.1:$port$reqPath", false, $ctx);
            envlite_assert(strpos($http_response_header[0] ?? '', '403') !== false,
                "expected 403 for $reqPath, got: " . ($http_response_header[0] ?? 'no headers'));
            envlite_assert(strpos($body ?: '', 'SECRET') === false,
                "$reqPath leaked file bytes");
        }
    } finally {
        foreach ($pipes as $p) { if (is_resource($p)) { @fclose($p); } }
        $status = @proc_get_status($proc);
        if ($status && $status['running']) { @proc_terminate($proc, 15); }
        @proc_close($proc);
    }
}

function test_router_blocks_backslash_segmented_ht_paths() {
    // PHP's file APIs treat `\` as a path separator on Windows. The
    // `(^|/)\.ht` regex requires a `/` boundary before `.ht`, so a
    // request like `/wp-content/database%5C.ht.sqlite` would decode to
    // `/wp-content/database\.ht.sqlite` and bypass the block — while
    // `file_exists($docroot . $path)` on Windows would still resolve
    // to the real `.ht.sqlite` and let php -S serve it. The router
    // normalizes `\` → `/` after decode so the regex catches it.
    //
    // The regression manifests on Windows; this test runs on any host
    // because it exercises the routing logic (regex + 403) rather than
    // the OS file-resolution behavior. Without normalization the
    // request would fall through to the front controller and the
    // fixture would respond 200; with normalization the router 403s.
    $site = realpath(envlite_test_tmpdir('router-backslash-ht'));
    envlite_assert($site !== false);
    file_put_contents("$site/index.php", "<?php echo 'FALLTHROUGH';");
    file_put_contents("$site/.ht-test", 'SECRET');

    $router = realpath(__DIR__ . '/../router.php');
    envlite_assert(is_file($router));
    $port = envlite_test_router_pick_free_port();
    $argv = [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $site, $router];
    $proc = proc_open(
        $argv,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $site
    );
    envlite_assert(is_resource($proc));

    try {
        envlite_assert(envlite_test_router_wait_for_bind($port));
        foreach (['/foo%5C.ht-test', '/wp-content%5C.ht-test', '/a%5cb%5c.ht-test'] as $reqPath) {
            $ctx = stream_context_create(['http' => ['ignore_errors' => true]]);
            $body = @file_get_contents("http://127.0.0.1:$port$reqPath", false, $ctx);
            envlite_assert(strpos($http_response_header[0] ?? '', '403') !== false,
                "expected 403 for $reqPath (backslash-segmented .ht), got: " . ($http_response_header[0] ?? 'no headers'));
            envlite_assert(strpos($body ?: '', 'FALLTHROUGH') === false,
                "$reqPath leaked to front controller");
        }
    } finally {
        foreach ($pipes as $p) { if (is_resource($p)) { @fclose($p); } }
        $status = @proc_get_status($proc);
        if ($status && $status['running']) { @proc_terminate($proc, 15); }
        @proc_close($proc);
    }
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

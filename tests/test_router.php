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

/**
 * Issue an HTTP GET to the test router and return the status line +
 * body. The helper isolates `$http_response_header` inside its own
 * scope so a request that fails before the server returns headers
 * cannot leak stale headers into the caller's assertions on the next
 * loop iteration — exactly the masking pattern codex flagged in
 * round 14.
 *
 * @return array{status: ?string, body: ?string}
 */
function envlite_test_router_request(int $port, string $path): array {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true]]);
    $body = @file_get_contents("http://127.0.0.1:$port$path", false, $ctx);
    return [
        'status' => $http_response_header[0] ?? null,
        'body'   => $body === false ? null : $body,
    ];
}

/**
 * Variant of envlite_test_router_request that does not follow 3xx
 * redirects and returns the full header list. file_get_contents() by
 * default chases up to 20 redirects, which collapses 301-then-200
 * into a single 200 in $http_response_header[0] and hides the
 * Location header — useless for asserting the router emitted the
 * canonical-slash redirect.
 *
 * @return array{status: ?string, headers: array<int, string>, body: ?string}
 */
function envlite_test_router_request_no_follow(int $port, string $path): array {
    $ctx = stream_context_create(['http' => [
        'ignore_errors'   => true,
        'follow_location' => 0,
    ]]);
    $body = @file_get_contents("http://127.0.0.1:$port$path", false, $ctx);
    return [
        'status'  => $http_response_header[0] ?? null,
        'headers' => $http_response_header ?? [],
        'body'    => $body === false ? null : $body,
    ];
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
            $r = envlite_test_router_request($port, $reqPath);
            envlite_assert(strpos($r['status'] ?? '', '403') !== false,
                "expected 403 for $reqPath, got: " . ($r['status'] ?? 'no headers'));
            envlite_assert(strpos($r['body'] ?? '', 'SECRET') === false,
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
        $r = envlite_test_router_request($port, '/my%20photo.jpg');
        envlite_assert(strpos($r['status'] ?? '', '200') !== false,
            'expected 200 for percent-encoded static file, got: ' . ($r['status'] ?? 'no headers'));
        envlite_assert($r['body'] === $payload,
            'expected upload bytes, got: ' . substr($r['body'] ?? '', 0, 100));
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
            $r = envlite_test_router_request($port, $reqPath);
            envlite_assert(strpos($r['status'] ?? '', '403') !== false,
                "expected 403 for $reqPath, got: " . ($r['status'] ?? 'no headers'));
            envlite_assert(strpos($r['body'] ?? '', 'SECRET') === false,
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
            $r = envlite_test_router_request($port, $reqPath);
            envlite_assert(strpos($r['status'] ?? '', '403') !== false,
                "expected 403 for $reqPath (backslash-segmented .ht), got: " . ($r['status'] ?? 'no headers'));
            envlite_assert(strpos($r['body'] ?? '', 'FALLTHROUGH') === false,
                "$reqPath leaked to front controller");
        }
    } finally {
        foreach ($pipes as $p) { if (is_resource($p)) { @fclose($p); } }
        $status = @proc_get_status($proc);
        if ($status && $status['running']) { @proc_terminate($proc, 15); }
        @proc_close($proc);
    }
}

function test_router_rejects_paths_containing_nul_bytes() {
    // Round 9 regression: `/%00` decodes to a path with a literal NUL.
    // On PHP 8+ filesystem APIs throw ValueError for NUL arguments, so
    // file_exists() inside the router would fatal the request and the
    // client would see a blank 500. The router now short-circuits with
    // a controlled 400 immediately after decoding.
    $site = realpath(envlite_test_tmpdir('router-nul'));
    envlite_assert($site !== false);
    file_put_contents("$site/index.php", "<?php echo 'FALLTHROUGH';");

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
        foreach (['/%00', '/foo%00bar', '/wp-content/uploads/%00.jpg'] as $reqPath) {
            $r = envlite_test_router_request($port, $reqPath);
            envlite_assert(strpos($r['status'] ?? '', '400') !== false,
                "expected 400 for $reqPath, got: " . ($r['status'] ?? 'no headers'));
            envlite_assert(strpos($r['body'] ?? '', 'FALLTHROUGH') === false,
                "$reqPath must not reach the front controller");
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

function test_router_redirects_bare_directory_to_trailing_slash() {
    // Regression: php -S with a router does not 301 bare directory URLs
    // the way Apache mod_dir (DirectorySlash) and nginx do by default.
    // Without this redirect, php -S serves wp-admin/index.php for a
    // request to /wp-admin while the address bar stays slashless, and
    // relative links on the dashboard (`<a href='plugins.php'>`)
    // resolve against the parent of /wp-admin — landing the user on
    // /plugins.php, a sibling of /wp-admin. That misses the file on
    // disk, falls through to WordPress, and redirect_canonical() 301s
    // it to /plugins.php/ which renders the home page. The 301 is
    // browser-cached so the broken state sticks across reloads.
    $site = realpath(envlite_test_tmpdir('router-dir-slash'));
    envlite_assert($site !== false);
    envlite_assert(mkdir("$site/wp-admin", 0777, true));
    file_put_contents("$site/index.php", "<?php echo 'FRONTPAGE';");
    file_put_contents("$site/wp-admin/index.php", "<?php echo 'ADMIN';");

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

        // Bare directory URL must 301 to the trailing-slash form so
        // relative links on the served page resolve against the
        // directory, not its parent. Location is relative (RFC 9110
        // §10.2.2 permits this) — every browser handles it.
        $r = envlite_test_router_request_no_follow($port, '/wp-admin');
        envlite_assert(strpos($r['status'] ?? '', '301') !== false,
            'expected 301 for /wp-admin, got: ' . ($r['status'] ?? 'no headers'));
        envlite_assert(in_array('Location: /wp-admin/', $r['headers'], true),
            'expected Location: /wp-admin/, got headers: ' . implode(' | ', $r['headers']));

        // Query string must round-trip on the redirect — without it,
        // GET-with-query navigations to a bare directory would lose
        // their parameters on the canonical redirect.
        $r = envlite_test_router_request_no_follow($port, '/wp-admin?foo=bar&baz=qux');
        envlite_assert(strpos($r['status'] ?? '', '301') !== false,
            'expected 301 for /wp-admin?foo=bar&baz=qux, got: ' . ($r['status'] ?? 'no headers'));
        envlite_assert(in_array('Location: /wp-admin/?foo=bar&baz=qux', $r['headers'], true),
            'expected query string preserved, got headers: ' . implode(' | ', $r['headers']));

        // Already-slashed directory must NOT redirect again — otherwise
        // the fix would loop and every admin request would 301-bounce.
        $r = envlite_test_router_request_no_follow($port, '/wp-admin/');
        envlite_assert(strpos($r['status'] ?? '', '200') !== false,
            'expected 200 for /wp-admin/, got: ' . ($r['status'] ?? 'no headers'));
        envlite_assert($r['body'] === 'ADMIN',
            'expected ADMIN body for /wp-admin/, got: ' . substr($r['body'] ?? '', 0, 100));
    } finally {
        foreach ($pipes as $p) { if (is_resource($p)) { @fclose($p); } }
        $status = @proc_get_status($proc);
        if ($status && $status['running']) { @proc_terminate($proc, 15); }
        @proc_close($proc);
    }
}

function test_router_serves_php_file_with_trailing_slash_or_path_info() {
    // Regression: `file_exists($docroot . $path)` returns false when the
    // path has a trailing slash on a regular file (POSIX stat() rejects
    // it with ENOTDIR). Without a walk-back probe, requests like
    // `/wp-admin/plugins.php/` and `/wp-admin/plugins.php/foo/bar` fall
    // through the router to the WP front controller and render the home
    // page. PHP's built-in server (`php -S`) would resolve these on its
    // own by walking the URL backward to find a regular file — but only
    // if the router returns false. The router pre-empts that with its
    // own `file_exists` check, so the resolution never happens.
    //
    // The probe mirrors php_cli_server's behavior: strip trailing
    // slashes, walk backward through path segments, and as soon as we
    // hit an existing regular file, return false so php -S handles
    // SCRIPT_NAME/PATH_INFO splitting natively.
    $site = realpath(envlite_test_tmpdir('router-pathinfo'));
    envlite_assert($site !== false);
    envlite_assert(mkdir("$site/wp-admin", 0777, true));
    file_put_contents("$site/index.php", "<?php echo 'FRONTPAGE';");
    file_put_contents(
        "$site/wp-admin/plugins.php",
        "<?php echo 'PLUGINS ' . (\$_SERVER['PATH_INFO'] ?? '-') . ' ' . (\$_SERVER['QUERY_STRING'] ?? '-');"
    );

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

        // Trailing slash on a regular .php file: must serve the file,
        // not fall through to the front controller. POSIX stat() rejects
        // trailing slashes on files (ENOTDIR), so file_exists() in the
        // router returns false without the probe.
        $r = envlite_test_router_request_no_follow($port, '/wp-admin/plugins.php/');
        envlite_assert(strpos($r['status'] ?? '', '200') !== false,
            'expected 200 for /wp-admin/plugins.php/, got: ' . ($r['status'] ?? 'no headers'));
        envlite_assert(strpos($r['body'] ?? '', 'PLUGINS') === 0,
            'expected PLUGINS body for /wp-admin/plugins.php/, got: ' . substr($r['body'] ?? '', 0, 200));

        // PATH_INFO after a real .php file: same mechanism — file_exists
        // on /wp-admin/plugins.php/foo is false, the probe must walk
        // back to plugins.php and let php -S split SCRIPT_NAME / PATH_INFO.
        $r = envlite_test_router_request_no_follow($port, '/wp-admin/plugins.php/foo/bar');
        envlite_assert(strpos($r['status'] ?? '', '200') !== false,
            'expected 200 for /wp-admin/plugins.php/foo/bar, got: ' . ($r['status'] ?? 'no headers'));
        envlite_assert(strpos($r['body'] ?? '', 'PLUGINS /foo/bar') === 0,
            'expected PATH_INFO=/foo/bar, got: ' . substr($r['body'] ?? '', 0, 200));

        // Query string must round-trip when the probe fires.
        $r = envlite_test_router_request_no_follow($port, '/wp-admin/plugins.php/foo?x=1&y=2');
        envlite_assert(strpos($r['status'] ?? '', '200') !== false,
            'expected 200 for /wp-admin/plugins.php/foo?x=1&y=2, got: ' . ($r['status'] ?? 'no headers'));
        envlite_assert(strpos($r['body'] ?? '', 'PLUGINS /foo x=1&y=2') === 0,
            'expected PATH_INFO=/foo with query, got: ' . substr($r['body'] ?? '', 0, 200));

        // Existing behavior preserved: bare /wp-admin/plugins.php (no
        // trailing slash, no PATH_INFO) still serves cleanly.
        $r = envlite_test_router_request_no_follow($port, '/wp-admin/plugins.php');
        envlite_assert(strpos($r['status'] ?? '', '200') !== false,
            'expected 200 for /wp-admin/plugins.php, got: ' . ($r['status'] ?? 'no headers'));
        envlite_assert(strpos($r['body'] ?? '', 'PLUGINS') === 0,
            'expected PLUGINS body, got: ' . substr($r['body'] ?? '', 0, 200));

        // Path that hits no real file in any ancestor must still fall
        // through to the front controller (WP handles 404s itself).
        // Walking back from /wp-admin/missing.php hits /wp-admin (a
        // directory, not a file), so the probe must not return false.
        $r = envlite_test_router_request_no_follow($port, '/wp-admin/missing.php');
        envlite_assert(strpos($r['status'] ?? '', '200') !== false,
            'expected fallthrough 200 for /wp-admin/missing.php, got: ' . ($r['status'] ?? 'no headers'));
        envlite_assert($r['body'] === 'FRONTPAGE',
            'expected FRONTPAGE for unknown path, got: ' . substr($r['body'] ?? '', 0, 200));

        // Deeply-nested path with no existing ancestor must not hang or
        // loop — the probe terminates at the root and falls through.
        $r = envlite_test_router_request_no_follow($port, '/a/b/c/d/e/f/g.php');
        envlite_assert(strpos($r['status'] ?? '', '200') !== false,
            'expected fallthrough 200 for deeply nested missing path, got: ' . ($r['status'] ?? 'no headers'));
        envlite_assert($r['body'] === 'FRONTPAGE',
            'expected FRONTPAGE for deep miss, got: ' . substr($r['body'] ?? '', 0, 200));
    } finally {
        foreach ($pipes as $p) { if (is_resource($p)) { @fclose($p); } }
        $status = @proc_get_status($proc);
        if ($status && $status['running']) { @proc_terminate($proc, 15); }
        @proc_close($proc);
    }
}

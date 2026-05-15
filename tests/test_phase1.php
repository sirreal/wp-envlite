<?php
function test_phase1_port_seed_in_range() {
    $port = envlite_phase1_seed_port('/some/abs/path');
    envlite_assert($port >= 8100 && $port <= 8899, "port $port out of pool");
}

function test_phase1_port_seed_deterministic() {
    envlite_assert_eq(
        envlite_phase1_seed_port('/tmp/example/foo'),
        envlite_phase1_seed_port('/tmp/example/foo')
    );
}

function test_phase1_port_seed_differs_for_different_paths() {
    // Not a strong claim, but two paths should at least sometimes differ.
    $a = envlite_phase1_seed_port('/a');
    $b = envlite_phase1_seed_port('/b');
    $c = envlite_phase1_seed_port('/abcdef');
    envlite_assert(count(array_unique([$a, $b, $c])) >= 2, 'expected some variation');
}

function test_phase1_port_is_free_on_random_high_port() {
    // Pick a port we expect free; in a CI sandbox this is best-effort but
    // 53219 is unlikely to be bound. If it is, the test reports it.
    $p = 53219;
    envlite_assert(envlite_phase1_port_is_free($p), "port $p unexpectedly in use");
}

function test_phase1_port_is_free_returns_false_when_bound() {
    $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    envlite_assert(is_resource($sock), "could not bind probe socket: $errstr");
    $name = stream_socket_get_name($sock, false); // "127.0.0.1:NNNN"
    [, $port] = explode(':', $name);
    envlite_assert(!envlite_phase1_port_is_free((int)$port), "expected $port reported in-use");
    fclose($sock);
}

function test_phase1_uses_cached_port_when_in_range() {
    $dir = envlite_test_tmpdir('phase1-cache');
    mkdir($dir . '/.cache/envlite', 0755, true);
    file_put_contents($dir . '/.cache/envlite/port', "8421\n");
    envlite_assert_eq(8421, envlite_phase1_discover_port($dir, null));
}

function test_phase1_trusts_cached_port_even_when_bound() {
    // Spec: "Once cached, the port is reused unconditionally. The user may
    // have envlite's own server running on it; re-probing would falsely
    // report 'in use'." Bind a probe socket and confirm the cached port
    // still comes back unchanged rather than being re-picked.
    $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    envlite_assert(is_resource($sock), "could not bind probe socket: $errstr");
    $name = stream_socket_get_name($sock, false);
    [, $boundPort] = explode(':', $name);
    $boundPort = (int) $boundPort;
    try {
        $dir = envlite_test_tmpdir('phase1-cache-bound');
        mkdir($dir . '/.cache/envlite', 0755, true);
        file_put_contents($dir . '/.cache/envlite/port', "$boundPort\n");
        envlite_assert_eq($boundPort, envlite_phase1_discover_port($dir, null));
    } finally {
        fclose($sock);
    }
}

function test_phase1_discover_port_throws_on_unwritable_cache_dir() {
    // envlite_phase1_discover_port calls envlite_atomic_write to record the
    // chosen port; if the .cache directory is unwritable, that throws a
    // RuntimeException. The up-command call site must wrap this in
    // envlite_phase_guard so the exception surfaces as the documented
    // `envlite up: phase 1: ...` error rather than escaping uncaught.
    if (DIRECTORY_SEPARATOR !== '/' || posix_geteuid() === 0) {
        // Same gating as test_clean_apply_reports_paths_that_remain_after_failed_deletion:
        // root bypasses permission bits, and Windows file semantics differ.
        return;
    }
    $dir = envlite_test_tmpdir('phase1-readonly-cache');
    mkdir("$dir/.cache", 0555);
    try {
        $thrown = null;
        try {
            envlite_phase1_discover_port($dir, null);
        } catch (\Throwable $e) {
            $thrown = $e;
        }
        envlite_assert($thrown !== null,
            'expected RuntimeException from atomic_write on unwritable cache dir');
        envlite_assert($thrown instanceof \RuntimeException,
            'expected RuntimeException, got: ' . get_class($thrown));
    } finally {
        chmod("$dir/.cache", 0755);
    }
}

function test_phase_guard_catches_phase1_throw_returns_one() {
    // Companion to the above: the up-command call site wraps the discover
    // call in envlite_phase_guard. The guard must catch the
    // RuntimeException, label it as phase 1, and return 1 — rather than
    // letting the throw escape envlite_cmd_up as an uncaught PHP error.
    if (DIRECTORY_SEPARATOR !== '/' || posix_geteuid() === 0) { return; }
    $dir = envlite_test_tmpdir('phase1-guard');
    mkdir("$dir/.cache", 0555);
    try {
        $rc = envlite_phase_guard('up', 1, function () use ($dir) {
            envlite_phase1_discover_port($dir, null);
        });
        envlite_assert_eq(1, $rc,
            'phase_guard must return 1 when phase 1 atomic_write throws');
    } finally {
        chmod("$dir/.cache", 0755);
    }
}

function test_up_with_bound_explicit_port_leaves_manifest_unmutated() {
    // Round 11 regression: the .ht.sqlite observation used to run
    // BEFORE phase 1. If `up --port=N` was invoked with N already bound,
    // phase 1 exited 1 only after the manifest had already gained an
    // entry for the live `.ht.sqlite` file. Spec: "no manifest mutation
    // occurs" on bind failure. Move the observation after phase 1.
    if (PHP_OS_FAMILY === 'Windows') { return; }

    // Synthetic wp-develop checkout with a pre-existing `.ht.sqlite` —
    // the observation has something it would otherwise record.
    $root = envlite_test_tmpdir('phase1-bind-fail-no-mutate');
    mkdir("$root/src/wp-includes", 0755, true);
    mkdir("$root/tests/phpunit/includes", 0755, true);
    mkdir("$root/src/wp-content/database", 0755, true);
    file_put_contents("$root/tests/phpunit/includes/bootstrap.php", '<?php');
    file_put_contents("$root/package.json", '{}');
    file_put_contents("$root/composer.json", '{}');
    file_put_contents("$root/wp-config-sample.php", '<?php');
    file_put_contents("$root/wp-tests-config-sample.php", '<?php');
    file_put_contents("$root/src/wp-content/database/.ht.sqlite", 'sqlite-content');

    // Bind a port so --port=N collides.
    $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    envlite_assert(is_resource($sock), "probe bind failed: $errstr");
    $name = stream_socket_get_name($sock, false);
    [, $boundPort] = explode(':', $name);
    $boundPort = (int) $boundPort;

    try {
        $envlitePhp = dirname(__DIR__) . '/envlite.php';
        [$exit, , $stderr] = envlite_proc_capture(
            [PHP_BINARY, $envlitePhp, '--force', 'up', "--port=$boundPort", '--no-serve'],
            $root
        );
        envlite_assert_eq(1, $exit,
            "envlite up --port=$boundPort must exit 1 when bound; stderr: " . substr($stderr, 0, 200));
        // Spec contract: bind failure produces the exact line
        // `envlite up: failed to bind 127.0.0.1:<port>` — no phase prefix,
        // no remediation hint. The earlier assertion that "phase 1"
        // appeared anywhere in stderr locked in the wrong message.
        envlite_assert(strpos($stderr, "envlite up: failed to bind 127.0.0.1:$boundPort") !== false,
            "stderr must contain the exact spec bind-failure line; got: " . substr($stderr, 0, 300));
        envlite_assert(strpos($stderr, 'phase 1') === false,
            'spec bind-failure line must NOT carry a phase prefix; got: ' . substr($stderr, 0, 200));

        // The manifest must not exist at all — or if .cache/envlite/ was
        // created by an earlier write attempt, the manifest must not
        // record the DB.
        $manifestPath = "$root/.cache/envlite/manifest";
        if (file_exists($manifestPath)) {
            $manifest = envlite_manifest_load($root);
            envlite_assert(
                !isset($manifest['src/wp-content/database/.ht.sqlite']),
                'manifest must not record .ht.sqlite when phase 1 bind probe fails'
            );
        }

        // The DB itself is untouched.
        envlite_assert_eq('sqlite-content',
            file_get_contents("$root/src/wp-content/database/.ht.sqlite"));
    } finally {
        fclose($sock);
    }
}

function test_phase1_does_not_write_port_cache_when_manifest_unreadable() {
    // Round 15 regression: envlite_phase1_write_cache used to call
    // envlite_atomic_write BEFORE envlite_manifest_load. A manifest
    // that exists but is unreadable (rounds 13/14) throws from
    // manifest_load — by that point the new `port` file had already
    // been written, and the manifest never got the entry. State
    // mutated without recording, violating the bind-failure-contract
    // spirit (no manifest mutation on failure).
    if (DIRECTORY_SEPARATOR !== '/' || posix_geteuid() === 0) { return; }
    $dir = envlite_test_tmpdir('phase1-manifest-unreadable');
    mkdir("$dir/.cache/envlite", 0755, true);
    // Pre-create the manifest with content envlite would consider valid,
    // then strip read perms so manifest_load throws.
    file_put_contents("$dir/.cache/envlite/manifest", str_repeat('a', 64) . "  some/path\n");
    chmod("$dir/.cache/envlite/manifest", 0000);

    try {
        $thrown = null;
        try {
            envlite_phase1_write_cache($dir, 8421);
        } catch (\Throwable $e) {
            $thrown = $e;
        }
        envlite_assert($thrown !== null,
            'write_cache must propagate the manifest_load throw');
        envlite_assert(strpos($thrown->getMessage(), 'cannot read manifest') !== false,
            'error must surface the manifest read failure; got: ' . $thrown->getMessage());

        envlite_assert(!file_exists("$dir/.cache/envlite/port"),
            'port file must NOT exist after a failed manifest load — no state mutation allowed');
    } finally {
        chmod("$dir/.cache/envlite/manifest", 0644);
    }
}

function test_phase1_ignores_cache_when_out_of_range() {
    $dir = envlite_test_tmpdir('phase1-bad-cache');
    mkdir($dir . '/.cache/envlite', 0755, true);
    // 70000 is outside the 1..65535 cached-port acceptance window, so the
    // cache must be ignored and a fresh port picked from the auto pool.
    file_put_contents($dir . '/.cache/envlite/port', "70000\n");
    $port = envlite_phase1_discover_port($dir, null);
    envlite_assert($port >= 8100 && $port <= 8899);
}

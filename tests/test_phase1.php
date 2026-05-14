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

function test_phase1_ignores_cache_when_out_of_range() {
    $dir = envlite_test_tmpdir('phase1-bad-cache');
    mkdir($dir . '/.cache/envlite', 0755, true);
    // 70000 is outside the 1..65535 cached-port acceptance window, so the
    // cache must be ignored and a fresh port picked from the auto pool.
    file_put_contents($dir . '/.cache/envlite/port', "70000\n");
    $port = envlite_phase1_discover_port($dir, null);
    envlite_assert($port >= 8100 && $port <= 8899);
}

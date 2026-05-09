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
    mkdir($dir . '/.envlite');
    file_put_contents($dir . '/.envlite/port', "8421\n");
    envlite_assert_eq(8421, envlite_phase1_discover_port($dir, null));
}

function test_phase1_ignores_cache_when_out_of_range() {
    $dir = envlite_test_tmpdir('phase1-bad-cache');
    mkdir($dir . '/.envlite');
    // 70000 is outside the 1..65535 cached-port acceptance window, so the
    // cache must be ignored and a fresh port picked from the auto pool.
    file_put_contents($dir . '/.envlite/port', "70000\n");
    $port = envlite_phase1_discover_port($dir, null);
    envlite_assert($port >= 8100 && $port <= 8899);
}

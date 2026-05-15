<?php
function envlite_assert(bool $cond, string $msg = ''): void {
    if (!$cond) {
        throw new \RuntimeException("assertion failed" . ($msg ? ": $msg" : ""));
    }
}

/**
 * Returns true when this Unix process can't rely on file permission
 * bits to gate access — either it's running as root (uid 0, which
 * bypasses most permission checks) OR the POSIX extension isn't
 * loaded (PHP can't tell). Tests that induce a permission failure
 * call this to bail cleanly.
 *
 * Distinct from a direct `posix_geteuid() === 0` check, which
 * fatals on a PHP build without the (optional) posix extension. The
 * function_exists guard makes the helper safe to call from every
 * test file's skip predicate.
 */
function envlite_test_should_skip_perm_bits(): bool {
    if (DIRECTORY_SEPARATOR !== '/') { return true; }      // Windows perms behave differently
    if (!function_exists('posix_geteuid')) { return true; } // ext-posix not available
    return posix_geteuid() === 0;
}

function envlite_assert_eq($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        throw new \RuntimeException(
            "assert_eq failed" . ($msg ? " ($msg)" : "") .
            ": expected " . var_export($expected, true) .
            ", got " . var_export($actual, true)
        );
    }
}

function envlite_test_run(string $dir): int {
    $files = glob($dir . '/test_*.php');
    sort($files);
    $before = get_defined_functions()['user'];
    foreach ($files as $f) { require_once $f; }
    $after = get_defined_functions()['user'];
    $tests = array_values(array_filter(
        array_diff($after, $before),
        fn($fn) => substr($fn, 0, 5) === 'test_'
    ));
    sort($tests);
    $failures = 0;
    foreach ($tests as $fn) {
        try {
            $fn();
            fwrite(STDERR, "PASS $fn\n");
        } catch (\Throwable $e) {
            $failures++;
            fwrite(STDERR, "FAIL $fn: " . $e->getMessage() . "\n");
        }
    }
    fwrite(STDERR, count($tests) . " tests, $failures failures\n");
    return $failures === 0 ? 0 : 1;
}

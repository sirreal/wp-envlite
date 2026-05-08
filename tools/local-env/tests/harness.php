<?php
function envlite_assert(bool $cond, string $msg = ''): void {
    if (!$cond) {
        throw new \RuntimeException("assertion failed" . ($msg ? ": $msg" : ""));
    }
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

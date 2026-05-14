<?php
// Pure-formatter tests for envlite_phase24_format_dump. The dump is what
// envlite emits to stderr when the parallel install pair fails; the spec
// requires every job's buffer to appear under labeled separators, even
// the successful partner's, because its output may carry warnings or
// context relevant to the failure.

function test_phase24_format_dump_includes_both_buffers_when_one_failed() {
    // Regression: prior implementation skipped the successful partner's
    // buffer ("Dump only the buffers of failed processes"), which dropped
    // useful context from the failure report. The spec says "dumps each
    // captured buffer" on failure of either or both — exercise the
    // single-failure case explicitly.
    $results = [
        'npm ci'           => ['exit' => 1, 'output' => "npm err: boom\n"],
        'composer install' => ['exit' => 0, 'output' => "composer ok\n"],
    ];
    $out = envlite_phase24_format_dump($results);
    envlite_assert(strpos($out, "--- npm ci ---\nnpm err: boom\n") !== false,
        "failed job buffer must appear under its label; got: $out");
    envlite_assert(strpos($out, "--- composer install ---\ncomposer ok\n") !== false,
        "successful partner buffer must also appear under its label; got: $out");
}

function test_phase24_format_dump_both_failed_under_separators() {
    $results = [
        'npm ci'           => ['exit' => 2, 'output' => "npm fail\n"],
        'composer install' => ['exit' => 3, 'output' => "composer fail\n"],
    ];
    $out = envlite_phase24_format_dump($results);
    envlite_assert_eq(
        "--- npm ci ---\nnpm fail\n--- composer install ---\ncomposer fail\n",
        $out
    );
}

function test_phase24_format_dump_appends_trailing_newline_when_missing() {
    // Subprocesses that exit without a trailing newline (rare with npm/
    // composer, possible with anything else) must not produce a glued
    // separator on the next line.
    $results = [
        'npm ci'           => ['exit' => 1, 'output' => 'no-newline'],
        'composer install' => ['exit' => 0, 'output' => ''],
    ];
    $out = envlite_phase24_format_dump($results);
    envlite_assert_eq(
        "--- npm ci ---\nno-newline\n--- composer install ---\n\n",
        $out
    );
}

function test_phase24_format_dump_preserves_label_order_from_input() {
    // Insertion order is meaningful: callers pass jobs in spec order
    // (npm ci then composer install), and the dump must preserve that
    // so the output is stable regardless of subprocess completion order.
    $results = [
        'composer install' => ['exit' => 0, 'output' => "first\n"],
        'npm ci'           => ['exit' => 1, 'output' => "second\n"],
    ];
    $out = envlite_phase24_format_dump($results);
    envlite_assert(strpos($out, '--- composer install ---') < strpos($out, '--- npm ci ---'),
        'dump must follow input insertion order');
}

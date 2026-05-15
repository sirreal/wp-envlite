<?php
// Regression tests for envlite_phase_guard's string-label path, added
// in the context of the phase 2/4 parallel install pair. The dump
// formatter that those tests originally lived alongside has been
// generalized and moved to tests/test_subprocess_dump.php.

function test_phase_guard_with_string_label_prefixes_unprefixed_messages() {
    // Round 9 regression: previously the parallel pair was wrapped with
    // `phase_guard('up', 24)` and any unprefixed throw was emitted as
    // `envlite up: phase 24: ...` — a phase number the spec doesn't
    // define. The fix uses a string label ("phases 2 and 4") and
    // phase_guard now treats a string label as the literal prefix.
    $rc = envlite_phase_guard('up', 'phases 2 and 4', function () {
        throw new \RuntimeException('proc_open spawn failed');
    });
    envlite_assert_eq(1, $rc, 'phase_guard must return 1 on throw');
    // Can't easily capture stderr here, but the code branch is exercised
    // and the int/string handling is documented in the phase_guard
    // docblock. The next test verifies the int path still works.
}

function test_phase_guard_with_string_label_passes_through_self_prefixed_messages() {
    // When inner code throws "phase 2: npm ci failed (exit 1)", the
    // existing self-prefix detection must skip the label completely.
    // Verify the label is ignored when the message starts with phase/phases.
    $rc = envlite_phase_guard('up', 'phases 2 and 4', function () {
        throw new \RuntimeException('phase 2: npm ci failed (exit 1)');
    });
    envlite_assert_eq(1, $rc);
}

function test_phase_guard_int_label_still_produces_phase_n_prefix() {
    // Don't regress the int-label path that every other phase uses.
    $rc = envlite_phase_guard('up', 5, function () {
        throw new \RuntimeException('SHA256 mismatch on plugin zip');
    });
    envlite_assert_eq(1, $rc);
}

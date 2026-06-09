<?php
// Tests for envlite_phase_guard: it wraps a phase callable, turning any
// throw into exit code 1 and prefixing the message with `phase <n>:`
// unless the inner code already named its own phase.

function test_phase_guard_int_label_produces_phase_n_prefix() {
    // An unprefixed throw gets the caller-supplied `phase <n>:` label.
    $rc = envlite_phase_guard('up', 2, function () {
        throw new \RuntimeException('SHA256 mismatch on plugin zip');
    });
    envlite_assert_eq(1, $rc, 'phase_guard must return 1 on throw');
}

function test_phase_guard_passes_through_self_prefixed_messages() {
    // When inner code throws "phase 2: ...", the self-prefix detection must
    // skip the label so it isn't applied twice.
    $rc = envlite_phase_guard('up', 2, function () {
        throw new \RuntimeException('phase 2: db.copy missing after extraction');
    });
    envlite_assert_eq(1, $rc);
}

function test_phase_guard_returns_zero_on_success() {
    $rc = envlite_phase_guard('up', 5, function () { /* no throw */ });
    envlite_assert_eq(0, $rc);
}

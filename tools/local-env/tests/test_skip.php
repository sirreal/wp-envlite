<?php
// Skip-rule tests are pure: they exercise envlite_phase{2,4}_input_hash and
// the conditions used by envlite_cmd_up to decide whether a phase should be
// skipped. They do NOT spawn npm/composer — those subprocesses are out of
// scope for unit tests.

function envlite_test_skip_make_repo(string $name, ?string $lockJson = null, ?string $composerJson = null): string {
    $dir = envlite_test_tmpdir($name);
    if ($lockJson !== null) {
        file_put_contents("$dir/package-lock.json", $lockJson);
    }
    if ($composerJson !== null) {
        file_put_contents("$dir/composer.json", $composerJson);
    }
    return $dir;
}

function test_phase2_input_hash_returns_null_when_lockfile_missing() {
    $dir = envlite_test_skip_make_repo('phase2-nolock');
    envlite_assert_eq(null, envlite_phase2_input_hash($dir));
}

function test_phase2_input_hash_matches_sha256_of_lockfile() {
    $dir = envlite_test_skip_make_repo('phase2-haslock', '{"lockfileVersion":3}');
    envlite_assert_eq(
        hash('sha256', '{"lockfileVersion":3}'),
        envlite_phase2_input_hash($dir)
    );
}

function test_phase4_input_hash_matches_sha256_of_composer_json() {
    $dir = envlite_test_skip_make_repo('phase4-cj', null, '{"name":"foo"}');
    envlite_assert_eq(
        hash('sha256', '{"name":"foo"}'),
        envlite_phase4_input_hash($dir)
    );
}

function test_skip_rule_phase2_recorded_hash_match_means_skip() {
    // Build the conditions that would let envlite_phase24_parallel skip
    // phase 2: node_modules/ exists, recorded hash matches current.
    $dir = envlite_test_skip_make_repo('phase2-skip', 'lockcontents');
    mkdir("$dir/node_modules", 0755, true);

    $current = envlite_phase2_input_hash($dir);
    envlite_state_save($dir, ['phase2.input_hash' => $current]);

    // Recompute the predicate that envlite_phase24_parallel uses inline.
    $state = envlite_state_load($dir);
    $shouldSkip = is_dir("$dir/node_modules")
        && ($state['phase2.input_hash'] ?? null) === $current;
    envlite_assert($shouldSkip, 'phase 2 should skip when hash matches and dir exists');
}

function test_skip_rule_phase2_drift_means_run() {
    // Recorded hash does not match current → must NOT skip.
    $dir = envlite_test_skip_make_repo('phase2-drift', 'newcontents');
    mkdir("$dir/node_modules", 0755, true);
    envlite_state_save($dir, ['phase2.input_hash' => str_repeat('0', 64)]);

    $current = envlite_phase2_input_hash($dir);
    $state = envlite_state_load($dir);
    $shouldSkip = is_dir("$dir/node_modules")
        && ($state['phase2.input_hash'] ?? null) === $current;
    envlite_assert(!$shouldSkip, 'phase 2 must run when recorded hash drifts');
}

function test_skip_rule_phase2_missing_node_modules_means_run() {
    // Recorded hash matches but node_modules/ missing → must run.
    $dir = envlite_test_skip_make_repo('phase2-nomodules', 'lockcontents');
    $current = envlite_phase2_input_hash($dir);
    envlite_state_save($dir, ['phase2.input_hash' => $current]);

    $state = envlite_state_load($dir);
    $shouldSkip = is_dir("$dir/node_modules")
        && ($state['phase2.input_hash'] ?? null) === $current;
    envlite_assert(!$shouldSkip, 'phase 2 must run when node_modules/ missing');
}

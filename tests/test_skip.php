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

function test_phase4_input_hash_mixes_php_version_with_composer_json() {
    // wordpress-develop has no composer.lock, so the input hash must
    // change when the PHP binary changes — otherwise switching PHP
    // versions skips Phase 4 against a vendor/ resolved for the
    // previous platform.
    $dir = envlite_test_skip_make_repo('phase4-cj', null, '{"name":"foo"}');
    envlite_assert_eq(
        hash('sha256', PHP_VERSION . "\0" . '{"name":"foo"}'),
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

function test_phase3_head_sha_returns_null_outside_git_repo() {
    // A directory with no .git/ at all: the helper returns null so the
    // skip rule short-circuits to comparing the other recorded hashes
    // and a null === null match still allows skip on a non-git tree.
    $dir = envlite_test_tmpdir('phase3-head-no-git');
    envlite_assert_eq(null, envlite_phase3_head_sha($dir));
}

function test_phase3_head_sha_detached_head() {
    // .git/HEAD contains a literal 40-hex commit SHA (detached HEAD
    // after a `git checkout <commit>`). The helper returns the SHA
    // directly without any ref resolution.
    $dir = envlite_test_tmpdir('phase3-head-detached');
    mkdir("$dir/.git", 0755, true);
    $sha = str_repeat('a1b2c3d4', 5); // 40 hex chars
    file_put_contents("$dir/.git/HEAD", "$sha\n");
    envlite_assert_eq($sha, envlite_phase3_head_sha($dir));
}

function test_phase3_head_sha_resolves_loose_ref() {
    // .git/HEAD points at refs/heads/<branch>; the loose ref file
    // exists at .git/refs/heads/<branch>. The helper reads through
    // the indirection and returns the resolved SHA.
    $dir = envlite_test_tmpdir('phase3-head-loose');
    mkdir("$dir/.git/refs/heads", 0755, true);
    $sha = str_repeat('b', 40);
    file_put_contents("$dir/.git/HEAD", "ref: refs/heads/feature-branch\n");
    file_put_contents("$dir/.git/refs/heads/feature-branch", "$sha\n");
    envlite_assert_eq($sha, envlite_phase3_head_sha($dir));
}

function test_phase3_head_sha_resolves_packed_ref() {
    // Common shape after a `git gc` or `git pack-refs --all`: loose
    // ref file is missing and the SHA lives in .git/packed-refs.
    $dir = envlite_test_tmpdir('phase3-head-packed');
    mkdir("$dir/.git", 0755, true);
    $sha = str_repeat('c', 40);
    file_put_contents("$dir/.git/HEAD", "ref: refs/heads/trunk\n");
    file_put_contents("$dir/.git/packed-refs",
        "# pack-refs with: peeled fully-peeled sorted\n"
        . "$sha refs/heads/trunk\n"
        . "^abc123\n"
    );
    envlite_assert_eq($sha, envlite_phase3_head_sha($dir));
}

function test_phase3_head_sha_returns_null_when_ref_unresolvable() {
    // HEAD points at a ref that isn't a loose file AND isn't in
    // packed-refs (broken state). Helper returns null — better than
    // returning the literal "ref: ..." string which would never match
    // a real SHA.
    $dir = envlite_test_tmpdir('phase3-head-broken-ref');
    mkdir("$dir/.git", 0755, true);
    file_put_contents("$dir/.git/HEAD", "ref: refs/heads/missing-branch\n");
    envlite_assert_eq(null, envlite_phase3_head_sha($dir));
}

function test_phase3_head_sha_resolves_linked_worktree() {
    // Round 23 regression: in a git linked worktree (`git worktree add`),
    // `.git` is a FILE containing `gitdir: <path>` not a directory.
    // Round-22's helper read `$repoRoot/.git/HEAD` and failed there,
    // returning null and letting Phase 3 skip across branch switches
    // in worktrees. The fix resolves the per-worktree gitdir from the
    // gitdir: pointer file.
    if (DIRECTORY_SEPARATOR !== '/') { return; }
    $base = envlite_test_tmpdir('phase3-worktree-base');
    // Main repo: $base/main/.git/ with a worktrees/ child.
    mkdir("$base/main/.git/worktrees/feature/refs/heads", 0755, true);
    mkdir("$base/main/.git/refs/heads", 0755, true);
    // Worktree: $base/wt/.git (file) → main/.git/worktrees/feature
    mkdir("$base/wt", 0755, true);
    file_put_contents("$base/wt/.git", "gitdir: $base/main/.git/worktrees/feature\n");
    // Worktree's HEAD points at refs/heads/feature; per-tree.
    file_put_contents("$base/main/.git/worktrees/feature/HEAD", "ref: refs/heads/feature\n");
    // commondir: points back at the main .git.
    file_put_contents("$base/main/.git/worktrees/feature/commondir", "../..\n");
    // Loose ref for the feature branch lives in the common .git/refs.
    $sha = str_repeat('f', 40);
    file_put_contents("$base/main/.git/refs/heads/feature", "$sha\n");

    envlite_assert_eq($sha, envlite_phase3_head_sha("$base/wt"),
        'helper must resolve HEAD through the worktree gitdir pointer');
}

function test_phase3_resolve_git_dir_plain_checkout() {
    $dir = envlite_test_tmpdir('phase3-resolve-plain');
    mkdir("$dir/.git", 0755, true);
    envlite_assert_eq("$dir/.git", envlite_phase3_resolve_git_dir($dir));
}

function test_phase3_resolve_git_dir_returns_null_outside_repo() {
    $dir = envlite_test_tmpdir('phase3-resolve-none');
    envlite_assert_eq(null, envlite_phase3_resolve_git_dir($dir));
}

function test_phase3_resolve_git_dir_linked_worktree_relative_target() {
    // `gitdir:` value can be relative — resolved against the worktree
    // root (the directory containing the `.git` pointer file).
    if (DIRECTORY_SEPARATOR !== '/') { return; }
    $dir = envlite_test_tmpdir('phase3-resolve-relative');
    mkdir("$dir/external/main/.git/worktrees/wt", 0755, true);
    file_put_contents("$dir/.git", "gitdir: external/main/.git/worktrees/wt\n");
    $resolved = envlite_phase3_resolve_git_dir($dir);
    envlite_assert_eq("$dir/external/main/.git/worktrees/wt", $resolved);
}

function test_skip_rule_phase3_head_sha_changed_means_run() {
    // Round 22 regression: phases 2 and 4 skipped (lockfiles unchanged)
    // but the user ran `git pull` so HEAD moved. Without the head_sha
    // component, the round-0 skip rule would have skipped Phase 3 and
    // left stale build artifacts. With it, the comparison forces a
    // rebuild.
    $dir = envlite_test_tmpdir('phase3-head-changed');
    mkdir("$dir/.git", 0755, true);
    $oldSha = str_repeat('d', 40);
    $newSha = str_repeat('e', 40);
    file_put_contents("$dir/.git/HEAD", "$newSha\n"); // detached HEAD at new SHA
    mkdir("$dir/src/wp-includes/js/dist", 0755, true);
    $state = [
        'phase3.recorded_npm_hash'      => 'npmhash',
        'phase3.recorded_composer_hash' => 'composerhash',
        'phase3.recorded_head_sha'      => $oldSha,
    ];
    $currentHeadSha = envlite_phase3_head_sha($dir);
    envlite_assert_eq($newSha, $currentHeadSha);
    $shouldSkip = is_dir("$dir/src/wp-includes/js/dist")
        && ($state['phase3.recorded_npm_hash'] ?? null) === 'npmhash'
        && ($state['phase3.recorded_composer_hash'] ?? null) === 'composerhash'
        && ($state['phase3.recorded_head_sha'] ?? null) === $currentHeadSha;
    envlite_assert(!$shouldSkip,
        'phase 3 must NOT skip when HEAD has moved since the last successful build');
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

<?php
function test_atomic_write_returns_hash_of_bytes() {
    $dir = envlite_test_tmpdir('atomic');
    $path = $dir . '/foo.txt';
    $bytes = "hello world\n";
    $hash = envlite_atomic_write($path, $bytes);
    envlite_assert_eq(hash('sha256', $bytes), $hash);
    envlite_assert_eq($bytes, file_get_contents($path));
}

function test_atomic_write_overwrites_existing() {
    $dir = envlite_test_tmpdir('atomic-overwrite');
    $path = $dir . '/foo.txt';
    file_put_contents($path, 'old');
    envlite_atomic_write($path, 'new');
    envlite_assert_eq('new', file_get_contents($path));
}

function test_atomic_write_no_tmp_left_behind() {
    // After a successful write, no envlite-tmp sibling must remain
    // beside the destination. The match pattern accommodates the
    // unique-suffix scheme introduced to prevent user-file collisions.
    $dir = envlite_test_tmpdir('atomic-clean');
    $path = $dir . '/foo.txt';
    envlite_atomic_write($path, 'x');
    $leftovers = glob($dir . '/foo.txt.envlite-tmp.*');
    envlite_assert_eq([], $leftovers, 'no envlite-tmp sibling must remain');
    // Also confirm nothing at the old deterministic `.tmp` name (older
    // releases used `$path . '.tmp'`).
    envlite_assert(!file_exists($path . '.tmp'), 'no legacy .tmp must remain');
}

function test_atomic_write_creates_parent_dir() {
    $dir = envlite_test_tmpdir('atomic-parent');
    $path = $dir . '/sub/dir/foo.txt';
    envlite_atomic_write($path, 'x');
    envlite_assert_eq('x', file_get_contents($path));
}

function test_atomic_write_does_not_touch_preexisting_file_at_legacy_tmp_name() {
    // Round 7 regression: the previous deterministic `$path.'.tmp'` was a
    // destructive-write footgun — `fopen($tmp, 'wb')` would truncate a
    // user file at the legacy `.tmp` path (or follow a symlink there and
    // truncate the target). The new unique-suffix scheme makes the
    // collision space astronomically large, so a pre-existing file at
    // the old name is left untouched.
    $dir = envlite_test_tmpdir('atomic-preexisting-tmp');
    $path = $dir . '/foo.txt';
    $legacy = $path . '.tmp';
    file_put_contents($legacy, 'USER_DATA_THAT_MUST_SURVIVE');
    envlite_atomic_write($path, 'envlite-payload');
    envlite_assert_eq('envlite-payload', file_get_contents($path));
    envlite_assert_eq('USER_DATA_THAT_MUST_SURVIVE', file_get_contents($legacy),
        'legacy .tmp sibling must not be clobbered by the atomic write');
}

function test_atomic_write_replaces_directory_at_destination() {
    // Round 8 regression: if the user mkdir'd over a path envlite expects
    // to be a regular file (e.g. `src/wp-config.php` is a directory), the
    // ownership check correctly classified it as drifted/unowned and the
    // caller's prompt accepted the overwrite. But the subsequent
    // rename($tmp, $path) failed because POSIX rename can't overlay a
    // directory. The phase aborted after consent, leaving the user
    // confused. Now atomic_write clears the non-regular destination
    // (rrmdir for dirs, unlink for symlinks) before the rename.
    $dir = envlite_test_tmpdir('atomic-replace-dir');
    $path = $dir . '/wp-config.php';
    mkdir($path);
    file_put_contents("$path/stray-file", 'user content the directory swallowed');
    envlite_atomic_write($path, "<?php // envlite\n");
    envlite_assert(is_file($path) && !is_dir($path),
        'destination must become a regular file');
    envlite_assert_eq("<?php // envlite\n", file_get_contents($path));
}

function test_atomic_write_replaces_symlink_at_destination() {
    // Companion case: if the user replaced an envlite-managed file with
    // a symlink (to anywhere — broken, to a dir, to another file), the
    // ownership check classifies it as drifted and after consent
    // atomic_write must clear the symlink rather than follow it (which
    // would clobber the symlink target).
    if (DIRECTORY_SEPARATOR !== '/') { return; }
    $dir = envlite_test_tmpdir('atomic-replace-symlink');
    $path = $dir . '/wp-config.php';
    $target = $dir . '/external-user-file';
    file_put_contents($target, 'EXTERNAL_USER_DATA');
    symlink($target, $path);
    envlite_atomic_write($path, "<?php // envlite\n");
    envlite_assert(is_file($path) && !is_link($path),
        'destination must become a regular file, not the symlink');
    envlite_assert_eq("<?php // envlite\n", file_get_contents($path));
    envlite_assert_eq('EXTERNAL_USER_DATA', file_get_contents($target),
        'symlink target must be untouched (no truncate-through-symlink)');
}

function test_write_managed_file_aborts_when_ownership_changes_under_caller() {
    // Round 33 P2 regression: the caller's prompt-or-bypass decision
    // is based on an earlier ownership read; if a race changes the
    // destination between that read and the helper's order-decision,
    // the helper would proceed with content-first (or manifest-first)
    // based on the NEW ownership, silently overwriting whatever
    // replaced the file. The helper now takes an $expectedOwnership
    // parameter and aborts when the current state disagrees.
    //
    // Build a fixture where the caller would have observed
    // 'owned_clean' (envlite-owned file with matching hash), but by
    // the time the helper runs the file's bytes are different
    // (someone overwrote with new content) — the helper now sees
    // 'owned_drifted' and must abort rather than proceed.
    $dir = envlite_test_tmpdir('write-managed-ownership-race');
    mkdir("$dir/.cache/envlite", 0755, true);
    file_put_contents("$dir/output.txt", 'envlite-original');
    $envliteHash = hash('sha256', 'envlite-original');
    $manifest = ['output.txt' => $envliteHash];
    envlite_manifest_save($dir, $manifest);

    // Caller would have computed ownership 'owned_clean' against
    // these bytes. Now simulate a race that replaced the file with
    // unrelated user content (drifted from envlite's recorded hash).
    file_put_contents("$dir/output.txt", 'user-replaced-mid-flight');

    $thrown = null;
    try {
        envlite_write_managed_file(
            $dir, $manifest, 'output.txt', "envlite-new-content\n",
            "$dir/output.txt", 'owned_clean'
        );
    } catch (\RuntimeException $e) {
        $thrown = $e;
    }
    envlite_assert($thrown !== null,
        'write_managed_file must abort on ownership divergence from caller');
    envlite_assert(strpos($thrown->getMessage(), 'ownership changed from owned_clean') !== false,
        'error must name the divergence; got: ' . $thrown->getMessage());

    // The user's mid-flight content must survive — the helper refused
    // to overwrite without re-prompt.
    envlite_assert_eq('user-replaced-mid-flight', file_get_contents("$dir/output.txt"),
        'user-replaced content must NOT be clobbered by the helper');
}

function test_write_managed_file_content_first_for_unowned_overwrite() {
    // Round 32 P2 regression: round-29's manifest-first ordering
    // poisoned the manifest with envlite's new hash *before*
    // atomic_write ran. A process kill (SIGKILL, panic) between
    // manifest_save and atomic_write left the user's pre-existing
    // content on disk + an envlite-claimed manifest entry — and a
    // later `clean --force` would delete the user's file. For unowned
    // and drifted destinations the helper now writes the content
    // FIRST, then commits the manifest entry. The crash window's
    // failure mode becomes "envlite content on disk, no manifest
    // entry" — clean orphans the file but does NOT delete user
    // content.
    //
    // Exercise the content-first path: pre-existing UNOWNED file at
    // the destination (no manifest entry); call write_managed_file
    // with new bytes; verify the file ends up byte-equal to the new
    // bytes AND the manifest now records the new hash.
    $dir = envlite_test_tmpdir('write-managed-content-first-unowned');
    mkdir("$dir/.cache/envlite", 0755, true);
    file_put_contents("$dir/output.txt", 'user-content');

    $manifest = [];  // unowned: no prior entry
    envlite_manifest_save($dir, $manifest);

    envlite_write_managed_file(
        $dir, $manifest, 'output.txt', "envlite-content\n", "$dir/output.txt"
    );

    envlite_assert_eq("envlite-content\n", file_get_contents("$dir/output.txt"),
        'file must hold envlite content after the write');
    envlite_assert_eq(hash('sha256', "envlite-content\n"), $manifest['output.txt'],
        'manifest must record the new hash after content-first commit');
    $onDisk = envlite_manifest_load($dir);
    envlite_assert_eq($manifest, $onDisk,
        'in-memory and on-disk manifests must match after content-first write');
}

function test_write_managed_file_rolls_back_manifest_when_atomic_write_fails() {
    // Round 29 P2 regression: round-23's manifest-first ordering meant
    // a failed atomic_write left the manifest claiming envlite owns a
    // file that's still the user's content. A subsequent clean --force
    // would delete the user's file. The helper now reverts the manifest
    // entry on atomic_write failure.
    //
    // Force the failure by making the "parent" directory a REGULAR FILE
    // — then atomic_write's mkdir on that path can't create a directory
    // (a file already exists there) AND fopen of the temp child fails
    // because the parent isn't a directory. This works regardless of
    // process EUID, so root CI containers exercise the rollback too
    // (the earlier chmod 0555 approach silently skipped under root).
    if (DIRECTORY_SEPARATOR !== '/') { return; }
    $dir = envlite_test_tmpdir('write-managed-rollback');
    mkdir("$dir/.cache/envlite", 0755, true);
    // A regular file where a directory would need to be.
    file_put_contents("$dir/blocker", 'not-a-directory');

    // Pre-existing manifest with an unrelated entry — must survive.
    $manifest = ['unrelated.txt' => str_repeat('a', 64)];
    envlite_manifest_save($dir, $manifest);

    $thrown = null;
    try {
        envlite_write_managed_file(
            $dir, $manifest, 'blocker/output.txt', "envlite-content\n",
            "$dir/blocker/output.txt"
        );
    } catch (\RuntimeException $e) {
        $thrown = $e;
    }
    envlite_assert($thrown !== null,
        'write_managed_file must propagate the atomic_write failure');

    // Manifest must be byte-identical to what it was BEFORE the call.
    // The in-memory $manifest var was reverted by the helper too.
    envlite_assert(!isset($manifest['blocker/output.txt']),
        'in-memory manifest entry must be reverted on failure');
    envlite_assert_eq(
        $manifest,
        envlite_manifest_load($dir),
        'on-disk manifest must match the in-memory rollback'
    );
    envlite_assert(isset($manifest['unrelated.txt']),
        'unrelated manifest entries must survive the rollback');
}

function test_write_managed_file_reverts_to_prior_hash_when_overwriting_fails() {
    // Variant: there WAS a prior manifest entry for this path. After
    // atomic_write failure the in-memory and on-disk manifest must
    // both restore the prior hash (not just unset the key).
    if (DIRECTORY_SEPARATOR !== '/') { return; }
    $dir = envlite_test_tmpdir('write-managed-rollback-overwrite');
    mkdir("$dir/.cache/envlite", 0755, true);
    // Regular-file blocker so the failure is root-independent.
    file_put_contents("$dir/blocker", 'not-a-directory');

    $priorHash = hash('sha256', 'user-content');
    $manifest = ['blocker/output.txt' => $priorHash];
    envlite_manifest_save($dir, $manifest);

    try {
        envlite_write_managed_file(
            $dir, $manifest, 'blocker/output.txt', "envlite-content\n",
            "$dir/blocker/output.txt"
        );
    } catch (\RuntimeException $e) {
        // expected
    }

    envlite_assert_eq($priorHash, $manifest['blocker/output.txt'],
        'in-memory manifest must keep the prior hash on rollback');
    envlite_assert_eq($manifest, envlite_manifest_load($dir),
        'on-disk manifest must restore the prior hash');
}

function test_atomic_write_does_not_follow_symlink_at_legacy_tmp_name() {
    // Companion to the above: a symlink at the legacy `.tmp` path
    // pointing to an external user file must be left intact (no
    // truncate-through-symlink) by the atomic write.
    if (DIRECTORY_SEPARATOR !== '/') { return; }
    $dir = envlite_test_tmpdir('atomic-preexisting-symlink');
    $path = $dir . '/foo.txt';
    $target = $dir . '/external-user-file';
    file_put_contents($target, 'EXTERNAL_DATA');
    symlink($target, $path . '.tmp');
    envlite_atomic_write($path, 'envlite-payload');
    envlite_assert_eq('envlite-payload', file_get_contents($path));
    envlite_assert_eq('EXTERNAL_DATA', file_get_contents($target),
        'symlink target must not be truncated/followed by the atomic write');
}

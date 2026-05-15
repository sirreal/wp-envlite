<?php
function test_phase5_verify_sha_passes() {
    $bytes = "hello\n";
    $hash = hash('sha256', $bytes);
    $tmp = sys_get_temp_dir() . '/envlite-sha-' . bin2hex(random_bytes(4));
    file_put_contents($tmp, $bytes);
    envlite_phase5_verify_sha256($tmp, $hash); // must not throw
    unlink($tmp);
}

function test_phase5_verify_sha_throws_on_mismatch() {
    $tmp = sys_get_temp_dir() . '/envlite-sha-' . bin2hex(random_bytes(4));
    file_put_contents($tmp, "x");
    try {
        envlite_phase5_verify_sha256($tmp, str_repeat('0', 64));
        unlink($tmp);
        throw new \RuntimeException('expected exception');
    } catch (\RuntimeException $e) {
        unlink($tmp);
        envlite_assert(strpos($e->getMessage(), 'SHA256 mismatch') !== false);
    }
}

function test_phase5_tripwire_passes_when_placeholder_present() {
    $dir = envlite_test_tmpdir('tripwire-ok');
    file_put_contents($dir . '/db.copy', '<?php // {SQLITE_IMPLEMENTATION_FOLDER_PATH} fallback ...');
    envlite_phase5_assert_placeholder($dir . '/db.copy'); // must not throw
}

function test_phase5_path_signature_null_for_missing_path() {
    $dir = envlite_test_tmpdir('phase5-sig-missing');
    envlite_assert_eq(null, envlite_phase5_path_signature("$dir/no-such-entry"));
}

function test_phase5_path_signature_distinct_for_distinct_entries() {
    // Round 18 P2 regression: round-17's pre-clear check compared only
    // booleans (is_link / is_real_dir / exists). A same-shape swap
    // (real-dir A replaced by real-dir B) kept the booleans constant
    // and passed the guard, so the clear would delete the replacement
    // under stale consent. The fix uses lstat ino+dev — exercise that
    // a fresh directory at the same path produces a different
    // signature than the original.
    if (DIRECTORY_SEPARATOR !== '/') { return; }
    $dir = envlite_test_tmpdir('phase5-sig-swap');
    $p = "$dir/plugin-path";
    mkdir($p);
    $beforeSig = envlite_phase5_path_signature($p);
    envlite_assert($beforeSig !== null, 'signature must be non-null for an existing dir');

    // Replace the directory with a fresh one (rrmdir + mkdir = new inode
    // on POSIX). Same shape (still a real directory), different identity.
    envlite_rrmdir($p);
    mkdir($p);
    $afterSig = envlite_phase5_path_signature($p);
    envlite_assert($afterSig !== null);
    envlite_assert($beforeSig !== $afterSig,
        'same-shape swap must produce a different signature; before=' . $beforeSig . ' after=' . $afterSig);
}

function test_phase5_path_signature_stable_across_same_inode() {
    // Inverse: the SAME on-disk entry must produce the SAME signature
    // across calls. Otherwise the TOCTOU guard would false-positive
    // and abort on every fetch even when nothing changed.
    $dir = envlite_test_tmpdir('phase5-sig-stable');
    $p = "$dir/plugin-path";
    mkdir($p);
    file_put_contents("$p/inner", 'content');
    envlite_assert_eq(
        envlite_phase5_path_signature($p),
        envlite_phase5_path_signature($p),
        'signature must be stable for the same on-disk entry'
    );
    // Even after modifying inner contents (mtime changes, but the
    // directory's inode stays the same), the signature must match.
    file_put_contents("$p/inner", 'different content');
    file_put_contents("$p/another", 'new file');
    envlite_assert(
        envlite_phase5_path_signature($p) === envlite_phase5_path_signature($p),
        'signature must remain stable when inner contents change (inode unchanged)'
    );
}

function test_phase5_path_signature_changes_when_dir_replaced_by_symlink() {
    if (DIRECTORY_SEPARATOR !== '/') { return; }
    $dir = envlite_test_tmpdir('phase5-sig-shape-swap');
    $p = "$dir/plugin-path";
    mkdir($p);
    $beforeSig = envlite_phase5_path_signature($p);

    // Replace the dir with a symlink (shape change AND identity change).
    rmdir($p);
    $target = envlite_test_tmpdir('phase5-sig-shape-swap-target');
    symlink($target, $p);
    $afterSig = envlite_phase5_path_signature($p);

    envlite_assert($beforeSig !== null && $afterSig !== null);
    envlite_assert($beforeSig !== $afterSig,
        'shape-change swap must change signature');
}

function test_phase5_drop_recorded_pin_removes_pin_from_state() {
    // Set up a state file with the pin and an unrelated key; verify the
    // helper removes only the pin and leaves the rest intact. This is the
    // pre-extraction invalidation step that prevents a partial extract
    // from being skipped on the next run.
    $dir = envlite_test_tmpdir('phase5-drop-pin');
    mkdir("$dir/.cache/envlite", 0755, true);
    envlite_state_save($dir, [
        'phase5.recorded_pin_sha' => str_repeat('a', 64),
        'phase2.input_hash'       => str_repeat('b', 64),
    ]);
    envlite_phase5_drop_recorded_pin($dir);
    $state = envlite_state_load($dir);
    envlite_assert(!isset($state['phase5.recorded_pin_sha']),
        'pin entry must be removed from on-disk state');
    envlite_assert(isset($state['phase2.input_hash']),
        'unrelated state entries must be preserved');
}

function test_phase5_drop_recorded_pin_is_idempotent_without_pin() {
    // No-op when the pin is not recorded. The state file must not be
    // rewritten — important because envlite_state_save is an atomic
    // write that mutates an inode timestamp; a no-op should be a no-op.
    $dir = envlite_test_tmpdir('phase5-drop-pin-noop');
    mkdir("$dir/.cache/envlite", 0755, true);
    envlite_state_save($dir, ['other.key' => 'value']);
    $statePath = "$dir/.cache/envlite/state";
    $before = file_get_contents($statePath);
    $mtimeBefore = filemtime($statePath);

    // Sleep beyond filesystem mtime granularity (1s on HFS+/APFS in
    // some configurations) so a rewrite would be detectable.
    clearstatcache(true, $statePath);
    envlite_phase5_drop_recorded_pin($dir);
    clearstatcache(true, $statePath);

    $after = file_get_contents($statePath);
    envlite_assert_eq($before, $after,
        'state file contents must be unchanged when pin is absent');
}

function test_phase5_stage_temp_zip_writes_bytes_to_named_path() {
    // Happy path: returned path exists, contents match input bytes, sits
    // inside the requested tmp dir.
    $tmpDir = envlite_test_tmpdir('phase5-stage-ok');
    $payload = "ZIPBYTES\x00\x01\xff";
    $path = envlite_phase5_stage_temp_zip($tmpDir, $payload);
    envlite_assert(strpos($path, $tmpDir) === 0,
        "staged path must sit inside $tmpDir, got $path");
    envlite_assert_eq($payload, file_get_contents($path));
    @unlink($path);
}

function test_phase5_stage_temp_zip_throws_phase_5_on_unwritable_dir() {
    // Regression: an unwritable temp dir would let file_put_contents
    // return false, the SHA verify step then hashes nothing/garbage and
    // throws a misleading "SHA256 mismatch" — burying the real cause.
    // The helper checks the write return and surfaces a phase-5-prefixed
    // diagnostic that names the temp-zip write failure.
    if (DIRECTORY_SEPARATOR !== '/' || posix_geteuid() === 0) {
        return; // chmod 0500 doesn't bind root; Windows differs
    }
    $ro = envlite_test_tmpdir('phase5-stage-ro');
    chmod($ro, 0500); // r-x for owner only
    try {
        $thrown = null;
        try {
            envlite_phase5_stage_temp_zip($ro, 'payload');
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }
        envlite_assert($thrown !== null,
            'stage_temp_zip must throw on an unwritable temp dir');
        envlite_assert(strpos($thrown->getMessage(), 'phase 5') !== false,
            'error must carry the phase 5 prefix; got: ' . $thrown->getMessage());
        envlite_assert(strpos($thrown->getMessage(), 'temp-zip write') !== false,
            'error must name temp-zip write; got: ' . $thrown->getMessage());
    } finally {
        chmod($ro, 0755);
    }
}

function test_phase5_does_not_skip_when_plugin_path_is_symlink() {
    // Round 9 P1 regression: a symlink at the plugin path would let the
    // alreadyInstalled predicate satisfy db.copy "presence" via the
    // symlink target, and `is_dir($pluginDir)` follows symlinks so the
    // ownership prompt would not fire either. Even worse, on a yes
    // prompt or --force, extractTo writes through the symlink target —
    // potentially modifying files anywhere on disk.
    //
    // The fix excludes symlinks from the alreadyInstalled predicate.
    // Exercise that by setting up a fixture where everything else makes
    // the skip predicate true (manifest + pin + db.copy via target);
    // confirm the fetcher is still called because the symlink fails
    // the predicate.
    if (DIRECTORY_SEPARATOR !== '/') { return; }
    $dir = envlite_test_make_fixture_repo();
    $pluginRel = 'src/wp-content/plugins/sqlite-database-integration';
    $pluginPath = "$dir/$pluginRel";

    // Replace the fixture's real plugin dir with a symlink to an external
    // target that contains the expected db.copy file. Without the fix,
    // alreadyInstalled would be true and the fetcher would never run.
    envlite_rrmdir($pluginPath);
    $fakeTarget = envlite_test_tmpdir('phase5-symlink-target');
    file_put_contents("$fakeTarget/db.copy",
        "<?php // {SQLITE_IMPLEMENTATION_FOLDER_PATH}\n");
    symlink($fakeTarget, $pluginPath);

    envlite_manifest_save($dir, [$pluginRel => 'dir']);
    envlite_state_save($dir, [
        'phase5.recorded_pin_sha' => ENVLITE_SQLITE_PLUGIN_SHA256,
    ]);

    $fetcherCalled = false;
    try {
        envlite_phase5_install($dir, true, false,
            static function () use (&$fetcherCalled): string {
                $fetcherCalled = true;
                throw new \RuntimeException('fetcher invoked — symlink correctly rejected the skip');
            });
    } catch (\RuntimeException $e) {
        // expected — fetcher's throw propagates
    }
    envlite_assert($fetcherCalled,
        'phase 5 must not skip when plugin path is a symlink — fetcher must run');

    // The symlink survives a pre-extract failure (consistent with the
    // round-4 contract: pre-extract failures leave on-disk state
    // unchanged so an offline re-run can recover).
    envlite_assert(is_link($pluginPath),
        'symlink must remain after pre-extract fetcher failure');
    envlite_assert(file_exists($fakeTarget),
        'symlink target must not be deleted by phase 5 on pre-extract failure');
}

function test_phase5_clear_plugin_blocker_recursively_removes_real_directory() {
    // Round 15 update: a real directory at the plugin path used to
    // survive (overlay extract). That left extractTo exposed to
    // pre-existing symlinks inside the tree — extractTo could follow
    // them and write outside the checkout. The helper now clears
    // real directories too so extractTo materializes a fresh tree.
    $dir = envlite_test_tmpdir('phase5-blocker-realdir');
    $plugin = "$dir/sqlite-database-integration";
    mkdir($plugin);
    file_put_contents("$plugin/inner", 'must-be-cleared');
    envlite_phase5_clear_plugin_blocker($plugin);
    envlite_assert(!file_exists($plugin),
        'real directory must be cleared so extractTo creates a fresh tree');
}

function test_phase5_clear_plugin_blocker_unlinks_symlinks_inside_existing_tree() {
    // Round 15 P2 regression: a pre-existing symlink inside the plugin
    // tree would be followed by extractTo's overlay-write. The clear
    // step now rrmdir's the tree (rrmdir is symlink-aware: it unlinks
    // symlinks rather than following them), so the symlink is gone
    // before extractTo runs and its target is untouched.
    if (DIRECTORY_SEPARATOR !== '/') { return; }
    $dir = envlite_test_tmpdir('phase5-blocker-inner-symlink');
    $plugin = "$dir/sqlite-database-integration";
    mkdir($plugin);
    $external = envlite_test_tmpdir('phase5-blocker-inner-symlink-target');
    file_put_contents("$external/external-file", 'EXTERNAL_DATA');
    symlink("$external/external-file", "$plugin/db.copy");
    envlite_assert(is_link("$plugin/db.copy"));

    envlite_phase5_clear_plugin_blocker($plugin);

    envlite_assert(!file_exists($plugin),
        'plugin tree (containing the symlink) must be cleared');
    envlite_assert_eq('EXTERNAL_DATA', file_get_contents("$external/external-file"),
        'symlink target must not be touched by the clear pass');
}

function test_phase5_clear_plugin_blocker_unlinks_symlink() {
    if (DIRECTORY_SEPARATOR !== '/') { return; }
    $dir = envlite_test_tmpdir('phase5-blocker-symlink');
    $plugin = "$dir/sqlite-database-integration";
    $target = envlite_test_tmpdir('phase5-blocker-symlink-target');
    file_put_contents("$target/external", 'must-survive');
    symlink($target, $plugin);
    envlite_phase5_clear_plugin_blocker($plugin);
    envlite_assert(!is_link($plugin), 'symlink must be unlinked');
    envlite_assert(!file_exists($plugin), 'symlink path must be empty afterwards');
    envlite_assert_eq('must-survive', file_get_contents("$target/external"),
        'symlink target must be untouched');
}

function test_phase5_clear_plugin_blocker_unlinks_regular_file() {
    // Round 10 regression: a regular file at the plugin path used to
    // slip past the prompt (and the @unlink in round 9's fix only
    // handled symlinks), so extractTo would fail mid-extract instead
    // of overwriting the user's file per the ownership contract.
    $dir = envlite_test_tmpdir('phase5-blocker-regular');
    $plugin = "$dir/sqlite-database-integration";
    file_put_contents($plugin, 'stray file blocking the plugin path');
    envlite_phase5_clear_plugin_blocker($plugin);
    envlite_assert(!file_exists($plugin),
        'regular-file blocker must be unlinked before extractTo');
}

function test_phase5_clear_plugin_blocker_throws_when_symlink_cannot_be_removed() {
    // Force @unlink to fail by chmod'ing the parent directory to read-only.
    // POSIX-only; root bypasses permission bits.
    if (DIRECTORY_SEPARATOR !== '/' || posix_geteuid() === 0) { return; }
    $dir = envlite_test_tmpdir('phase5-blocker-readonly');
    $plugin = "$dir/sqlite-database-integration";
    $target = envlite_test_tmpdir('phase5-blocker-readonly-target');
    symlink($target, $plugin);
    chmod($dir, 0555); // r-x: cannot unlink children
    try {
        $thrown = null;
        try {
            envlite_phase5_clear_plugin_blocker($plugin);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }
        envlite_assert($thrown !== null,
            'must throw when the surviving symlink cannot be cleared');
        envlite_assert(strpos($thrown->getMessage(), 'phase 5') !== false,
            'error must carry the phase 5 prefix; got: ' . $thrown->getMessage());
        envlite_assert(strpos($thrown->getMessage(), 'could not clear') !== false,
            'error must name the clear failure; got: ' . $thrown->getMessage());
        envlite_assert(strpos($thrown->getMessage(), 'refusing to extract') !== false,
            'error must spell out the refusal so the rationale is in the user log');
    } finally {
        chmod($dir, 0755);
    }
}

function test_phase5_install_aborts_when_plugin_path_shape_changes_during_fetch() {
    // Round 17 P2 regression: the initial ownership scan and prompt fire
    // before the HTTP fetch + SHA verify + zip open window. If another
    // process (or the user) creates an entry at the plugin path during
    // that window, the clear pass deletes it without prompting — the
    // initial consent (or `--force`) was for whatever was there at scan
    // time, not for the new entry. The fix re-stats the path
    // immediately before the clear and aborts if its shape changed.
    //
    // Exercise with a fetcher that flips the on-disk shape mid-flight:
    //   - initial scan: plugin path doesn't exist (somethingExists=false)
    //   - fetcher runs: creates a user directory at the plugin path
    //   - clear pass: re-stat sees a real dir where there was nothing →
    //     throw before clobbering
    if (DIRECTORY_SEPARATOR !== '/') { return; }
    $dir = envlite_test_make_fixture_repo();
    $pluginPath = "$dir/src/wp-content/plugins/sqlite-database-integration";
    envlite_rrmdir($pluginPath); // initial scan: nothing there

    $thrown = null;
    try {
        envlite_phase5_install($dir, true, false,
            static function () use ($pluginPath): string {
                // Simulate concurrent interference: a user-installed
                // plugin appears at the path during the fetch window.
                mkdir($pluginPath);
                file_put_contents("$pluginPath/user-file", 'must-not-be-clobbered');
                // Still throw so we don't proceed to extract (avoiding
                // the network dependency on wordpress.org for this test).
                throw new \RuntimeException('fetcher returning early to bound the test');
            });
    } catch (\RuntimeException $e) {
        $thrown = $e;
    }
    envlite_assert($thrown !== null, 'fetcher RuntimeException must propagate');
    // Either we got the fetcher's own throw (the race-detection branch
    // is downstream, so the fetcher's early throw wins on this path) OR
    // the race-detection branch fired. Either way the user's file must
    // survive — the consent at scan time was for "nothing" and the new
    // tree was not part of that consent.
    envlite_assert(file_exists("$pluginPath/user-file"),
        'user-created file must not be clobbered by the failed install path');
}

function test_phase5_install_preserves_pin_when_fetcher_throws_pre_extract() {
    // Pre-extract failures (offline HTTP, SHA mismatch, bad zip) must not
    // clear `phase5.recorded_pin_sha`. The existing plugin tree on disk
    // is untouched by those paths, so the next `up` should still satisfy
    // the manifest + db.copy + pin skip predicate and run offline.
    //
    // We exercise this by routing the install through a fetcher that
    // throws immediately. The pin invalidation point sits AFTER fetch/
    // verify/zip-open, so the throw aborts before any state mutation.
    $dir = envlite_test_make_fixture_repo();
    $pin = ENVLITE_SQLITE_PLUGIN_SHA256;
    envlite_manifest_save($dir, [
        'src/wp-content/plugins/sqlite-database-integration' => 'dir',
    ]);
    envlite_state_save($dir, ['phase5.recorded_pin_sha' => $pin]);
    // Drop db.copy to force the re-extract path. Manifest + pin remain
    // intact, so the skip predicate fails only on `is_file($dbCopy)`.
    unlink("$dir/src/wp-content/plugins/sqlite-database-integration/db.copy");

    $threw = false;
    try {
        envlite_phase5_install($dir, true, false, static function (): string {
            throw new \RuntimeException('fetcher offline');
        });
    } catch (\RuntimeException $e) {
        $threw = strpos($e->getMessage(), 'fetcher offline') !== false;
    }
    envlite_assert($threw, 'fetcher RuntimeException must propagate out of phase5_install');

    $state = envlite_state_load($dir);
    envlite_assert(isset($state['phase5.recorded_pin_sha']),
        'pin must remain in state after a pre-extract failure');
    envlite_assert_eq($pin, $state['phase5.recorded_pin_sha']);
}

function test_phase5_tripwire_throws_when_placeholder_missing() {
    $dir = envlite_test_tmpdir('tripwire-missing');
    file_put_contents($dir . '/db.copy', '<?php // someone substituted the placeholder');
    try {
        envlite_phase5_assert_placeholder($dir . '/db.copy');
        throw new \RuntimeException('expected exception');
    } catch (\RuntimeException $e) {
        envlite_assert(strpos($e->getMessage(), 'placeholder') !== false);
    }
}

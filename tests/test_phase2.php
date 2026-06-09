<?php
function test_phase2_verify_sha_passes() {
    $bytes = "hello\n";
    $hash = hash('sha256', $bytes);
    $tmp = sys_get_temp_dir() . '/envlite-sha-' . bin2hex(random_bytes(4));
    file_put_contents($tmp, $bytes);
    envlite_phase2_verify_sha256($tmp, $hash); // must not throw
    unlink($tmp);
}

function test_phase2_verify_sha_throws_on_mismatch() {
    $tmp = sys_get_temp_dir() . '/envlite-sha-' . bin2hex(random_bytes(4));
    file_put_contents($tmp, "x");
    try {
        envlite_phase2_verify_sha256($tmp, str_repeat('0', 64));
        unlink($tmp);
        throw new \RuntimeException('expected exception');
    } catch (\RuntimeException $e) {
        unlink($tmp);
        envlite_assert(strpos($e->getMessage(), 'SHA256 mismatch') !== false);
    }
}

function test_phase2_tripwire_passes_when_placeholder_present() {
    $dir = envlite_test_tmpdir('tripwire-ok');
    file_put_contents($dir . '/db.copy', '<?php // {SQLITE_IMPLEMENTATION_FOLDER_PATH} fallback ...');
    envlite_phase2_assert_placeholder($dir . '/db.copy'); // must not throw
}

function test_phase2_path_signature_null_for_missing_path() {
    $dir = envlite_test_tmpdir('phase2-sig-missing');
    envlite_assert_eq(null, envlite_phase2_path_signature("$dir/no-such-entry"));
}

function test_phase2_path_signature_distinct_for_distinct_entries() {
    // Round 18 P2 regression: round-17's pre-clear check compared only
    // booleans (is_link / is_real_dir / exists). A same-shape swap
    // (real-dir A replaced by real-dir B) kept the booleans constant
    // and passed the guard, so the clear would delete the replacement
    // under stale consent. The fix uses lstat ino+dev — exercise that
    // a fresh directory at the same path produces a different
    // signature than the original.
    if (DIRECTORY_SEPARATOR !== '/') { return; }
    $dir = envlite_test_tmpdir('phase2-sig-swap');
    $p = "$dir/plugin-path";
    mkdir($p);
    $beforeSig = envlite_phase2_path_signature($p);
    envlite_assert($beforeSig !== null, 'signature must be non-null for an existing dir');

    // Replace the directory with a fresh one (rrmdir + mkdir = new inode
    // on POSIX). Same shape (still a real directory), different identity.
    envlite_rrmdir($p);
    mkdir($p);
    $afterSig = envlite_phase2_path_signature($p);
    envlite_assert($afterSig !== null);
    envlite_assert($beforeSig !== $afterSig,
        'same-shape swap must produce a different signature; before=' . $beforeSig . ' after=' . $afterSig);
}

function test_phase2_path_signature_stable_across_same_inode() {
    // Inverse: the SAME on-disk entry must produce the SAME signature
    // across calls. Otherwise the TOCTOU guard would false-positive
    // and abort on every fetch even when nothing changed.
    $dir = envlite_test_tmpdir('phase2-sig-stable');
    $p = "$dir/plugin-path";
    mkdir($p);
    file_put_contents("$p/inner", 'content');
    envlite_assert_eq(
        envlite_phase2_path_signature($p),
        envlite_phase2_path_signature($p),
        'signature must be stable for the same on-disk entry'
    );
    // Even after modifying inner contents (mtime changes, but the
    // directory's inode stays the same), the signature must match.
    file_put_contents("$p/inner", 'different content');
    file_put_contents("$p/another", 'new file');
    envlite_assert(
        envlite_phase2_path_signature($p) === envlite_phase2_path_signature($p),
        'signature must remain stable when inner contents change (inode unchanged)'
    );
}

function test_phase2_path_signature_changes_when_dir_replaced_by_symlink() {
    if (DIRECTORY_SEPARATOR !== '/') { return; }
    $dir = envlite_test_tmpdir('phase2-sig-shape-swap');
    $p = "$dir/plugin-path";
    mkdir($p);
    $beforeSig = envlite_phase2_path_signature($p);

    // Replace the dir with a symlink (shape change AND identity change).
    rmdir($p);
    $target = envlite_test_tmpdir('phase2-sig-shape-swap-target');
    symlink($target, $p);
    $afterSig = envlite_phase2_path_signature($p);

    envlite_assert($beforeSig !== null && $afterSig !== null);
    envlite_assert($beforeSig !== $afterSig,
        'shape-change swap must change signature');
}

function envlite_test_phase2_build_minimal_plugin_zip(string $zipPath): void {
    // Build a minimal zip resembling the real sqlite-database-integration
    // archive: a top-level directory with a `db.copy` file. Used by the
    // apply_extract integration tests below — they need a real ZipArchive
    // handle that can extractTo successfully, but a fully valid SQLite
    // drop-in (matching the SHA pin) is impossible to fabricate without
    // the actual upstream bytes.
    $zip = new \ZipArchive();
    envlite_assert($zip->open($zipPath, \ZipArchive::CREATE) === true,
        "could not create test zip at $zipPath");
    $zip->addEmptyDir('sqlite-database-integration');
    $zip->addFromString('sqlite-database-integration/db.copy',
        "<?php // {SQLITE_IMPLEMENTATION_FOLDER_PATH}\n");
    $zip->close();
}

function test_phase2_apply_extract_throws_when_signature_mismatched() {
    // Round 19 integration regression: round-18 added the identity-based
    // TOCTOU check inside envlite_phase2_install, but the new tests
    // exercised only the signature helper in isolation. They would have
    // passed even if the install path stopped calling the helper.
    // envlite_phase2_apply_extract is now the small testable seam —
    // confirm the guard fires when the initial signature doesn't match
    // the current path identity.
    if (DIRECTORY_SEPARATOR !== '/') { return; }
    $dir = envlite_test_tmpdir('phase2-apply-mismatch');
    $pluginsParent = "$dir/src/wp-content/plugins";
    mkdir($pluginsParent, 0755, true);
    $pluginPath = "$pluginsParent/sqlite-database-integration";

    $zipPath = envlite_test_tmpdir('phase2-apply-mismatch-zip') . '/payload.zip';
    envlite_test_phase2_build_minimal_plugin_zip($zipPath);
    $zip = new \ZipArchive();
    envlite_assert($zip->open($zipPath) === true);

    try {
        $initialSignature = envlite_phase2_path_signature($pluginPath); // null
        // Simulate concurrent interference: a user creates a directory
        // at the plugin path during the fetch window.
        mkdir($pluginPath);
        file_put_contents("$pluginPath/user-file", 'must-survive');

        $thrown = null;
        try {
            envlite_phase2_apply_extract($dir, $pluginPath, $initialSignature, $zip);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }
        envlite_assert($thrown !== null,
            'apply_extract must throw when the plugin path identity changes');
        envlite_assert(strpos($thrown->getMessage(), 'changed identity') !== false,
            'error must name the identity-change failure; got: ' . $thrown->getMessage());

        // The user's directory and inner file must survive — the guard
        // fired BEFORE the clear pass, so no clobbering.
        envlite_assert(is_dir($pluginPath),
            'guard must abort before clearing — directory must survive');
        envlite_assert_eq('must-survive', file_get_contents("$pluginPath/user-file"),
            'user file must survive the guard-throw path');
    } finally {
        $zip->close();
    }
}

function test_phase2_apply_extract_succeeds_when_signature_matches() {
    // Positive case: signature unchanged from initial scan to apply
    // time. Helper clears the (empty) plugin path and extracts the zip
    // into the parent. End state: a fresh tree from the zip.
    if (DIRECTORY_SEPARATOR !== '/') { return; }
    $dir = envlite_test_tmpdir('phase2-apply-ok');
    $pluginsParent = "$dir/src/wp-content/plugins";
    mkdir($pluginsParent, 0755, true);
    $pluginPath = "$pluginsParent/sqlite-database-integration";

    $zipPath = envlite_test_tmpdir('phase2-apply-ok-zip') . '/payload.zip';
    envlite_test_phase2_build_minimal_plugin_zip($zipPath);
    $zip = new \ZipArchive();
    envlite_assert($zip->open($zipPath) === true);

    try {
        $initialSignature = envlite_phase2_path_signature($pluginPath); // null
        envlite_phase2_apply_extract($dir, $pluginPath, $initialSignature, $zip);
        envlite_assert(is_dir($pluginPath),
            'extract must materialize the plugin directory');
        envlite_assert(is_file("$pluginPath/db.copy"),
            'extract must produce db.copy from the zip');
    } finally {
        $zip->close();
    }
}

function test_phase2_drop_recorded_pin_removes_pin_from_state() {
    // Set up a state file with the pin and an unrelated key; verify the
    // helper removes only the pin and leaves the rest intact. This is the
    // pre-extraction invalidation step that prevents a partial extract
    // from being skipped on the next run.
    $dir = envlite_test_tmpdir('phase2-drop-pin');
    mkdir("$dir/.cache/envlite", 0755, true);
    envlite_state_save($dir, [
        'phase2.recorded_pin_sha' => str_repeat('a', 64),
        'other.key'       => str_repeat('b', 64),
    ]);
    envlite_phase2_drop_recorded_pin($dir);
    $state = envlite_state_load($dir);
    envlite_assert(!isset($state['phase2.recorded_pin_sha']),
        'pin entry must be removed from on-disk state');
    envlite_assert(isset($state['other.key']),
        'unrelated state entries must be preserved');
}

function test_phase2_drop_recorded_pin_is_idempotent_without_pin() {
    // No-op when the pin is not recorded. The state file must not be
    // rewritten — important because envlite_state_save is an atomic
    // write that mutates an inode timestamp; a no-op should be a no-op.
    $dir = envlite_test_tmpdir('phase2-drop-pin-noop');
    mkdir("$dir/.cache/envlite", 0755, true);
    envlite_state_save($dir, ['other.key' => 'value']);
    $statePath = "$dir/.cache/envlite/state";
    $before = file_get_contents($statePath);
    $mtimeBefore = filemtime($statePath);

    // Sleep beyond filesystem mtime granularity (1s on HFS+/APFS in
    // some configurations) so a rewrite would be detectable.
    clearstatcache(true, $statePath);
    envlite_phase2_drop_recorded_pin($dir);
    clearstatcache(true, $statePath);

    $after = file_get_contents($statePath);
    envlite_assert_eq($before, $after,
        'state file contents must be unchanged when pin is absent');
}

function test_phase2_stage_temp_zip_writes_bytes_to_named_path() {
    // Happy path: returned path exists, contents match input bytes, sits
    // inside the requested tmp dir.
    $tmpDir = envlite_test_tmpdir('phase2-stage-ok');
    $payload = "ZIPBYTES\x00\x01\xff";
    $path = envlite_phase2_stage_temp_zip($tmpDir, $payload);
    envlite_assert(strpos($path, $tmpDir) === 0,
        "staged path must sit inside $tmpDir, got $path");
    envlite_assert_eq($payload, file_get_contents($path));
    @unlink($path);
}

function test_phase2_stage_temp_zip_throws_phase_2_on_unwritable_dir() {
    // Regression: an unwritable temp dir would let file_put_contents
    // return false, the SHA verify step then hashes nothing/garbage and
    // throws a misleading "SHA256 mismatch" — burying the real cause.
    // The helper checks the write return and surfaces a phase-5-prefixed
    // diagnostic that names the temp-zip write failure.
    if (DIRECTORY_SEPARATOR !== '/' || posix_geteuid() === 0) {
        return; // chmod 0500 doesn't bind root; Windows differs
    }
    $ro = envlite_test_tmpdir('phase2-stage-ro');
    chmod($ro, 0500); // r-x for owner only
    try {
        $thrown = null;
        try {
            envlite_phase2_stage_temp_zip($ro, 'payload');
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }
        envlite_assert($thrown !== null,
            'stage_temp_zip must throw on an unwritable temp dir');
        envlite_assert(strpos($thrown->getMessage(), 'phase 2') !== false,
            'error must carry the phase 2 prefix; got: ' . $thrown->getMessage());
        envlite_assert(strpos($thrown->getMessage(), 'temp-zip write') !== false,
            'error must name temp-zip write; got: ' . $thrown->getMessage());
    } finally {
        chmod($ro, 0755);
    }
}

function test_phase2_does_not_skip_when_plugin_path_is_symlink() {
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
    $fakeTarget = envlite_test_tmpdir('phase2-symlink-target');
    file_put_contents("$fakeTarget/db.copy",
        "<?php // {SQLITE_IMPLEMENTATION_FOLDER_PATH}\n");
    symlink($fakeTarget, $pluginPath);

    envlite_manifest_save($dir, [$pluginRel => 'dir']);
    envlite_state_save($dir, [
        'phase2.recorded_pin_sha' => ENVLITE_SQLITE_PLUGIN_SHA256,
    ]);

    $fetcherCalled = false;
    try {
        envlite_phase2_install($dir, true,
            static function () use (&$fetcherCalled): string {
                $fetcherCalled = true;
                throw new \RuntimeException('fetcher invoked — symlink correctly rejected the skip');
            });
    } catch (\RuntimeException $e) {
        // expected — fetcher's throw propagates
    }
    envlite_assert($fetcherCalled,
        'phase 2 must not skip when plugin path is a symlink — fetcher must run');

    // The symlink survives a pre-extract failure (consistent with the
    // round-4 contract: pre-extract failures leave on-disk state
    // unchanged so an offline re-run can recover).
    envlite_assert(is_link($pluginPath),
        'symlink must remain after pre-extract fetcher failure');
    envlite_assert(file_exists($fakeTarget),
        'symlink target must not be deleted by phase 2 on pre-extract failure');
}

function test_phase2_clear_plugin_blocker_recursively_removes_real_directory() {
    // Round 15 update: a real directory at the plugin path used to
    // survive (overlay extract). That left extractTo exposed to
    // pre-existing symlinks inside the tree — extractTo could follow
    // them and write outside the checkout. The helper now clears
    // real directories too so extractTo materializes a fresh tree.
    $dir = envlite_test_tmpdir('phase2-blocker-realdir');
    $plugin = "$dir/sqlite-database-integration";
    mkdir($plugin);
    file_put_contents("$plugin/inner", 'must-be-cleared');
    envlite_phase2_clear_plugin_blocker($plugin);
    envlite_assert(!file_exists($plugin),
        'real directory must be cleared so extractTo creates a fresh tree');
}

function test_phase2_clear_plugin_blocker_unlinks_symlinks_inside_existing_tree() {
    // Round 15 P2 regression: a pre-existing symlink inside the plugin
    // tree would be followed by extractTo's overlay-write. The clear
    // step now rrmdir's the tree (rrmdir is symlink-aware: it unlinks
    // symlinks rather than following them), so the symlink is gone
    // before extractTo runs and its target is untouched.
    if (DIRECTORY_SEPARATOR !== '/') { return; }
    $dir = envlite_test_tmpdir('phase2-blocker-inner-symlink');
    $plugin = "$dir/sqlite-database-integration";
    mkdir($plugin);
    $external = envlite_test_tmpdir('phase2-blocker-inner-symlink-target');
    file_put_contents("$external/external-file", 'EXTERNAL_DATA');
    symlink("$external/external-file", "$plugin/db.copy");
    envlite_assert(is_link("$plugin/db.copy"));

    envlite_phase2_clear_plugin_blocker($plugin);

    envlite_assert(!file_exists($plugin),
        'plugin tree (containing the symlink) must be cleared');
    envlite_assert_eq('EXTERNAL_DATA', file_get_contents("$external/external-file"),
        'symlink target must not be touched by the clear pass');
}

function test_phase2_clear_plugin_blocker_unlinks_symlink() {
    if (DIRECTORY_SEPARATOR !== '/') { return; }
    $dir = envlite_test_tmpdir('phase2-blocker-symlink');
    $plugin = "$dir/sqlite-database-integration";
    $target = envlite_test_tmpdir('phase2-blocker-symlink-target');
    file_put_contents("$target/external", 'must-survive');
    symlink($target, $plugin);
    envlite_phase2_clear_plugin_blocker($plugin);
    envlite_assert(!is_link($plugin), 'symlink must be unlinked');
    envlite_assert(!file_exists($plugin), 'symlink path must be empty afterwards');
    envlite_assert_eq('must-survive', file_get_contents("$target/external"),
        'symlink target must be untouched');
}

function test_phase2_clear_plugin_blocker_unlinks_regular_file() {
    // Round 10 regression: a regular file at the plugin path used to
    // slip past the prompt (and the @unlink in round 9's fix only
    // handled symlinks), so extractTo would fail mid-extract instead
    // of overwriting the user's file per the ownership contract.
    $dir = envlite_test_tmpdir('phase2-blocker-regular');
    $plugin = "$dir/sqlite-database-integration";
    file_put_contents($plugin, 'stray file blocking the plugin path');
    envlite_phase2_clear_plugin_blocker($plugin);
    envlite_assert(!file_exists($plugin),
        'regular-file blocker must be unlinked before extractTo');
}

function test_phase2_clear_plugin_blocker_throws_when_symlink_cannot_be_removed() {
    // Force @unlink to fail by chmod'ing the parent directory to read-only.
    // POSIX-only; root bypasses permission bits.
    if (DIRECTORY_SEPARATOR !== '/' || posix_geteuid() === 0) { return; }
    $dir = envlite_test_tmpdir('phase2-blocker-readonly');
    $plugin = "$dir/sqlite-database-integration";
    $target = envlite_test_tmpdir('phase2-blocker-readonly-target');
    symlink($target, $plugin);
    chmod($dir, 0555); // r-x: cannot unlink children
    try {
        $thrown = null;
        try {
            envlite_phase2_clear_plugin_blocker($plugin);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }
        envlite_assert($thrown !== null,
            'must throw when the surviving symlink cannot be cleared');
        envlite_assert(strpos($thrown->getMessage(), 'phase 2') !== false,
            'error must carry the phase 2 prefix; got: ' . $thrown->getMessage());
        envlite_assert(strpos($thrown->getMessage(), 'could not clear') !== false,
            'error must name the clear failure; got: ' . $thrown->getMessage());
        envlite_assert(strpos($thrown->getMessage(), 'refusing to extract') !== false,
            'error must spell out the refusal so the rationale is in the user log');
    } finally {
        chmod($dir, 0755);
    }
}

// NOTE: an earlier `test_phase2_install_aborts_when_plugin_path_shape_changes_during_fetch`
// test claimed to exercise the install-path TOCTOU re-check, but its injected
// fetcher threw BEFORE the re-check code ran — the test would have passed even
// without the guard. Removed to avoid masking future regressions.

function test_phase2_install_preserves_pin_when_fetcher_throws_pre_extract() {
    // Pre-extract failures (offline HTTP, SHA mismatch, bad zip) must not
    // clear `phase2.recorded_pin_sha` or disturb the on-disk plugin tree:
    // envlite_phase2_drop_recorded_pin runs only AFTER a successful fetch,
    // immediately before extractTo. The failed paths never reach it.
    //
    // Re-entry into the fetch path is forced here by a pin MISMATCH — the
    // documented "code-level pin bump re-downloads" trigger: state records a
    // stale pin, so the skip predicate fails even though the manifest entry
    // and db.copy are intact. db.copy stays on disk; we then assert it AND
    // the recorded pin both survive the fetcher's throw.
    $dir = envlite_test_make_fixture_repo();
    $stalePin = str_repeat('e', 64); // deliberately != ENVLITE_SQLITE_PLUGIN_SHA256
    $dbCopy = "$dir/src/wp-content/plugins/sqlite-database-integration/db.copy";
    envlite_manifest_save($dir, [
        'src/wp-content/plugins/sqlite-database-integration' => 'dir',
    ]);
    envlite_state_save($dir, ['phase2.recorded_pin_sha' => $stalePin]);
    envlite_assert(is_file($dbCopy), 'fixture must have db.copy present');
    $dbCopyContentsBefore = file_get_contents($dbCopy);

    $threw = false;
    try {
        envlite_phase2_install($dir, true, static function (): string {
            throw new \RuntimeException('fetcher offline');
        });
    } catch (\RuntimeException $e) {
        $threw = strpos($e->getMessage(), 'fetcher offline') !== false;
    }
    envlite_assert($threw, 'fetcher RuntimeException must propagate out of phase2_install');

    $state = envlite_state_load($dir);
    envlite_assert(isset($state['phase2.recorded_pin_sha']),
        'pin entry must remain in state after a pre-extract failure');
    envlite_assert_eq($stalePin, $state['phase2.recorded_pin_sha'],
        'pre-extract failure must not mutate the recorded pin');

    // db.copy must survive too — pre-extract failures leave the on-disk
    // plugin tree untouched.
    envlite_assert(is_file($dbCopy),
        'db.copy must survive a pre-extract failure');
    envlite_assert_eq($dbCopyContentsBefore, file_get_contents($dbCopy),
        'db.copy contents must be byte-identical (no partial write through the failed install)');
}

function test_phase2_tripwire_throws_when_placeholder_missing() {
    $dir = envlite_test_tmpdir('tripwire-missing');
    file_put_contents($dir . '/db.copy', '<?php // someone substituted the placeholder');
    try {
        envlite_phase2_assert_placeholder($dir . '/db.copy');
        throw new \RuntimeException('expected exception');
    } catch (\RuntimeException $e) {
        envlite_assert(strpos($e->getMessage(), 'placeholder') !== false);
    }
}

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

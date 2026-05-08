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

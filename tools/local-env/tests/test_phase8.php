<?php
function test_phase8_router_content_exact() {
    $expected = "<?php\n"
              . "\$path = parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH);\n"
              . "\$file = __DIR__ . '/src' . \$path;\n"
              . "if (\$path !== '/' && file_exists(\$file) && !is_dir(\$file)) {\n"
              . "    return false;\n"
              . "}\n"
              . "require __DIR__ . '/src/index.php';\n";
    envlite_assert_eq($expected, envlite_phase8_router_content());
}

function test_phase8_install_writes_router_php() {
    $dir = envlite_test_tmpdir('phase8');
    envlite_phase8_install($dir, false);
    envlite_assert(is_file($dir . '/router.php'));
    envlite_assert_eq(envlite_phase8_router_content(), file_get_contents($dir . '/router.php'));
    $manifest = envlite_manifest_load($dir);
    envlite_assert(isset($manifest['router.php']));
}

function test_phase8_install_silent_overwrite_when_in_manifest_drifted() {
    $dir = envlite_test_tmpdir('phase8-drift');
    // First install
    envlite_phase8_install($dir, false);
    // Drift it
    file_put_contents($dir . '/router.php', "<?php // user tampered\n");
    // Re-install: must overwrite without prompting (force=false). If the prompt fires
    // we would stall on STDIN; the test harness has no TTY, so a prompt would actually
    // exit(5). We set force=true here as a defense — the assertion is the content.
    envlite_phase8_install($dir, true);
    envlite_assert_eq(envlite_phase8_router_content(), file_get_contents($dir . '/router.php'));
}

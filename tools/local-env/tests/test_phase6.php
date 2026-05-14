<?php
function test_phase6_render_substitutes_placeholders() {
    $sample = "define( 'DB_NAME', 'youremptytestdbnamehere' );\n"
            . "define( 'DB_USER', 'yourusernamehere' );\n"
            . "define( 'DB_PASSWORD', 'yourpasswordhere' );\n"
            . "define( 'WP_PHP_BINARY', 'php' );\n";
    $out = envlite_phase6_render($sample);
    envlite_assert(strpos($out, "'wordpress_test'") !== false);
    envlite_assert(strpos($out, "'wp'") !== false);
    envlite_assert(strpos($out, 'youremptytestdbnamehere') === false);
    envlite_assert(strpos($out, 'yourusernamehere') === false);
    envlite_assert(strpos($out, 'yourpasswordhere') === false);
}

function test_phase6_render_throws_when_placeholder_missing() {
    try {
        envlite_phase6_render("define( 'DB_NAME', 'someothername' );");
        throw new \RuntimeException('expected exception');
    } catch (\RuntimeException $e) {
        envlite_assert(strpos($e->getMessage(), 'placeholder') !== false);
    }
}

function test_phase6_render_pins_wp_php_binary_to_caller() {
    $sample = "define( 'DB_NAME', 'youremptytestdbnamehere' );\n"
            . "define( 'DB_USER', 'yourusernamehere' );\n"
            . "define( 'DB_PASSWORD', 'yourpasswordhere' );\n"
            . "define( 'WP_PHP_BINARY', 'php' );\n";
    $out = envlite_phase6_render($sample, '/opt/php80/bin/php');
    envlite_assert_eq(0, substr_count($out, "define( 'WP_PHP_BINARY', 'php' );"));
    envlite_assert_eq(1, substr_count($out, "define( 'WP_PHP_BINARY', '/opt/php80/bin/php' );"));
}

function test_phase6_render_throws_when_wp_php_binary_sample_literal_missing() {
    // If upstream removes or renames the WP_PHP_BINARY define, the pin step
    // has nothing to anchor on; surface the divergence loudly so envlite is
    // updated intentionally rather than silently leaking a `'php'` literal.
    $sample = "define( 'DB_NAME', 'youremptytestdbnamehere' );\n"
            . "define( 'DB_USER', 'yourusernamehere' );\n"
            . "define( 'DB_PASSWORD', 'yourpasswordhere' );\n";
    try {
        envlite_phase6_render($sample);
        throw new \RuntimeException('expected exception');
    } catch (\RuntimeException $e) {
        envlite_assert(strpos($e->getMessage(), 'WP_PHP_BINARY') !== false);
    }
}

function test_phase6_render_throws_when_db_file_already_defined() {
    $sample = "define( 'DB_NAME', 'youremptytestdbnamehere' );\n"
            . "define( 'DB_USER', 'yourusernamehere' );\n"
            . "define( 'DB_PASSWORD', 'yourpasswordhere' );\n"
            . "define( 'WP_PHP_BINARY', 'php' );\n"
            . "define( 'DB_FILE', 'something.sqlite' );\n";
    try {
        envlite_phase6_render($sample);
        throw new \RuntimeException('expected exception');
    } catch (\RuntimeException $e) {
        envlite_assert(strpos($e->getMessage(), 'DB_FILE') !== false);
    }
}

function test_phase6_render_appends_db_file_define() {
    $sample = "define( 'DB_NAME', 'youremptytestdbnamehere' );\n"
            . "define( 'DB_USER', 'yourusernamehere' );\n"
            . "define( 'DB_PASSWORD', 'yourpasswordhere' );\n"
            . "define( 'WP_PHP_BINARY', 'php' );\n";
    $out = envlite_phase6_render($sample);
    envlite_assert(preg_match("/define\\(\\s*'DB_FILE'\\s*,\\s*'\\.ht\\.test\\.sqlite'\\s*\\)\\s*;/", $out) === 1);
    // Output must end with a single trailing newline.
    envlite_assert(substr($out, -1) === "\n");
    envlite_assert(substr($out, -2) !== "\n\n");
}

function test_phase6_render_appends_db_file_when_sample_has_no_trailing_newline() {
    $sample = "define( 'DB_NAME', 'youremptytestdbnamehere' );\n"
            . "define( 'DB_USER', 'yourusernamehere' );\n"
            . "define( 'DB_PASSWORD', 'yourpasswordhere' );\n"
            . "define( 'WP_PHP_BINARY', 'php' );"; // no trailing \n
    $out = envlite_phase6_render($sample);
    envlite_assert(preg_match("/define\\(\\s*'DB_FILE'\\s*,\\s*'\\.ht\\.test\\.sqlite'\\s*\\)\\s*;/", $out) === 1);
    envlite_assert(substr($out, -1) === "\n");
}

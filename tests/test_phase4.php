<?php
function envlite_test_wp_config_sample(): string {
    // Inline mimic of wp-config-sample.php carrying exactly the structure
    // envlite_phase4_render depends on: DB placeholders, an 8-define salt
    // block (AUTH_KEY..NONCE_SALT) with the sample salt string, the
    // marker line, and CRLF endings so the normalize test exercises that
    // codepath.
    return "<?php\r\n"
        . "define( 'DB_NAME',     'database_name_here' );\r\n"
        . "define( 'DB_USER',     'username_here' );\r\n"
        . "define( 'DB_PASSWORD', 'password_here' );\r\n"
        . "\r\n"
        . "define( 'AUTH_KEY',         'put your unique phrase here' );\r\n"
        . "define( 'SECURE_AUTH_KEY',  'put your unique phrase here' );\r\n"
        . "define( 'LOGGED_IN_KEY',    'put your unique phrase here' );\r\n"
        . "define( 'NONCE_KEY',        'put your unique phrase here' );\r\n"
        . "define( 'AUTH_SALT',        'put your unique phrase here' );\r\n"
        . "define( 'SECURE_AUTH_SALT', 'put your unique phrase here' );\r\n"
        . "define( 'LOGGED_IN_SALT',   'put your unique phrase here' );\r\n"
        . "define( 'NONCE_SALT',       'put your unique phrase here' );\r\n"
        . "\r\n"
        . "/* That's all, stop editing! Happy publishing. */\r\n"
        . "require_once ABSPATH . 'wp-settings.php';\r\n";
}

function test_phase4_render_substitutes_db_constants() {
    $sample = envlite_test_wp_config_sample();
    $out = envlite_phase4_render($sample, 8421, null);
    envlite_assert(strpos($out, "'wordpress'") !== false, 'DB_NAME substituted');
    envlite_assert(strpos($out, 'database_name_here') === false);
    envlite_assert(strpos($out, 'username_here') === false);
    envlite_assert(strpos($out, 'password_here') === false);
}

function test_phase4_render_injects_wp_home_siteurl_before_marker() {
    $sample = envlite_test_wp_config_sample();
    $out = envlite_phase4_render($sample, 8421, null);
    $home = "define( 'WP_HOME',    'http://127.0.0.1:8421' );";
    $site = "define( 'WP_SITEURL', 'http://127.0.0.1:8421' );";
    $marker = "/* That's all, stop editing! Happy publishing. */";
    envlite_assert(strpos($out, $home) !== false);
    envlite_assert(strpos($out, $site) !== false);
    // injection must precede the marker
    envlite_assert(strpos($out, $home) < strpos($out, $marker), 'WP_HOME must be before marker');
    envlite_assert(strpos($out, $site) < strpos($out, $marker), 'WP_SITEURL must be before marker');
}

function test_phase4_render_replaces_salts_when_provided() {
    $sample = envlite_test_wp_config_sample();
    $salts = "define( 'AUTH_KEY', 'XYZ' );\n"
           . "define( 'SECURE_AUTH_KEY', 'XYZ' );\n"
           . "define( 'LOGGED_IN_KEY', 'XYZ' );\n"
           . "define( 'NONCE_KEY', 'XYZ' );\n"
           . "define( 'AUTH_SALT', 'XYZ' );\n"
           . "define( 'SECURE_AUTH_SALT', 'XYZ' );\n"
           . "define( 'LOGGED_IN_SALT', 'XYZ' );\n"
           . "define( 'NONCE_SALT', 'XYZ' );";
    $out = envlite_phase4_render($sample, 8421, $salts);
    envlite_assert(strpos($out, "'put your unique phrase here'") === false);
    envlite_assert(substr_count($out, "'XYZ'") === 8);
}

function test_phase4_render_keeps_sample_salts_when_null_provided() {
    $sample = envlite_test_wp_config_sample();
    $out = envlite_phase4_render($sample, 8421, null);
    // 8 sample salt lines remain unchanged
    envlite_assert_eq(8, substr_count($out, "'put your unique phrase here'"));
}

function test_phase4_render_treats_salts_as_literal_not_backreferences() {
    // The salts API returns random bytes; some include `$` and `\` which
    // preg_replace's replacement argument would interpret as backreferences.
    // The render path must insert the salts as a literal block.
    $sample = envlite_test_wp_config_sample();
    $literalBlock =
          "define( 'AUTH_KEY', 'a\$1b\\\\1c\$&d' );\n"
        . "define( 'SECURE_AUTH_KEY', 'X' );\n"
        . "define( 'LOGGED_IN_KEY', 'X' );\n"
        . "define( 'NONCE_KEY', 'X' );\n"
        . "define( 'AUTH_SALT', 'X' );\n"
        . "define( 'SECURE_AUTH_SALT', 'X' );\n"
        . "define( 'LOGGED_IN_SALT', 'X' );\n"
        . "define( 'NONCE_SALT', 'X' );";
    $out = envlite_phase4_render($sample, 8421, $literalBlock);
    envlite_assert(
        strpos($out, "define( 'AUTH_KEY', 'a\$1b\\\\1c\$&d' );") !== false,
        'metacharacters in salts must survive verbatim'
    );
}

function test_phase4_render_aborts_when_salt_block_is_reshaped() {
    // Regression: the older `.*?` span between AUTH_KEY and NONCE_SALT
    // would happily match across any inserted content and silently delete
    // it during replacement. The tightened regex requires the eight
    // defines to sit contiguously (whitespace-only between them), so any
    // reshape — even a single inserted line — aborts with a phase 4
    // diagnostic instead of subtly corrupting wp-config.php.
    $reshaped = "<?php\r\n"
        . "define( 'AUTH_KEY',         'put your unique phrase here' );\r\n"
        . "define( 'SECURE_AUTH_KEY',  'put your unique phrase here' );\r\n"
        . "define( 'WP_CACHE',         true );\r\n"  // foreign define injected mid-block
        . "define( 'LOGGED_IN_KEY',    'put your unique phrase here' );\r\n"
        . "define( 'NONCE_KEY',        'put your unique phrase here' );\r\n"
        . "define( 'AUTH_SALT',        'put your unique phrase here' );\r\n"
        . "define( 'SECURE_AUTH_SALT', 'put your unique phrase here' );\r\n"
        . "define( 'LOGGED_IN_SALT',   'put your unique phrase here' );\r\n"
        . "define( 'NONCE_SALT',       'put your unique phrase here' );\r\n"
        . "define( 'DB_NAME',     'database_name_here' );\r\n"
        . "define( 'DB_USER',     'username_here' );\r\n"
        . "define( 'DB_PASSWORD', 'password_here' );\r\n"
        . "/* That's all, stop editing! Happy publishing. */\r\n";
    $salts = "define( 'AUTH_KEY', 'X' );\n"
           . "define( 'SECURE_AUTH_KEY', 'X' );\n"
           . "define( 'LOGGED_IN_KEY', 'X' );\n"
           . "define( 'NONCE_KEY', 'X' );\n"
           . "define( 'AUTH_SALT', 'X' );\n"
           . "define( 'SECURE_AUTH_SALT', 'X' );\n"
           . "define( 'LOGGED_IN_SALT', 'X' );\n"
           . "define( 'NONCE_SALT', 'X' );";
    $thrown = null;
    try {
        envlite_phase4_render($reshaped, 8421, $salts);
    } catch (\RuntimeException $e) {
        $thrown = $e;
    }
    envlite_assert($thrown !== null,
        'reshaped salt block must abort, not silently span across the foreign define');
    envlite_assert(strpos($thrown->getMessage(), 'AUTH_KEY..NONCE_SALT') !== false,
        'error must identify the salt block; got: ' . $thrown->getMessage());
}

function test_phase4_render_normalizes_crlf_in_sample() {
    // wp-config-sample.php ships CRLF in tree on a normal checkout. The
    // render path must normalize so the output is LF-only and the recorded
    // hash is stable across git EOL settings.
    $sample = envlite_test_wp_config_sample();
    $out = envlite_phase4_render($sample, 8421, null);
    envlite_assert(strpos($out, "\r\n") === false, 'rendered config must be LF-only');
}

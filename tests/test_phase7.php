<?php
function test_phase7_render_substitutes_db_constants() {
    $sample = file_get_contents(dirname(__DIR__, 3) . '/wp-config-sample.php');
    $out = envlite_phase7_render($sample, 8421, null);
    envlite_assert(strpos($out, "'wordpress'") !== false, 'DB_NAME substituted');
    envlite_assert(strpos($out, 'database_name_here') === false);
    envlite_assert(strpos($out, 'username_here') === false);
    envlite_assert(strpos($out, 'password_here') === false);
}

function test_phase7_render_injects_wp_home_siteurl_before_marker() {
    $sample = file_get_contents(dirname(__DIR__, 3) . '/wp-config-sample.php');
    $out = envlite_phase7_render($sample, 8421, null);
    $home = "define( 'WP_HOME',    'http://127.0.0.1:8421' );";
    $site = "define( 'WP_SITEURL', 'http://127.0.0.1:8421' );";
    $marker = "/* That's all, stop editing! Happy publishing. */";
    envlite_assert(strpos($out, $home) !== false);
    envlite_assert(strpos($out, $site) !== false);
    // injection must precede the marker
    envlite_assert(strpos($out, $home) < strpos($out, $marker), 'WP_HOME must be before marker');
    envlite_assert(strpos($out, $site) < strpos($out, $marker), 'WP_SITEURL must be before marker');
}

function test_phase7_render_replaces_salts_when_provided() {
    $sample = file_get_contents(dirname(__DIR__, 3) . '/wp-config-sample.php');
    $salts = "define( 'AUTH_KEY', 'XYZ' );\n"
           . "define( 'SECURE_AUTH_KEY', 'XYZ' );\n"
           . "define( 'LOGGED_IN_KEY', 'XYZ' );\n"
           . "define( 'NONCE_KEY', 'XYZ' );\n"
           . "define( 'AUTH_SALT', 'XYZ' );\n"
           . "define( 'SECURE_AUTH_SALT', 'XYZ' );\n"
           . "define( 'LOGGED_IN_SALT', 'XYZ' );\n"
           . "define( 'NONCE_SALT', 'XYZ' );";
    $out = envlite_phase7_render($sample, 8421, $salts);
    envlite_assert(strpos($out, "'put your unique phrase here'") === false);
    envlite_assert(substr_count($out, "'XYZ'") === 8);
}

function test_phase7_render_keeps_sample_salts_when_null_provided() {
    $sample = file_get_contents(dirname(__DIR__, 3) . '/wp-config-sample.php');
    $out = envlite_phase7_render($sample, 8421, null);
    // 8 sample salt lines remain unchanged
    envlite_assert_eq(8, substr_count($out, "'put your unique phrase here'"));
}

function test_phase7_render_treats_salts_as_literal_not_backreferences() {
    // The salts API returns random bytes; some include `$` and `\` which
    // preg_replace's replacement argument would interpret as backreferences.
    // The render path must insert the salts as a literal block.
    $sample = file_get_contents(dirname(__DIR__, 3) . '/wp-config-sample.php');
    $literalBlock =
          "define( 'AUTH_KEY', 'a\$1b\\\\1c\$&d' );\n"
        . "define( 'SECURE_AUTH_KEY', 'X' );\n"
        . "define( 'LOGGED_IN_KEY', 'X' );\n"
        . "define( 'NONCE_KEY', 'X' );\n"
        . "define( 'AUTH_SALT', 'X' );\n"
        . "define( 'SECURE_AUTH_SALT', 'X' );\n"
        . "define( 'LOGGED_IN_SALT', 'X' );\n"
        . "define( 'NONCE_SALT', 'X' );";
    $out = envlite_phase7_render($sample, 8421, $literalBlock);
    envlite_assert(
        strpos($out, "define( 'AUTH_KEY', 'a\$1b\\\\1c\$&d' );") !== false,
        'metacharacters in salts must survive verbatim'
    );
}

function test_phase7_render_normalizes_crlf_in_sample() {
    // wp-config-sample.php ships CRLF in tree on a normal checkout. The
    // render path must normalize so the output is LF-only and the recorded
    // hash is stable across git EOL settings.
    $sample = file_get_contents(dirname(__DIR__, 3) . '/wp-config-sample.php');
    $out = envlite_phase7_render($sample, 8421, null);
    envlite_assert(strpos($out, "\r\n") === false, 'rendered config must be LF-only');
}

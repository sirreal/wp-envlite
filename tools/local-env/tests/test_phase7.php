<?php
function test_phase7_render_substitutes_db_constants() {
    $sample = file_get_contents(dirname(__DIR__, 3) . '/wp-config-sample.php');
    $out = envlite_phase7_render($sample, 8421, null);
    envlite_assert(str_contains($out, "'wordpress'"), 'DB_NAME substituted');
    envlite_assert(!str_contains($out, 'database_name_here'));
    envlite_assert(!str_contains($out, 'username_here'));
    envlite_assert(!str_contains($out, 'password_here'));
}

function test_phase7_render_injects_wp_home_siteurl_before_marker() {
    $sample = file_get_contents(dirname(__DIR__, 3) . '/wp-config-sample.php');
    $out = envlite_phase7_render($sample, 8421, null);
    $home = "define( 'WP_HOME',    'http://127.0.0.1:8421' );";
    $site = "define( 'WP_SITEURL', 'http://127.0.0.1:8421' );";
    $marker = "/* That's all, stop editing! Happy publishing. */";
    envlite_assert(str_contains($out, $home));
    envlite_assert(str_contains($out, $site));
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
    envlite_assert(!str_contains($out, "'put your unique phrase here'"));
    envlite_assert(substr_count($out, "'XYZ'") === 8);
}

function test_phase7_render_keeps_sample_salts_when_null_provided() {
    $sample = file_get_contents(dirname(__DIR__, 3) . '/wp-config-sample.php');
    $out = envlite_phase7_render($sample, 8421, null);
    // 8 sample salt lines remain unchanged
    envlite_assert_eq(8, substr_count($out, "'put your unique phrase here'"));
}

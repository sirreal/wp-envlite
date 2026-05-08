<?php
function test_phase6_render_substitutes_placeholders() {
    $sample = "define( 'DB_NAME', 'youremptytestdbnamehere' );\n"
            . "define( 'DB_USER', 'yourusernamehere' );\n"
            . "define( 'DB_PASSWORD', 'yourpasswordhere' );\n";
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

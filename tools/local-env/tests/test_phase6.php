<?php
function test_phase6_render_substitutes_placeholders() {
    $sample = "define( 'DB_NAME', 'youremptytestdbnamehere' );\n"
            . "define( 'DB_USER', 'yourusernamehere' );\n"
            . "define( 'DB_PASSWORD', 'yourpasswordhere' );\n";
    $out = envlite_phase6_render($sample);
    envlite_assert(str_contains($out, "'wordpress_test'"));
    envlite_assert(str_contains($out, "'wp'"));
    envlite_assert(!str_contains($out, 'youremptytestdbnamehere'));
    envlite_assert(!str_contains($out, 'yourusernamehere'));
    envlite_assert(!str_contains($out, 'yourpasswordhere'));
}

function test_phase6_render_throws_when_placeholder_missing() {
    try {
        envlite_phase6_render("define( 'DB_NAME', 'someothername' );");
        throw new \RuntimeException('expected exception');
    } catch (\RuntimeException $e) {
        envlite_assert(str_contains($e->getMessage(), 'placeholder'));
    }
}

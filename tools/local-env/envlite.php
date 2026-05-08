<?php
const ENVLITE_VERSION = '0.1.0';

function envlite_help_text(): string {
    return "envlite — wordpress-develop dev environment setup\n"
         . "\n"
         . "Usage:\n"
         . "  php tools/local-env/envlite.php <subcommand> [args]\n"
         . "\n"
         . "Subcommands:\n"
         . "  init [--port=N] [--no-build]   Run all setup phases.\n"
         . "  serve                          Run the dev server on the cached port.\n"
         . "  clean                          Remove envlite-managed files.\n"
         . "  help                           Print this help.\n"
         . "\n"
         . "Global flags:\n"
         . "  --force                        Disable interactive prompts.\n";
}

function envlite_main(array $argv): int {
    array_shift($argv); // drop script name
    $force = false;
    $rest = [];
    foreach ($argv as $a) {
        if ($a === '--force') { $force = true; continue; }
        $rest[] = $a;
    }
    $sub = $rest[0] ?? 'help';
    $args = array_slice($rest, 1);

    if ($sub === 'help' || $sub === '--help' || $sub === '-h') {
        fwrite(STDERR, envlite_help_text());
        return 0;
    }
    if ($sub === 'init')  { return envlite_cmd_init($args, $force); }
    if ($sub === 'serve') { return envlite_cmd_serve($args, $force); }
    if ($sub === 'clean') { return envlite_cmd_clean($args, $force); }

    fwrite(STDERR, "envlite: unknown subcommand: $sub\n");
    return 2;
}

function envlite_cmd_init(array $args, bool $force): int {
    fwrite(STDERR, "envlite init: not implemented\n");
    return 1;
}

function envlite_cmd_serve(array $args, bool $force): int {
    fwrite(STDERR, "envlite serve: not implemented\n");
    return 1;
}

function envlite_cmd_clean(array $args, bool $force): int {
    fwrite(STDERR, "envlite clean: not implemented\n");
    return 1;
}

if (!defined('ENVLITE_NO_AUTORUN') && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    exit(envlite_main($_SERVER['argv']));
}

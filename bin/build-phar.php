<?php
/**
 * Builds envlite into a single-file phar.
 *
 * Usage:
 *   php -d phar.readonly=0 bin/build-phar.php [output-path]
 *
 * Default output: envlite.phar at the repository root. Phar creation needs
 * phar.readonly to be off; this script checks and aborts with the correct
 * invocation if it is on.
 */

if (filter_var(ini_get('phar.readonly'), FILTER_VALIDATE_BOOLEAN)) {
    fwrite(STDERR, "phar.readonly is enabled; phar creation is blocked.\n");
    fwrite(STDERR, "Rerun with: php -d phar.readonly=0 {$argv[0]}\n");
    exit(1);
}

$repoRoot = dirname(__DIR__);
$output   = $argv[1] ?? $repoRoot . '/envlite.phar';

$source = $repoRoot . '/envlite.php';
$router = $repoRoot . '/router.php';
foreach ([$source, $router] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "missing source file: $file\n");
        exit(1);
    }
}

// envlite.php carries an executable `#!` shebang on line 1. Inside the phar
// it is require'd, so a leading shebang would be emitted as text on every
// run; strip it before packing.
$sourceCode = file_get_contents($source);
if (strncmp($sourceCode, '#!', 2) === 0) {
    $newline    = strpos($sourceCode, "\n");
    $sourceCode = $newline === false ? '' : substr($sourceCode, $newline + 1);
}

// Context-aware stub. Invoked as the CLI tool it runs envlite_main(); invoked
// by `php -S` as the router (cli-server SAPI) it dispatches to router.php.
// Phar::mapPhar registers the `envlite.phar` alias so the archive resolves its
// own files regardless of the on-disk filename — the phar works when installed
// as a bare `envlite`. envlite.php's autorun guard does not fire inside a phar
// (realpath() of a phar:// path is false), so envlite_main is called here.
$stub = <<<'STUB'
#!/usr/bin/env php
<?php
if (!defined('ENVLITE_PHAR_MAPPED')) { Phar::mapPhar('envlite.phar'); define('ENVLITE_PHAR_MAPPED', 1); }
if (PHP_SAPI === 'cli-server') {
    return require 'phar://envlite.phar/router.php';
}
define('ENVLITE_NO_AUTORUN', true);
require 'phar://envlite.phar/envlite.php';
exit(envlite_main($_SERVER['argv']));
__HALT_COMPILER();
STUB;

@unlink($output);
$phar = new Phar($output);
$phar->startBuffering();
$phar->addFromString('envlite.php', $sourceCode);
$phar->addFile($router, 'router.php');
$phar->setStub($stub);
$phar->setSignatureAlgorithm(Phar::SHA256);
$phar->stopBuffering();

@chmod($output, 0755);

printf("built %s (%s bytes)\n", $output, number_format(filesize($output)));

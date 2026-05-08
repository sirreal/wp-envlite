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

function envlite_format_log(?string $subcommand, string $message): string {
    $prefix = $subcommand === null ? 'envlite' : "envlite $subcommand";
    $message = rtrim($message, "\n");
    return "$prefix: $message\n";
}

function envlite_log(?string $subcommand, string $message): void {
    fwrite(STDERR, envlite_format_log($subcommand, $message));
}

function envlite_path_to_posix(string $path): string {
    return str_replace('\\', '/', $path);
}

function envlite_path_relative_to(string $root, string $abs): string {
    $root = rtrim(envlite_path_to_posix($root), '/');
    $abs = envlite_path_to_posix($abs);
    if ($abs === $root) { return ''; }
    if (str_starts_with($abs, $root . '/')) {
        return substr($abs, strlen($root) + 1);
    }
    throw new \InvalidArgumentException("path outside repo root: $abs");
}

function envlite_manifest_path(string $repoRoot): string {
    return rtrim(envlite_path_to_posix($repoRoot), '/') . '/.envlite/manifest';
}

function envlite_manifest_load(string $repoRoot): array {
    $path = envlite_manifest_path($repoRoot);
    if (!is_file($path)) { return []; }
    $entries = [];
    foreach (explode("\n", file_get_contents($path)) as $line) {
        $line = rtrim($line, "\r");
        if ($line === '') { continue; }
        // Two-space delimiter. Hash field is exactly 64 hex chars or the literal "dir".
        if (!preg_match('/^([0-9a-f]{64}|dir)  (.+)$/', $line, $m)) {
            continue; // malformed, skip
        }
        $entries[$m[2]] = $m[1];
    }
    return $entries;
}

function envlite_manifest_save(string $repoRoot, array $entries): void {
    $lines = '';
    foreach ($entries as $path => $hash) {
        $lines .= "$hash  $path\n";
    }
    $manifestPath = envlite_manifest_path($repoRoot);
    $dir = dirname($manifestPath);
    if (!is_dir($dir)) { mkdir($dir, 0700, true); }
    envlite_atomic_write($manifestPath, $lines);
}

function envlite_atomic_write(string $path, string $bytes): string {
    $dir = dirname($path);
    if (!is_dir($dir)) { mkdir($dir, 0755, true); }
    $hash = hash('sha256', $bytes);
    $tmp = $path . '.tmp';
    $fh = fopen($tmp, 'wb');
    if ($fh === false) { throw new \RuntimeException("cannot open $tmp"); }
    if (fwrite($fh, $bytes) !== strlen($bytes)) {
        fclose($fh); @unlink($tmp);
        throw new \RuntimeException("short write to $tmp");
    }
    // fsync for crash-durability before rename. Available since PHP 8.1; on
    // older PHPs we settle for fflush, which is the best we can do without
    // pulling in extensions.
    fflush($fh);
    if (function_exists('fsync')) { @fsync($fh); }
    fclose($fh);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new \RuntimeException("rename failed: $tmp -> $path");
    }
    return $hash;
}

/**
 * @param array<string,string> $manifest path => sha256-hex|"dir"
 * @param string|null $currentBytes Null if the file/dir does not exist on disk
 *                                  or is a directory entry whose contents we don't drift-check.
 * @return 'absent'|'owned_clean'|'owned_drifted'|'unowned'
 */
function envlite_ownership(array $manifest, string $relPath, ?string $currentBytes): string {
    $recorded = $manifest[$relPath] ?? null;
    if ($currentBytes === null && $recorded === null) { return 'absent'; }
    if ($recorded === null) { return 'unowned'; }
    if ($recorded === 'dir') { return 'owned_clean'; }
    if ($currentBytes === null) {
        // Recorded as file but currentBytes wasn't provided — caller missed reading it.
        // Treat as drifted; safer to prompt.
        return 'owned_drifted';
    }
    return hash('sha256', $currentBytes) === $recorded ? 'owned_clean' : 'owned_drifted';
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

    envlite_log(null, "unknown subcommand: $sub");
    return 2;
}

function envlite_cmd_init(array $args, bool $force): int {
    envlite_log('init', 'not implemented');
    return 1;
}

function envlite_cmd_serve(array $args, bool $force): int {
    envlite_log('serve', 'not implemented');
    return 1;
}

function envlite_cmd_clean(array $args, bool $force): int {
    envlite_log('clean', 'not implemented');
    return 1;
}

if (!defined('ENVLITE_NO_AUTORUN') && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    exit(envlite_main($_SERVER['argv']));
}

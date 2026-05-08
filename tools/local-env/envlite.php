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

function envlite_format_prompt(
    string $subcommand,
    string $operation, // unused for now; kept so future ops can specialize wording
    string $relPath,
    ?string $recordedHash,
    ?string $currentHash
): string {
    if ($recordedHash !== null && $currentHash !== null) {
        $rec = substr($recordedHash, 0, 8);
        $cur = substr($currentHash, 0, 8);
        $body = "envlite owns $relPath but content has drifted (recorded {$rec}\u{2026}, current {$cur}\u{2026}). Overwrite?";
    } else {
        $body = "not envlite-owned: $relPath. Overwrite?";
    }
    return "envlite $subcommand: $body [y/N] ";
}

/**
 * Pure-IO variant for testability. Production code calls envlite_prompt() below.
 */
function envlite_prompt_io(
    bool $force,
    bool $isTty,
    string $subcommand,
    string $operation,
    string $relPath,
    ?string $recordedHash,
    ?string $currentHash,
    $stdin,
    $stderr
): bool {
    if ($force) { return true; }
    if (!$isTty) {
        fwrite($stderr, envlite_format_log(
            null,
            "non-interactive context and --force not given; aborting at $operation on $relPath"
        ));
        return false;
    }
    fwrite($stderr, envlite_format_prompt($subcommand, $operation, $relPath, $recordedHash, $currentHash));
    $line = fgets($stdin);
    if ($line === false) { return false; }
    $resp = strtolower(trim($line));
    return $resp === 'y' || $resp === 'yes';
}

/**
 * Production wrapper. Returns true=overwrite, false=skip. On non-interactive
 * abort the caller must exit 5 — see callers.
 */
function envlite_prompt(
    bool $force,
    string $subcommand,
    string $operation,
    string $relPath,
    ?string $recordedHash,
    ?string $currentHash
): bool {
    return envlite_prompt_io(
        $force,
        stream_isatty(STDIN),
        $subcommand,
        $operation,
        $relPath,
        $recordedHash,
        $currentHash,
        STDIN,
        STDERR
    );
}

/**
 * Convenience: returns true if the caller should proceed with the write.
 * On non-interactive abort, exits 5 directly (matches spec).
 */
function envlite_prompt_or_abort(
    bool $force,
    string $subcommand,
    string $operation,
    string $relPath,
    ?string $recordedHash,
    ?string $currentHash
): bool {
    if ($force) { return true; }
    if (!stream_isatty(STDIN)) {
        envlite_log(null, "non-interactive context and --force not given; aborting at $operation on $relPath");
        exit(5);
    }
    $ok = envlite_prompt($force, $subcommand, $operation, $relPath, $recordedHash, $currentHash);
    if (!$ok) { exit(5); }
    return true;
}

const ENVLITE_REPO_MARKERS = [
    'package.json',
    'composer.json',
    'wp-config-sample.php',
    'wp-tests-config-sample.php',
    'src/wp-includes',
    'tests/phpunit/includes/bootstrap.php',
];

function envlite_phase0_is_wordpress_develop(string $root): bool {
    foreach (ENVLITE_REPO_MARKERS as $m) {
        if (!file_exists($root . '/' . $m)) { return false; }
    }
    return true;
}

/** Extracts [major, minor, patch] from any string containing a `\d+\.\d+\.\d+` substring. */
function envlite_phase0_parse_version(string $output): array {
    if (!preg_match('/(\d+)\.(\d+)\.(\d+)/', $output, $m)) {
        throw new \RuntimeException("could not parse version from: " . trim($output));
    }
    return [(int)$m[1], (int)$m[2], (int)$m[3]];
}

function envlite_phase0_version_ge(array $a, array $b): bool {
    for ($i = 0; $i < 3; $i++) {
        if ($a[$i] > $b[$i]) { return true; }
        if ($a[$i] < $b[$i]) { return false; }
    }
    return true;
}

/**
 * Returns null on missing tool (proc_open failure / nonzero exit / unparseable
 * output). Returns [major, minor, patch] otherwise. The version flag arg
 * accommodates `--version` (npm/composer) and `-v` if a future tool prefers it.
 */
function envlite_phase0_tool_version(array $cmd): ?array {
    $proc = @proc_open(
        $cmd,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($proc)) { return null; }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($proc);
    if ($exit !== 0) { return null; }
    try {
        return envlite_phase0_parse_version($out !== '' ? $out : $err);
    } catch (\Throwable $e) {
        return null;
    }
}

/** Runs all preflight checks. Calls envlite_log and exits 3 on first failure. */
function envlite_phase0_run(string $repoRoot): void {
    if (!envlite_phase0_is_wordpress_develop($repoRoot)) {
        envlite_log(null, "preflight: $repoRoot is not a wordpress-develop checkout");
        exit(3);
    }
    if (PHP_VERSION_ID < 70400) {
        envlite_log(null, 'preflight: PHP ' . PHP_VERSION . ' is below the 7.4 floor');
        exit(3);
    }
    foreach (['pdo_sqlite', 'sqlite3', 'openssl', 'simplexml', 'zip'] as $ext) {
        if (!extension_loaded($ext)) {
            envlite_log(null, "preflight: required PHP extension missing: $ext");
            exit(3);
        }
    }
    $tools = [
        ['node',     ['node', '--version'],     [20, 10, 0]],
        ['npm',      ['npm', '--version'],      [10, 2, 0]],
        ['composer', ['composer', '--version'], [2, 0, 0]],
    ];
    foreach ($tools as [$name, $cmd, $min]) {
        $ver = envlite_phase0_tool_version($cmd);
        if ($ver === null) {
            envlite_log(null, "preflight: $name not found or did not report a version");
            exit(3);
        }
        if (!envlite_phase0_version_ge($ver, $min)) {
            $vstr = implode('.', $ver);
            $mstr = implode('.', $min);
            envlite_log(null, "preflight: $name $vstr is below the $mstr minimum");
            exit(3);
        }
    }
}

const ENVLITE_PORT_LOW = 8100;
const ENVLITE_PORT_POOL_SIZE = 800;

function envlite_phase1_seed_port(string $absPath): int {
    // hash('crc32b') is unsigned and 8 hex chars; substr(-7) is 28 bits, fits in PHP int even on 32-bit.
    $digest = hash('crc32b', $absPath);
    $seed = hexdec(substr($digest, -7));
    return ENVLITE_PORT_LOW + ($seed % ENVLITE_PORT_POOL_SIZE);
}

function envlite_phase1_port_is_free(int $port): bool {
    $sock = @stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
    if (!is_resource($sock)) { return false; }
    fclose($sock);
    return true;
}

function envlite_phase1_discover_port(string $repoRoot, ?int $explicitPort): int {
    $cachePath = rtrim(envlite_path_to_posix($repoRoot), '/') . '/.envlite/port';

    if ($explicitPort !== null) {
        if (!envlite_phase1_port_is_free($explicitPort)) {
            envlite_log('init', "phase 1: port $explicitPort is in use; try a different --port (e.g. lsof -nP -iTCP:$explicitPort -sTCP:LISTEN)");
            exit(1);
        }
        envlite_phase1_write_cache($repoRoot, $explicitPort);
        return $explicitPort;
    }

    if (is_file($cachePath)) {
        $cached = (int) trim(file_get_contents($cachePath));
        if ($cached >= ENVLITE_PORT_LOW && $cached <= ENVLITE_PORT_LOW + ENVLITE_PORT_POOL_SIZE - 1) {
            return $cached;
        }
        // out of range: fall through to re-pick
    }

    $start = envlite_phase1_seed_port(realpath($repoRoot) ?: $repoRoot);
    for ($i = 0; $i < ENVLITE_PORT_POOL_SIZE; $i++) {
        $cand = ENVLITE_PORT_LOW + ((($start - ENVLITE_PORT_LOW) + $i) % ENVLITE_PORT_POOL_SIZE);
        if (envlite_phase1_port_is_free($cand)) {
            envlite_phase1_write_cache($repoRoot, $cand);
            return $cand;
        }
    }
    envlite_log('init', 'phase 1: no free port in 8100-8899');
    exit(1);
}

function envlite_phase1_write_cache(string $repoRoot, int $port): void {
    $cachePath = rtrim(envlite_path_to_posix($repoRoot), '/') . '/.envlite/port';
    $hash = envlite_atomic_write($cachePath, "$port\n");
    $manifest = envlite_manifest_load($repoRoot);
    $manifest['.envlite/port'] = $hash;
    envlite_manifest_save($repoRoot, $manifest);
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

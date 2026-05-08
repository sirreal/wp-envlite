<?php
const ENVLITE_VERSION = '0.1.0';

function envlite_help_text(): string {
	return
		<<<'TEXT'
		envlite — wordpress-develop dev environment setup

		Usage:
		  php tools/local-env/envlite.php <subcommand> [args]

		Subcommands:
		  init [--port=N] [--no-build]   Run all setup phases.
		  serve                          Run the dev server on the cached port.
		  clean                          Remove envlite-managed files.
		  help                           Print this help.

		Global flags:
		  --force                        Disable interactive prompts.

		TEXT;
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
    $prefix = $root . '/';
    if (substr($abs, 0, strlen($prefix)) === $prefix) {
        return substr($abs, strlen($prefix));
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

/** Capture variant: returns [$exit, $stdout, $stderr]. Used by Phase 0. */
function envlite_proc_capture(array $cmd, ?string $cwd = null): array {
    $proc = @proc_open(
        $cmd,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd
    );
    if (!is_resource($proc)) { return [-1, '', '']; }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($proc);
    return [$exit, $stdout ?: '', $stderr ?: ''];
}

/** Streaming variant: child stdio inherits the parent's. Used by Phases 2/3/4 and `serve`. */
function envlite_proc_stream(array $cmd, ?string $cwd = null): int {
    $proc = @proc_open($cmd, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $cwd);
    if (!is_resource($proc)) { return -1; }
    return proc_close($proc);
}

/**
 * Returns null on missing tool (proc_open failure / nonzero exit / unparseable
 * output). Returns [major, minor, patch] otherwise. The version flag arg
 * accommodates `--version` (npm/composer) and `-v` if a future tool prefers it.
 */
function envlite_phase0_tool_version(array $cmd): ?array {
    [$exit, $stdout, $stderr] = envlite_proc_capture($cmd);
    if ($exit !== 0) { return null; }
    try {
        return envlite_phase0_parse_version($stdout !== '' ? $stdout : $stderr);
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
        ['npm',      ['npm', '--version'],      [10, 2, 3]],
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

function envlite_phase2_npm_ci(string $repoRoot): void {
    $exit = envlite_proc_stream(['npm', 'ci'], $repoRoot);
    if ($exit !== 0) {
        envlite_log('init', "phase 2: npm ci failed (exit $exit)");
        exit(1);
    }
}

function envlite_phase3_build_dev(string $repoRoot): void {
    $exit = envlite_proc_stream(['npm', 'run', 'build:dev'], $repoRoot);
    if ($exit !== 0) {
        envlite_log('init', "phase 3: npm run build:dev failed (exit $exit)");
        exit(1);
    }
}

function envlite_phase4_composer_install(string $repoRoot): void {
    $exit = envlite_proc_stream(
        ['composer', 'install', '--no-interaction', '--ignore-platform-req=ext-simplexml'],
        $repoRoot
    );
    if ($exit !== 0) {
        envlite_log('init', "phase 4: composer install failed (exit $exit)");
        exit(1);
    }
}

const ENVLITE_SQLITE_PLUGIN_URL = 'https://downloads.wordpress.org/plugin/sqlite-database-integration.zip';
const ENVLITE_SQLITE_PLUGIN_SHA256 = '44be096a14ebcea424b5e4bf764436ec85fb067f74ab47822c4c5346df21591e';
const ENVLITE_SQLITE_PLACEHOLDER = '{SQLITE_IMPLEMENTATION_FOLDER_PATH}';

function envlite_http_get(string $url, int $timeoutSeconds = 30): string {
    $ctx = stream_context_create([
        'http'  => [
            'follow_location' => 1,
            'max_redirects'   => 5,
            'timeout'         => $timeoutSeconds,
            'header'          => "User-Agent: envlite/" . ENVLITE_VERSION . "\r\n",
        ],
        'https' => [
            'follow_location' => 1,
            'max_redirects'   => 5,
            'timeout'         => $timeoutSeconds,
            'header'          => "User-Agent: envlite/" . ENVLITE_VERSION . "\r\n",
        ],
    ]);
    $bytes = @file_get_contents($url, false, $ctx);
    if ($bytes === false) {
        throw new \RuntimeException("HTTP fetch failed: $url");
    }
    return $bytes;
}

function envlite_phase5_verify_sha256(string $path, string $expected): void {
    $actual = hash_file('sha256', $path);
    if ($actual !== $expected) {
        throw new \RuntimeException("SHA256 mismatch on $path: expected $expected, got $actual");
    }
}

function envlite_phase5_assert_placeholder(string $dbCopyPath): void {
    $bytes = @file_get_contents($dbCopyPath);
    if ($bytes === false || strpos($bytes, ENVLITE_SQLITE_PLACEHOLDER) === false) {
        throw new \RuntimeException(
            "tripwire: " . ENVLITE_SQLITE_PLACEHOLDER . " placeholder missing from $dbCopyPath; spec assumption broken"
        );
    }
}

function envlite_phase5_install(string $repoRoot, bool $force): void {
    $pluginDir = "$repoRoot/src/wp-content/plugins/sqlite-database-integration";
    $dbCopy    = "$pluginDir/db.copy";
    $dbPhpRel  = 'src/wp-content/db.php';
    $pluginRel = 'src/wp-content/plugins/sqlite-database-integration';
    $manifest  = envlite_manifest_load($repoRoot);

    // Step 1: skip if already installed (manifest entry + db.copy on disk).
    $alreadyInstalled = isset($manifest[$pluginRel]) && $manifest[$pluginRel] === 'dir' && is_file($dbCopy);
    if (!$alreadyInstalled) {
        // Steps 2-4: prompt if dest exists and is not envlite-owned.
        if (is_dir($pluginDir) && !isset($manifest[$pluginRel])) {
            envlite_prompt_or_abort($force, 'init', 'overwrite plugin tree', $pluginRel, null, null);
        }
        $tmpZip = sys_get_temp_dir() . '/envlite-sqlite-' . bin2hex(random_bytes(4)) . '.zip';
        $bytes = envlite_http_get(ENVLITE_SQLITE_PLUGIN_URL);
        file_put_contents($tmpZip, $bytes);
        try {
            envlite_phase5_verify_sha256($tmpZip, ENVLITE_SQLITE_PLUGIN_SHA256);
            $zip = new \ZipArchive();
            if ($zip->open($tmpZip) !== true) {
                throw new \RuntimeException("ZipArchive::open failed: $tmpZip");
            }
            $zip->extractTo("$repoRoot/src/wp-content/plugins/");
            $zip->close();
        } finally {
            @unlink($tmpZip);
        }
        $manifest[$pluginRel] = 'dir';
        envlite_manifest_save($repoRoot, $manifest);
    }

    // Step 5: copy db.copy → db.php with manifest contract.
    if (!is_file($dbCopy)) {
        throw new \RuntimeException("phase 5: db.copy missing at $dbCopy after extraction");
    }
    $dbBytes = @file_get_contents($dbCopy);
    if ($dbBytes === false) {
        throw new \RuntimeException("phase 5: cannot read $dbCopy");
    }
    $dbPhpAbs = "$repoRoot/$dbPhpRel";
    $current = null;
    if (is_file($dbPhpAbs)) {
        $current = @file_get_contents($dbPhpAbs);
        if ($current === false) {
            throw new \RuntimeException("phase 5: cannot read $dbPhpAbs");
        }
    }
    $ownership = envlite_ownership($manifest, $dbPhpRel, $current);
    if ($ownership === 'owned_drifted') {
        $rec = $manifest[$dbPhpRel];
        $cur = $current !== null ? hash('sha256', $current) : null;
        envlite_prompt_or_abort($force, 'init', 'overwrite drifted file', $dbPhpRel, $rec, $cur);
    } elseif ($ownership === 'unowned') {
        envlite_prompt_or_abort($force, 'init', 'overwrite unowned file', $dbPhpRel, null, null);
    }
    $hash = envlite_atomic_write($dbPhpAbs, $dbBytes);
    $manifest[$dbPhpRel] = $hash;
    envlite_manifest_save($repoRoot, $manifest);

    // Step 6: tripwire.
    envlite_phase5_assert_placeholder($dbCopy);
}

function envlite_phase6_render(string $sample): string {
    $replacements = [
        'youremptytestdbnamehere' => 'wordpress_test',
        'yourusernamehere'        => 'wp',
        'yourpasswordhere'        => 'wp',
    ];
    foreach ($replacements as $placeholder => $value) {
        if (substr_count($sample, $placeholder) !== 1) {
            throw new \RuntimeException("phase 6: placeholder '$placeholder' must appear exactly once");
        }
    }
    $out = strtr($sample, $replacements);
    foreach (array_keys($replacements) as $placeholder) {
        if (strpos($out, $placeholder) !== false) {
            throw new \RuntimeException("phase 6: placeholder '$placeholder' still present after substitution");
        }
    }
    return $out;
}

function envlite_phase6_install(string $repoRoot, bool $force): void {
    $samplePath = "$repoRoot/wp-tests-config-sample.php";
    $outRel = 'wp-tests-config.php';
    $outAbs = "$repoRoot/$outRel";

    $sample = @file_get_contents($samplePath);
    if ($sample === false) {
        throw new \RuntimeException("phase 6: cannot read $samplePath");
    }
    $rendered = envlite_phase6_render($sample);

    $manifest = envlite_manifest_load($repoRoot);
    $current  = null;
    if (is_file($outAbs)) {
        $current = @file_get_contents($outAbs);
        if ($current === false) {
            throw new \RuntimeException("phase 6: cannot read $outAbs");
        }
    }
    $ownership = envlite_ownership($manifest, $outRel, $current);
    if ($ownership === 'owned_drifted') {
        $cur = $current !== null ? hash('sha256', $current) : null;
        envlite_prompt_or_abort($force, 'init', 'overwrite drifted file', $outRel, $manifest[$outRel], $cur);
    } elseif ($ownership === 'unowned') {
        envlite_prompt_or_abort($force, 'init', 'overwrite unowned file', $outRel, null, null);
    }
    $hash = envlite_atomic_write($outAbs, $rendered);
    $manifest[$outRel] = $hash;
    envlite_manifest_save($repoRoot, $manifest);
}

const ENVLITE_SALT_URL = 'https://api.wordpress.org/secret-key/1.1/salt/';

function envlite_phase7_render(string $sample, int $port, ?string $saltsBlock): string {
    // 1. DB constants — exactly one of each in the sample.
    $dbReplacements = [
        'database_name_here' => 'wordpress',
        'username_here'      => 'wp',
        'password_here'      => 'wp',
    ];
    foreach ($dbReplacements as $placeholder => $value) {
        if (substr_count($sample, $placeholder) !== 1) {
            throw new \RuntimeException("phase 7: placeholder '$placeholder' must appear exactly once");
        }
    }
    $cfg = strtr($sample, $dbReplacements);

    // 2. Salt block: AUTH_KEY through NONCE_SALT, 8 contiguous define()s.
    if ($saltsBlock !== null) {
        $pattern = '/define\(\s*\'AUTH_KEY\'.*?define\(\s*\'NONCE_SALT\'\s*,\s*\'[^\']*\'\s*\);/s';
        $count = preg_match_all($pattern, $cfg, $m);
        if ($count !== 1) {
            throw new \RuntimeException("phase 7: expected exactly one salt block, found $count");
        }
        $cfg = preg_replace($pattern, $saltsBlock, $cfg, 1);
    }

    // 3. Inject WP_HOME / WP_SITEURL before the marker.
    $marker = "/* That's all, stop editing! Happy publishing. */";
    if (substr_count($cfg, $marker) !== 1) {
        throw new \RuntimeException("phase 7: expected exactly one marker line");
    }
    $inject = "define( 'WP_HOME',    'http://127.0.0.1:$port' );\n"
            . "define( 'WP_SITEURL', 'http://127.0.0.1:$port' );\n\n";
    $pos = strpos($cfg, $marker);
    return substr($cfg, 0, $pos) . $inject . substr($cfg, $pos);
}

function envlite_phase7_fetch_salts(): ?string {
    try {
        $bytes = envlite_http_get(ENVLITE_SALT_URL, 5);
        // Sanity: must contain 8 define() lines and the keys we care about.
        if (substr_count($bytes, "define(") < 8 || strpos($bytes, 'NONCE_SALT') === false) {
            return null;
        }
        return rtrim($bytes, "\n");
    } catch (\Throwable $e) {
        envlite_log('init', "phase 7: salt fetch failed: " . $e->getMessage() . " (continuing with sample placeholders)");
        return null;
    }
}

function envlite_phase7_install(string $repoRoot, int $port, bool $force): void {
    $samplePath = "$repoRoot/wp-config-sample.php";
    $outRel = 'src/wp-config.php';
    $outAbs = "$repoRoot/$outRel";

    $sample = @file_get_contents($samplePath);
    if ($sample === false) {
        throw new \RuntimeException("phase 7: cannot read $samplePath");
    }
    $salts  = envlite_phase7_fetch_salts();
    $rendered = envlite_phase7_render($sample, $port, $salts);

    $manifest = envlite_manifest_load($repoRoot);
    $current  = null;
    if (is_file($outAbs)) {
        $current = @file_get_contents($outAbs);
        if ($current === false) {
            throw new \RuntimeException("phase 7: cannot read $outAbs");
        }
    }
    $ownership = envlite_ownership($manifest, $outRel, $current);
    if ($ownership === 'owned_drifted') {
        $cur = $current !== null ? hash('sha256', $current) : null;
        envlite_prompt_or_abort($force, 'init', 'overwrite drifted file', $outRel, $manifest[$outRel], $cur);
    } elseif ($ownership === 'unowned') {
        envlite_prompt_or_abort($force, 'init', 'overwrite unowned file', $outRel, null, null);
    }
    $hash = envlite_atomic_write($outAbs, $rendered);
    $manifest[$outRel] = $hash;
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
    $port = null;
    $noBuild = false;
    foreach ($args as $a) {
        if ($a === '--no-build') { $noBuild = true; continue; }
        if (preg_match('/^--port=(\d+)$/', $a, $m)) {
            $port = (int) $m[1];
            if ($port < 1 || $port > 65535) {
                envlite_log('init', "invalid --port value: $a");
                return 2;
            }
            continue;
        }
        envlite_log('init', "unknown argument: $a");
        return 2;
    }

    $repoRoot = getcwd();

    // Phase 0
    envlite_phase0_run($repoRoot);

    // Observation point: record .ht.sqlite if present and not in manifest.
    envlite_observe_ht_sqlite($repoRoot);

    // Phase 1
    $resolvedPort = envlite_phase1_discover_port($repoRoot, $port);
    fwrite(STDERR, "envlite init: port $resolvedPort\n");

    // Phase 2: npm ci
    envlite_phase2_npm_ci($repoRoot);

    // Phase 3: build:dev (skippable)
    if (!$noBuild) {
        envlite_phase3_build_dev($repoRoot);
    }

    // Phase 4: composer install
    envlite_phase4_composer_install($repoRoot);

    // Phases 5-7 throw RuntimeException for diagnostic failures (e.g.,
    // SHA256 mismatch, missing placeholders, I/O errors). Convert each into
    // the spec's `envlite init: phase N: <cause>` line + exit 1.
    $phases = [
        [5, function () use ($repoRoot, $force) { envlite_phase5_install($repoRoot, $force); }],
        [6, function () use ($repoRoot, $force) { envlite_phase6_install($repoRoot, $force); }],
        [7, function () use ($repoRoot, $resolvedPort, $force) { envlite_phase7_install($repoRoot, $resolvedPort, $force); }],
    ];
    foreach ($phases as [$n, $fn]) {
        $rc = envlite_init_phase_guard($n, $fn);
        if ($rc !== 0) { return $rc; }
    }

    fwrite(STDERR, "envlite init: ok (port $resolvedPort)\n");
    return 0;
}

function envlite_init_phase_guard(int $n, callable $fn): int {
    try {
        $fn();
        return 0;
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        $prefix = "phase $n: ";
        if (strpos($msg, $prefix) !== 0) {
            $msg = $prefix . $msg;
        }
        envlite_log('init', $msg);
        return 1;
    }
}

function envlite_observe_ht_sqlite(string $repoRoot): void {
    $rel = 'src/wp-content/database/.ht.sqlite';
    $abs = "$repoRoot/$rel";
    if (!is_file($abs)) { return; }
    $manifest = envlite_manifest_load($repoRoot);
    if (isset($manifest[$rel])) { return; }
    $bytes = @file_get_contents($abs);
    // Read failure: leave the file unrecorded rather than capturing the
    // empty-string hash. clean will treat it as user-owned, which is correct.
    if ($bytes === false) { return; }
    $manifest[$rel] = hash('sha256', $bytes);
    envlite_manifest_save($repoRoot, $manifest);
}

function envlite_cmd_serve(array $args, bool $force): int {
    if (!empty($args)) {
        envlite_log('serve', 'unexpected arguments: ' . implode(' ', $args));
        return 2;
    }

    $repoRoot = getcwd();
    if (!envlite_phase0_is_wordpress_develop($repoRoot)) {
        envlite_log('serve', 'not a wordpress-develop checkout');
        return 3;
    }

    $cachePath = "$repoRoot/.envlite/port";
    if (!is_file($cachePath)) {
        envlite_log('serve', 'no cached port; run `envlite init` first');
        return 1;
    }
    $port = (int) trim(file_get_contents($cachePath));
    if ($port < 1 || $port > 65535) {
        envlite_log('serve', "cached port out of range: $port");
        return 1;
    }

    if (!envlite_phase1_port_is_free($port)) {
        envlite_log('serve', "failed to bind 127.0.0.1:$port");
        return 1;
    }

    // Stream the dev server. SIGINT propagates to the child via terminal.
    $exit = envlite_proc_stream(
        ['php', '-S', "127.0.0.1:$port", '-t', 'src', __DIR__ . '/router.php'],
        $repoRoot
    );
    return $exit === 0 ? 0 : 1;
}

function envlite_cmd_clean(array $args, bool $force): int {
    if (!empty($args)) {
        envlite_log('clean', 'unexpected arguments: ' . implode(' ', $args));
        return 2;
    }
    $repoRoot = getcwd();
    if (!is_dir("$repoRoot/.envlite")) {
        envlite_log('clean', 'nothing to clean (no .envlite/ directory)');
        return 0;
    }

    envlite_observe_ht_sqlite($repoRoot);
    $manifest = envlite_manifest_load($repoRoot);
    $paths = envlite_clean_collect($manifest);

    if (empty($paths)) {
        envlite_log('clean', 'manifest is empty; removing .envlite/ only');
    } else {
        // Single batch prompt.
        if (!$force) {
            if (!stream_isatty(STDIN)) {
                envlite_log(null, 'non-interactive context and --force not given; aborting at clean');
                return 5;
            }
            fwrite(STDERR, "envlite clean: will remove " . count($paths) . " path(s):\n");
            foreach ($paths as $p) { fwrite(STDERR, "  $p\n"); }
            fwrite(STDERR, "envlite clean: continue? [y/N] ");
            $line = fgets(STDIN);
            $resp = $line === false ? '' : strtolower(trim($line));
            if ($resp !== 'y' && $resp !== 'yes') {
                envlite_log('clean', 'aborted by user');
                return 5;
            }
        }
        envlite_clean_apply($repoRoot, $paths);
    }

    // Remove .envlite/ itself.
    @unlink("$repoRoot/.envlite/manifest");
    @unlink("$repoRoot/.envlite/port");
    @rmdir("$repoRoot/.envlite");
    return 0;
}

/** Pure: returns paths in reverse insertion order. */
function envlite_clean_collect(array $manifest): array {
    return array_reverse(array_keys($manifest));
}

/** I/O: deletes each path. Must be called after the prompt has been resolved. */
function envlite_clean_apply(string $repoRoot, array $paths): void {
    foreach ($paths as $rel) {
        $abs = "$repoRoot/$rel";
        if (!file_exists($abs) && !is_dir($abs)) { continue; }
        if (is_dir($abs) && !is_link($abs)) {
            envlite_rrmdir($abs);
        } else {
            @unlink($abs);
        }
    }
}

function envlite_rrmdir(string $dir): void {
    $items = scandir($dir);
    if ($items === false) { return; }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') { continue; }
        $path = "$dir/$item";
        if (is_dir($path) && !is_link($path)) {
            envlite_rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

if (!defined('ENVLITE_NO_AUTORUN') && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    exit(envlite_main($_SERVER['argv']));
}

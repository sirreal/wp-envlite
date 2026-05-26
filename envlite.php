#!/usr/bin/env php
<?php
const ENVLITE_VERSION = '0.1.0';

function envlite_help_text(): string {
	return
		<<<'TEXT'
		envlite — wordpress-develop dev environment setup

		Usage:
		  envlite <subcommand> [args]

		Commands:
		  up [--port=N] [--no-build] [--no-serve] [--rebuild]
		      Set up the checkout and start the dev server. Installs JS/PHP
		      deps in parallel, builds, writes config, installs the SQLite
		      drop-in, runs wp_install() if needed, then launches `php -S`.
		      Re-runs are cheap — unchanged phases are skipped. After install,
		      sign in at /wp-login.php with admin / password.

		  clean
		      Remove every file envlite created. Prompts before deleting;
		      --force skips prompts. Does not touch node_modules/, vendor/,
		      or build outputs.

		  help, --help, -h
		      Print this help.

		  --version, -V
		      Print the version and exit.

		Flags:
		  --port=N      Port for the dev server. Default: derived from the
		                checkout path, cached at .cache/envlite/port.
		  --no-build    Skip `npm run build:dev` even if inputs changed.
		  --no-serve    Run setup phases only; don't launch the server.
		  --rebuild     Re-run every setup phase, ignoring cached state.
		  --force       Answer 'y' to every prompt. Required in CI.

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
    // Only Windows mixes `\` and `/` as path separators. On Unix, `\` is
    // a legal filename character — rewriting it would corrupt the
    // computed state-directory and manifest-key paths for any checkout
    // sitting at `/tmp/wp\dev` and similar. Phases would then write to
    // the actual cwd while envlite's `.cache/envlite/...` paths landed
    // somewhere else, and `clean` invoked from the real path could not
    // find the state it had created.
    if (PHP_OS_FAMILY !== 'Windows') { return $path; }
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
    return rtrim(envlite_path_to_posix($repoRoot), '/') . '/.cache/envlite/manifest';
}

function envlite_manifest_load(string $repoRoot): array {
    $path = envlite_manifest_path($repoRoot);
    // Three states for the manifest path, each handled differently:
    //
    //   absent   (file_exists is false AND is_link is false) → empty
    //            manifest, returns [] normally. First run on a fresh
    //            checkout lands here.
    //
    //   exists-and-regular-file → read and parse below. Read failure
    //            (permission denied, IO error) throws — see comment
    //            block after the @file_get_contents call.
    //
    //   exists-but-not-regular (directory, broken symlink, symlink to a
    //            non-file, FIFO, etc.) → this used to slip past
    //            `!is_file` as "absent" because is_file follows symlinks
    //            and is false for everything non-regular. Silently
    //            returning [] in this state causes the same
    //            ownership-loss damage as a read failure: a following
    //            `clean --force` wipes `.cache/envlite/` (along with
    //            the manifest blocker) and orphans every previously
    //            managed file. Treat it as a hard read failure.
    if (!file_exists($path) && !is_link($path)) {
        // Both file_exists and is_link returning false on the manifest
        // path can mean two things: (a) the manifest is really absent
        // (the common first-run case — empty manifest, fine), or (b)
        // the state directory exists but lacks search/read permission
        // so PHP can't tell whether the manifest is in there. Case (b)
        // looks identical to (a) at this level, but treating an
        // inaccessible-but-present manifest as empty causes the same
        // ownership-loss scenarios codex flagged in rounds 13/14:
        // `clean --force` would walk the empty list and wipe
        // `.cache/envlite/` along with whatever ownership records it
        // hid, orphaning every managed file. Probe the state dir
        // explicitly; if it exists but isn't accessible, throw.
        $stateDir = dirname($path);
        if (is_dir($stateDir) && @scandir($stateDir) === false) {
            throw new \RuntimeException(
                "cannot read state directory $stateDir; manifest may exist but is inaccessible"
            );
        }
        return [];
    }
    if (!is_file($path) || is_link($path)) {
        throw new \RuntimeException(
            "manifest at $path is not a regular file; refusing to load"
        );
    }
    // Distinguish "manifest does not exist" (empty list, fine) from
    // "manifest exists but we can't read it" (permission denied, IO
    // error). The latter must NOT be silently treated as empty: a
    // following `clean --force` would then wipe `.cache/envlite/`
    // without removing the previously-managed files (they'd be orphaned
    // with no record envlite ever wrote them), and a following `up`
    // would rewrite the manifest with only the new entries, losing the
    // historical ownership records.
    $bytes = @file_get_contents($path);
    if ($bytes === false) {
        throw new \RuntimeException("cannot read manifest at $path");
    }
    $entries = [];
    foreach (explode("\n", $bytes) as $line) {
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
    // envlite_atomic_write handles parent-directory creation (@-suppressed
    // so a failure surfaces only via its RuntimeException with the envlite
    // prefix). A duplicate `mkdir` here would emit a raw PHP warning ahead
    // of the handled error on a read-only checkout or with a blocker at
    // the cache path, violating the spec's diagnostic-prefix contract.
    envlite_atomic_write(envlite_manifest_path($repoRoot), $lines);
}

function envlite_state_path(string $repoRoot): string {
    return rtrim(envlite_path_to_posix($repoRoot), '/') . '/.cache/envlite/state';
}

function envlite_state_load(string $repoRoot): array {
    $path = envlite_state_path($repoRoot);
    if (!is_file($path)) { return []; }
    $entries = [];
    foreach (explode("\n", file_get_contents($path)) as $line) {
        $line = rtrim($line, "\r");
        if ($line === '') { continue; }
        $tab = strpos($line, "\t");
        if ($tab === false) { continue; }
        $key = substr($line, 0, $tab);
        $value = substr($line, $tab + 1);
        $entries[$key] = $value;
    }
    return $entries;
}

function envlite_state_save(string $repoRoot, array $entries): void {
    ksort($entries);
    $lines = '';
    foreach ($entries as $key => $value) {
        $lines .= "$key\t$value\n";
    }
    // See envlite_manifest_save for why the parent mkdir lives only in
    // envlite_atomic_write (@-suppressed, surfaces failures via the
    // envlite-prefixed RuntimeException).
    envlite_atomic_write(envlite_state_path($repoRoot), $lines);
}

function envlite_atomic_write(string $path, string $bytes): string {
    $dir = dirname($path);
    // @-suppress on mkdir/fopen: failures are handled explicitly below
    // (fopen returns false → RuntimeException). The default PHP warning
    // duplicates that surface with less context.
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $hash = hash('sha256', $bytes);
    // Unique temp name + exclusive-create mode (`xb` → O_EXCL). The old
    // deterministic `$path.'.tmp'` was a destructive-write footgun: if a
    // user/tool had a file (or symlink) at that path, `wb` would truncate
    // it (or follow the symlink to its target and truncate THAT) before
    // any ownership prompt could run. The temp file sits next to the
    // destination so the rename below stays on the same filesystem (atomic).
    $tmp = $path . '.envlite-tmp.' . bin2hex(random_bytes(8));
    $fh = @fopen($tmp, 'xb');
    if ($fh === false) { throw new \RuntimeException("cannot create temp file $tmp"); }
    if (fwrite($fh, $bytes) !== strlen($bytes)) {
        fclose($fh); @unlink($tmp);
        throw new \RuntimeException("short write to $tmp");
    }
    // fsync for crash-durability before rename. Available since PHP 8.1;
    // on older PHPs we settle for fflush, which is the best we can do
    // without pulling in extensions. Check returns at every step:
    // delayed allocation on tmpfs, ENOSPC on a full disk, and EIO on a
    // network filesystem can all surface only at flush/fsync/close time
    // — `fwrite` succeeds because the bytes hit a kernel buffer but the
    // backing store never accepts them. Renaming past that point would
    // commit a temp file whose contents were never durable, and the
    // manifest entry would record the SHA of bytes that don't actually
    // exist on disk.
    if (@fflush($fh) === false) {
        fclose($fh); @unlink($tmp);
        throw new \RuntimeException("fflush failed for $tmp");
    }
    if (function_exists('fsync') && @fsync($fh) === false) {
        fclose($fh); @unlink($tmp);
        throw new \RuntimeException("fsync failed for $tmp");
    }
    if (@fclose($fh) === false) {
        @unlink($tmp);
        throw new \RuntimeException("fclose failed for $tmp");
    }
    // Clear a non-regular destination before the rename. PHP's rename()
    // can replace a regular file or a non-existent path atomically, but
    // it cannot overwrite a directory (POSIX EISDIR). Callers that hit
    // this path have already cleared ownership: Phases 5–7 prompted the
    // user (or honored --force) before reaching atomic_write, so by
    // contract any non-regular entry sitting in our way is something
    // the user agreed to overwrite. Internal `.cache/envlite/*` writes
    // don't go through ownership, but envlite owns that subtree
    // wholesale per the state-directory contract, so blindly clearing
    // anything in our way there is also correct. Symlinks are unlinked
    // (not followed); the existing `rrmdir` helper already refuses to
    // recurse through symlinks at its top level.
    if (is_link($path)) {
        @unlink($path);
    } elseif (is_dir($path)) {
        envlite_rrmdir($path);
    }
    // @-suppress on rename: PHP's default warning is unprefixed and
    // would land on stderr in violation of the spec's "every diagnostic
    // line carries the envlite prefix" contract. The handled
    // RuntimeException below flows through envlite_phase_guard (or the
    // caller's own try/catch) and gets the proper `envlite <sub>:
    // phase N: rename failed: …` shape.
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new \RuntimeException("rename failed: $tmp -> $path");
    }
    return $hash;
}

/**
 * @param array<string,string> $manifest path => sha256-hex|"dir"
 * @param bool $existsOnDisk True if anything sits at the path — regular file,
 *                           symlink (even broken), FIFO, directory.
 * @param string|null $currentBytes Bytes of a regular file at the path, or null
 *                                  if the path is empty, a directory entry whose
 *                                  contents are not drift-checked, OR a
 *                                  non-regular entry (symlink, FIFO) whose
 *                                  content we deliberately don't read.
 * @return 'absent'|'owned_clean'|'owned_drifted'|'unowned'
 */
function envlite_ownership(
    array $manifest,
    string $relPath,
    bool $existsOnDisk,
    ?string $currentBytes
): string {
    $recorded = $manifest[$relPath] ?? null;
    if (!$existsOnDisk) {
        // Nothing on disk. Either absent (caller writes directly) or
        // owned_clean (manifest claims ownership, user deleted, safe to
        // recreate without prompting).
        return $recorded === null ? 'absent' : 'owned_clean';
    }
    // Something exists on disk from here on.
    if ($recorded === null) { return 'unowned'; }
    if ($recorded === 'dir') { return 'owned_clean'; }
    if ($currentBytes === null) {
        // Exists but is not a readable regular file (symlink, broken
        // symlink, FIFO, directory at a file path). Manifest claims
        // envlite owns a regular file here; the on-disk entry no longer
        // matches that contract. Classify as drifted so the user is
        // prompted before the rename clobbers whatever is there. The
        // earlier impl returned `owned_clean` for null bytes — that bypass
        // let an unowned symlink/FIFO get silently overwritten.
        return 'owned_drifted';
    }
    return hash('sha256', $currentBytes) === $recorded ? 'owned_clean' : 'owned_drifted';
}

/**
 * Helper for callers: read regular-file bytes if the path holds one;
 * return the existence flag and the bytes (null if not readable as a
 * regular file). Used to drive envlite_ownership() decisions.
 *
 * @return array{bool, string|null} [existsOnDisk, regularBytesOrNull]
 */
function envlite_path_inspect(string $abs): array {
    // file_exists follows symlinks (false for broken symlinks); is_link
    // catches symlinks regardless. The OR catches every "something is here"
    // case including broken symlinks, FIFOs, dirs, sockets.
    $exists = file_exists($abs) || is_link($abs);
    if (!$exists) { return [false, null]; }
    // is_file follows symlinks. A symlink-to-regular technically passes
    // is_file, but we intentionally treat any symlink as non-regular here
    // so the ownership path sees it as drifted and prompts — envlite did
    // not put a symlink there.
    if (!is_file($abs) || is_link($abs)) { return [true, null]; }
    $bytes = @file_get_contents($abs);
    return [true, $bytes === false ? null : $bytes];
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

// Each marker is paired with its expected shape: 'file' or 'dir'.
// file_exists() alone would let a malformed checkout pass preflight —
// e.g. a regular file at `src/wp-includes`, or a directory called
// `package.json`. Phase 0 would then approve a non-wordpress-develop
// tree and the later phases would try to install dependencies and
// write envlite outputs into it.
const ENVLITE_REPO_MARKERS = [
    'package.json'                          => 'file',
    'composer.json'                         => 'file',
    'wp-config-sample.php'                  => 'file',
    'wp-tests-config-sample.php'            => 'file',
    'src/wp-includes'                       => 'dir',
    'tests/phpunit/includes/bootstrap.php'  => 'file',
];

function envlite_phase0_is_wordpress_develop(string $root): bool {
    foreach (ENVLITE_REPO_MARKERS as $rel => $kind) {
        $abs = $root . '/' . $rel;
        if ($kind === 'file' && !is_file($abs)) { return false; }
        if ($kind === 'dir' && !is_dir($abs)) { return false; }
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
 * On Windows, resolve a bare command name (e.g. "npm") to its full path
 * by walking PATH + PATHEXT. PHP's proc_open with an array command does
 * not perform PATHEXT lookup on Windows, so bare "npm" / "composer" —
 * which ship as .cmd / .bat shims — fail to spawn there otherwise.
 *
 * On non-Windows, or for command names that already contain a path
 * separator (or a drive letter on Windows), returns the input unchanged.
 * When no resolution can be found, returns the input as well so the
 * subsequent proc_open failure can surface its own diagnostic.
 */
function envlite_resolve_windows_command(string $name): string {
    if (PHP_OS_FAMILY !== 'Windows') { return $name; }
    if (strpbrk($name, "/\\") !== false) { return $name; }
    if (preg_match('/^[A-Za-z]:/', $name)) { return $name; }

    static $cache = [];
    if (array_key_exists($name, $cache)) { return $cache[$name]; }

    $exts = array_map('strtolower',
        explode(';', getenv('PATHEXT') ?: '.COM;.EXE;.BAT;.CMD'));
    $hasExplicitExt = preg_match('/\.[A-Za-z0-9]+$/', $name);
    $paths = explode(PATH_SEPARATOR, getenv('PATH') ?: '');
    foreach ($paths as $dir) {
        if ($dir === '') { continue; }
        $base = rtrim($dir, "\\/") . DIRECTORY_SEPARATOR . $name;
        $candidates = $hasExplicitExt ? [$base] : array_map(fn($e) => $base . $e, $exts);
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $cache[$name] = $candidate;
            }
        }
    }
    return $cache[$name] = $name;
}

/** Apply envlite_resolve_windows_command to the executable in a cmd array. */
function envlite_resolve_cmd(array $cmd): array {
    if (PHP_OS_FAMILY === 'Windows' && isset($cmd[0]) && is_string($cmd[0])) {
        $cmd[0] = envlite_resolve_windows_command($cmd[0]);
    }
    return $cmd;
}

/**
 * Build a `cmd.exe /d /s /c "..."` invocation string for a Windows
 * batch-script command. Pure formatter — exposed for testing on any
 * host. Callers pass the result to proc_open with
 * `['bypass_shell' => true]` in the options array so PHP does not
 * re-wrap the already-wrapped string.
 *
 * Why this exists: array-form proc_open on Windows calls CreateProcess
 * directly, which can launch executables (.exe) but cannot interpret
 * batch scripts (.cmd/.bat). The npm/composer shims that ship with
 * those tools on Windows are batch files, so envlite must route them
 * through cmd.exe explicitly.
 *
 * Escaping: cmd.exe's quoting rules differ from PHP's array-form
 * escaping (Microsoft C runtime). We build the string ourselves with
 * cmd.exe-native conventions:
 *   - args with whitespace or cmd.exe metacharacters are wrapped in
 *     double quotes
 *   - inside double quotes, internal `"` is doubled to `""`, `^` and `%`
 *     are prefixed with `^` (to suppress variable expansion)
 *   - the whole inner command is wrapped in outer quotes; `/s` strips
 *     exactly the first and last quote of the line and runs the rest
 *     literally, which is the only cmd.exe parsing mode predictable
 *     across multiple inner-quoted arguments.
 */
function envlite_cmd_exe_wrap_string(array $cmd): string {
    $parts = array_map(static function (string $arg): string {
        if ($arg !== '' && !preg_match('/[\s"<>&|^()%,;=!]/', $arg)) {
            return $arg;
        }
        $escaped = preg_replace('/([\^%])/', '^$1', $arg);
        $escaped = str_replace('"', '""', $escaped);
        return '"' . $escaped . '"';
    }, $cmd);
    return 'cmd.exe /d /s /c "' . implode(' ', $parts) . '"';
}

/**
 * Spawn a subprocess uniformly across platforms. On Windows, a resolved
 * .cmd/.bat shim is wrapped via cmd.exe with `bypass_shell` set so PHP
 * doesn't add a second cmd.exe layer. Everywhere else (and for direct
 * .exe / PHP_BINARY invocations) the array form is passed through to
 * proc_open unchanged.
 *
 * @param array $cmd          executable as the first element, args after
 * @param array $descriptors  proc_open descriptor spec
 * @param-out array|null $pipes the proc_open-allocated pipe array
 * @return resource|false    proc_open return value
 */
function envlite_proc_open(array $cmd, array $descriptors, &$pipes, ?string $cwd) {
    if (PHP_OS_FAMILY !== 'Windows' || !isset($cmd[0]) || !is_string($cmd[0])) {
        return @proc_open(envlite_resolve_cmd($cmd), $descriptors, $pipes, $cwd);
    }
    $cmd[0] = envlite_resolve_windows_command($cmd[0]);
    if (!preg_match('/\.(cmd|bat)$/i', $cmd[0])) {
        return @proc_open($cmd, $descriptors, $pipes, $cwd);
    }
    return @proc_open(
        envlite_cmd_exe_wrap_string($cmd),
        $descriptors,
        $pipes,
        $cwd,
        null,
        ['bypass_shell' => true]
    );
}

/** Capture variant: returns [$exit, $stdout, $stderr]. Used by Phase 0 and Phase 3. */
function envlite_proc_capture(array $cmd, ?string $cwd = null): array {
    $proc = envlite_proc_open(
        $cmd,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd
    );
    if (!is_resource($proc)) { return [-1, '', '']; }
    fclose($pipes[0]);
    [$stdout, $stderr] = envlite_drain_two_pipes($pipes[1], $pipes[2]);
    $exit = proc_close($proc);
    return [$exit, $stdout, $stderr];
}

/**
 * Concurrently drain two non-blocking pipes (typically stdout/stderr of a
 * proc_open child) until both reach EOF. Closes each pipe as it finishes.
 *
 * Reading stdout to EOF before stderr can deadlock when the child writes
 * more than a pipe buffer (~64KB on Linux) to stderr — the child blocks
 * trying to write, the parent blocks waiting on stdout.
 *
 * @return array{0:string,1:string} [stdoutBuffer, stderrBuffer]
 */
function envlite_drain_two_pipes($pipe1, $pipe2): array {
    stream_set_blocking($pipe1, false);
    stream_set_blocking($pipe2, false);
    $buf1 = $buf2 = '';
    $isWindows = PHP_OS_FAMILY === 'Windows';
    while (is_resource($pipe1) || is_resource($pipe2)) {
        $read = [];
        if (is_resource($pipe1)) { $read[] = $pipe1; }
        if (is_resource($pipe2)) { $read[] = $pipe2; }

        if ($isWindows) {
            // stream_select cannot observe proc_open pipes on Windows.
            usleep(50_000);
        } else {
            $write = $except = null;
            $n = @stream_select($read, $write, $except, 1);
            if ($n === false || $n === 0) { continue; }
        }

        $candidates = [];
        if (is_resource($pipe1)) { $candidates[] = [$pipe1, 1]; }
        if (is_resource($pipe2)) { $candidates[] = [$pipe2, 2]; }
        foreach ($candidates as [$s, $which]) {
            $chunk = fread($s, 8192);
            if ($chunk !== false && $chunk !== '') {
                if ($which === 1) { $buf1 .= $chunk; } else { $buf2 .= $chunk; }
            }
            if (feof($s)) {
                fclose($s);
                if ($which === 1) { $pipe1 = null; } else { $pipe2 = null; }
            }
        }
    }
    return [$buf1, $buf2];
}

/** Streaming variant: child stdio inherits the parent's. Used by the Windows dev-server fallback. */
function envlite_proc_stream(array $cmd, ?string $cwd = null): int {
    $proc = envlite_proc_open($cmd, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $cwd);
    if (!is_resource($proc)) { return -1; }
    return proc_close($proc);
}

/**
 * Picks the router script for `php -S`. Run from source, that is router.php
 * sitting beside envlite.php. Run from a phar, __DIR__ is a phar:// path no
 * separate `php -S` process can resolve, so the phar's own file path is used
 * instead — the phar stub detects the cli-server SAPI and dispatches to the
 * bundled router. Pure so tests exercise both branches.
 */
function envlite_dev_server_router(string $pharPath, string $sourceDir): string {
    return $pharPath !== '' ? $pharPath : $sourceDir . '/router.php';
}

/**
 * Builds the argv passed to `php -S`. Excludes the binary itself —
 * pcntl_exec receives the binary as its first argument and the rest as $args.
 * On the Windows fallback path, envlite_run_dev_server prepends PHP_BINARY.
 */
function envlite_dev_server_argv(string $repoRoot, int $port): array {
    $pharPath = class_exists('Phar', false) ? \Phar::running(false) : '';
    $router = envlite_dev_server_router($pharPath, __DIR__);
    return ['-S', "127.0.0.1:$port", '-t', 'src', $router];
}

/**
 * Returns true if pcntl_exec is usable on this platform right now.
 * Split into a function so tests can read the same predicate the launcher uses.
 */
function envlite_pcntl_exec_available(): bool {
    return PHP_OS_FAMILY !== 'Windows' && function_exists('pcntl_exec');
}

/**
 * Launches the dev server. On Unix, requires pcntl (enforced by Phase 0 at
 * up time and re-checked here for safety) and replaces the current process
 * via pcntl_exec — same PID, no parent-child relay. On Windows, falls back
 * to envlite_proc_stream which inherits stdio so SIGINT still reaches the
 * child. Returns only on error or when the Windows-fallback child exits.
 */
function envlite_run_dev_server(string $repoRoot, int $port): int {
    $argv = envlite_dev_server_argv($repoRoot, $port);

    // Multi-worker `php -S`. Only knob PHP exposes; PHP 7.4+ on Unix, ignored
    // on Windows. Don't clobber a user-exported value.
    if (getenv('PHP_CLI_SERVER_WORKERS') === false) {
        putenv('PHP_CLI_SERVER_WORKERS=3');
    }

    if (PHP_OS_FAMILY !== 'Windows') {
        if (!function_exists('pcntl_exec')) {
            // Phase 0 enforces pcntl on Unix; this defensive check exists so a
            // PHP build that hides pcntl behind extension config still gets a
            // clean error rather than degrading to proc_open silently.
            envlite_log(null, 'pcntl extension is required on Unix; reinstall PHP with pcntl');
            return 1;
        }
        // pcntl_exec uses the *current* working directory; chdir first so
        // `-t src` resolves relative to the repo root, matching the proc_open
        // path's $cwd argument.
        if (!@chdir($repoRoot)) {
            envlite_log(null, "failed to chdir to $repoRoot before exec");
            return 1;
        }
        // Suppress the warning pcntl_exec emits on failure; we surface our own.
        @pcntl_exec(PHP_BINARY, $argv);
        // pcntl_exec returns only on failure (success replaces the process).
        envlite_log(null, 'pcntl_exec(php -S) failed; the dev server did not start');
        return 1;
    }

    // Windows fallback. Use PHP_BINARY explicitly so we don't depend on PATH
    // resolution to the same PHP that is running envlite.
    $exit = envlite_proc_stream(array_merge([PHP_BINARY], $argv), $repoRoot);
    return $exit === 0 ? 0 : 1;
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

function envlite_phase0_required_extensions(): array {
    // `gd` is required by the WordPress core test bootstrap:
    // phpunit.xml.dist sets WP_RUN_CORE_TESTS=1, which makes
    // tests/phpunit/includes/bootstrap.php abort if `gd` is missing
    // before any group filter applies. Checking it at preflight stops
    // envlite from claiming success while `phpunit --group html-api`
    // still fails downstream.
    $exts = ['gd', 'pdo_sqlite', 'sqlite3', 'openssl', 'simplexml', 'zip'];
    if (PHP_OS_FAMILY !== 'Windows') {
        // pcntl is required on Unix so envlite_run_dev_server can call
        // pcntl_exec into php -S. Windows lacks pcntl entirely; the
        // dev-server launcher falls back to proc_open there.
        $exts[] = 'pcntl';
    }
    return $exts;
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
    foreach (envlite_phase0_required_extensions() as $ext) {
        if (!extension_loaded($ext)) {
            envlite_log(null, "preflight: required PHP extension missing: $ext");
            exit(3);
        }
    }
    // Phase 5 (salts URL) and Phase 5 (plugin zip) fetch over HTTPS via
    // PHP's URL stream wrappers. allow_url_fopen=0 makes those silently
    // unavailable, and the failure surfaces only after npm/composer/build
    // have run. Catch it up front so the user gets a one-line preflight
    // error instead of a confusing mid-phase abort.
    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        envlite_log(null, 'preflight: PHP allow_url_fopen is disabled; required for HTTP fetches in Phase 5');
        exit(3);
    }
    // proc_open is the substrate for every subprocess envlite spawns
    // (node/npm/composer/php). Hardened php.ini configurations sometimes
    // list it in `disable_functions`; reaching it via the version probe
    // below would emit a raw PHP error instead of the documented
    // preflight exit 3.
    if (!function_exists('proc_open')) {
        envlite_log(null, 'preflight: proc_open() is disabled; required to spawn node/npm/composer');
        exit(3);
    }
    // pcntl_exec is what envlite_run_dev_server calls on Unix to replace
    // its PHP process with `php -S`. Loading the pcntl extension (checked
    // above) is necessary but not sufficient: hardened php.ini configs
    // can list pcntl_exec in `disable_functions` even when the extension
    // itself loads. envlite_pcntl_exec_available() is the same predicate
    // the launcher uses; sharing it here makes preflight fail fast
    // instead of after all setup phases.
    if (PHP_OS_FAMILY !== 'Windows' && !envlite_pcntl_exec_available()) {
        envlite_log(null, 'preflight: pcntl_exec() is disabled; required for the dev-server handoff on Unix');
        exit(3);
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
    $cachePath = rtrim(envlite_path_to_posix($repoRoot), '/') . '/.cache/envlite/port';

    if ($explicitPort !== null) {
        if (!envlite_phase1_port_is_free($explicitPort)) {
            // Spec contract: bind failure produces the single diagnostic
            // line `envlite up: failed to bind 127.0.0.1:<port>`. Phase 1's
            // explicit-port path is just that bind failure — don't prepend
            // `phase 1:` (the documented bind-failure line has no phase
            // label) or append remediation hints (the README's
            // troubleshooting table covers them).
            envlite_log('up', "failed to bind 127.0.0.1:$explicitPort");
            exit(1);
        }
        envlite_phase1_write_cache($repoRoot, $explicitPort);
        return $explicitPort;
    }

    if (is_file($cachePath)) {
        $cached = (int) trim(file_get_contents($cachePath));
        if ($cached >= 1 && $cached <= 65535) {
            // Spec: trust the cache unconditionally; do not re-probe. A running
            // envlite server on the cached port would otherwise look "in use"
            // and trigger a re-pick that re-stamps wp-config.php to a new URL.
            return $cached;
        }
        // cache corrupt or out of any sane range: fall through to re-pick
    }

    $start = envlite_phase1_seed_port(realpath($repoRoot) ?: $repoRoot);
    for ($i = 0; $i < ENVLITE_PORT_POOL_SIZE; $i++) {
        $cand = ENVLITE_PORT_LOW + ((($start - ENVLITE_PORT_LOW) + $i) % ENVLITE_PORT_POOL_SIZE);
        if (envlite_phase1_port_is_free($cand)) {
            envlite_phase1_write_cache($repoRoot, $cand);
            return $cand;
        }
    }
    envlite_log('up', 'phase 1: no free port in 8100-8899');
    exit(1);
}

function envlite_phase1_write_cache(string $repoRoot, int $port): void {
    $cachePath = rtrim(envlite_path_to_posix($repoRoot), '/') . '/.cache/envlite/port';
    // Load the manifest BEFORE writing the port cache. envlite_manifest_load
    // throws when the manifest exists but is unreadable or non-regular
    // (rounds 13/14); doing the load after the atomic_write would leave a
    // newly-written `.cache/envlite/port` file unrecorded in the manifest
    // — exactly the "phase 1 mutated state without recording it" failure
    // the spec's bind-failure contract is trying to prevent. Failing here
    // surfaces the manifest issue before any state mutation occurs.
    $manifest = envlite_manifest_load($repoRoot);
    $hash = envlite_atomic_write($cachePath, "$port\n");
    $manifest['.cache/envlite/port'] = $hash;
    envlite_manifest_save($repoRoot, $manifest);
}

function envlite_phase2_input_hash(string $repoRoot): ?string {
    $path = "$repoRoot/package-lock.json";
    if (!is_file($path)) { return null; }
    return hash_file('sha256', $path);
}

function envlite_phase4_input_hash(string $repoRoot): ?string {
    $path = "$repoRoot/composer.json";
    if (!is_file($path)) { return null; }
    // Mix the running PHP into the hash. wordpress-develop intentionally
    // ships no composer.lock, so Composer resolves fresh on every install
    // and can pick a different package set when the user switches PHP
    // versions (different platform constraints in dependencies). Without
    // this, swapping PHP and re-running `up` skips Phase 4 against a
    // vendor/ tree resolved for the previous PHP — phpunit then fails
    // against an incompatible autoload set.
    return hash('sha256', PHP_VERSION . "\0" . file_get_contents($path));
}

/**
 * Run multiple subprocesses concurrently with per-process buffered output.
 *
 * @param array<string, array{cmd: array, cwd: ?string}> $jobs label => spec
 * @return array<string, array{exit: int, output: string}>
 */
function envlite_run_parallel_buffered(array $jobs): array {
    $procs = [];
    $streamLabel = []; // (int) stream id => label
    foreach ($jobs as $label => $spec) {
        $proc = envlite_proc_open(
            $spec['cmd'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $spec['cwd'] ?? null
        );
        if (!is_resource($proc)) {
            foreach ($procs as $p) {
                @proc_terminate($p['proc']);
                @proc_close($p['proc']);
            }
            throw new \RuntimeException("failed to spawn $label");
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $procs[$label] = [
            'proc'   => $proc,
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
            'buf'    => '',
        ];
        $streamLabel[(int) $pipes[1]] = $label;
        $streamLabel[(int) $pipes[2]] = $label;
    }

    // PHP's stream_select() cannot observe proc_open() pipes on Windows
    // (returns false unconditionally), so on Windows we poll the
    // already-non-blocking pipes with a short sleep instead.
    $isWindows = PHP_OS_FAMILY === 'Windows';

    while (true) {
        $read = [];
        foreach ($procs as $p) {
            if (is_resource($p['stdout'])) { $read[] = $p['stdout']; }
            if (is_resource($p['stderr'])) { $read[] = $p['stderr']; }
        }
        if (empty($read)) { break; }

        if ($isWindows) {
            usleep(50_000);
        } else {
            $write = $except = null;
            $n = @stream_select($read, $write, $except, 1);
            if ($n === false) { continue; } // EINTR — retry
            if ($n === 0)     { continue; } // timeout — retry
        }

        foreach ($read as $stream) {
            $label = $streamLabel[(int) $stream] ?? null;
            if ($label === null) { continue; }
            $chunk = fread($stream, 8192);
            if ($chunk !== false && $chunk !== '') {
                $procs[$label]['buf'] .= $chunk;
            }
            if (feof($stream)) {
                fclose($stream);
                if ($procs[$label]['stdout'] === $stream) {
                    $procs[$label]['stdout'] = null;
                }
                if ($procs[$label]['stderr'] === $stream) {
                    $procs[$label]['stderr'] = null;
                }
            }
        }
    }

    $result = [];
    foreach ($procs as $label => $p) {
        $result[$label] = [
            'exit'   => proc_close($p['proc']),
            'output' => $p['buf'],
        ];
    }
    return $result;
}

/**
 * Format a subprocess failure dump: every job's buffer under a
 * `--- <label> ---` separator, with a trailing newline if the buffer
 * didn't end with one. Pure; called by envlite_phase24_parallel on
 * any failure and by envlite_phase3_build_dev for its single-job dump.
 *
 * @param array<string, array{exit:int, output:string}> $results
 */
function envlite_format_subprocess_dump(array $results): string {
    $out = '';
    foreach ($results as $label => $r) {
        $out .= "--- $label ---\n";
        $out .= $r['output'];
        if ($r['output'] === '' || substr($r['output'], -1) !== "\n") {
            $out .= "\n";
        }
    }
    return $out;
}

/**
 * Phases 2 and 4 — npm ci and composer install, in parallel.
 * Returns a record of which phases were skipped this run; the caller
 * (Phase 3) needs that to decide whether the build:dev sentinel + recorded
 * hashes are sufficient to skip itself.
 *
 * @return array{phase2_skipped: bool, phase4_skipped: bool}
 */
function envlite_phase24_parallel(string $repoRoot, bool $rebuild): array {
    $state = envlite_state_load($repoRoot);

    $npmHash      = envlite_phase2_input_hash($repoRoot);
    $composerHash = envlite_phase4_input_hash($repoRoot);

    $phase2Skip = !$rebuild
        && $npmHash !== null
        && is_dir("$repoRoot/node_modules")
        && ($state['phase2.input_hash'] ?? null) === $npmHash;
    $phase4Skip = !$rebuild
        && $composerHash !== null
        && is_dir("$repoRoot/vendor")
        && ($state['phase4.input_hash'] ?? null) === $composerHash;

    if ($phase2Skip && $phase4Skip) {
        return ['phase2_skipped' => true, 'phase4_skipped' => true];
    }

    $jobs = [];
    if (!$phase2Skip) {
        $jobs['npm ci'] = ['cmd' => ['npm', 'ci'], 'cwd' => $repoRoot];
    }
    if (!$phase4Skip) {
        $jobs['composer install'] = [
            'cmd' => ['composer', 'install', '--no-interaction', '--ignore-platform-req=ext-simplexml'],
            'cwd' => $repoRoot,
        ];
    }

    // Invalidate recorded hashes for any phase we're about to re-run.
    // `npm ci` / `composer install` create the install directory first
    // (or leave the previous one in place) and only later finish, so a
    // mid-run failure can leave node_modules/vendor on disk paired with
    // the still-matching old hash. The next `up` would then skip the
    // broken install. Drop the hash up front so a subsequent run with
    // unchanged inputs re-attempts the install.
    if (!$phase2Skip || !$phase4Skip) {
        if (!$phase2Skip) { unset($state['phase2.input_hash']); }
        if (!$phase4Skip) { unset($state['phase4.input_hash']); }
        envlite_state_save($repoRoot, $state);
    }

    fwrite(STDERR, "envlite up: installing dependencies\u{2026}\n");
    $results = envlite_run_parallel_buffered($jobs);

    $failed = [];
    foreach ($results as $label => $r) {
        if ($r['exit'] !== 0) { $failed[$label] = $r; }
    }
    if (!empty($failed)) {
        // Spec: "On failure of either or both, envlite waits for both to
        // complete, then dumps each captured buffer to stderr under labeled
        // separators." The surviving partner's output often carries
        // warnings/context relevant to the failure, so dump every job's
        // buffer, not just the failed one(s).
        fwrite(STDERR, envlite_format_subprocess_dump($results));
        if (count($failed) === 1) {
            $label = array_keys($failed)[0];
            $phaseN = $label === 'npm ci' ? 2 : 4;
            $exit = $failed[$label]['exit'];
            throw new \RuntimeException("phase $phaseN: $label failed (exit $exit)");
        }
        throw new \RuntimeException('phases 2 and 4: install subprocesses failed');
    }

    // Record state for each phase that ran successfully.
    if (!$phase2Skip) { $state['phase2.input_hash'] = $npmHash; }
    if (!$phase4Skip) { $state['phase4.input_hash'] = $composerHash; }
    envlite_state_save($repoRoot, $state);

    return ['phase2_skipped' => $phase2Skip, 'phase4_skipped' => $phase4Skip];
}

/**
 * Phase 3 — npm run build:dev, serial after phases 2 & 4.
 * Skips when both deps phases skipped, sentinel exists, and recorded
 * hashes still match. `--no-build` forces skip; `--rebuild` forces run.
 *
 * Output is buffered, not streamed: build:dev prints hundreds of
 * webpack lines on every run, which drowns out envlite's own status
 * lines and is uninteresting on success. On failure, the captured
 * stdout+stderr is dumped under a `--- npm run build:dev ---`
 * separator (matching the phase 2/4 dump format) before the throw
 * surfaces as `envlite up: phase 3: ...`.
 */
/**
 * Read the SHA the current HEAD points at, without shelling out to git.
 * Returns null when the repo isn't a git checkout, when HEAD is in a
 * shape we don't understand, or when the ref can't be resolved.
 *
 * Used as a Phase 3 cache key. The deps-hash + sentinel check on its
 * own can't tell that the user has run `git pull` or switched branches
 * to a tree where build inputs under `src/` changed but
 * `package-lock.json` and `composer.json` didn't — without this
 * fingerprint, Phase 3 would skip and leave stale build artifacts from
 * the previous source tree. The HEAD SHA changes on every commit/pull/
 * branch-switch in a wordpress-develop checkout, which is the common
 * vector for source changes; uncommitted edits in `src/` are still the
 * user's responsibility (use `--rebuild`).
 */
function envlite_phase3_head_sha(string $repoRoot): ?string {
    // Resolve the per-worktree git directory. In a plain checkout this
    // is `$repoRoot/.git/`; in a linked worktree (`git worktree add ...`)
    // `$repoRoot/.git` is a regular file containing `gitdir: <path>`
    // where the worktree's per-tree HEAD/refs live (the object store and
    // packed-refs still belong to the main repo, but HEAD is local). The
    // round-22 implementation read `$repoRoot/.git/HEAD` directly and
    // returned null on a linked worktree, which the skip rule then
    // mapped to `null === null` and let Phase 3 skip across branch
    // switches there — exactly the regression this helper was added to
    // prevent.
    $gitDir = envlite_phase3_resolve_git_dir($repoRoot);
    if ($gitDir === null) { return null; }
    $head = @file_get_contents($gitDir . '/HEAD');
    if ($head === false) { return null; }
    $head = trim($head);
    if (preg_match('/^[0-9a-f]{40}$/', $head)) {
        return $head; // detached HEAD
    }
    if (strpos($head, 'ref: ') !== 0) { return null; }
    $ref = trim(substr($head, 5));
    // Loose ref first — refs/heads/<branch> lives under the WORKTREE
    // gitDir for a linked worktree (per-tree HEAD), but other refs
    // (tags, remote-tracking) live under the COMMON gitdir. Try the
    // worktree dir first, then walk up to the common dir if needed.
    $commonDir = envlite_phase3_resolve_git_common_dir($gitDir);
    foreach (array_unique([$gitDir, $commonDir]) as $dir) {
        if ($dir === null) { continue; }
        $refFile = $dir . '/' . $ref;
        if (is_file($refFile)) {
            $sha = trim(@file_get_contents($refFile) ?: '');
            if (preg_match('/^[0-9a-f]{40}$/', $sha)) { return $sha; }
        }
    }
    // Fallback: packed-refs lives only in the common gitdir.
    if ($commonDir !== null) {
        $packed = @file_get_contents($commonDir . '/packed-refs');
        if ($packed !== false) {
            foreach (explode("\n", $packed) as $line) {
                $line = rtrim($line, "\r");
                if ($line === '' || $line[0] === '#' || $line[0] === '^') { continue; }
                if (preg_match('/^([0-9a-f]{40}) (.+)$/', $line, $m) && $m[2] === $ref) {
                    return $m[1];
                }
            }
        }
    }
    return null;
}

/**
 * Return the absolute path to the per-worktree git directory for a
 * checkout at $repoRoot, or null when the checkout isn't a git tree.
 *   - plain checkout:     `$repoRoot/.git/` (directory)
 *   - linked worktree:    `$repoRoot/.git` is a file
 *                          `gitdir: /path/to/main/.git/worktrees/<name>`
 *                          — return that target.
 */
function envlite_phase3_resolve_git_dir(string $repoRoot): ?string {
    $dotGit = $repoRoot . '/.git';
    if (is_dir($dotGit) && !is_link($dotGit)) { return $dotGit; }
    if (is_file($dotGit)) {
        $contents = @file_get_contents($dotGit);
        if ($contents === false) { return null; }
        if (preg_match('/^gitdir:\s*(.+)$/m', $contents, $m)) {
            $target = trim($m[1]);
            // Relative paths in `gitdir:` are resolved against the
            // location of the `.git` file (the worktree root).
            if ($target !== '' && $target[0] !== '/' && !preg_match('/^[A-Za-z]:/', $target)) {
                $target = $repoRoot . '/' . $target;
            }
            return rtrim($target, '/\\');
        }
    }
    return null;
}

/**
 * Resolve the *common* git directory (the main repo's .git) given a
 * per-worktree git dir. For a plain checkout this is the same path;
 * for a linked worktree it's read from `commondir` (a file inside the
 * worktree's gitdir containing the path to the main .git, absolute or
 * relative to the worktree gitdir).
 */
function envlite_phase3_resolve_git_common_dir(string $gitDir): ?string {
    $commonFile = $gitDir . '/commondir';
    if (!is_file($commonFile)) {
        // Plain checkout: the common dir IS the per-worktree dir.
        return $gitDir;
    }
    $target = trim(@file_get_contents($commonFile) ?: '');
    if ($target === '') { return null; }
    if ($target[0] !== '/' && !preg_match('/^[A-Za-z]:/', $target)) {
        $target = $gitDir . '/' . $target;
    }
    return rtrim($target, '/\\');
}

function envlite_phase3_build_dev(
    string $repoRoot,
    bool $rebuild,
    bool $noBuild,
    bool $phase2Skipped,
    bool $phase4Skipped
): void {
    if ($noBuild) { return; }

    // Sentinel: build:dev produces files under src/wp-includes/js/dist
    // (Gutenberg copy in --dev mode). The entire src/wp-includes/js path
    // is gitignored, so a fresh checkout has nothing there; existence is a
    // reliable proxy for "build:dev has produced output". A tracked source
    // file (e.g. src/wp-includes/version.php) would not work — it is in
    // every clean checkout and gives a false positive after the user
    // removes ignored build artifacts.
    $sentinel    = "$repoRoot/src/wp-includes/js/dist";
    $npmHash     = envlite_phase2_input_hash($repoRoot);
    $composerHash = envlite_phase4_input_hash($repoRoot);
    $headSha     = envlite_phase3_head_sha($repoRoot);
    $state       = envlite_state_load($repoRoot);

    // Skip rule has three hash/identity components: package-lock,
    // composer.json, and the HEAD SHA capture from git so a branch
    // switch or `git pull` that changes src/ inputs without changing
    // the lockfiles still rebuilds. Each component compares the
    // current value against the value recorded on the last successful
    // build; null === null is allowed so a non-git checkout still
    // gets the skip on identical re-runs.
    $skip = !$rebuild
        && $phase2Skipped
        && $phase4Skipped
        && is_dir($sentinel)
        && $npmHash !== null && $composerHash !== null
        && ($state['phase3.recorded_npm_hash'] ?? null)      === $npmHash
        && ($state['phase3.recorded_composer_hash'] ?? null) === $composerHash
        && ($state['phase3.recorded_head_sha'] ?? null)      === $headSha;
    if ($skip) { return; }

    // Invalidate recorded hashes before attempting the build. `build:dev`
    // writes incrementally into src/wp-includes/{js,css,blocks}, so a
    // partial run can leave the sentinel directory in place. If the
    // recorded hashes still matched, the next `up` would skip Phase 3
    // even though the last build attempt failed. Drop them up front;
    // re-record only on a successful build.
    unset(
        $state['phase3.recorded_npm_hash'],
        $state['phase3.recorded_composer_hash'],
        $state['phase3.recorded_head_sha']
    );
    envlite_state_save($repoRoot, $state);

    fwrite(STDERR, "envlite up: building assets\u{2026}\n");
    [$exit, $stdout, $stderr] = envlite_proc_capture(['npm', 'run', 'build:dev'], $repoRoot);
    if ($exit !== 0) {
        // proc_capture returns stdout and stderr separately because it
        // drains the pipes concurrently to avoid pipe-buffer deadlocks;
        // chronological interleaving is therefore lost. Concatenate them
        // for the dump — webpack errors land on stderr, informational
        // lines on stdout, and both are useful for diagnosis.
        fwrite(STDERR, envlite_format_subprocess_dump([
            'npm run build:dev' => ['exit' => $exit, 'output' => $stdout . $stderr],
        ]));
        throw new \RuntimeException("phase 3: npm run build:dev failed (exit $exit)");
    }

    if ($npmHash !== null)      { $state['phase3.recorded_npm_hash']      = $npmHash; }
    if ($composerHash !== null) { $state['phase3.recorded_composer_hash'] = $composerHash; }
    // Skip writing the HEAD entry when there's no git repo to fingerprint
    // — the skip check uses null === null on the absent-side path.
    // Writing an empty string would later miscompare against current
    // null and force a needless rebuild.
    if ($headSha !== null)      { $state['phase3.recorded_head_sha']      = $headSha; }
    envlite_state_save($repoRoot, $state);
}

// Versioned (immutable) URL. The unsuffixed
// .../sqlite-database-integration.zip URL points to whatever wordpress.org
// publishes as latest, so its SHA256 changes on every plugin release —
// pairing it with a fixed pin breaks fresh installs whenever upstream
// ships a new version. The versioned URL is content-addressable.
const ENVLITE_SQLITE_PLUGIN_URL = 'https://downloads.wordpress.org/plugin/sqlite-database-integration.2.2.23.zip';
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

/**
 * Stage the downloaded plugin zip in a temp directory and return the
 * full path. Throws RuntimeException (with the `phase 5:` prefix) on
 * write failure — an unwritable temp directory or a full disk that
 * would otherwise yield a misleading SHA256 mismatch on garbage or a
 * short write that quietly passes verification by accident.
 *
 * Exposed as a separate function so the failure path is unit-testable
 * with a caller-supplied $tmpDir; production callers pass
 * sys_get_temp_dir().
 */
function envlite_phase5_stage_temp_zip(string $tmpDir, string $bytes): string {
    $tmpZip = rtrim($tmpDir, '/\\') . '/envlite-sqlite-' . bin2hex(random_bytes(4)) . '.zip';
    $written = @file_put_contents($tmpZip, $bytes);
    if ($written === false || $written !== strlen($bytes)) {
        $detail = $written === false
            ? 'open failed'
            : 'short write (' . $written . '/' . strlen($bytes) . ')';
        throw new \RuntimeException("phase 5: temp-zip write to $tmpZip failed: $detail");
    }
    return $tmpZip;
}

/**
 * Capture a stable identity for the Phase 5 plugin path so a
 * before/after comparison can detect any change — including a
 * same-shape swap (e.g. one real directory replaced by another).
 * Returns null when nothing exists at the path; otherwise a string
 * derived from `lstat`'s inode + device numbers, which uniquely
 * identify a filesystem object on POSIX. On Windows NTFS lstat
 * exposes a usable file index; FAT-shaped filesystems may report
 * zero/duplicate inodes, which is an acceptable degradation for a
 * local-dev-only attack vector.
 *
 * @return string|null `<ino>:<dev>` for an existing entry, `null` if absent
 */
function envlite_phase5_path_signature(string $path): ?string {
    $stat = @lstat($path);
    if ($stat === false || !is_array($stat)) { return null; }
    $ino = $stat['ino'] ?? $stat[1] ?? 0;
    $dev = $stat['dev'] ?? $stat[0] ?? 0;
    return $ino . ':' . $dev;
}

/**
 * Run the at-commit-point part of Phase 5: TOCTOU re-check, clear,
 * extract. Separated from envlite_phase5_install so the guard is
 * unit-testable with a caller-supplied ZipArchive instead of having
 * to fetch + SHA-verify against the live wordpress.org pin.
 *
 * Contract:
 *   - The caller has already prompted (or honored --force), staged the
 *     verified zip, and opened the ZipArchive. $initialSignature is
 *     the path identity captured at scan time.
 *   - The caller holds the zip handle's lifetime; this function does
 *     NOT close it. (Closing belongs to the caller's `finally` so the
 *     archive is released before the temp zip is unlinked — important
 *     on Windows where an open archive locks the file.)
 *
 * Throws RuntimeException with the `phase 5:` prefix on either failure
 * mode (identity changed, extractTo failed). Both throws happen before
 * any subsequent caller-side state writes — manifest_save / state_save
 * run only after this returns cleanly.
 */
function envlite_phase5_apply_extract(
    string $repoRoot,
    string $pluginDir,
    ?string $initialSignature,
    \ZipArchive $zip
): void {
    // Re-check the plugin path identity. The ownership prompt fired
    // against the specific entry present at the initial scan; any
    // create/remove/swap during the fetch window invalidates that
    // consent.
    $currentSignature = envlite_phase5_path_signature($pluginDir);
    if ($currentSignature !== $initialSignature) {
        throw new \RuntimeException(
            "phase 5: plugin path $pluginDir changed identity during fetch; refusing to overwrite without re-prompt"
        );
    }
    // Strict pre-extract clear of any entry at the plugin path. The
    // helper re-stats after each removal to defend against silent
    // @unlink/rrmdir failure and TOCTOU.
    envlite_phase5_clear_plugin_blocker($pluginDir);
    // extractTo returns false on partial/failed extraction. Throwing
    // here means no subsequent state writes (manifest/state) record
    // the broken tree.
    $extracted = $zip->extractTo("$repoRoot/src/wp-content/plugins/");
    if ($extracted !== true) {
        throw new \RuntimeException("phase 5: ZipArchive::extractTo failed for $pluginDir");
    }
}

/**
 * Strict pre-extract cleanup for the Phase 5 plugin path. The caller
 * holds the user's consent (prompt approved or --force); this function
 * makes sure the path is **empty** before extractTo runs:
 *
 *   - Symlinks (any flavor) are unlinked. envlite never writes a symlink
 *     here; following one with extractTo could write the plugin tree
 *     anywhere on disk reachable via the link.
 *   - Non-directory entries (regular file, FIFO, socket) are unlinked
 *     so extractTo can create the directory at the path.
 *   - A real directory at the path is recursively removed via the
 *     symlink-aware envlite_rrmdir helper. Overlay-extract into an
 *     existing tree would let extractTo follow a pre-existing symlink
 *     inside the tree (e.g. `sqlite-database-integration/db.copy` →
 *     somewhere outside the checkout) and write through it. Clearing
 *     the tree first means extractTo always materializes a fresh
 *     directory whose contents come entirely from the verified zip.
 *     The user's consent (prompt or --force) applies to the whole
 *     tree replacement, which matches the "overwrite plugin tree"
 *     prompt wording.
 *
 * Re-stats AFTER each clearing attempt: a `@unlink` or rrmdir can fail
 * silently on permissions/RO mounts, and a TOCTOU race could swap an
 * entry between the caller's initial scan and now. If anything still
 * sits at the path, throw with the `phase 5:` prefix — extractTo
 * cannot proceed safely.
 */
function envlite_phase5_clear_plugin_blocker(string $pluginDir): void {
    if (is_link($pluginDir)) {
        @unlink($pluginDir);
    } elseif (is_dir($pluginDir)) {
        // rrmdir is symlink-aware: refuses to recurse through symlinks
        // at the top level (round 8) AND unlinks symlinks inside
        // rather than following them (existing behavior in
        // envlite_rrmdir's foreach).
        envlite_rrmdir($pluginDir);
    } elseif (file_exists($pluginDir)) {
        @unlink($pluginDir);
    }
    if (file_exists($pluginDir) || is_link($pluginDir)) {
        throw new \RuntimeException(
            "phase 5: could not clear $pluginDir before extract; refusing to extract"
        );
    }
}

/**
 * Drop the phase 5 pin SHA from state before re-entering the
 * download/extract path. Idempotent — no-op when the pin isn't recorded.
 *
 * Why this exists: extractTo writes the plugin tree incrementally and
 * recreates `db.copy` early in the unzip stream. A mid-extraction
 * failure can leave the alreadyInstalled check (manifest entry +
 * db.copy present + pin matches) satisfied on the next `up`, which
 * would then skip the re-download and run against a partial plugin
 * tree. Invalidating the pin up front and only re-recording on a
 * successful extract guarantees the next `up` re-attempts after any
 * failure, matching the npm/composer/build phases' pattern.
 */
function envlite_phase5_drop_recorded_pin(string $repoRoot): void {
    $state = envlite_state_load($repoRoot);
    if (!isset($state['phase5.recorded_pin_sha'])) { return; }
    unset($state['phase5.recorded_pin_sha']);
    envlite_state_save($repoRoot, $state);
}

function envlite_phase5_install(
    string $repoRoot,
    bool $force,
    bool $rebuild = false,
    ?callable $fetcher = null
): void {
    // $fetcher is dependency-injected so tests can exercise pre-extract
    // failure paths (HTTP throw, offline) without network. Production
    // callers pass null and get the real wordpress.org fetch.
    $fetcher = $fetcher ?? static function (): string {
        return envlite_http_get(ENVLITE_SQLITE_PLUGIN_URL);
    };

    $pluginDir = "$repoRoot/src/wp-content/plugins/sqlite-database-integration";
    $dbCopy    = "$pluginDir/db.copy";
    $dbPhpRel  = 'src/wp-content/db.php';
    $pluginRel = 'src/wp-content/plugins/sqlite-database-integration';
    $manifest  = envlite_manifest_load($repoRoot);
    $state     = envlite_state_load($repoRoot);

    // Step 1: skip download/extract if (a) plugin path is a REAL directory
    // (not a symlink or any other non-dir entry) recorded in the manifest,
    // (b) db.copy is present, AND (c) the recorded pin SHA matches the
    // current code literal. --rebuild bypasses the skip. envlite only ever
    // writes a real directory at the plugin path; anything else there is
    // external modification and must not satisfy the skip predicate.
    $pluginIsLink = is_link($pluginDir);
    $pluginIsRealDir = is_dir($pluginDir) && !$pluginIsLink;
    // Capture a stable identity for the TOCTOU re-check before the
    // clear pass (see comment around the re-stat assertion below).
    $initialSignature = envlite_phase5_path_signature($pluginDir);
    $pinMatches = ($state['phase5.recorded_pin_sha'] ?? null) === ENVLITE_SQLITE_PLUGIN_SHA256;
    $alreadyInstalled = !$rebuild
        && $pluginIsRealDir
        && isset($manifest[$pluginRel])
        && $manifest[$pluginRel] === 'dir'
        && is_file($dbCopy)
        && $pinMatches;
    if (!$alreadyInstalled) {
        // Steps 2-4: prompt before overwriting if anything other than
        // envlite's owned real-directory tree sits at the plugin path:
        //   - any symlink (always external; envlite never writes one)
        //   - any non-directory entry (regular file, FIFO, socket) —
        //     extractTo cannot create a directory through these, and
        //     envlite never writes them either
        //   - an unowned real directory (user-installed plugin)
        $somethingExists = file_exists($pluginDir) || $pluginIsLink;
        $isOurOwnedDir = $pluginIsRealDir && isset($manifest[$pluginRel]);
        if ($somethingExists && !$isOurOwnedDir) {
            envlite_prompt_or_abort($force, 'up', 'overwrite plugin tree', $pluginRel, null, null);
        }
        $bytes = $fetcher();
        $tmpZip = envlite_phase5_stage_temp_zip(sys_get_temp_dir(), $bytes);
        try {
            envlite_phase5_verify_sha256($tmpZip, ENVLITE_SQLITE_PLUGIN_SHA256);
            $zip = new \ZipArchive();
            if ($zip->open($tmpZip) !== true) {
                throw new \RuntimeException("ZipArchive::open failed: $tmpZip");
            }
            try {
                // Invalidate the recorded pin SHA *here*, immediately
                // before extractTo touches disk. Doing it earlier — before
                // fetch/SHA/zip-open — would invalidate the pin even when
                // the existing plugin tree was never modified (offline
                // re-run, transient HTTP failure, SHA mismatch on a
                // partial download).
                envlite_phase5_drop_recorded_pin($repoRoot);
                unset($state['phase5.recorded_pin_sha']);
                // TOCTOU re-check + clear + extract is delegated to a
                // helper so the guard is unit-testable without going
                // through the live wordpress.org fetch. See
                // envlite_phase5_apply_extract() for the full contract.
                envlite_phase5_apply_extract($repoRoot, $pluginDir, $initialSignature, $zip);
            } finally {
                // Always close the archive before the outer finally
                // unlinks the temp zip. On Windows an open archive locks
                // the underlying file and the unlink would fail, leaving
                // the downloaded zip in sys_get_temp_dir() forever.
                $zip->close();
            }
        } finally {
            @unlink($tmpZip);
        }
        $manifest[$pluginRel] = 'dir';
        envlite_manifest_save($repoRoot, $manifest);

        // Record the pin SHA so a subsequent code-level pin bump
        // re-triggers download/extract automatically.
        $state['phase5.recorded_pin_sha'] = ENVLITE_SQLITE_PLUGIN_SHA256;
        envlite_state_save($repoRoot, $state);
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
    [$exists, $current] = envlite_path_inspect($dbPhpAbs);
    $ownership = envlite_ownership($manifest, $dbPhpRel, $exists, $current);
    if ($ownership === 'owned_drifted') {
        $rec = $manifest[$dbPhpRel];
        $cur = $current !== null ? hash('sha256', $current) : null;
        envlite_prompt_or_abort($force, 'up', 'overwrite drifted file', $dbPhpRel, $rec, $cur);
    } elseif ($ownership === 'unowned') {
        envlite_prompt_or_abort($force, 'up', 'overwrite unowned file', $dbPhpRel, null, null);
    }
    $hash = envlite_atomic_write($dbPhpAbs, $dbBytes);
    $manifest[$dbPhpRel] = $hash;
    envlite_manifest_save($repoRoot, $manifest);

    // Step 6: tripwire.
    envlite_phase5_assert_placeholder($dbCopy);
}

function envlite_phase6_render(string $sample, string $phpBinary = PHP_BINARY): string {
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

    // Pin WP_PHP_BINARY to the PHP that ran envlite. The sample's bare
    // `'php'` is a PATH lookup, so PHPUnit's bootstrap (which shells out
    // to WP_PHP_BINARY for tests/phpunit/includes/install.php) would
    // otherwise use whatever `php` resolves on PATH — and that may not
    // be the build envlite preflight-checked (different SQLite, missing
    // extensions, wrong version).
    $samplePhpBinary = "define( 'WP_PHP_BINARY', 'php' );";
    if (substr_count($out, $samplePhpBinary) !== 1) {
        throw new \RuntimeException(
            "phase 6: WP_PHP_BINARY sample literal not found exactly once; envlite assumption broken"
        );
    }
    // PHPUnit's bootstrap builds the install command as
    //   system( WP_PHP_BINARY . ' ' . escapeshellarg($config) . ... )
    // — it escapes the args but NOT WP_PHP_BINARY. If PHP_BINARY contains
    // spaces or shell metacharacters (Windows `C:\Program Files\...`),
    // the shell splits it and `install.php` fails. escapeshellarg here
    // produces a shell-safe single argument; var_export then escapes
    // that for embedding as a PHP literal.
    $out = str_replace(
        $samplePhpBinary,
        "define( 'WP_PHP_BINARY', " . var_export(escapeshellarg($phpBinary), true) . " );",
        $out
    );

    if (preg_match("/define\\s*\\(\\s*['\"]DB_FILE['\"]/", $out)) {
        throw new \RuntimeException(
            "phase 6: DB_FILE already defined in wp-tests-config-sample.php; envlite assumption broken"
        );
    }
    if (substr($out, -1) !== "\n") {
        $out .= "\n";
    }
    $out .= "define( 'DB_FILE', '.ht.test.sqlite' );\n";
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
    [$exists, $current] = envlite_path_inspect($outAbs);
    $ownership = envlite_ownership($manifest, $outRel, $exists, $current);
    if ($ownership === 'owned_drifted') {
        $cur = $current !== null ? hash('sha256', $current) : null;
        envlite_prompt_or_abort($force, 'up', 'overwrite drifted file', $outRel, $manifest[$outRel], $cur);
    } elseif ($ownership === 'unowned') {
        envlite_prompt_or_abort($force, 'up', 'overwrite unowned file', $outRel, null, null);
    }
    $hash = envlite_atomic_write($outAbs, $rendered);
    $manifest[$outRel] = $hash;
    envlite_manifest_save($repoRoot, $manifest);
}

const ENVLITE_SALT_URL = 'https://api.wordpress.org/secret-key/1.1/salt/';

function envlite_phase7_render(string $sample, int $port, ?string $saltsBlock): string {
    // wp-config-sample.php ships with CRLF line endings in tree. envlite
    // injects LF-only lines (WP_HOME/WP_SITEURL, the salts block); without
    // normalization the rendered output would be a mix of CRLF and LF, which
    // makes envlite's recorded hash sensitive to how the user's git client
    // chose to check out the sample. Normalize once up front so the output
    // is LF-only and the hash is portable.
    $sample = str_replace("\r\n", "\n", $sample);

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
    // The salts API returns random bytes that can include `$` and `\`; using
    // them as preg_replace's replacement argument would let sequences like
    // `$1` or `\1` be interpreted as backreferences and silently corrupt the
    // saved salts. Use a callback so the block is inserted as a literal.
    //
    // The regex matches each define line for the eight known keys, in order,
    // separated only by whitespace (`\s+`). An earlier `.*?` form spanned
    // any intermediate content — so if upstream ever inserts an extra line
    // (a comment, another define) between two salt defines, the replacement
    // would silently delete that intervening content. The tighter regex
    // refuses to match against a reshaped block, and the count assertion
    // below turns that into an abort with a clear message.
    if ($saltsBlock !== null) {
        $saltKeys = [
            'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
            'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT',
        ];
        $parts = array_map(
            static fn(string $k): string => "define\\(\\s*'" . $k . "',\\s*'[^']*'\\s*\\);",
            $saltKeys
        );
        $pattern = '/' . implode('\\s+', $parts) . '/';
        $count = preg_match_all($pattern, $cfg, $m);
        if ($count !== 1) {
            throw new \RuntimeException(
                "phase 7: expected exactly one contiguous AUTH_KEY..NONCE_SALT block, found $count"
                . " (upstream sample may have inserted content between the salt defines)"
            );
        }
        $cfg = preg_replace_callback(
            $pattern,
            static function () use ($saltsBlock) { return $saltsBlock; },
            $cfg,
            1
        );
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
        envlite_log('up', "phase 7: salt fetch failed: " . $e->getMessage() . " (continuing with sample placeholders)");
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
    [$exists, $current] = envlite_path_inspect($outAbs);
    $ownership = envlite_ownership($manifest, $outRel, $exists, $current);
    if ($ownership === 'owned_drifted') {
        $cur = $current !== null ? hash('sha256', $current) : null;
        envlite_prompt_or_abort($force, 'up', 'overwrite drifted file', $outRel, $manifest[$outRel], $cur);
    } elseif ($ownership === 'unowned') {
        envlite_prompt_or_abort($force, 'up', 'overwrite unowned file', $outRel, null, null);
    }
    $hash = envlite_atomic_write($outAbs, $rendered);
    $manifest[$outRel] = $hash;
    envlite_manifest_save($repoRoot, $manifest);
}

/**
 * Phase 8 — bootstrap WP and run wp_install if not already installed.
 *
 * Runs in a fresh `php` subprocess. The script is piped via stdin (no
 * second committed asset to ship alongside router.php). Subprocess
 * isolation keeps wp-settings.php's many side effects (constants,
 * autoloaders, shutdown handlers, wp_die) from corrupting envlite's
 * own process or its exit semantics.
 */
function envlite_phase8_install_site(string $repoRoot, int $port): void {
    // Nowdoc — no $variable expansion in the template; values are
    // substituted via strtr() with var_export()'d literals so a path
    // with quotes/spaces can't break the script.
    $tmpl = <<<'PHP'
<?php
// envlite Phase 8 — site install. Loaded into a fresh `php` process via stdin.
$repo_root = __REPO_ROOT__;
$port      = __PORT__;

$_SERVER['HTTP_HOST']      = "127.0.0.1:$port";
$_SERVER['SERVER_NAME']    = '127.0.0.1';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

if (!defined('WP_INSTALLING')) {
    define('WP_INSTALLING', true);
}

require_once "$repo_root/src/wp-load.php";
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

if (is_blog_installed()) {
    exit(0);
}

$result = wp_install(
    'WordPress Develop Envlite',
    'admin',
    'admin@example.com',
    false,
    '',
    'password'
);
if (empty($result['user_id'])) {
    fwrite(STDERR, "wp_install returned no user_id\n");
    exit(1);
}
exit(0);
PHP;

    $script = strtr($tmpl, [
        '__REPO_ROOT__' => var_export($repoRoot, true),
        '__PORT__'      => (string) $port,
    ]);

    $proc = envlite_proc_open(
        [PHP_BINARY],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $repoRoot
    );
    if (!is_resource($proc)) {
        throw new \RuntimeException('failed to spawn php subprocess');
    }
    fwrite($pipes[0], $script);
    fclose($pipes[0]);
    [$stdout, $stderr] = envlite_drain_two_pipes($pipes[1], $pipes[2]);
    $exit = proc_close($proc);

    if ($exit !== 0) {
        $msg = trim($stderr !== '' ? $stderr : ($stdout ?: ''));
        $first = $msg === '' ? "exit $exit" : strtok($msg, "\n");
        throw new \RuntimeException("install subprocess: $first");
    }
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
    if ($sub === '--version' || $sub === '-V') {
        echo ENVLITE_VERSION . "\n";
        return 0;
    }
    if ($sub === 'up')    { return envlite_cmd_up($args, $force); }
    if ($sub === 'clean') { return envlite_cmd_clean($args, $force); }

    envlite_log(null, "unknown subcommand: $sub");
    return 2;
}

function envlite_cmd_up(array $args, bool $force): int {
    $port = null;
    $noBuild = false;
    $noServe = false;
    $rebuild = false;
    foreach ($args as $a) {
        if ($a === '--no-build') { $noBuild = true; continue; }
        if ($a === '--no-serve') { $noServe = true; continue; }
        if ($a === '--rebuild')  { $rebuild = true; continue; }
        if (preg_match('/^--port=(\d+)$/', $a, $m)) {
            $port = (int) $m[1];
            if ($port < 1 || $port > 65535) {
                envlite_log('up', "invalid --port value: $a");
                return 2;
            }
            continue;
        }
        envlite_log('up', "unknown argument: $a");
        return 2;
    }

    $repoRoot = getcwd();

    envlite_phase0_run($repoRoot);

    fwrite(STDERR, "envlite up: getting ready\u{2026}\n");

    // Phase 1 may write `.cache/envlite/port` and update the manifest, and
    // envlite_atomic_write throws RuntimeException on a failed temp-file
    // write or rename (permissions, full disk, read-only checkout). Wrap
    // the call so those surface as the documented `envlite up: phase 1: ...`
    // line + exit 1 rather than escaping as an uncaught PHP error.
    $resolvedPort = 0;
    $rc = envlite_phase_guard('up', 1, function () use ($repoRoot, $port, &$resolvedPort) {
        $resolvedPort = envlite_phase1_discover_port($repoRoot, $port);
    });
    if ($rc !== 0) { return $rc; }

    // Persist the .ht.sqlite observation now that Phase 1 has succeeded
    // and we are committed to running setup. The spec's bind-failure
    // contract is "no manifest mutation occurs", and observation runs
    // BEFORE phase 1 used to violate that: `--port=N` with N already
    // bound (or auto-discovery with no free port in the pool) exited 1
    // only AFTER the manifest had already gained the DB entry.
    // Phase 1's own state writes (`.cache/envlite/port`, manifest entry)
    // happen only on a successful bind probe, so doing the observation
    // here keeps the contract consistent. envlite_manifest_save can
    // still throw on a failed atomic write (read-only cache dir, full
    // disk); surface that as the documented envlite error line + exit 1
    // rather than letting it escape as an uncaught PHP error.
    try {
        envlite_observe_ht_sqlite($repoRoot, true);
    } catch (\Throwable $e) {
        envlite_log('up', 'observe .ht.sqlite: ' . $e->getMessage());
        return 1;
    }

    // Phases 2 & 4 in parallel (composer install || npm ci), with skip+record.
    // Pass a string label here — the spec has no "phase 24"; the parallel
    // pair maps to phases 2 and 4. envlite_phase24_parallel's own throws
    // already carry `phase 2: ...` / `phase 4: ...` / `phases 2 and 4: ...`
    // prefixes; this label only kicks in for unprefixed throws (proc_open
    // spawn failure, state-file write failure before the jobs start).
    $phase24 = ['phase2_skipped' => false, 'phase4_skipped' => false];
    $rc = envlite_phase_guard('up', 'phases 2 and 4', function () use ($repoRoot, $rebuild, &$phase24) {
        $phase24 = envlite_phase24_parallel($repoRoot, $rebuild);
    });
    if ($rc !== 0) { return $rc; }

    // Phase 3 (build:dev), serial after the parallel pair.
    $rc = envlite_phase_guard('up', 3, function () use ($repoRoot, $rebuild, $noBuild, $phase24) {
        envlite_phase3_build_dev(
            $repoRoot,
            $rebuild,
            $noBuild,
            $phase24['phase2_skipped'],
            $phase24['phase4_skipped']
        );
    });
    if ($rc !== 0) { return $rc; }

    $phases = [
        [5, function () use ($repoRoot, $force, $rebuild) { envlite_phase5_install($repoRoot, $force, $rebuild); }],
        [6, function () use ($repoRoot, $force) { envlite_phase6_install($repoRoot, $force); }],
        [7, function () use ($repoRoot, $resolvedPort, $force) { envlite_phase7_install($repoRoot, $resolvedPort, $force); }],
        [8, function () use ($repoRoot, $resolvedPort) { envlite_phase8_install_site($repoRoot, $resolvedPort); }],
    ];
    foreach ($phases as [$n, $fn]) {
        $rc = envlite_phase_guard('up', $n, $fn);
        if ($rc !== 0) { return $rc; }
    }

    // Re-observe `.ht.sqlite` now that Phase 8 has triggered its creation.
    // On a fresh checkout the DB didn't exist at the start-of-up observation
    // point, so the manifest didn't yet record it. The spec's final-state
    // contract requires the live DB to be envlite-tracked content after a
    // successful up — without this second pass, the first run leaves the
    // file orphan (not in the manifest) and a later clean wouldn't prompt.
    // Persist mode so the ownership carries across runs.
    try {
        envlite_observe_ht_sqlite($repoRoot, true);
    } catch (\Throwable $e) {
        envlite_log('up', 'observe .ht.sqlite (post-phase-8): ' . $e->getMessage());
        return 1;
    }

    if ($noServe) {
        fwrite(STDERR, "envlite up: environment ready (--no-serve; not starting dev server)\n");
        return 0;
    }

    if (!envlite_phase1_port_is_free($resolvedPort)) {
        envlite_log('up', "failed to bind 127.0.0.1:$resolvedPort");
        return 1;
    }

    fwrite(STDERR, "envlite up: environment ready, starting dev server on http://127.0.0.1:$resolvedPort/ (admin / password)\n");
    // Hand off to the dev-server launcher. pcntl on Unix means this function
    // never returns on success; the line above is the last thing envlite
    // itself prints.
    return envlite_run_dev_server($repoRoot, $resolvedPort);
}

/**
 * Wrap a phase's execution in a single try/catch that turns any throw
 * into the documented one-line `envlite <sub>: <prefix>: <message>` form
 * plus exit code 1.
 *
 * @param int|string $label Phase label. An int produces "phase <n>:";
 *                          a string is used verbatim as the prefix (e.g.
 *                          "phases 2 and 4" for the parallel install pair,
 *                          which has no single phase number in the spec).
 *                          The label is dropped when the thrown message
 *                          already starts with `phase`/`phases`, so inner
 *                          code that names its own phase wins.
 */
function envlite_phase_guard(string $sub, $label, callable $fn): int {
    try {
        $fn();
        return 0;
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        // If the exception message already starts with "phase N:" or
        // "phases N and M:" — i.e. the inner code already named its phase —
        // pass it through unchanged. Otherwise prepend the caller-supplied
        // label.
        if (!preg_match('/^phases? \d/', $msg)) {
            $prefix = is_int($label) ? "phase $label" : (string) $label;
            $msg = "$prefix: $msg";
        }
        envlite_log($sub, $msg);
        return 1;
    }
}

/**
 * Record the live `.ht.sqlite` as envlite-tracked content when present
 * and not already known. The augmented manifest is returned in every
 * case so callers that need the in-memory view get it.
 *
 * $persist=true (up): writes the augmented manifest to disk so the
 * observation survives across runs. envlite owns the DB from this point
 * on, and subsequent re-runs treat it as such for drift/ownership.
 *
 * $persist=false (clean): leaves the on-disk manifest untouched. The
 * spec calls this recording transient — it exists only so the file
 * appears in *this* clean invocation's prompt list. If the user
 * declines the prompt (or exits non-interactively), the manifest must
 * remain as it was on disk before clean started, or the next `up`
 * would treat the DB as envlite-owned content that envlite never wrote.
 */
function envlite_observe_ht_sqlite(string $repoRoot, bool $persist): array {
    $rel = 'src/wp-content/database/.ht.sqlite';
    $abs = "$repoRoot/$rel";
    $manifest = envlite_manifest_load($repoRoot);
    if (!is_file($abs) || isset($manifest[$rel])) { return $manifest; }
    // hash_file streams the file in fixed-size chunks; using
    // file_get_contents + hash('sha256', $bytes) would load the entire
    // SQLite DB into memory and can exceed PHP's CLI memory_limit on a
    // content-heavy dev install (millions of posts, media uploads
    // streamed through WP imports). Returns false on read failure —
    // treat that the same way we used to treat file_get_contents
    // returning false: leave the file unrecorded so clean classifies it
    // as user-owned (the correct outcome when envlite can't see the
    // content authoritatively).
    $hash = @hash_file('sha256', $abs);
    if ($hash === false) { return $manifest; }
    $manifest[$rel] = $hash;
    if ($persist) {
        envlite_manifest_save($repoRoot, $manifest);
    }
    return $manifest;
}

function envlite_cmd_clean(array $args, bool $force): int {
    if (!empty($args)) {
        envlite_log('clean', 'unexpected arguments: ' . implode(' ', $args));
        return 2;
    }
    $repoRoot = getcwd();
    $stateDir = "$repoRoot/.cache/envlite";
    // Four shapes the state path can take:
    //   a) Nothing there → "nothing to clean" success.
    //   b) Real directory → walk the manifest, clean, rrmdir.
    //   c) Symlink to a directory → walk the manifest (reads through the
    //      symlink), clean. Final rrmdir unlinks the symlink only, per
    //      the symlink-aware top-level rule (round 8); the target's own
    //      residual state files survive but are no longer linked to.
    //   d) Non-directory entry (regular file, FIFO, broken symlink,
    //      symlink-to-file) → no manifest can live here. Just unlink
    //      the blocker so the next `up` can recreate the state dir.
    //
    // The flag of interest is `is_dir($stateDir)`: true for (b) and (c),
    // false for (d) and (a). is_link distinguishes (c) from (b) but the
    // manifest walk handles both the same way.
    if (!is_dir($stateDir) && !is_link($stateDir) && !file_exists($stateDir)) {
        envlite_log('clean', 'nothing to clean (no .cache/envlite/ directory)');
        return 0;
    }
    if (!is_dir($stateDir)) {
        // Case (d): no walkable manifest, just clear the blocker.
        if (!@unlink($stateDir)) {
            envlite_log('clean', "could not remove non-directory at .cache/envlite/");
            return 1;
        }
        envlite_log('clean', 'removed non-directory blocker at .cache/envlite/');
        return 0;
    }
    // Cases (b) and (c) fall through to the manifest walk below.

    // Transient observation: the .ht.sqlite entry exists only so the file
    // appears in this clean invocation's prompt. If the user declines or
    // the prompt aborts non-interactively, the on-disk manifest must
    // remain unchanged — otherwise a subsequent `up` would see the DB as
    // envlite-tracked content it never wrote.
    //
    // envlite_manifest_load (called transitively here) throws when the
    // manifest exists but cannot be read. Surface that as a clean error
    // rather than letting it escape as an uncaught PHP error — and
    // refuse to proceed: walking an empty list would orphan every
    // envlite-managed file the unreadable manifest tracked.
    try {
        $manifest = envlite_observe_ht_sqlite($repoRoot, false);
    } catch (\Throwable $e) {
        envlite_log('clean', $e->getMessage());
        return 1;
    }
    $paths = envlite_clean_collect($manifest);

    $failed = [];
    if (empty($paths)) {
        envlite_log('clean', 'manifest is empty; removing .cache/envlite/ only');
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
        $failed = envlite_clean_apply($repoRoot, $paths);
    }

    if (!empty($failed)) {
        // Keep manifest and state intact so the user can retry. Wiping them
        // here would orphan the still-present files — clean would no longer
        // know they were envlite-owned.
        foreach ($failed as $rel) {
            envlite_log('clean', "could not remove: $rel");
        }
        envlite_log('clean', count($failed) . ' path(s) remain; manifest and state preserved for retry');
        return 1;
    }

    // Remove .cache/envlite/ recursively. envlite owns the entire
    // directory per the state-directory contract, so an unconditional
    // rrmdir is correct. The previous explicit-unlink-then-rmdir form
    // only knew about manifest/port/state, so a leftover `.tmp` sibling
    // from an interrupted atomic write would survive: rmdir would
    // silently fail, clean would return 0, and the next clean would
    // be a no-op against an empty manifest while the directory persists.
    envlite_rrmdir("$repoRoot/.cache/envlite");
    if (is_dir("$repoRoot/.cache/envlite")) {
        envlite_log('clean', 'could not remove .cache/envlite/');
        return 1;
    }
    return 0;
}

/** Pure: returns paths in reverse insertion order. */
function envlite_clean_collect(array $manifest): array {
    return array_reverse(array_keys($manifest));
}

/**
 * I/O: deletes each path. Must be called after the prompt has been resolved.
 *
 * Returns a list of manifest paths that could not be fully removed (file
 * still on disk, or directory non-empty after rrmdir). Callers should
 * treat a non-empty list as a clean failure and preserve manifest/state
 * so the user can retry.
 *
 * @return string[] manifest-relative paths that remained after the attempt
 */
function envlite_clean_apply(string $repoRoot, array $paths): array {
    $canonicalRoot = @realpath($repoRoot);
    if ($canonicalRoot === false) {
        // Without a canonical root we can't enforce the containment
        // check below. Fail every entry rather than risk recursing
        // outside the checkout.
        return $paths;
    }
    // Normalize separators before the prefix comparison. realpath on
    // Windows returns backslash-separated paths; the root prefix would
    // otherwise end in `/` while the resolved entries used `\`, and
    // the `strpos starts-with` check would treat every legitimate
    // entry as escaping the checkout — `clean` would fail on Windows
    // for normal output paths like `C:\repo\src\wp-config.php`.
    $rootPrefix = envlite_path_to_forward_slashes($canonicalRoot);
    $rootPrefix = rtrim($rootPrefix, '/') . '/';

    // The state directory itself may be a symlink to a target outside
    // the checkout — a spec-supported user setup (redirected state to
    // a faster filesystem). Manifest entries under `.cache/envlite/*`
    // (Phase 1's `port`, plus the manifest itself) then resolve via
    // the symlink to outside repoRoot. The containment check needs an
    // exception for those: they're allowed inside the (resolved) state
    // dir regardless of where it points, but nowhere else.
    $stateDir = "$repoRoot/.cache/envlite";
    $stateDirCanonical = @realpath($stateDir);
    $stateDirPrefix = null;
    if ($stateDirCanonical !== false) {
        $stateDirPrefix = envlite_path_to_forward_slashes($stateDirCanonical);
        $stateDirPrefix = rtrim($stateDirPrefix, '/') . '/';
    }

    $failed = [];
    foreach ($paths as $rel) {
        $abs = "$repoRoot/$rel";
        // Use the same "anything is here" predicate the ownership path uses:
        // file_exists follows symlinks (returns false for broken symlinks),
        // so a manifest entry replaced by a dangling symlink would slip past
        // a `!file_exists && !is_dir` check, leaving the symlink behind as
        // an orphan once the manifest itself is wiped by clean. is_link
        // catches that case.
        if (!file_exists($abs) && !is_dir($abs) && !is_link($abs)) { continue; }
        // Ancestor-symlink defense: a manifest entry whose ancestor has
        // been swapped for a symlink (e.g. `src/wp-content/plugins` →
        // `/tmp/shared-plugins`) would let envlite_rrmdir follow the
        // link and recursively delete files outside the checkout — the
        // round-8 top-level is_link guard only protects the leaf
        // component. Resolve the FULL path and refuse if it escapes
        // the canonical repo root. realpath of a symlink resolves
        // through every intermediate component, so anything pointed
        // outside the checkout falls out cleanly. Broken symlinks have
        // no resolved path; for those, fall back to using the literal
        // join (the leaf is the symlink itself, which we unlink — no
        // recursion possible).
        $canonicalAbs = is_link($abs) && !file_exists($abs)
            ? null  // broken symlink, unlinkable directly
            : @realpath($abs);
        if ($canonicalAbs !== null && $canonicalAbs !== false) {
            $canonicalAbsNorm = envlite_path_to_forward_slashes($canonicalAbs);
            $insideRepo = strpos($canonicalAbsNorm . '/', $rootPrefix) === 0;
            $insideState = $stateDirPrefix !== null
                && strpos($canonicalAbsNorm . '/', $stateDirPrefix) === 0;
            if (!$insideRepo && !$insideState) {
                // Resolved path escapes both the checkout AND the
                // (possibly-symlinked) state directory. Refuse to touch
                // it. Record as failed so the caller preserves the
                // manifest and the user can investigate.
                $failed[] = $rel;
                continue;
            }
        }
        if (is_dir($abs) && !is_link($abs)) {
            envlite_rrmdir($abs);
        } else {
            @unlink($abs);
        }
        if (file_exists($abs) || is_dir($abs) || is_link($abs)) {
            $failed[] = $rel;
        }
    }
    return $failed;
}

/**
 * Cross-platform normalizer: rewrite `\` to `/` regardless of host OS.
 * Used by clean's containment check to compare paths produced by
 * `realpath()` (which returns OS-native separators) against a fixed
 * forward-slash convention. Distinct from envlite_path_to_posix(),
 * which preserves `\` on Unix because the character is a legal
 * filename byte there — that function operates on USER-supplied paths,
 * while this one operates on realpath()'d strings that already include
 * any necessary canonicalization.
 */
function envlite_path_to_forward_slashes(string $path): string {
    return str_replace('\\', '/', $path);
}

function envlite_rrmdir(string $dir): void {
    // Refuse to recurse through a symlinked directory at the top level.
    // If the caller passed a path that is itself a symlink to a directory,
    // scandir would happily follow it and the foreach below would delete
    // the target's contents — files entirely outside envlite-owned state.
    // Unlink the symlink itself instead and leave the target alone.
    // The recursive descent below already protects against symlinked
    // sub-entries via the `!is_link($path)` guard.
    if (is_link($dir)) {
        @unlink($dir);
        return;
    }
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

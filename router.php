<?php
$rawPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// php -S decodes percent-encoding before mapping a URL to a file, so the
// router must decode too before its own filesystem and .ht checks. Without
// this, (a) uploads like /wp-content/uploads/my%20photo.jpg would miss
// `my photo.jpg` on disk and fall through to WordPress (404), and (b) a
// probe like /%2Eht.sqlite for the SQLite drop-in's data file would
// bypass the .ht block on the raw URI and get served by php -S as
// `.ht.sqlite`. Decode once and apply both checks against the result.
$path = rawurldecode($rawPath);

// Normalize backslashes to forward slashes before the .ht and filesystem
// checks. PHP's file APIs treat `\` as a path separator on Windows, so a
// decoded `%5C` would otherwise let `/wp-content/database\.ht.sqlite`
// slip past the `(^|/)\.ht` segment regex while `file_exists($docroot .
// $path)` still resolves to the real DB file — the router would then
// return false and php -S would serve `.ht.sqlite`. Normalize once and
// run every subsequent check against forward-slash-only paths. Harmless
// on Unix where filenames may legitimately contain `\` — uploads with
// literal backslashes are pathological in WordPress and not supported.
$path = str_replace('\\', '/', $path);

// Reject NUL bytes immediately. A request like `/%00` decodes to a path
// containing a literal NUL; PHP 8+ filesystem APIs throw ValueError when
// any argument contains NUL, so the file_exists call below would fatal
// the router (response: blank 500) instead of returning a controlled
// status. PHP 7.4 silently truncates at NUL, which is also wrong. A
// 400 here is the right shape for "malformed URL" — no real client
// sends NUL in a path on purpose.
if (strpos($path, "\0") !== false) {
    http_response_code(400);
    return true;
}

// php -S does not honor Apache .ht* deny rules. Block any segment so the
// SQLite DB at wp-content/database/.ht.sqlite is not downloadable. The
// match is case-insensitive: macOS and Windows ship case-insensitive
// filesystems by default, so a request for `/wp-content/database/.HT.sqlite`
// would otherwise be resolved to the same DB file and served.
if (preg_match('#(^|/)\.ht#i', $path)) {
    http_response_code(403);
    return true;
}

// DOCUMENT_ROOT is the absolute resolution of php -S's -t flag. Using it
// instead of a path computed from __DIR__ lets the router live outside the
// target repo (e.g. envlite invoked from a different checkout).
$docroot = $_SERVER['DOCUMENT_ROOT'];
$file = $docroot . $path;

if ($path !== '/' && file_exists($file)) {
    if (!is_dir($file)) {
        return false;
    }
    // Bare directory URL: 301 to the trailing-slash form so relative links
    // on the served page resolve against the directory, not its parent.
    // Without this, php -S serves wp-admin/index.php for a request to
    // `/wp-admin` while the address bar stays slashless. The dashboard's
    // sidebar uses relative links (`<a href='plugins.php'>`) which then
    // resolve to `/plugins.php` (sibling of `/wp-admin`) — that misses
    // the file on disk, falls through to WordPress, and redirect_canonical()
    // 301s it to `/plugins.php/` which the front controller serves as the
    // home page. The 301 is browser-cached, so the broken state sticks
    // across reloads. Apache mod_dir (DirectorySlash) and nginx both 301
    // bare directory URLs the same way; this restores that contract.
    // Built from the raw path so percent-encoding round-trips unchanged.
    if (substr($path, -1) !== '/') {
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        header('Location: ' . $rawPath . '/' . ($qs !== '' ? '?' . $qs : ''), true, 301);
        return true;
    }
    // Existing directory with trailing slash: let the built-in server
    // serve its index.php (e.g. /wp-admin/ -> wp-admin/index.php).
    // Without an index, fall through to the front controller to avoid
    // directory listings.
    if (file_exists(rtrim($file, '/') . '/index.php')) {
        return false;
    }
}

// Walk-back probe for trailing-slash-on-file and PATH_INFO-style URLs.
// POSIX stat() rejects a trailing slash on a regular file with ENOTDIR,
// so `file_exists('/wp-admin/plugins.php/')` is false even though the
// underlying file exists. Without this probe the request falls through
// to the WP front controller, which renders the home page — the
// symptom users see after their browser caches a 301 from
// redirect_canonical() that appended a slash. php -S would resolve
// these URLs natively (see sapi/cli/php_cli_server.c ~L1443) by
// walking backward through the URL until it finds a regular file,
// then running it with SCRIPT_NAME/PATH_INFO split at the boundary —
// but only if the router returns false. We mirror that walk-back here
// so php -S takes over for any URL where an ancestor is a real file.
if (!file_exists($file)) {
    $probe = rtrim($path, '/');
    while ($probe !== '') {
        $probeFile = $docroot . $probe;
        if (file_exists($probeFile)) {
            if (is_file($probeFile)) {
                return false;
            }
            // An ancestor exists but is a directory (e.g. /wp-admin/
            // for /wp-admin/missing.php). PHP CLI server would 404 here;
            // we instead fall through to the front controller so WP can
            // serve its own 404 (or rewrite to a real handler).
            break;
        }
        $cut = strrpos($probe, '/');
        if ($cut === false || $cut === 0) {
            break;
        }
        $probe = substr($probe, 0, $cut);
    }
}

require $docroot . '/index.php';

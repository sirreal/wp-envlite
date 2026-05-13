<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// php -S does not honor Apache .ht* deny rules. Block any segment so the
// SQLite DB at wp-content/database/.ht.sqlite is not downloadable.
if (preg_match('#(^|/)\.ht#', $path)) {
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
    // Existing directory: let the built-in server serve its index.php
    // (e.g. /wp-admin/ -> wp-admin/index.php). Without an index, fall
    // through to the front controller to avoid directory listings.
    if (file_exists(rtrim($file, '/') . '/index.php')) {
        return false;
    }
}

require $docroot . '/index.php';

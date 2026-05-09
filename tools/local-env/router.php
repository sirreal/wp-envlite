<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// php -S does not honor Apache .ht* deny rules. Block any segment so the
// SQLite DB at wp-content/database/.ht.sqlite is not downloadable.
if (preg_match('#(^|/)\.ht#', $path)) {
    http_response_code(403);
    return true;
}

$file = dirname(__DIR__, 2) . '/src' . $path;
if ($path !== '/' && file_exists($file) && !is_dir($file)) {
    return false;
}
require dirname(__DIR__, 2) . '/src/index.php';

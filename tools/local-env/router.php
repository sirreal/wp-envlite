<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = dirname(__DIR__, 2) . '/src' . $path;
if ($path !== '/' && file_exists($file) && !is_dir($file)) {
    return false;
}
require dirname(__DIR__, 2) . '/src/index.php';

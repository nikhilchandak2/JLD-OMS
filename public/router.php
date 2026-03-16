<?php
/**
 * Router for PHP built-in web server (local development).
 * Serves existing files (e.g. /assets/...) and forwards everything else to index.php.
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($uri !== '/' && $uri !== '' && file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    return false; // serve static file
}
require __DIR__ . '/index.php';

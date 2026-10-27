<?php

declare(strict_types=1);

/*
 * Router for `php -S`, used only by the browser end-to-end tests.
 *
 * `php -S -t public` serves files and runs PHP, but it has no rewrite: an unknown path is a 404
 * from the built-in server rather than from the application. This router gives it the same rule
 * Apache has through `.htaccess`: a real file is served, everything else goes to `public/index.php`.
 */

$root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Static files (CSS, uploads, favicon) are served as themselves.
$file = realpath($root . $path);

if ($file !== false && is_file($file) && str_starts_with($file, realpath($root))) {
    return false;
}

// Everything else is one request to the application, exactly as Apache would deliver it.
require $root . DIRECTORY_SEPARATOR . 'index.php';

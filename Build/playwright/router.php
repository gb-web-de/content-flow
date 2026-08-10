<?php

declare(strict_types=1);

/*
 * Router for PHP's built-in web server, used by the Playwright job.
 *
 * The built-in server resolves a request to a file or returns 404 - it has no
 * rewrite rules, so every TYPO3 route (/typo3/..., every frontend page) has to
 * be handed to the front controller explicitly. Returning false lets the server
 * deliver a real file (assets, fileadmin) itself.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$documentRoot = $_SERVER['DOCUMENT_ROOT'];

if (is_string($path) && $path !== '/' && is_file($documentRoot . $path)) {
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $documentRoot . '/index.php';

require $documentRoot . '/index.php';

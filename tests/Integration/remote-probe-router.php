<?php

declare(strict_types=1);

/*
 * Router for the SPEC-043 listener (`php -S … remote-probe-router.php`).
 *
 * Logs every request as one line, METHOD PATH, to REMOTE_PROBE_LOG. Under
 * /manifest-… it serves the bytes of REMOTE_PROBE_STORE as a C2PA manifest
 * store, so a service that does fetch gets a real store back (AC6); every
 * other path is 404.
 */
$log = getenv('REMOTE_PROBE_LOG');
$method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : '?';
$uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '?';

if (is_string($log) && $log !== '') {
    file_put_contents($log, $method.' '.$uri."\n", FILE_APPEND | LOCK_EX);
}

$store = getenv('REMOTE_PROBE_STORE');

if (str_starts_with($uri, '/manifest-') && is_string($store) && is_file($store)) {
    header('Content-Type: application/c2pa');
    readfile($store);

    return true;
}

http_response_code(404);

return true;

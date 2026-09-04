<?php
/**
 * Locates the non-public application directory and boots it.
 *
 * On the server, CI writes `_app_root.php` next to this file containing the
 * absolute path to ~/app (or ~/app-staging). That indirection is what lets the
 * identical code run in production and staging without editing paths.
 *
 * Locally there is no generated file, so it falls back to the repo layout
 * (backend/public/.. == backend/) and everything just works with `php -S`.
 *
 * Both this file and _app_root.php are blocked by .htaccess.
 */
declare(strict_types=1);

$appRoot = is_file(__DIR__ . '/_app_root.php')
    ? (string) require __DIR__ . '/_app_root.php'
    : dirname(__DIR__);

$bootstrap = rtrim($appRoot, '/') . '/src/bootstrap.php';

if (!is_file($bootstrap)) {
    http_response_code(500);
    header('Content-Type: application/json');
    // Do not echo the path: it would disclose the server's directory layout.
    echo json_encode(['error' => 'Application is not configured correctly.']);
    error_log('Diwan: bootstrap not found at ' . $bootstrap);
    exit(1);
}

require $bootstrap;

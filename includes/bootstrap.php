<?php
/**
 * Loaded at the top of every page. Sets up config, error handling, session, DB and auth.
 */
define('APP_ROOT', dirname(__DIR__));
define('STORAGE_PATH', APP_ROOT . '/storage');

$GLOBALS['config'] = require APP_ROOT . '/config/config.php';
if (is_file(APP_ROOT . '/config/config.local.php')) {
    $GLOBALS['config'] = array_replace_recursive($GLOBALS['config'], require APP_ROOT . '/config/config.local.php');
}

date_default_timezone_set($GLOBALS['config']['timezone']);
error_reporting(E_ALL);
ini_set('display_errors', $GLOBALS['config']['debug'] ? '1' : '0');

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

if (PHP_SAPI !== 'cli') {
    // Baseline security headers
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; form-action 'self'");

    if (!defined('SKIP_INSTALL_CHECK') && !is_file(STORAGE_PATH . '/installed.lock')) {
        redirect('install.php');
    }
    start_secure_session();
}

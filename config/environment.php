<?php
if (defined('APP_ENVIRONMENT')) {
    return;
}
date_default_timezone_set('Asia/Bangkok');
$environment = 'development';
if (!in_array($environment, ['production', 'development'], true)) {
    throw new RuntimeException('Invalid application environment.');
}
define('APP_ENVIRONMENT', $environment);
if (PHP_SAPI !== 'cli' && APP_ENVIRONMENT === 'production') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}
$configFile = __DIR__ . '/local.php';
if (!is_file($configFile)) {
    throw new RuntimeException('Copy config/local.example.php to config/local.php and configure an isolated demo database.');
}
$appSettings = require $configFile;
$runtimeRoot = dirname(__DIR__) . '/.runtime';
foreach (['logs', 'sessions/development', 'locks', 'locks/development'] as $directory) {
    $path = $runtimeRoot . '/' . $directory;
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException('Cannot create local runtime directory.');
    }
}
define('APP_OPS_ROOT', $runtimeRoot);
require_once __DIR__ . '/runtime.php';
app_runtime_bootstrap();
define('APP_BASE_PATH', $appSettings['base_path']);
if (PHP_SAPI !== 'cli' && is_file(APP_OPS_ROOT . '/maintenance-' . APP_ENVIRONMENT . '.lock')) {
    http_response_code(503);
    header('Retry-After: 120');
    header('Content-Type: text/html; charset=UTF-8');
    exit('<!doctype html><html lang="th"><meta charset="utf-8"><title>กำลังปรับปรุงระบบ</title><body><h1>กำลังปรับปรุงระบบ</h1><p>กรุณารอสักครู่ แล้วเปิดหน้านี้อีกครั้ง</p></body></html>');
}
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_save_path(APP_OPS_ROOT . '/sessions/' . APP_ENVIRONMENT);
    session_name(APP_ENVIRONMENT === 'production' ? 'RPBS_LIVE' : 'RPBS_DEV');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => APP_BASE_PATH . '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

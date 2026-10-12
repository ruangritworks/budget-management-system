<?php
require_once __DIR__ . '/config/environment.php';
require_once __DIR__ . '/config/security.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="th"><meta charset="utf-8"><title>ออกจากระบบ</title><body><form method="post">' . csrf_field() . '<button>ยืนยันออกจากระบบ</button></form></body></html>';
    exit;
}
csrf_require();
$_SESSION = [];
session_destroy();
setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => APP_BASE_PATH . '/', 'httponly' => true, 'samesite' => 'Lax']);
header("Location: login.php");

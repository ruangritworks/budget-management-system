<?php
require_once __DIR__ . '/config/environment.php';
require 'config/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $u = substr(trim((string)($_POST['username'] ?? '')), 0, 50);
    $p = (string)($_POST['password'] ?? '');
    $ip = substr($_SERVER['REMOTE_ADDR'] ?? 'local', 0, 45);
    $limit = $conn->prepare('SELECT COUNT(*) attempts FROM login_attempts WHERE username=? AND client_ip=? AND successful=0 AND created_at>DATE_SUB(NOW(),INTERVAL 15 MINUTE)');
    $limit->bind_param('ss', $u, $ip);$limit->execute();
    if ((int)$limit->get_result()->fetch_assoc()['attempts'] >= 10) { http_response_code(429); exit('เข้าสู่ระบบไม่สำเร็จหลายครั้ง กรุณารอ 15 นาทีหรือติดต่อผู้ดูแล'); }

    $stmt = $conn->prepare('SELECT * FROM users WHERE username=?');
    $stmt->bind_param('s', $u);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $successful = $user && (int)$user['active'] === 1 && password_verify($p, $user['password_hash']);
    $outcome = $successful ? 1 : 0;
    $attempt = $conn->prepare('INSERT INTO login_attempts(username,client_ip,successful) VALUES(?,?,?)');
    $attempt->bind_param('ssi', $u, $ip, $outcome);$attempt->execute();
    if ($successful) {
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['last_activity'] = time();
        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'full_name' => $user['full_name'],
            'role' => $user['role'],
            'unit_id' => (int)$user['unit_id'],
        ];
        header('Location: index.php');
        exit;
    }

    $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
}
?>
<!DOCTYPE html>
<html lang="th">
<head><meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="UTF-8">
<title>เข้าสู่ระบบ 35MDBS</title>
<link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body class="login-page">
<form method="post" class="login-box"><?=csrf_field()?><?=request_field()?>
<?php if (APP_ENVIRONMENT === 'development'): ?>
<p style="background:#fff3cd;color:#664d03;padding:12px;border-radius:8px;">ชุดพัฒนา / ทดสอบ — ข้อมูลแยกจากระบบใช้งานจริง</p>
<?php endif; ?>
<h2>เข้าสู่ระบบ 35MDBS</h2>
<input name="username" placeholder="ชื่อผู้ใช้" required>
<input name="password" type="password" placeholder="รหัสผ่าน" required>
<button type="submit">เข้าสู่ระบบ</button>
<?php if ($error !== ''): ?>
    <p class="error" style="margin:10px 0 0;"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>
</form>
</body>
</html>

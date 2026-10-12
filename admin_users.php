<?php
require 'config/auth.php';
require 'config/db.php';
require_roles(['admin']);

$message = '';
$error = '';
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

$units = [];
$unitResult = $conn->query('SELECT id, unit_code, unit_name FROM units ORDER BY unit_code');
while ($row = $unitResult->fetch_assoc()) {
    $units[] = $row;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && in_array($_POST['action'] ?? '', ['toggle','disable'], true)) {
    try {
        begin_financial_transaction($conn);
        $admins = $conn->query("SELECT id FROM users WHERE role='admin' AND active=1 ORDER BY id FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
        $id = (int)($_POST['id'] ?? 0);
        if ($id === current_user_id()) throw new RuntimeException('ไม่สามารถปิดบัญชีของตนเอง');
        $stmt = $conn->prepare('SELECT * FROM users WHERE id=? FOR UPDATE');
        $stmt->bind_param('i',$id); $stmt->execute(); $person=$stmt->get_result()->fetch_assoc();
        if (!$person) throw new RuntimeException('ไม่พบผู้ใช้');
        $active = ($_POST['action']==='disable') ? 0 : (1-(int)$person['active']);
        if (!$active && $person['role']==='admin' && (int)$person['active'] && count($admins)<=1) throw new RuntimeException('ต้องมีผู้ดูแลที่ใช้งานได้อย่างน้อยหนึ่งคน');
        $stmt=$conn->prepare('UPDATE users SET active=? WHERE id=?'); $stmt->bind_param('ii',$active,$id); $stmt->execute();
        $conn->commit(); header('Location: admin_users.php?msg=toggled'); exit;
    } catch(Throwable $e) { $conn->rollback(); $error=operation_error($e); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($_POST['action'] ?? '', ['toggle','disable'], true)) {
    $action = $_POST['action'] ?? 'create';
    $id = (int)($_POST['id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $positionName = trim($_POST['position_name'] ?? '');
    $role = $_POST['role'] ?? 'clerk';
    $unitId = (int)($_POST['unit_id'] ?? 0);
    $active = isset($_POST['active']) ? 1 : 0;
    $password = (string)($_POST['password'] ?? '');

    if (!in_array($role, ['admin', 'clerk', 'officer'], true)) {
        $role = 'clerk';
    }

    if ($id === current_user_id() && ($role !== 'admin' || !$active)) {
        $error = 'ไม่สามารถลดสิทธิ์หรือปิดบัญชีของตนเอง';
    } elseif ($password !== '' && mb_strlen($password) < 12) {
        $error = 'รหัสผ่านใหม่ต้องยาวอย่างน้อย 12 ตัวอักษร';
    } elseif ($username === '' || $fullName === '' || $unitId <= 0) {
        $error = 'กรุณากรอกข้อมูลผู้ใช้ให้ครบถ้วน';
    } elseif ($action === 'create' && $password === '') {
        $error = 'กรุณากำหนดรหัสผ่านสำหรับผู้ใช้ใหม่';
    } else {
        try {
            begin_financial_transaction($conn);
            $activeAdmins = $conn->query("SELECT id FROM users WHERE role='admin' AND active=1 ORDER BY id FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
            if ($action==='update' && ($role!=='admin' || !$active) && count($activeAdmins)<=1 && in_array($id,array_map('intval',array_column($activeAdmins,'id')),true)) throw new RuntimeException('ต้องมีผู้ดูแลอย่างน้อยหนึ่งคน');
            if ($action === 'update' && $id > 0) {
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare('
                        UPDATE users
                        SET username = ?, password_hash = ?, full_name = ?, position_name = ?, role = ?, unit_id = ?, active = ?
                        WHERE id = ?
                    ');
                    $stmt->bind_param('sssssiii', $username, $hash, $fullName, $positionName, $role, $unitId, $active, $id);
                } else {
                    $stmt = $conn->prepare('
                        UPDATE users
                        SET username = ?, full_name = ?, position_name = ?, role = ?, unit_id = ?, active = ?
                        WHERE id = ?
                    ');
                    $stmt->bind_param('ssssiii', $username, $fullName, $positionName, $role, $unitId, $active, $id);
                }
                $stmt->execute();
                $conn->commit();
                header('Location: admin_users.php?msg=updated');
                exit;
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('
                INSERT INTO users (username, password_hash, full_name, position_name, role, unit_id, active)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->bind_param('sssssii', $username, $hash, $fullName, $positionName, $role, $unitId, $active);
            $stmt->execute();
            $conn->commit();
            header('Location: admin_users.php?msg=created');
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            $error = 'บันทึกไม่ได้ อาจมี username ซ้ำหรือข้อมูลหน่วยงานไม่ถูกต้อง';
        }
    }
}

$editRow = null;
if ($editId > 0) {
    $stmt = $conn->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editRow = $stmt->get_result()->fetch_assoc();
}

$msg = $_GET['msg'] ?? '';
if ($msg === 'created') $message = 'เพิ่มผู้ใช้เรียบร้อยแล้ว';
if ($msg === 'updated') $message = 'แก้ไขผู้ใช้เรียบร้อยแล้ว';
if ($msg === 'deleted') $message = 'ลบผู้ใช้เรียบร้อยแล้ว';
if ($msg === 'toggled') $message = 'เปลี่ยนสถานะผู้ใช้เรียบร้อยแล้ว';

$result = $conn->query('
    SELECT u.*, un.unit_code, un.unit_name
    FROM users u
    LEFT JOIN units un ON un.id = u.unit_id
    ORDER BY u.id ASC
');

function user_form_value(?array $row, string $key, string $default = ''): string {
    return htmlspecialchars((string)($row[$key] ?? $default));
}
?>
<!DOCTYPE html>
<html lang="th">
<head><meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="UTF-8">
<title>จัดการผู้ใช้</title>
<link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body>
<?php require 'template/nav.php'; ?>

<div class="container">
    <div class="panel">
        <h1 class="page-title">จัดการผู้ใช้</h1>
        <div class="toolbar">
            <a class="btn btn-primary" href="admin_users.php">ผู้ใช้</a>
            <a class="btn btn-outline" href="units.php">หน่วยงาน</a>
            <a class="btn btn-outline" href="allocations.php">งบตั้งต้น</a>
            <a class="btn btn-outline" href="signers.php">ผู้ลงนาม</a>
        </div>
        <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <form method="post"><?=csrf_field()?><?=request_field()?>
            <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
            <input type="hidden" name="id" value="<?= (int)($editRow['id'] ?? 0) ?>">
            <div class="form-grid form-grid-2">
                <label>Username <span class="required">*</span></label>
                <input name="username" value="<?= user_form_value($editRow, 'username') ?>" required>

                <label>ชื่อ-สกุล <span class="required">*</span></label>
                <input name="full_name" value="<?= user_form_value($editRow, 'full_name') ?>" required>

                <label>ตำแหน่ง</label>
                <input name="position_name" value="<?= user_form_value($editRow, 'position_name') ?>" placeholder="เช่น นายทหารการเงิน">

                <label>รหัสผ่าน <?= $editRow ? '' : '<span class="required">*</span>' ?></label>
                <input name="password" type="password" <?= $editRow ? '' : 'required' ?>>

                <label>สิทธิ์</label>
                <select name="role">
                    <?php foreach (['admin' => 'ผู้ดูแลระบบ', 'officer' => 'ผู้ตรวจสอบ/ผู้จ่าย', 'clerk' => 'เจ้าหน้าที่การเงิน'] as $value => $label): ?>
                        <option value="<?= $value ?>" <?= (($editRow['role'] ?? 'clerk') === $value) ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>

                <label>หน่วยงาน</label>
                <select name="unit_id" required>
                    <option value="">-- เลือกหน่วยงาน --</option>
                    <?php foreach ($units as $unit): ?>
                        <option value="<?= (int)$unit['id'] ?>" <?= (int)($editRow['unit_id'] ?? 0) === (int)$unit['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($unit['unit_code'] . ' - ' . $unit['unit_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label>เปิดใช้งาน</label>
                <label><input type="checkbox" name="active" value="1" <?= (int)($editRow['active'] ?? 1) === 1 ? 'checked' : '' ?> style="width:auto;"> ใช้งานได้</label>
            </div>

            <div class="toolbar" style="margin-top:12px;">
                <button class="btn btn-success" type="submit"><?= $editRow ? 'บันทึกการแก้ไข' : 'เพิ่มผู้ใช้' ?></button>
                <?php if ($editRow): ?><a class="btn btn-muted" href="admin_users.php">ยกเลิก</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="table-wrap">
            <table>
                <tr>
                    <th>Username</th>
                    <th>ชื่อ</th>
                    <th>สิทธิ์</th>
                    <th>หน่วยงาน</th>
                    <th>สถานะ</th>
                    <th style="width:220px;">จัดการ</th>
                </tr>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['username']) ?></td>
                        <td><?= htmlspecialchars($row['full_name']) ?></td>
                        <td><?= htmlspecialchars(role_label($row['role'])) ?></td>
                        <td><?= htmlspecialchars(trim(($row['unit_code'] ?? '') . ' - ' . ($row['unit_name'] ?? ''))) ?></td>
                        <td><?= (int)$row['active'] === 1 ? 'ใช้งาน' : 'ปิดใช้งาน' ?></td>
                        <td>
                            <div class="action-links">
                                <a href="admin_users.php?edit=<?= (int)$row['id'] ?>">แก้ไข</a>
                                <?php if ((int)$row['id'] !== current_user_id()): ?>
                                    <form method="post"><?=csrf_field()?><?=request_field()?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=(int)$row['id']?>"><button class="btn btn-outline"><?= (int)$row['active']===1?'ปิดใช้งาน':'เปิดใช้งาน'?></button></form>
                                    
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </table>
        </div>
    </div>
</div>
</body>
</html>

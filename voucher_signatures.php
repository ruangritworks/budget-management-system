<?php
require 'config/auth.php';
require 'config/db.php';
require_once 'config/signers.php';
require_permission('budget.view');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $conn->prepare('SELECT v.*, u.unit_code, u.unit_name FROM payment_vouchers v JOIN units u ON u.id=v.unit_id WHERE v.id=?');
$stmt->bind_param('i', $id);
$stmt->execute();
$voucher = $stmt->get_result()->fetch_assoc();
if (!$voucher || !can_access_unit((int)$voucher['unit_id'])) { http_response_code(404); exit('ไม่พบฎีกา'); }

$options = voucher_signer_options($conn);
$existing = [];
$stmt = $conn->prepare('SELECT signer_key, signer_id FROM voucher_signatures WHERE voucher_id=?');
$stmt->bind_param('i', $id);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $existing[$row['signer_key']] = (int)$row['signer_id'];
$roles = ['approved' => 'หัวหน้างบประมาณ / ผู้รับรอง'];
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!has_permission('voucher.receive') && !has_permission('voucher.pay')) require_permission('voucher.receive');
    $chosen = [];
    foreach ($roles as $key => $label) {
        $signerId = (int)($_POST['signers'][$key] ?? 0);
        if ($signerId < 1) continue;
        foreach ($options as $person) {
            if ((int)$person['id'] === $signerId) { $chosen[$key] = $person; break; }
        }
        if (!isset($chosen[$key])) $error = 'รายชื่อผู้ลงนามไม่ถูกต้อง';
    }
    if (!$chosen) $error = 'กรุณาเลือกผู้ลงนามอย่างน้อยหนึ่งคน';
    if ($error === '') {
        try { begin_financial_transaction($conn);
            $delete = $conn->prepare('DELETE FROM voucher_signatures WHERE voucher_id=?');
            $delete->bind_param('i', $id); $delete->execute();
            $insert = $conn->prepare('INSERT INTO voucher_signatures (voucher_id,signer_key,signer_id,full_name,position_name,selected_by) VALUES (?,?,?,?,?,?)');
            $userId = current_user_id();
            foreach ($chosen as $key => $person) {
                $insert->bind_param('isissi', $id, $key, $person['id'], $person['full_name'], $person['position_name'], $userId);
                $insert->execute();
            }
            $conn->commit();
            header('Location: print_document.php?type=voucher&id='.$id); exit;
        } catch (Throwable $e) { $conn->rollback(); $error = 'บันทึกผู้ลงนามไม่สำเร็จ'; }
    }
}
?>
<!doctype html><html lang="th"><head><meta name="viewport" content="width=device-width, initial-scale=1"><meta charset="utf-8"><title>เลือกผู้ลงนามฎีกา</title><link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__.'/assets/css/style.css') ?>"></head><body>
<?php require 'template/nav.php'; ?>
<main class="container"><section class="panel"><h1 class="page-title">เลือกผู้ลงนามก่อนพิมพ์ฎีกา</h1><p class="page-subtitle"><?= htmlspecialchars($voucher['voucher_no']) ?> · <?= htmlspecialchars($voucher['unit_code'].' '.$voucher['unit_name']) ?></p></section>
<section class="panel"><?php if($error):?><div class="alert alert-danger"><?=htmlspecialchars($error)?></div><?php endif;?>
<form method="post"><?=csrf_field()?><?=request_field()?><input type="hidden" name="id" value="<?=$id?>"><div class="form-grid">
<?php foreach($roles as $key=>$label):?><label for="signer_<?=$key?>"><?=$label?></label><select id="signer_<?=$key?>" name="signers[<?=$key?>]" required><option value="">-- เลือกผู้ลงนามก่อนพิมพ์ --</option><?php foreach($options as $person):?><option value="<?=(int)$person['id']?>" <?=($existing[$key]??0)===(int)$person['id']?'selected':''?>><?=htmlspecialchars($person['full_name'].' — '.$person['position_name'])?></option><?php endforeach;?></select><?php endforeach;?></div>
<?php if(!$options):?><p class="alert alert-danger">ยังไม่มีรายชื่อผู้ลงนาม กรุณาเพิ่มที่เมนูจัดการระบบก่อน</p><?php endif;?>
<div class="toolbar" style="margin-top:16px"><button class="btn btn-success" <?=$options?'':'disabled'?>>บันทึกผู้ลงนามและเปิดหน้าพิมพ์</button><a class="btn btn-outline" href="payment_vouchers.php">กลับรายการฎีกา</a></div></form></section></main></body></html>

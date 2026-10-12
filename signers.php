<?php
require 'config/auth.php';
require 'config/db.php';
require_once 'config/signers.php';
require_roles(['admin']);
$error = '';
$ready = shared_signer_ready($conn);
$action = (string)($_POST['action'] ?? 'save');
$editId = (int)($_POST['signer_id'] ?? $_GET['edit'] ?? 0);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (!in_array($action, ['save', 'delete'], true)) throw new DomainException('คำขอไม่ถูกต้อง');
        begin_financial_transaction($conn);
        if ($action === 'delete') {
            delete_budget_signer($conn, $editId);
        } elseif ($editId > 0) {
            update_budget_signer($conn, $editId, $_POST);
        } else {
            save_shared_budget_signer($conn, $_POST);
        }
        $conn->commit();
        header('Location: signers.php?msg=' . ($action === 'delete' ? 'deleted' : 'saved'));
        exit;
    } catch (Throwable $e) {
        $conn->rollback();
        $error = operation_error($e);
    }
}
$signer = shared_budget_signer($conn);
$editRequested = $editId > 0 && $action !== 'delete';
$editing = $editRequested ? signer_record($conn, $editId) : null;
if ($editRequested && !$editing && !$error) $error = 'ไม่พบผู้ลงนาม หรือรายชื่อนี้ถูกลบแล้ว';
$form = $error && $action === 'save' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? $_POST : ($editRequested ? ($editing ?? []) : ($signer ?? []));
$formId = $editRequested ? $editId : ($signer ? (int)$signer['id'] : 0);
$legacy = $conn->query('SELECT id,unit_id,full_name,position_name FROM document_signers WHERE unit_id IS NOT NULL AND active=1 ORDER BY full_name,id')->fetch_all(MYSQLI_ASSOC);
function signer_h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ผู้ลงนามกลาง | 35MDBS</title><link rel="stylesheet" href="assets/css/style.css?v=<?=filemtime(__DIR__.'/assets/css/style.css')?>"></head><body>
<?php require 'template/nav.php'; ?>
<main class="container"><section class="panel"><h1 class="page-title">ผู้ลงนามหัวหน้างบประมาณ</h1><p class="page-subtitle">กำหนดหนึ่งคนเป็นผู้ลงนามกลางสำหรับเอกสารและรายงานทุกหน่วย ไม่ต้องเลือกหน่วยหรือประเภทเอกสาร</p></section>
<section class="panel">
<?php if (!$ready): ?><div class="alert alert-danger">กรุณาติดตั้ง database/alter_v10_shared_budget_signer.sql ในชุดพัฒนาก่อนบันทึก</div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?=signer_h($error)?></div><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'saved'): ?><div class="alert alert-success">บันทึกผู้ลงนามแล้ว เอกสารที่ลงนามไว้ก่อนหน้านี้ยังคงชื่อเดิม</div><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'deleted'): ?><div class="alert alert-success">ลบรายชื่อออกจากตัวเลือกแล้ว ชื่อในเอกสารที่บันทึกไว้ยังคงเดิม</div><?php endif; ?>
<h2 id="signer-form-title"><?= $editRequested ? 'แก้ไขผู้ลงนาม' : ($signer ? 'ข้อมูลผู้ลงนามกลาง' : 'เพิ่มผู้ลงนามกลาง') ?></h2>
<?php if ($editing && $editing['unit_id'] !== null): ?><p class="hint">กำลังแก้ไขรายชื่อเดิม หากต้องการตั้งเป็นหัวหน้างบประมาณกลาง ให้กลับไปกด “ใช้เป็นผู้ลงนามกลาง”</p><?php endif; ?>
<form id="signer-form" method="post"><?=csrf_field()?><?=request_field()?>
<input type="hidden" name="action" value="save"><input id="signer-id" type="hidden" name="signer_id" value="<?=$formId?>">
<div class="field-grid">
<div class="form-field"><label for="signer-name">ชื่อ-สกุล *</label><input id="signer-name" name="full_name" maxlength="255" value="<?=signer_h($form['full_name']??'')?>" required autocomplete="name"><span class="hint">ใส่ยศหน้าชื่อได้ หากต้องการให้แสดงบนเอกสาร</span></div>
<div class="form-field"><label for="signer-position">ยศ / ตำแหน่ง *</label><input id="signer-position" name="position_name" maxlength="255" value="<?=signer_h($form['position_name']??'')?>" placeholder="เช่น หัวหน้างบประมาณ" required></div>
</div><div class="toolbar form-actions"><button class="btn btn-success" <?=$ready?'':'disabled'?>>บันทึกผู้ลงนาม</button><?php if ($editRequested): ?><a class="btn btn-outline" href="signers.php">ยกเลิกการแก้ไข</a><?php endif; ?></div>
</form><p class="hint">เอกสารฎีกายังคงให้ตรวจชื่อผู้ลงนามและบันทึกก่อนพิมพ์ ส่วนรายงาน เงินยืม และ PO ใช้รายชื่อกลางนี้</p>
</section>
<?php foreach (['ผู้ลงนามกลางปัจจุบัน' => $signer ? [$signer] : [], 'รายชื่อเดิมที่เคยบันทึก' => $legacy] as $heading => $people): ?>
<?php if (!$people) continue; ?><section class="panel"><h2><?=signer_h($heading)?></h2><p class="hint">แก้ไขหรือลบรายชื่อได้ โดยไม่เปลี่ยนชื่อในเอกสารที่ลงนามไว้แล้ว</p><div class="table-wrap"><table><thead><tr><th>ชื่อ-สกุล</th><th>ยศ / ตำแหน่ง</th><th>จัดการ</th></tr></thead><tbody>
<?php foreach ($people as $person): ?><tr><td><?=signer_h($person['full_name'])?></td><td><?=signer_h($person['position_name'])?></td><td><div class="toolbar compact">
<a class="btn btn-outline" href="signers.php?edit=<?=(int)$person['id']?>#signer-form">แก้ไข</a>
<?php if ($person['unit_id'] ?? false): ?><button type="button" class="btn btn-outline" data-use-signer data-name="<?=signer_h($person['full_name'])?>" data-position="<?=signer_h($person['position_name'])?>">ใช้เป็นผู้ลงนามกลาง</button><?php endif; ?>
<form method="post" data-delete-signer><?=csrf_field()?><?=request_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="signer_id" value="<?=(int)$person['id']?>"><button class="btn btn-danger">ลบ</button></form>
</div></td></tr><?php endforeach; ?>
</tbody></table></div></section><?php endforeach; ?></main>
<script>
document.querySelectorAll('[data-use-signer]').forEach(button=>button.addEventListener('click',()=>{
    const name=document.getElementById('signer-name');
    document.getElementById('signer-id').value='0';
    document.getElementById('signer-form-title').textContent='กำหนดผู้ลงนามกลาง';
    name.value=button.dataset.name;
    document.getElementById('signer-position').value=button.dataset.position;
    name.focus();name.scrollIntoView({block:'center',behavior:'smooth'});
}));
document.querySelectorAll('[data-delete-signer]').forEach(form=>form.addEventListener('submit',event=>{
    if(!confirm('ยืนยันลบผู้ลงนามนี้ออกจากตัวเลือก? ชื่อในเอกสารที่ลงนามไว้แล้วจะไม่เปลี่ยนแปลง')) event.preventDefault();
}));
</script>
</body></html>

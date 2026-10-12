<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
require_once __DIR__ . '/financial.php';
$module = $documentModule ?? ($_GET['module'] ?? 'voucher');
$cfg = financial_config($module);
require_permission('budget.view');
$year = (int)($_GET['year'] ?? $_POST['fiscal_year'] ?? fiscal_year_for_date());
$unitId = is_admin() ? (int)($_GET['unit_id'] ?? 0) : current_unit_id();
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        begin_financial_transaction($conn);
        $action = (string)($_POST['action'] ?? 'save');
        $id = (int)($_POST['id'] ?? 0);
        if ($action === 'save') financial_create($conn, $module, $_POST);
        elseif ($action === 'status') financial_transition($conn, $module, $id, (string)($_POST['action_date'] ?? ''), isset($_POST['settled_amount']) ? (string)$_POST['settled_amount'] : null);
        elseif ($action === 'cancel') financial_cancel($conn, $module, $id, (string)($_POST['reason'] ?? ''), (string)($_POST['action_date'] ?? ''));
        elseif ($action === 'link') financial_link_existing($conn, $module, $id, (int)($_POST['control_account_id'] ?? 0), isset($_POST['settled_amount']) ? (string)$_POST['settled_amount'] : null);
        elseif ($action === 'edit') financial_edit($conn, $module, $id, $_POST);
        else throw new RuntimeException('ไม่พบคำสั่งที่เลือก');
        $conn->commit();
        header('Location: ' . basename($_SERVER['SCRIPT_NAME']) . '?year=' . $year . '&unit_id=' . $unitId . '&msg=saved');
        exit;
    } catch (Throwable $exception) {
        $conn->rollback();
        if ($exception->getCode() === 403) http_response_code(403);
        $error = operation_error($exception);
    }
}
$params = [$year];
$types = 'i';
$accountSql = 'SELECT ca.*,u.unit_code,u.unit_name FROM control_accounts ca JOIN units u ON u.id=ca.unit_id WHERE ca.fiscal_year=? AND ca.cancelled_at IS NULL';
if ($unitId > 0) { $accountSql .= ' AND ca.unit_id=?'; $params[] = $unitId; $types .= 'i'; }
$stmt = $conn->prepare($accountSql . ' ORDER BY ca.budget_code');
$stmt->bind_param($types, ...$params);
$stmt->execute();
$accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$sql = 'SELECT d.*,u.unit_code,ca.budget_code FROM ' . $cfg['table'] . ' d JOIN units u ON u.id=d.unit_id LEFT JOIN control_accounts ca ON ca.id=d.control_account_id WHERE d.fiscal_year=?' . (isset($_GET['show_cancelled']) ? '' : ' AND d.cancelled_at IS NULL');
if ($unitId > 0) $sql .= ' AND d.unit_id=?';
$stmt = $conn->prepare($sql . ' ORDER BY d.id DESC');
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$units = is_admin() ? $conn->query('SELECT * FROM units ORDER BY unit_code')->fetch_all(MYSQLI_ASSOC) : [];
$edit = null;
if (isset($_GET['edit'])) {
    try { $edit = financial_document($conn, $module, (int)$_GET['edit'], false); }
    catch (Throwable $exception) { http_response_code(403); exit(operation_error($exception)); }
}
$editPermission = $edit && $module === 'voucher' && $edit['status'] === $cfg['done'] ? 'voucher.pay' : $cfg['permission'];
if ($edit) { require_permission($editPermission); $year = (int)$edit['fiscal_year']; }
$editAccount = null;
if ($edit && $edit['control_account_id']) {
    $stmt = $conn->prepare('SELECT budget_code,item_name FROM control_accounts WHERE id=?');
    $stmt->bind_param('i', $edit['control_account_id']);
    $stmt->execute();
    $editAccount = $stmt->get_result()->fetch_assoc();
}
$form = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && in_array($_POST['action'] ?? '', ['save','edit'], true)) ? $_POST : [];
function financial_h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$labels = ['received'=>'รอจ่าย','paid'=>'จ่ายแล้ว','open'=>'รอส่งใช้เงินยืม','closed'=>'ส่งใช้แล้ว','reserved'=>'ผูกพัน','released'=>'ปลดวงเงินแล้ว'];
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>รายการ<?=financial_h($cfg['title'])?></title><link rel="stylesheet" href="assets/css/style.css?v=<?=filemtime(__DIR__.'/../assets/css/style.css')?>"></head><body>
<?php require __DIR__ . '/../template/nav.php'; ?>
<main class="container"><section class="panel"><h1 class="page-title">รายการ<?=financial_h($cfg['title'])?></h1><p class="page-subtitle">เลือกบัญชีคุมงบประมาณต้นทางก่อนบันทึก · วงเงินสงวนและยอดจ่ายสัมพันธ์กับบัญชีคุม</p><div class="toolbar"><a class="btn btn-outline" href="payment_vouchers.php?year=<?=$year?>">ฎีกา</a><a class="btn btn-outline" href="budget_advances.php?year=<?=$year?>">เงินยืม</a><a class="btn btn-outline" href="po_reservations.php?year=<?=$year?>">PO</a><a class="btn btn-outline" href="document_archive.php?year=<?=$year?>">เอกสารที่ออกแล้ว</a></div>
<?php if ($error): ?><div class="alert alert-danger"><?=financial_h($error)?></div><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'saved'): ?><div class="alert alert-success">บันทึกแล้ว กรุณาตรวจรายการและยอดในบัญชีคุม</div><?php endif; ?>
<form method="get" class="toolbar"><label>ปีงบประมาณ</label><input type="number" name="year" value="<?=$year?>" style="width:150px"><?php if (is_admin()): ?><select name="unit_id" style="max-width:300px"><option value="0">ทุกหน่วย</option><?php foreach ($units as $unit): ?><option value="<?=$unit['id']?>" <?=$unitId===(int)$unit['id']?'selected':''?>><?=financial_h($unit['unit_code'].' '.$unit['unit_name'])?></option><?php endforeach; ?></select><?php endif; ?><label><input type="checkbox" name="show_cancelled" value="1" <?=isset($_GET['show_cancelled'])?'checked':''?>> แสดงรายการที่ลบแล้ว</label><button class="btn btn-primary">ค้นหา</button></form></section>
<?php if (has_permission($editPermission)): ?><section class="panel"><h2><?=$edit?'แก้ไขเอกสาร':'เพิ่ม'.$cfg['title']?></h2>
<form method="post" class="financial-document-form"><?=csrf_field()?><?=request_field()?><input type="hidden" name="action" value="<?=$edit?'edit':'save'?>"><input type="hidden" name="fiscal_year" value="<?=$year?>"><input type="hidden" name="id" value="<?=(int)($edit['id']??0)?>">
<div class="field-grid">
<div class="form-field"><label for="financial-account">บัญชีคุมต้นทาง *</label>
<?php if ($edit): ?><input id="financial-account" value="<?=financial_h(($editAccount['budget_code']??'ไม่พบบัญชี').' / '.($editAccount['item_name']??''))?>" readonly><span class="hint">เอกสาร <?=financial_h($edit[$cfg['number']])?> ใช้บัญชีเดิม ไม่ย้ายการตัดวงเงิน หากต้องย้ายบัญชีให้ลบพร้อมเหตุผลแล้วสร้างรายการใหม่</span><?php if($edit['control_account_id']&&has_permission('voucher.pay')):?><a href="gfmis_confirm.php?id=<?=(int)$edit['control_account_id']?>">ตรวจ / แก้ยอดยืนยัน GFMIS</a><?php endif;?>
<?php else: ?><select id="financial-account" name="control_account_id" required><option value="">-- เลือกบัญชีที่ต้องการตัดวงเงิน --</option><?php foreach ($accounts as $account): $sum=ledger_totals($conn,(int)$account['id']); ?><option value="<?=$account['id']?>" <?=(int)($form['control_account_id']??0)===(int)$account['id']?'selected':''?>><?=financial_h($account['unit_code'].' / '.$account['budget_code'].' / '.$account['item_name'])?> · ใช้ได้ <?=number_format(ledger_available_cents($account['received_amount'],$sum)/100,2)?> บาท</option><?php endforeach; ?></select><?php endif; ?></div>
<div class="form-field"><label for="financial-date">วันที่เอกสาร *</label><input id="financial-date" type="date" name="doc_date" value="<?=financial_h($form['doc_date']??$edit[$cfg['date']]??date('Y-m-d'))?>" required></div>
<div class="form-field"><label for="financial-amount">จำนวนเงิน *</label><input id="financial-amount" type="number" name="amount" min="0.01" step="0.01" value="<?=financial_h($form['amount']??$edit['amount']??'')?>" required></div>
<?php if (!$edit): ?>
<div class="form-field"><label for="financial-category">หมวดเงิน</label><input id="financial-category" name="expense_category" pattern="\d{3}" maxlength="3" inputmode="numeric" value="<?=financial_h($form['expense_category']??'')?>" placeholder="เว้นว่างเพื่อใช้หมวดเงินของบัญชีคุม" aria-describedby="financial-category-hint"><span class="hint" id="financial-category-hint">ต้องตรงกับหมวดที่เลือกตอนสร้างบัญชีคุมงบประมาณ</span></div>
<?php endif; ?>
<?php if ($module==='voucher' || $edit): ?><div class="form-field"><label for="financial-number">เลขเอกสาร</label><input id="financial-number" name="doc_no" maxlength="50" value="<?=financial_h($form['doc_no']??$edit[$cfg['number']]??'')?>" placeholder="เว้นว่างเพื่อรันเลขอัตโนมัติ"></div><?php endif; ?>
<?php if ($module==='po'): ?><div class="form-field"><label for="financial-po">เลข PO ภายนอก</label><input id="financial-po" name="po_no" value="<?=financial_h($form['po_no']??$edit['po_no']??'')?>"></div><div class="form-field"><label for="financial-vendor">ผู้ขาย / คู่สัญญา</label><input id="financial-vendor" name="vendor" value="<?=financial_h($form['vendor']??$edit['vendor']??'')?>"></div><?php endif; ?>
<?php if ($edit && $edit['status']===$cfg['done']): ?><div class="form-field"><label>วันที่ดำเนินการ *</label><input type="date" name="action_date" value="<?=financial_h($form['action_date']??($module==='voucher'?$edit['paid_date']:($module==='advance'?$edit['settled_date']:$edit['released_date'])))?>" required></div><?php if ($module==='advance'): ?><div class="form-field"><label>ค่าใช้จ่ายจริง *</label><input type="number" name="settled_amount" min="0" step="0.01" value="<?=financial_h($form['settled_amount']??$edit['settled_amount']??'0')?>" required></div><?php endif; ?><?php endif; ?>
<div class="form-field form-field-full"><label for="financial-description">รายละเอียด *</label><textarea id="financial-description" name="description" maxlength="255" rows="3" required><?=financial_h($form['description']??$edit[$cfg['detail']]??'')?></textarea></div>
<?php if ($edit): ?><div class="form-field form-field-full"><label for="financial-reason">เหตุผลแก้ไข *</label><input id="financial-reason" name="reason" minlength="5" maxlength="255" value="<?=financial_h($form['reason']??'')?>" required></div><?php endif; ?>
</div><div class="toolbar form-actions"><button class="btn btn-success" <?=$accounts||$edit?'':'disabled'?>>บันทึก<?=$edit?'การแก้ไข':'และสงวนวงเงิน'?></button><?php if ($edit): ?><a class="btn btn-outline" href="<?=financial_h(basename($_SERVER['SCRIPT_NAME']))?>?year=<?=$year?>">ยกเลิกการแก้ไข</a><?php endif; ?></div></form>
<?php if (!$accounts && !$edit): ?><p class="alert alert-danger">ยังไม่มีบัญชีคุมงบประมาณ ในปี/หน่วยที่เลือก กรุณาเพิ่มงบหรือเปลี่ยนตัวกรองก่อน</p><?php endif; ?></section><?php endif; ?>
<section class="panel"><div class="table-wrap"><table><tr><th>เลขเอกสาร / วันที่</th><th>หน่วย / รหัสงบประมาณ</th><th>รายละเอียด</th><th>จำนวนเงิน</th><th>สถานะ</th><th>ดำเนินการ</th></tr>
<?php foreach ($rows as $row): $date=fiscal_year_for_date()===(int)$row['fiscal_year']?date('Y-m-d'):$row[$cfg['date']]; $done=$row['status']===$cfg['done']; $cancelled=(bool)$row['cancelled_at']; ?>
<tr><td><?=financial_h($row[$cfg['number']])?><br><?=financial_h($row[$cfg['date']])?></td><td><?=financial_h($row['unit_code'])?><br><?php if ($row['control_account_id']): ?><a href="control_account_detail.php?id=<?=$row['control_account_id']?>"><?=financial_h($row['budget_code'])?></a><?php else: ?><span class="badge badge-draft">รอผูกบัญชีคุมงบประมาณ</span><?php endif; ?></td><td><?=financial_h($row[$cfg['detail']])?><?php if ($cancelled): ?><br>เหตุผล: <?=financial_h($row['cancel_reason'])?><?php endif; ?></td><td class="text-right"><?=number_format((float)$row['amount'],2)?><?php if ($module==='advance' && $row['settled_amount']!==null): ?><br>ใช้จริง <?=number_format((float)$row['settled_amount'],2)?><br>คืน/ปลด <?=number_format((float)$row['amount']-(float)$row['settled_amount'],2)?><?php endif; ?></td><td><?=$cancelled?'ยกเลิกแล้ว':financial_h($labels[$row['status']]??$row['status'])?></td><td>
<a href="<?=$module==='voucher'?'voucher_signatures.php?id='.$row['id']:'print_document.php?type='.$module.'&id='.$row['id']?>" target="_blank">ดู / พิมพ์</a>
<?php if (!$cancelled && $row['control_account_id'] && has_permission($module==='voucher' && $done?'voucher.pay':$cfg['permission'])): ?><a href="<?=financial_h(basename($_SERVER['SCRIPT_NAME']))?>?year=<?=$year?>&edit=<?=$row['id']?>">แก้ไข</a><?php endif; ?>
<?php if (!$cancelled && !$done && $row['control_account_id'] && has_permission($module==='voucher'?'voucher.pay':$cfg['permission'])): ?>
<details><summary><?=$module==='voucher'?'บันทึกจ่าย':($module==='advance'?'ส่งใช้เงินยืม':'ปลดวงเงิน PO')?></summary><form method="post"><?=csrf_field()?><?=request_field()?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?=$row['id']?>"><input type="hidden" name="fiscal_year" value="<?=$year?>"><label>วันที่ดำเนินการ</label><input type="date" name="action_date" value="<?=financial_h($date)?>" required><?php if ($module==='advance'): ?><label>ค่าใช้จ่ายจริง (ส่วนที่เหลือจะปลดวงเงิน)</label><input type="number" name="settled_amount" min="0" max="<?=financial_h($row['amount'])?>" step="0.01" required><?php endif; ?><button class="btn btn-primary">ยืนยัน</button></form></details><?php endif; ?>
<?php if (!$cancelled && has_permission($module==='voucher' && $done?'voucher.pay':$cfg['permission'])): ?><details><summary>ลบรายการ</summary><form method="post" onsubmit="return confirm('ยืนยันลบรายการและปรับยอดงบ? ระบบจะเก็บประวัติพร้อมเหตุผล')"><p class="hint">ลบแบบยกเลิกเพื่อเก็บประวัติและปรับยอดงบคืน</p><?=csrf_field()?><?=request_field()?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?=$row['id']?>"><input type="hidden" name="fiscal_year" value="<?=$year?>"><input type="date" name="action_date" value="<?=financial_h($date)?>" required><input name="reason" placeholder="เหตุผลลบรายการ" minlength="5" maxlength="255" required><button class="btn btn-danger">ยืนยันลบรายการ</button></form></details><?php endif; ?>
<?php if (!$cancelled && !$row['control_account_id'] && is_admin()): ?><details><summary>ผูกเอกสารเดิมกับบัญชีคุมงบประมาณ</summary><form method="post"><?=csrf_field()?><?=request_field()?><input type="hidden" name="action" value="link"><input type="hidden" name="id" value="<?=$row['id']?>"><input type="hidden" name="fiscal_year" value="<?=$year?>"><select name="control_account_id" required><option value="">-- ตรวจเอกสารแล้วเลือกบัญชีคุมงบประมาณ --</option><?php foreach ($accounts as $account): if((int)$account['unit_id']!==(int)$row['unit_id'])continue; ?><option value="<?=$account['id']?>"><?=financial_h($account['budget_code'].' '.$account['item_name'])?></option><?php endforeach; ?></select><?php if ($module==='advance' && $done): ?><label>ยอดใช้จริงตามหลักฐานเดิม</label><input name="settled_amount" type="number" min="0" max="<?=financial_h($row['amount'])?>" step="0.01" required><?php endif; ?><button class="btn btn-primary">ยืนยันผูกบัญชี</button></form></details><?php endif; ?>
</td></tr><?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="6">ไม่มีรายการในปี/หน่วยที่เลือก</td></tr><?php endif; ?></table></div></section></main></body></html>

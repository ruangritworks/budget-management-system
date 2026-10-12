<?php
require 'config/auth.php';
require 'config/db.php';
require_permission('budget.create');

$currentYear = fiscal_year_for_date();
$years = range($currentYear - 2, $currentYear + 2);
$units = $conn->query('SELECT id, unit_code, unit_name FROM units ORDER BY unit_code')->fetch_all(MYSQLI_ASSOC);
$currentUnitId = (int)($_SESSION['user']['unit_id'] ?? 0);
?>
<!DOCTYPE html><html lang="th"><head><meta name="viewport" content="width=device-width, initial-scale=1"><meta charset="UTF-8"><title>เพิ่มงบประมาณโอนครั้งแรก</title><link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>"></head><body>
<?php require 'template/nav.php'; ?>
<main class="container">
<section class="panel"><h1 class="page-title">เพิ่มงบประมาณ (โอนครั้งแรก)</h1><p class="page-subtitle">ข้อมูลชุดนี้จะสร้างรายการบัญชีคุม GFMIS ให้อัตโนมัติ</p></section>
<form action="save_budget.php" method="post"><?=csrf_field()?><?=request_field()?><input type="hidden" name="transfer_round" value="1"><?php require "config/classification_form.php"; ?>
<section class="panel"><h2>ข้อมูลการสั่งจ่าย</h2><div class="form-grid form-grid-2">
<label>ปีงบประมาณ <span class="required">*</span></label><select name="fiscal_year" required><?php foreach ($years as $y): ?><option value="<?= $y ?>" <?= $y === $currentYear ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?></select>

<label>สั่งจ่ายงวดที่ <span class="required">*</span></label><select name="installment_code" required><option value="1">งวดที่ 1</option><option value="2">งวดที่ 2</option><option value="additional">งวดเพิ่มเติม</option></select>
<label>ลงวันที่สั่งจ่าย <span class="required">*</span></label><input type="date" name="doc_date" required>
<label>วันที่รับเรื่อง <span class="required">*</span></label><input type="date" name="receive_date" required>
</div><div style="margin-top:14px"><label>เลขที่เอกสารสั่งจ่าย <span class="required">*</span></label><div class="doc-no-grid" style="margin-top:6px;align-items:center"><input type="text" name="doc_no_part1" maxlength="2" minlength="2" placeholder="กห" required><input type="text" name="doc_no_part2" maxlength="4" inputmode="numeric" pattern="[0-9]{4}" placeholder="0406" required><div style="display:flex;align-items:center;gap:10px"><strong>/</strong><input type="text" name="doc_no_part3" maxlength="4" minlength="4" placeholder="0001" pattern="[0-9]{4}" required></div></div><span class="hint">รูปแบบตัวอย่าง: กห 0406 / XXXX</span></div></section>
<section class="panel"><h2>รายละเอียดงบประมาณ</h2><div class="form-grid form-grid-2">
<label>ชื่อรายการ <span class="required">*</span></label><textarea name="item_name" rows="3" required></textarea>
<label>รหัส GFMIS <span class="required">*</span></label><input type="text" name="gfmis_code" maxlength="30" placeholder="0200484708000000" required>
<label>วันที่เงินโอนเข้าระบบ GFMIS <span class="required">*</span></label><input type="date" name="gfmis_date" required>
<label>รหัสกิจกรรมหลัก <span class="required">*</span></label><input type="text" name="activity_code" maxlength="30" placeholder="02004XXXX13901" required>
<label>วงเงินรวม <span class="required">*</span></label><input type="number" step="0.01" min="0.01" name="amount" required>
<label>รหัสงบประมาณ ทบ.</label><input type="text" name="budget_code"><label>แหล่งของเงิน</label><input type="text" name="source_fund">

</div><div style="margin-top:14px"><label>ประเภทงบประมาณ <span class="required">*</span></label><div class="checkbox-group" style="margin-top:8px">
<label><input type="checkbox" name="budget_types[]" value="Annual"> งบประจำปี</label><label><input type="checkbox" name="budget_types[]" value="Investment"> งบลงทุน</label><label><input type="checkbox" name="budget_types[]" value="Central"> งบกลาง</label><label><input type="checkbox" name="budget_types[]" value="Interagency"> งบเบิกแทนกัน</label><label><input type="checkbox" name="budget_types[]" value="Carryover"> กันไว้เบิกเหลื่อมปี</label><label><input type="checkbox" name="budget_types[]" value="Operating"> งบดำเนินงาน</label><label><input type="checkbox" name="budget_types[]" value="Additional"> เพิ่มเติม/อื่น ๆ</label>
</div></div><div class="toolbar" style="margin-top:20px"><button type="submit" class="btn btn-success">ยืนยันเพิ่มรายการอนุมัติเงินประจำงวด</button><a href="index.php" class="btn btn-outline">ยกเลิกและกลับหน้าหลัก</a></div></section></form>
</main><script src="assets/js/organization.js?v=<?=filemtime(__DIR__.'/assets/js/organization.js')?>" defer></script></body></html>

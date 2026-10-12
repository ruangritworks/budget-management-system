<?php
require 'config/auth.php';
require 'config/db.php';
require_permission('budget.create');
require 'config/organization.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: add_budget.php'); exit; }

try {
if(!organization_ready($conn)) throw new DomainException('กรุณาติดตั้งข้อมูลจำแนกงบ v8 ก่อนสร้างบัญชีคุมงบประมาณ');
$p1 = trim($_POST['doc_no_part1'] ?? '');
$p2 = trim($_POST['doc_no_part2'] ?? '');
$p3 = trim($_POST['doc_no_part3'] ?? '');
$docNo = $p1 . ' ' . $p2 . ' / ' . $p3;
$docDate = $_POST['doc_date'] ?? '';
$receiveDate = $_POST['receive_date'] ?? '';
$gfmisDate = $_POST['gfmis_date'] ?? '';
$fiscalYear = (int)($_POST['fiscal_year'] ?? 0);
$unitId = (int)($_POST['unit_id'] ?? 0);
$installment = $_POST['installment_code'] ?? '1';
$itemName = trim($_POST['item_name'] ?? '');
$gfmisCode = trim($_POST['gfmis_code'] ?? '');
$activityCode = trim($_POST['activity_code'] ?? '');
$amount = money_decimal(money_cents((string)($_POST['amount'] ?? '0')));
$budgetCode = trim($_POST['budget_code'] ?? '');
$sourceFund = trim($_POST['source_fund'] ?? '');
$expenseCategory = trim($_POST['expense_category'] ?? '');
$budgetTypes = $_POST['budget_types'] ?? [];
$createdBy = (int)($_SESSION['user']['id'] ?? 0);

if (mb_strlen($p1) !== 2 || !preg_match('/^\d{4}$/', $p2) || !preg_match('/^\d{4}$/', $p3)) die('เลขที่เอกสารต้องอยู่ในรูปแบบ กห 0406 / XXXX');
if (!in_array($installment, ['1', '2', 'additional'], true)) die('กรุณาเลือกงวดสั่งจ่ายให้ถูกต้อง');
if ($fiscalYear < 2500 || $unitId <= 0 || $docDate === '' || $receiveDate === '' || $gfmisDate === '' || $itemName === '' || $gfmisCode === '' || $activityCode === '') die('กรุณากรอกข้อมูลที่มีเครื่องหมาย * ให้ครบถ้วน');
if (!can_access_unit($unitId)) { http_response_code(403); exit('ไม่มีสิทธิ์เพิ่มงบให้หน่วยงานนี้'); }
validate_fiscal_date($docDate,$fiscalYear);
if ($amount <= 0) die('วงเงินต้องมากกว่า 0');
if (!is_array($budgetTypes) || !$budgetTypes) die('กรุณาเลือกประเภทงบประมาณอย่างน้อย 1 รายการ');
if (array_diff($budgetTypes,['Annual','Investment','Central','Interagency','Carryover','Operating','Additional'])) throw new DomainException('ประเภทงบประมาณไม่ถูกต้อง');
foreach ([$itemName,$subject??$itemName] as $text) if(mb_strlen($text)>255) throw new DomainException('ชื่อรายการต้องไม่เกิน 255 ตัวอักษร');
foreach ([$gfmisCode,$activityCode,$budgetCode] as $code) if(mb_strlen($code)>50) throw new DomainException('รหัสต้องไม่เกิน 50 ตัวอักษร');
validate_fiscal_date($gfmisDate,$fiscalYear);
if ($expenseCategory !== '' && !preg_match('/^\d{3}$/', $expenseCategory)) die('หมวดเงินต้องเป็นตัวเลข 3 หลัก');

$subject = $itemName;
$typesValue = implode(',', array_map('trim', $budgetTypes));
$round = 1;
$payingUnitId = $unitId;
$itemType = '';

begin_financial_transaction($conn);
    $classification = classification_validate($conn,$_POST,$unitId);
    $stmt = $conn->prepare('INSERT INTO budget_transfers
      (doc_no,doc_date,fiscal_year,unit_id,transfer_round,installment_code,paying_unit_id,receive_date,
       control_account_no,budget_code,source_fund,expense_category,item_type,subject,item_name,gfmis_code,gfmis_date,activity_code,budget_types,total_amount,created_by)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $empty = '';
    $stmt->bind_param('ssiiisisssssssssssssi', $docNo,$docDate,$fiscalYear,$unitId,$round,$installment,$payingUnitId,$receiveDate,$empty,$budgetCode,$sourceFund,$expenseCategory,$itemType,$subject,$itemName,$gfmisCode,$gfmisDate,$activityCode,$typesValue,$amount,$createdBy);
    $stmt->execute();
    $transferId = (int)$conn->insert_id;

    $item = $conn->prepare('INSERT INTO budget_transfer_items (transfer_id,description,amount) VALUES (?,?,?)');
    $item->bind_param('iss', $transferId, $itemName, $amount);
    $item->execute();

    $transType = 'transfer_in'; $refTable = 'budget_transfers'; $remark = 'เอกสารโอนงบเลขที่ ' . $docNo;
    $tx = $conn->prepare('INSERT INTO budget_transactions (unit_id,fiscal_year,trans_date,trans_type,amount,ref_table,ref_id,remark,created_by) VALUES (?,?,?,?,?,?,?,?,?)');
    $tx->bind_param('iissssisi', $unitId,$fiscalYear,$docDate,$transType,$amount,$refTable,$transferId,$remark,$createdBy);
    $tx->execute();

    $controlId = db_sync_control_account($conn, $transferId);
    classification_store($conn,$controlId,$classification);
    $conn->commit();
    header('Location: control_account_detail.php?id=' . $controlId . '&created=1'); exit;
} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo 'เกิดข้อผิดพลาด: ' . htmlspecialchars(operation_error($e));
}

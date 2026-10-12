<?php
require 'config/auth.php';
require 'config/db.php';
require 'config/allocations.php';
require_roles(['admin', 'clerk']);

$error = '';
$message = '';
$year = (int)($_GET['year'] ?? $_POST['fiscal_year'] ?? fiscal_year_for_date());
$units = $conn->query('SELECT id, unit_code, unit_name FROM units ORDER BY unit_code')->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        begin_financial_transaction($conn);
        $unitId=is_admin()?(int)($_POST['unit_id']??0):current_unit_id();
        allocation_save($conn,$unitId,$year,(string)($_POST['base_amount']??'0'),trim((string)($_POST['reason']??'')),($_POST['action']??'save')==='cancel');
        $conn->commit();header('Location: allocations.php?year='.$year.'&msg=saved');exit;
    }catch(Throwable $e){$conn->rollback();$error=operation_error($e);}
}
$edit=null;
if(isset($_GET['edit'])){
    $q=$conn->prepare('SELECT * FROM budget_allocations WHERE id=?');$editId=(int)$_GET['edit'];$q->bind_param('i',$editId);$q->execute();$edit=$q->get_result()->fetch_assoc();
    if(!$edit||!can_access_unit((int)$edit['unit_id'])){http_response_code(403);exit('ไม่มีสิทธิ์แก้ไขรายการนี้');}
    $year=(int)$edit['fiscal_year'];
}
if (($_GET['msg'] ?? '') === 'saved') $message = 'บันทึกงบตั้งต้นและรายการการเงินเรียบร้อยแล้ว';
$sql = 'SELECT ba.*, u.unit_code, u.unit_name, us.full_name AS creator FROM budget_allocations ba JOIN units u ON u.id=ba.unit_id LEFT JOIN users us ON us.id=ba.created_by WHERE ba.fiscal_year=?';
if (!is_admin()) $sql .= ' AND ba.unit_id=' . current_unit_id();
$sql .= ' ORDER BY u.unit_code';
$stmt=$conn->prepare($sql); $stmt->bind_param('i',$year); $stmt->execute(); $rows=$stmt->get_result();
?>
<!doctype html><html lang="th"><head><meta name="viewport" content="width=device-width, initial-scale=1"><meta charset="utf-8"><title>งบประมาณตั้งต้น</title><link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>"></head><body><?php require 'template/nav.php'; ?>
<main class="container"><section class="panel"><h1 class="page-title">จัดการงบประมาณตั้งต้น</h1><p class="page-subtitle">วงเงินนี้เป็นยอดเริ่มต้นสำหรับคำนวณงบคงเหลือของแต่ละหน่วย</p></section>
<section class="panel"><?php if ($message): ?><div class="alert alert-success"><?=htmlspecialchars($message)?></div><?php endif;?><?php if ($error):?><div class="alert alert-danger"><?=htmlspecialchars($error)?></div><?php endif;?>
<form method="post"><?=csrf_field()?><?=request_field()?><div class="form-grid form-grid-2"><label>ปีงบประมาณ</label><input type="number" name="fiscal_year" value="<?=$year?>" min="2500" max="3000" required><label>หน่วยงาน</label><?php if(is_admin()):?><select name="unit_id" required><option value="">-- เลือกหน่วยงาน --</option><?php foreach($units as $u):?><option value="<?=$u['id']?>" <?=(int)($edit['unit_id']??$_POST['unit_id']??0)===(int)$u['id']?'selected':''?>><?=htmlspecialchars($u['unit_code'].' - '.$u['unit_name'])?></option><?php endforeach;?></select><?php else:?><input value="หน่วยงานของผู้ใช้ปัจจุบัน" readonly><?php endif;?><label>วงเงินตั้งต้น (บาท)</label><input type="number" name="base_amount" min="0" step="0.01" value="<?=htmlspecialchars((string)($_POST['base_amount']??$edit['base_amount']??''))?>" required><label>เหตุผลแก้ไข / ลบ (จำเป็นเมื่อมีงบตั้งต้นแล้ว)</label><input name="reason" minlength="5" maxlength="255" value="<?=htmlspecialchars($_POST['reason']??'')?>"></div><div class="toolbar" style="margin-top:12px"><button class="btn btn-success">บันทึกงบตั้งต้น</button><a class="btn btn-outline" href="allocations.php?year=<?=$year?>">โหลดรายการปีนี้</a></div></form></section>
<section class="panel"><div class="table-wrap"><table><tr><th>ปี</th><th>หน่วยงาน</th><th class="text-right">วงเงินตั้งต้น</th><th>ผู้บันทึก</th><th>จัดการ</th></tr><?php while($r=$rows->fetch_assoc()):?><tr><td><?=$r['fiscal_year']?></td><td><?=htmlspecialchars($r['unit_code'].' - '.$r['unit_name'])?></td><td class="text-right"><?=number_format((float)$r['base_amount'],2)?></td><td><?=htmlspecialchars($r['creator']??'-')?></td><td><a href="?year=<?=$year?>&edit=<?=(int)$r['id']?>">แก้ไข</a><?php if((float)$r['base_amount']>0):?><details><summary>ลบงบตั้งต้น</summary><form method="post" onsubmit="return confirm('ยืนยันลบงบตั้งต้น? จะปรับยอดตั้งต้นเป็นศูนย์และเก็บประวัติ')"><?=csrf_field()?><?=request_field()?><input type="hidden" name="action" value="cancel"><input type="hidden" name="unit_id" value="<?=(int)$r['unit_id']?>"><input type="hidden" name="fiscal_year" value="<?=$year?>"><input name="reason" minlength="5" maxlength="255" placeholder="เหตุผลลบ" required><button class="btn btn-danger">ยืนยันลบ</button></form></details><?php else:?> ยอดเป็นศูนย์<?php endif;?></td></tr><?php endwhile;?></table></div></section></main></body></html>


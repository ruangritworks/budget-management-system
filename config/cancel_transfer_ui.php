<?php
require __DIR__.'/auth.php';require __DIR__.'/db.php';require_once __DIR__.'/transfers.php';require_permission('budget.delete');
$id=(int)($_GET['id']??$_POST['id']??0);$error='';
$stmt=$conn->prepare('SELECT * FROM budget_transfers WHERE id=?');$stmt->bind_param('i',$id);$stmt->execute();$doc=$stmt->get_result()->fetch_assoc();
if(!$doc||!can_access_unit((int)$doc['unit_id'])){http_response_code(403);exit('ไม่พบเอกสารหรือไม่มีสิทธิ์');}
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
 try{begin_financial_transaction($conn);transfer_cancel($conn,$id,trim($_POST['reason']??''));$conn->commit();header('Location: budget_menu.php?year='.$doc['fiscal_year'].'&cancelled=1');exit;}
 catch(Throwable $e){$conn->rollback();$error=operation_error($e);}
}
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>ยกเลิกเอกสารงบประมาณ</title><link rel="stylesheet" href="assets/css/style.css"></head><body><?php require __DIR__.'/../template/nav.php';?><main class="container"><section class="panel"><h1 class="page-title">ยกเลิกเอกสารงบประมาณ</h1><p><?=htmlspecialchars($doc['doc_no'].' / '.$doc['subject'])?></p><p>ข้อมูลเดิมยังคงอยู่ในระบบ หากบัญชีมีรายการเบิกอยู่ต้องตรวจและยกเลิกจากเอกสารต้นทางก่อน</p><?php if($error):?><div class="alert alert-danger"><?=htmlspecialchars($error)?></div><?php endif;?><form method="post"><?=csrf_field()?><?=request_field()?><input type="hidden" name="id" value="<?=$id?>"><label>เหตุผลยกเลิก</label><textarea name="reason" minlength="5" maxlength="255" required></textarea><button class="btn btn-danger">ยืนยันยกเลิกเอกสาร</button><a class="btn btn-outline" href="budget_detail.php?id=<?=$id?>">กลับ</a></form></section></main></body></html>

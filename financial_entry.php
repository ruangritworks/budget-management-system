<?php
require 'config/auth.php'; require 'config/db.php'; require 'config/financial_actions.php';
require_permission('control.manage');
$origin=(string)($_GET['origin']??'ledger');$id=(int)($_GET['id']??0);$error='';
$tables=['ledger'=>'control_account_entries','release'=>'reservation_releases','transaction'=>'budget_transactions'];
if(!isset($tables[$origin])||$id<1){http_response_code(404);exit('ไม่พบรายการ');}
$q=$conn->prepare('SELECT * FROM '.$tables[$origin].' WHERE id=?');$q->bind_param('i',$id);$q->execute();$entry=$q->get_result()->fetch_assoc();
if(!$entry){http_response_code(404);exit('ไม่พบรายการ');}
if($origin==='transaction'){
    if(!can_access_unit((int)$entry['unit_id'])){http_response_code(403);exit('ไม่มีสิทธิ์ในหน่วยนี้');}
    $year=(int)$entry['fiscal_year'];$back='transactions.php?year='.$year;
    $source=financial_source_url($entry['ref_table'],(int)$entry['ref_id'],$year);
    if($entry['ledger_entry_id'])$source='financial_entry.php?id='.(int)$entry['ledger_entry_id'];
    if($entry['cancelled_at']){http_response_code(409);exit('รายการนี้ถูกลบแล้ว');}
    $entry['entry_date']=$entry['trans_date'];$entry['note']=$entry['remark'];
}else{
    $account=ledger_account($conn,(int)$entry['control_account_id'],false);$year=(int)$account['fiscal_year'];$back='control_account_detail.php?id='.(int)$account['id'];
    $source=null;
    if($origin==='ledger'){
        if($entry['voided_at']){http_response_code(409);exit('รายการนี้ถูกลบแล้ว');}
        $source=financial_source_url($entry['source_table'],(int)$entry['source_id'],$year);
        if($entry['source_table']&&!$source){http_response_code(409);exit('กรุณาจัดการจากเอกสารต้นทาง');}
    }else{
        $s=$conn->prepare('SELECT source_table,source_id,voided_at FROM control_account_entries WHERE id=?');$s->bind_param('i',$entry['reserve_entry_id']);$s->execute();$reserve=$s->get_result()->fetch_assoc();
        if(!$reserve||$reserve['voided_at']||money_cents($entry['amount'])===0){http_response_code(409);exit('รายการนี้ถูกลบแล้ว');}
        $source=financial_source_url($reserve['source_table'],(int)$reserve['source_id'],$year);
        $entry['entry_date']=$entry['release_date'];$entry['note']=$entry['reason'];
    }
}
if($source){header('Location: '.$source);exit;}
if(($_SERVER['REQUEST_METHOD']??'')==='POST')try{
    begin_financial_transaction($conn);$action=$_POST['action']??'edit';
    if(!in_array($action,['edit','cancel'],true))throw new DomainException('ไม่พบคำสั่ง');
    if($origin==='ledger'){
        if($action==='cancel')ledger_void($conn,$id,(string)($_POST['reason']??''));else ledger_edit($conn,$id,$_POST);
    }elseif($origin==='release')ledger_release_edit($conn,$id,array_replace($_POST,['release_date'=>$_POST['entry_date']??'']),$action==='cancel');
    else financial_legacy_edit($conn,$id,$_POST,$action==='cancel');
    $conn->commit();header('Location: '.$back.'&saved=1');exit;
}catch(Throwable $e){$conn->rollback();$error=operation_error($e);if($e->getCode()===403)http_response_code(403);}
$form=array_replace($entry,($_SERVER['REQUEST_METHOD']??'')==='POST'?$_POST:[]);
$labels=['increase'=>'เพิ่มงบ','transfer_out'=>'โอนออก','return_army'=>'ส่งคืน ทบ.','carry'=>'กันไว้เบิก','request'=>'ขอใช้','obligation'=>'ผูกพัน','payment'=>'ตัดจ่าย','remittance'=>'นำส่ง'];
$units=$conn->query('SELECT id,unit_code,unit_name FROM units ORDER BY unit_code')->fetch_all(MYSQLI_ASSOC);
function feh($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>แก้ไข / ลบรายการการเงิน</title><link rel="stylesheet" href="assets/css/style.css?v=<?=filemtime(__DIR__.'/assets/css/style.css')?>"></head><body><?php require 'template/nav.php';?><main class="container">
<section class="panel"><h1 class="page-title">แก้ไข / ลบรายการการเงิน</h1><p>ใช้บัญชีต้นทางเดิม การเปลี่ยนยอดจะปรับบัญชีคุมและรายงานทันที ลบแบบยกเลิกเพื่อรักษาประวัติ</p><a class="btn btn-outline" href="<?=feh($back)?>">กลับรายการ</a><?php if($origin!=='transaction'):?><a class="btn btn-outline" href="gfmis_confirm.php?id=<?=(int)$account['id']?>">ตรวจยอดยืนยัน GFMIS</a><?php endif;?></section>
<?php if($error):?><div class="alert alert-danger"><?=feh($error)?></div><?php endif;?>
<section class="panel"><h2>แก้ไขรายการ</h2><form method="post"><?=csrf_field()?><?=request_field()?><input type="hidden" name="action" value="edit"><div class="field-grid">
<div class="form-field"><label>วันที่รายการ *</label><input type="date" name="entry_date" value="<?=feh($form['entry_date'])?>" required></div>
<div class="form-field"><label>จำนวนเงิน *</label><input type="number" name="amount" min="0.01" step="0.01" value="<?=feh($form['amount'])?>" required></div>
<?php if($origin==='ledger'):?>
<div class="form-field"><label>ประเภท *</label><select name="entry_type"><?php foreach($labels as $k=>$label):?><option value="<?=$k?>" <?=$form['entry_type']===$k?'selected':''?>><?=$label?></option><?php endforeach;?></select></div>
<div class="form-field"><label>เบิกครั้งที่ *</label><input type="number" name="disbursement_no" min="1" value="<?=(int)$form['disbursement_no']?>" required></div>
<div class="form-field"><label>รหัส GFMIS *</label><input name="gfmis_code" value="<?=feh($form['gfmis_code'])?>" required></div>
<div class="form-field"><label>รหัสกิจกรรม *</label><input name="activity_code" value="<?=feh($form['activity_code'])?>" required></div>
<div class="form-field"><label>หน่วยผู้เบิก *</label><select name="spending_unit_id"><?php foreach($units as $u):if(!is_admin()&&(int)$u['id']!==current_unit_id())continue;?><option value="<?=(int)$u['id']?>" <?=(int)$form['spending_unit_id']===(int)$u['id']?'selected':''?>><?=feh($u['unit_code'].' '.$u['unit_name'])?></option><?php endforeach;?></select></div>
<div class="form-field"><label>หมวดเงิน</label><input name="expense_category" value="<?=feh($form['expense_category'])?>" pattern="\d{3}" maxlength="3"></div>
<?php endif;?>
<div class="form-field form-field-full"><label>รายละเอียด</label><textarea name="note" rows="3"><?=feh($form['note'])?></textarea></div>
<div class="form-field form-field-full"><label>เหตุผลแก้ไข *</label><input name="reason" minlength="5" maxlength="255" value="<?=feh($_POST['reason']??'')?>" required></div>
</div><div class="toolbar form-actions"><button class="btn btn-success">บันทึกการแก้ไข</button></div></form></section>
<section class="panel"><h2>ลบรายการ</h2><form method="post" onsubmit="return confirm('ยืนยันลบรายการและคำนวณยอดงบใหม่?')"><?=csrf_field()?><?=request_field()?><input type="hidden" name="action" value="cancel"><div class="form-field"><label>เหตุผลลบ *</label><input name="reason" minlength="5" maxlength="255" required></div><div class="toolbar form-actions"><button class="btn btn-danger">ยืนยันลบรายการ</button></div></form></section></main></body></html>

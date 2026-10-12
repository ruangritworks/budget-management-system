<?php
require 'config/auth.php';require 'config/db.php';require_permission('budget.view');
$entity=trim($_GET['entity']??'');$id=(int)($_GET['id']??0);
$sql='SELECT * FROM audit_events WHERE 1=1';$params=[];$types='';
if(!is_admin()){$sql.=' AND unit_id=?';$params[]=current_unit_id();$types.='i';}
if($entity!==''){$sql.=' AND entity_type=?';$params[]=$entity;$types.='s';}
if($id>0){$sql.=' AND entity_id=?';$params[]=$id;$types.='i';}
$stmt=$conn->prepare($sql.' ORDER BY id DESC LIMIT 200');if($types)$stmt->bind_param($types,...$params);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
function ah(mixed $value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>ประวัติการเปลี่ยนแปลง</title><link rel="stylesheet" href="assets/css/style.css"></head><body><?php require 'template/nav.php';?><main class="container"><section class="panel"><h1 class="page-title">ประวัติการเปลี่ยนแปลง</h1><p>แสดง 200 รายการล่าสุด ข้อมูลประวัติไม่สามารถแก้ไขหรือลบทับได้</p><form method="get" class="toolbar"><input name="entity" value="<?=ah($entity)?>" placeholder="ประเภทข้อมูล เช่น payment_vouchers"><input type="number" name="id" value="<?=$id?:''?>" placeholder="รหัสรายการ"><button class="btn btn-primary">ค้นหา</button></form></section><section class="panel"><div class="table-wrap"><table><tr><th>วันเวลา</th><th>ผู้ดำเนินการ</th><th>คำสั่ง</th><th>รายการ</th><th>ค่าก่อน / หลัง</th></tr><?php foreach($rows as $row):?><tr><td><?=ah($row['created_at'])?></td><td><?=ah($row['actor_name']?:'ผู้ดูแลฐานข้อมูล')?> / <?=ah($row['actor_role'])?></td><td><?=ah($row['action'])?></td><td><?=ah($row['entity_type'])?> #<?=$row['entity_id']?></td><td><details><summary>ดูรายละเอียด</summary><strong>ก่อน</strong><pre style="white-space:pre-wrap;word-break:break-word"><?=ah($row['before_data'])?></pre><strong>หลัง</strong><pre style="white-space:pre-wrap;word-break:break-word"><?=ah($row['after_data'])?></pre></details></td></tr><?php endforeach;?></table></div></section></main></body></html>

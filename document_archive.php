<?php
require 'config/auth.php';require 'config/db.php';require_permission('report.view');
$id=(int)($_GET['id']??0);
if($id>0){
 $stmt=$conn->prepare('SELECT * FROM document_snapshots WHERE id=?');$stmt->bind_param('i',$id);$stmt->execute();$doc=$stmt->get_result()->fetch_assoc();
 if(!$doc||((int)$doc['unit_id']===0&&!is_admin())||((int)$doc['unit_id']!==0&&!can_access_unit((int)$doc['unit_id']))){http_response_code(403);exit('ไม่มีสิทธิ์ดูเอกสารนี้');}
 if(!hash_equals($doc['sha256'],hash('sha256',$doc['payload']))){http_response_code(500);exit('ตรวจสอบความสมบูรณ์ของเอกสารไม่ผ่าน กรุณาติดต่อผู้ดูแล');}
 audit_event($conn,'DOWNLOAD','document_snapshots',$id,(int)$doc['unit_id']?:null,['sha256'=>$doc['sha256']]);
 header('Content-Type: '.$doc['content_type']);header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');
 $disposition=$doc['content_type']==='text/html'?'inline':'attachment';
 header('Content-Disposition: '.$disposition.'; filename="'.preg_replace('/[^a-zA-Z0-9_.-]/','_',$doc['filename']).'"');
 echo $doc['payload'];exit;
}
$year=(int)($_GET['year']??fiscal_year_for_date());
$sql='SELECT id,document_type,document_id,unit_id,filename,sha256,created_at FROM document_snapshots WHERE fiscal_year=?'.(is_admin()?'':' AND unit_id=?').' ORDER BY id DESC LIMIT 200';$stmt=$conn->prepare($sql);is_admin()?$stmt->bind_param('i',$year):$stmt->bind_param('ii',$year,$_SESSION['user']['unit_id']);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>เอกสารที่ออกแล้ว</title><link rel="stylesheet" href="assets/css/style.css"></head><body><?php require 'template/nav.php';?><main class="container"><section class="panel"><h1 class="page-title">เอกสารที่ออกแล้ว</h1><p>เก็บไฟล์และชื่อผู้ลงนามตามวันที่ออกเอกสาร การแก้ชื่อหรือข้อมูลภายหลังไม่เปลี่ยนฉบับเก่า</p><form method="get" class="toolbar"><input type="number" name="year" value="<?=$year?>"><button class="btn btn-primary">ค้นหา</button></form></section><section class="panel"><div class="table-wrap"><table><tr><th>ฉบับ</th><th>วันเวลา</th><th>ประเภท / ไฟล์</th><th>ตรวจสอบ</th><th>เปิดเอกสาร</th></tr><?php foreach($rows as $row):?><tr><td>#<?=$row['id']?></td><td><?=htmlspecialchars($row['created_at'])?></td><td><?=htmlspecialchars($row['document_type'].' / '.$row['filename'])?></td><td><details><summary>SHA-256</summary><?=htmlspecialchars($row['sha256'])?></details></td><td><a href="?id=<?=$row['id']?>" target="_blank">เปิดฉบับที่จัดเก็บ</a></td></tr><?php endforeach;?></table></div></section></main></body></html>

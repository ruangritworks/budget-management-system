<?php
require_once __DIR__.'/../config/environment.php';
$thaiDateVersion=filemtime(__DIR__.'/../assets/js/thai-date.js');
$signatureStyleVersion=filemtime(__DIR__.'/../assets/css/document-signatures.css');
$currentPage=basename($_SERVER['PHP_SELF']??'');
$navGroups=[
 'งบประมาณ'=>['add_budget.php'=>'สร้างบัญชีคุม / รับงบครั้งแรก','transfer_round2.php'=>'รายการเบิกจากบัญชีคุม','control_accounts.php'=>'บัญชีคุม GFMIS','budget_menu.php'=>'เอกสารงบประมาณ','allocations.php'=>'งบตั้งต้น','report_review.php'=>'ตรวจสอบ / แก้ไข'],
 'การเงิน'=>['payment_vouchers.php'=>'ฎีกา / ตัดจ่าย','budget_advances.php'=>'เงินยืม','po_reservations.php'=>'ใบสั่งซื้อ / PO','transactions.php'=>'ประวัติรายการเงิน'],
 'ภาพรวม / รายงาน'=>['overview.php?view=unit'=>'ภาพรวมหน่วย','overview.php?view=cost'=>'ภาพรวมศูนย์ต้นทุน','overview.php?view=gfmis_cost'=>'ศูนย์ต้นทุน มุมมอง GFMIS','overview.php?view=gfmis'=>'ผลการเบิกจ่าย GFMIS','overview.php?view=quarter'=>'ผลการเบิกจ่ายรายไตรมาส','report_catalog.php'=>'เลือกรายงาน / ส่งออก Excel','disbursement_results.php'=>'รายละเอียดการเบิกจ่าย','document_archive.php'=>'เอกสารฉบับที่ออกแล้ว'],
];
if(($_SESSION['user']['role']??'')==='admin')$navGroups['จัดการระบบ']=['organization.php?tab=units'=>'หน่วยหลัก / หน่วยย่อย / หน่วยรอง','organization.php?tab=categories'=>'หมวดเงิน','budget_catalog.php'=>'โครงสร้างงบงานกลาง','budget_catalog.php?tab=assign'=>'หน่วยรับผิดชอบงบงาน','admin_users.php'=>'ผู้ใช้งานและสิทธิ์','signers.php'=>'ผู้ลงนาม','fiscal_periods.php'=>'ปิดงวด / ปีงบประมาณ','audit_history.php'=>'ประวัติการเปลี่ยนแปลง'];
?>
<nav class="topbar mdbs-nav" aria-label="เมนูหลัก">
 <a class="mdbs-brand" href="<?=APP_BASE_PATH?>/index.php"><img src="<?=APP_BASE_PATH?>/template/LOGO-35MD.png" alt="ตราหน่วย มทบ.35"><span>35MDBS<small>ระบบบริหารงบประมาณ มทบ.35</small></span></a>
 <a href="<?=APP_BASE_PATH?>/index.php" class="<?=$currentPage==='index.php'?'active':''?>">หน้าหลัก</a>
 <?php foreach($navGroups as $name=>$links):$active=false;foreach(array_keys($links) as $path)if(strtok($path,'?')===$currentPage)$active=true;?>
 <details class="nav-group <?=$active?'active':''?>"><summary><?=$name?></summary><div class="nav-dropdown"><?php foreach($links as $path=>$label):?><a href="<?=APP_BASE_PATH?>/<?=htmlspecialchars($path)?>"><?=$label?></a><?php endforeach;?></div></details><?php endforeach;?>
 <form method="post" action="<?=APP_BASE_PATH?>/logout.php" class="logout"><?=csrf_field()?><button class="btn btn-outline" type="submit">ออกจากระบบ</button></form>
</nav>
<?php if(APP_ENVIRONMENT==='development'):?><div class="environment-banner">ชุดพัฒนา / ทดสอบ · ไม่ใช่ชุดบันทึกข้อมูลใช้งานจริง</div><?php endif;?>
<script src="<?=APP_BASE_PATH?>/assets/js/thai-date.js?v=<?=$thaiDateVersion?>" defer></script>
<link rel="stylesheet" href="<?=APP_BASE_PATH?>/assets/css/document-signatures.css?v=<?=$signatureStyleVersion?>">
<script>document.querySelectorAll('.nav-group').forEach(item=>item.addEventListener('toggle',()=>{if(item.open)document.querySelectorAll('.nav-group').forEach(other=>{if(other!==item)other.open=false;});}));document.addEventListener('click',event=>{if(!event.target.closest('.nav-group'))document.querySelectorAll('.nav-group').forEach(item=>item.open=false);});document.addEventListener('keydown',event=>{if(event.key==='Escape')document.querySelectorAll('.nav-group').forEach(item=>item.open=false);});</script>

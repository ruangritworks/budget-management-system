<?php
require 'config/auth.php';require 'config/db.php';require 'config/organization.php';require_roles(['admin']);
$ready=organization_ready($conn);$error='';$tab=in_array($_GET['tab']??'', ['units','categories','programs'],true)?$_GET['tab']:'units';
if($_SERVER['REQUEST_METHOD']==='POST') {
 try {
  if(!$ready)throw new DomainException('ต้องติดตั้ง migration v8 ก่อนใช้ข้อมูลจำแนกงบ');
  begin_financial_transaction($conn);$action=$_POST['action']??'';
  if($action==='unit')organization_save_unit($conn,$_POST);
  elseif($action==='program')$savedProgram=organization_save_program($conn,$_POST);
  elseif($action==='category') {
   $code=organization_text($_POST,'code',3);$name=organization_text($_POST,'category_name');$active=isset($_POST['active'])?1:0;
   if(!preg_match('/^[0-9]{3}$/',$code))throw new DomainException('รหัสหมวดเงินต้องเป็นตัวเลข 3 หลัก');
   $q=$conn->prepare('INSERT INTO expense_categories(code,category_name,active) VALUES (?,?,?) ON DUPLICATE KEY UPDATE category_name=VALUES(category_name),active=VALUES(active)');$q->bind_param('ssi',$code,$name,$active);$q->execute();audit_event($conn,'SAVE','expense_categories',(int)$code,null,['code'=>$code,'name'=>$name,'active'=>$active]);
  } else throw new DomainException('รายการไม่ถูกต้อง');
  $conn->commit();$redirect='organization.php?tab='.$tab.'&saved=1';
  if($action==='program')$redirect.='&sub_unit_id='.(int)$_POST['sub_unit_id'].'&responsible_unit_id='.(int)$_POST['responsible_unit_id'].'&template_id='.$savedProgram;
  header('Location: '.$redirect);exit;
 }catch(Throwable $e){$conn->rollback();$error=operation_error($e);}
}
$units=$ready?organization_units($conn):[];
$categories=$ready?$conn->query('SELECT * FROM expense_categories ORDER BY code')->fetch_all(MYSQLI_ASSOC):[];
$programs=$ready?$conn->query('SELECT p.*,u.unit_name,u.unit_code,s.parent_unit_id,sub.unit_name sub_unit_name FROM budget_programs p JOIN units u ON u.id=p.responsible_unit_id JOIN unit_structure s ON s.unit_id=u.id LEFT JOIN units sub ON sub.id=s.parent_unit_id ORDER BY sub.unit_code,u.unit_code,p.program_code')->fetch_all(MYSQLI_ASSOC):[];
$edit=null;foreach($tab==='units'?$units:($tab==='programs'?$programs:$categories) as $r)if((string)($r['id']??$r['code'])===(string)($_GET['edit']??''))$edit=$r;
function org_h($s):string{return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
$levels=['main'=>'หน่วยหลัก','sub'=>'หน่วยย่อย','secondary'=>'หน่วยรอง'];
$newLevel=array_key_exists($_GET['level']??'', $levels)?$_GET['level']:'main';
$selectedLevel=$edit['unit_level']??$newLevel;
$isMainLevel=$selectedLevel==='main';
$templateId=(int)($_POST['template_id']??$_GET['template_id']??0);$sourceProgram=null;
if($tab==='programs'&&!$edit)foreach($programs as $p)if((int)$p['id']===$templateId&&$p['active'])$sourceProgram=$p;
$programForm=$tab==='programs'&&$error?$_POST:($edit??[]);
if($sourceProgram&&!$error) {
 foreach(['responsible_unit_id','parent_unit_id','strategy_no','strategy_name','plan_name','activity_name'] as $key)$programForm[$key]=$sourceProgram[$key];
 $programForm['active']=1;
}
$programUnit=(int)($programForm['responsible_unit_id']??$_GET['responsible_unit_id']??0);
$programSub=(int)($programForm['sub_unit_id']??$programForm['parent_unit_id']??$_GET['sub_unit_id']??0);
$unitById=[];foreach($units as $u)$unitById[(int)$u['id']]=$u;
if(!isset($unitById[$programSub])||$unitById[$programSub]['unit_level']!=='sub'||!$unitById[$programSub]['active'])$programSub=0;
if(!isset($unitById[$programUnit])||$unitById[$programUnit]['unit_level']!=='secondary'||!$unitById[$programUnit]['active']||(int)$unitById[$programUnit]['parent_unit_id']!==$programSub)$programUnit=0;


?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>โครงสร้างหน่วยและแผนงาน | 35MDBS</title><link rel="stylesheet" href="assets/css/style.css?v=<?=filemtime(__DIR__.'/assets/css/style.css')?>"></head><body><?php require 'template/nav.php';?>
<main class="container"><section class="panel"><p class="eyebrow">35MDBS / ข้อมูลตั้งต้น</p><h1 class="page-title">หน่วยและการจำแนกงบประมาณ</h1><p>โครงสร้างมีเพียง 3 ระดับ: หน่วยหลัก → หน่วยย่อย → หน่วยรอง เริ่มเพิ่มหน่วยหลักก่อน แล้วเพิ่มหน่วยย่อยและหน่วยรองที่สังกัดตามลำดับ</p><div class="toolbar"><?php foreach(['units'=>'โครงสร้างหน่วย','categories'=>'หมวดเงิน 3 หลัก','programs'=>'ยุทธศาสตร์ / แผนงาน / โครงการ'] as $key=>$label):?><a class="btn <?=$key===$tab?'btn-primary':'btn-outline'?>" href="<?=$key==='programs'?'budget_catalog.php':'?tab='.$key?>"><?=$label?></a><?php endforeach;?></div></section>
<?php if(!$ready):?><div class="alert alert-danger">ยังไม่ได้ติดตั้งตาราง v8 กรุณาให้ผู้ดูแลติดตั้งในชุดพัฒนาก่อน ห้ามนำ schema.sql สร้างทับข้อมูลเดิม</div><?php elseif($error):?><div class="alert alert-danger"><?=org_h($error)?></div><?php elseif(isset($_GET['saved'])):?><div class="alert alert-success">บันทึกข้อมูลเรียบร้อย</div><?php endif;?>
<?php if($ready):?><section class="panel"><?php if($tab==='units'):?><div class="toolbar"><?php foreach($levels as $levelKey=>$levelLabel):?><a class="btn <?=$selectedLevel===$levelKey?'btn-primary':'btn-outline'?>" href="?tab=units&amp;level=<?=$levelKey?>">เพิ่ม<?=$levelLabel?></a><?php endforeach;?></div><p>หน่วยหลักไม่ต้องเลือกสังกัด · หน่วยย่อยเลือกหน่วยหลัก · หน่วยรองเลือกหน่วยย่อย</p><?php endif;?><h2><?=$edit?'แก้ไขข้อมูล':'เพิ่มข้อมูล'?></h2><form method="post"><?=csrf_field()?><?=request_field()?><div class="form-grid form-grid-2">
<?php if($tab==='units'):?><input type="hidden" name="action" value="unit"><input type="hidden" name="id" value="<?=(int)($edit['id']??0)?>"><label>รหัสหน่วย *</label><input name="unit_code" maxlength="50" value="<?=org_h($edit['unit_code']??'')?>" required><label>ชื่อหน่วย *</label><input name="unit_name" maxlength="255" value="<?=org_h($edit['unit_name']??'')?>" required><label>ระดับหน่วย *</label><select name="unit_level" id="unit-level"><?php foreach($levels as $k=>$v):?><option value="<?=$k?>" <?=$selectedLevel===$k?'selected':''?>><?=$v?></option><?php endforeach;?></select><label id="unit-parent-label" <?=$isMainLevel?'hidden':''?>><?=$selectedLevel==='sub'?'หน่วยหลักที่สังกัด *':'หน่วยย่อยที่สังกัด *'?></label><select name="parent_unit_id" id="unit-parent" <?=$isMainLevel?'hidden disabled':'required'?>><option value=""><?=$selectedLevel==='sub'?'เลือกหน่วยหลัก':'เลือกหน่วยย่อย'?></option><?php foreach($units as $u):if($u['unit_level']==='secondary'||(int)$u['id']===(int)($edit['id']??0))continue;?><option data-level="<?=$u['unit_level']?>" value="<?=(int)$u['id']?>" <?=(int)($edit['parent_unit_id']??0)===(int)$u['id']?'selected':''?>><?=org_h($levels[$u['unit_level']].' · '.$u['unit_name'])?></option><?php endforeach;?></select><label>รหัสศูนย์ต้นทุน</label><input name="cost_center" maxlength="50" value="<?=org_h($edit['cost_center']??'')?>">
<?php elseif($tab==='categories'):?><input type="hidden" name="action" value="category"><label>รหัสหมวดเงิน *</label><input name="code" pattern="[0-9]{3}" maxlength="3" value="<?=org_h($edit['code']??'')?>" <?=$edit?'readonly':''?> required><label>ชื่อหมวดเงิน *</label><input name="category_name" maxlength="255" value="<?=org_h($edit['category_name']??'')?>" required>
<?php else:?><input type="hidden" name="action" value="program"><input type="hidden" name="id" value="<?=(int)($programForm['id']??0)?>">
<label>หน่วยย่อยที่สังกัด *</label><select name="sub_unit_id" id="program-sub" required><option value="">เลือกหน่วยย่อยก่อน</option><?php foreach($units as $u):if($u['unit_level']!=='sub'||!$u['active'])continue;?><option value="<?=(int)$u['id']?>" <?=$programSub===(int)$u['id']?'selected':''?>><?=org_h($u['unit_name'].' ('.$u['unit_code'].') · '.($unitById[(int)$u['parent_unit_id']]['unit_name']??'ยังไม่กำหนดหน่วยหลัก'))?></option><?php endforeach;?></select>
<label>หน่วยรองที่รับผิดชอบ *</label><select name="responsible_unit_id" id="program-secondary" required><option value="">เลือกหน่วยรองในหน่วยย่อยนี้</option><?php foreach($units as $u):if($u['unit_level']!=='secondary'||!$u['active']||(int)$u['parent_unit_id']!==$programSub)continue;?><option value="<?=(int)$u['id']?>" <?=$programUnit===(int)$u['id']?'selected':''?>><?=org_h($u['unit_name'].' ('.$u['unit_code'].')')?></option><?php endforeach;?></select>
<?php if(!$edit):?><label>ใช้ข้อมูลยุทธศาสตร์ / แผนงาน / กิจกรรมเดิม</label><select name="template_id" id="program-template"><option value="">สร้างชุดแผนงานใหม่</option><?php foreach($programs as $p):if(!$p['active']||(int)$p['responsible_unit_id']!==$programUnit)continue;?><option value="<?=(int)$p['id']?>" <?=$templateId===(int)$p['id']?'selected':''?>><?=org_h($p['plan_name'].' · '.$p['activity_name'].' (จาก '.$p['work_group'].')')?></option><?php endforeach;?></select><?php endif;?>
<p>กรอกยุทธศาสตร์ แผนงาน และกิจกรรมครั้งแรกครั้งเดียว ครั้งต่อไปเลือกชุดเดิมแล้วกรอกเฉพาะรหัสกับชื่อกลุ่มงบงานใหม่ ข้อมูลเดิมแก้ไขได้จากปุ่มแก้ไขในตาราง ไม่ต้องสร้างรายการใหม่</p>
<?php foreach(['program_code'=>'รหัสกลุ่มงบงาน / โครงการ','strategy_no'=>'ยุทธศาสตร์ที่','strategy_name'=>'ชื่อยุทธศาสตร์ (ถ้ามี)','plan_name'=>'แผนงาน','activity_name'=>'กิจกรรม','work_group'=>'กลุ่มงบงาน','project_name'=>'โครงการ (ถ้ามี)'] as $key=>$label):?><label><?=$label?></label><input name="<?=$key?>" maxlength="<?=$key==='program_code'?50:($key==='strategy_no'?20:255)?>" value="<?=org_h($programForm[$key]??'')?>" <?=in_array($key,['strategy_name','project_name'],true)?'':'required'?>><?php endforeach;?><?php endif;?>
<label>สถานะ</label><label><input type="checkbox" name="active" value="1" <?=($tab==='programs'?(!$programForm||isset($programForm['active'])&&$programForm['active']):(!$edit||$edit['active']))?'checked':''?>> เปิดใช้งาน</label></div><div class="toolbar"><button class="btn btn-primary"><?= $tab==='programs'?'บันทึกและเพิ่มกลุ่มงบงานถัดไป':'บันทึก'?></button><a class="btn btn-outline" href="?tab=<?=$tab?>">ยกเลิก / เพิ่มใหม่</a></div></form></section>
<section class="panel"><h2>รายการข้อมูลตั้งต้น</h2><div class="table-wrap"><table><thead><tr><?php foreach($tab==='units'?['รหัส','หน่วย','ระดับ','สังกัด','ศูนย์ต้นทุน','สถานะ','จัดการ']:($tab==='categories'?['รหัส','หมวดเงิน','สถานะ','จัดการ']:['รหัส','หน่วยย่อย','หน่วยรอง','ยุทธศาสตร์','แผนงาน','กิจกรรม','กลุ่มงบงาน / โครงการ','สถานะ','จัดการ']) as $label):?><th><?=$label?></th><?php endforeach;?></tr></thead><tbody>
<?php foreach($tab==='units'?$units:($tab==='categories'?$categories:$programs) as $r):?><tr><?php
 if($tab==='units'){$parent='ยังไม่กำหนด';foreach($units as $u)if((int)$u['id']===(int)$r['parent_unit_id'])$parent=$u['unit_name'];$values=[$r['unit_code'],$r['unit_name'],$levels[$r['unit_level']],$r['unit_level']==='main'?'-':$parent,$r['cost_center']];}
 elseif($tab==='categories')$values=[$r['code'],$r['category_name']];else $values=[$r['program_code'],$r['sub_unit_name'],$r['unit_name'].' ('.$r['unit_code'].')',$r['strategy_no'],$r['plan_name'],$r['activity_name'],$r['work_group'].' / '.$r['project_name']];
 foreach($values as $v)echo '<td>'.org_h($v).'</td>';?><td><?=$r['active']?'เปิดใช้งาน':'ปิดใช้งาน'?></td><td><a href="?tab=<?=$tab?>&edit=<?=urlencode((string)($r['id']??$r['code']))?>">แก้ไข</a><?php if($tab==='programs'):?> | <a href="?tab=programs&amp;sub_unit_id=<?=(int)$r['parent_unit_id']?>&amp;responsible_unit_id=<?=(int)$r['responsible_unit_id']?>&amp;template_id=<?=(int)$r['id']?>">+ เพิ่มกลุ่มงบงาน</a><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section><?php endif;?></main><?php if($tab==='programs'):?><script type="application/json" id="program-templates-data"><?=json_encode($programs,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?></script><script type="application/json" id="program-units-data"><?=json_encode($units,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?></script><?php endif;?><script src="assets/js/organization.js?v=<?=filemtime(__DIR__.'/assets/js/organization.js')?>" defer></script></body></html>

<?php
// Reporting hierarchy never grants access to another unit's financial records.
function organization_ready(mysqli $db): bool {
    $r=$db->query("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('unit_structure','expense_categories','budget_programs','budget_classifications','budget_quarter_plans','gfmis_payment_confirmations')");
    return (int)$r->fetch_assoc()['n']===6;
}
function organization_units(mysqli $db): array {
    return $db->query("SELECT u.*,s.parent_unit_id,COALESCE(s.unit_level,'sub') unit_level,COALESCE(s.cost_center,'') cost_center,COALESCE(s.active,1) active FROM units u LEFT JOIN unit_structure s ON s.unit_id=u.id ORDER BY u.unit_code")->fetch_all(MYSQLI_ASSOC);
}
function organization_text(array $data,string $key,int $max=255,bool $required=true): string {
    $v=trim((string)($data[$key]??''));
    if(($required&&$v==='')||mb_strlen($v)>$max) throw new DomainException('กรุณากรอก '.$key.' ให้ครบและไม่เกิน '.$max.' ตัวอักษร');
    return $v;
}
function organization_save_unit(mysqli $db,array $data): int {
    require_roles(['admin']);
    $id=(int)($data['id']??0);$parent=(int)($data['parent_unit_id']??0)?:null;
    $level=(string)($data['unit_level']??'sub');
    $code=organization_text($data,'unit_code',50);$name=organization_text($data,'unit_name');
    $cost=organization_text($data,'cost_center',50,false);$active=isset($data['active'])?1:0;
    if(!in_array($level,['main','sub','secondary'],true)) throw new DomainException('ระดับหน่วยไม่ถูกต้อง');
    if($level==='main') { $parent=null; }
    else {
        if(!$parent||$parent===$id) throw new DomainException($level==='sub'?'กรุณาเลือกหน่วยหลักที่สังกัด':'กรุณาเลือกหน่วยย่อยที่สังกัด');
        $q=$db->prepare('SELECT unit_level,active FROM unit_structure WHERE unit_id=? FOR UPDATE');$q->bind_param('i',$parent);$q->execute();$p=$q->get_result()->fetch_assoc();
        if(!$p||!(int)$p['active']||$p['unit_level']!==($level==='sub'?'main':'sub')) throw new DomainException('หน่วยย่อยต้องอยู่ใต้หน่วยหลัก และหน่วยรองต้องอยู่ใต้หน่วยย่อย');
    }
    if($id) {
        $q=$db->prepare('SELECT * FROM unit_structure WHERE unit_id=? FOR UPDATE');$q->bind_param('i',$id);$q->execute();$old=$q->get_result()->fetch_assoc();
        if($old&&($old['unit_level']!==$level||(int)$old['parent_unit_id']!==(int)$parent)) {
            $q=$db->prepare('SELECT (SELECT COUNT(*) FROM unit_structure WHERE parent_unit_id=?)+(SELECT COUNT(*) FROM budget_classifications WHERE main_unit_id=? OR sub_unit_id=? OR secondary_unit_id=?)+(SELECT COUNT(*) FROM budget_programs WHERE responsible_unit_id=?) n');$q->bind_param('iiiii',$id,$id,$id,$id,$id);$q->execute();
            if((int)$q->get_result()->fetch_assoc()['n']) throw new DomainException('หน่วยมีหน่วยสมาชิกหรือรายการอ้างอิงแล้ว กรุณาสร้างหน่วยใหม่แทนการย้ายระดับหรือเปลี่ยนสังกัด');
        }
        $q=$db->prepare('UPDATE units SET unit_code=?,unit_name=? WHERE id=?');$q->bind_param('ssi',$code,$name,$id);$q->execute();
    } else { $q=$db->prepare('INSERT INTO units(unit_code,unit_name) VALUES (?,?)');$q->bind_param('ss',$code,$name);$q->execute();$id=(int)$db->insert_id; }
    $q=$db->prepare('INSERT INTO unit_structure(unit_id,parent_unit_id,unit_level,cost_center,active) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE parent_unit_id=VALUES(parent_unit_id),unit_level=VALUES(unit_level),cost_center=VALUES(cost_center),active=VALUES(active)');
    $q->bind_param('iissi',$id,$parent,$level,$cost,$active);$q->execute();
    audit_event($db,'SAVE','unit_structure',$id,$id,['parent'=>$parent,'level'=>$level,'cost_center'=>$cost,'active'=>$active]);return $id;
}
function organization_save_program(mysqli $db,array $d): int {
    require_roles(['admin']);$id=(int)($d['id']??0);$unit=(int)($d['responsible_unit_id']??0);
    require_once __DIR__.'/budget_catalog.php';
    if($id&&budget_catalog_ready($db)) {
        $q=$db->prepare('SELECT program_id FROM budget_work_assignments WHERE program_id=?');$q->bind_param('i',$id);$q->execute();
        if($q->get_result()->fetch_assoc())throw new DomainException('รายการนี้ใช้โครงสร้างงบงานกลาง กรุณาแก้ไขที่เมนูโครงสร้างงบงานกลาง');
    }
    $q=$db->prepare("SELECT s.parent_unit_id FROM unit_structure s JOIN unit_structure p ON p.unit_id=s.parent_unit_id AND p.unit_level='sub' AND p.active=1 WHERE s.unit_id=? AND s.unit_level='secondary' AND s.active=1 FOR UPDATE");$q->bind_param('i',$unit);$q->execute();
    $scope=$q->get_result()->fetch_assoc();
    if(!$scope) throw new DomainException('กรุณาเลือกหน่วยรองที่อยู่ในหน่วยย่อยที่เปิดใช้งาน');
    if(array_key_exists('sub_unit_id',$d)&&(int)$d['sub_unit_id']!==(int)$scope['parent_unit_id']) throw new DomainException('หน่วยรองไม่อยู่ในหน่วยย่อยที่เลือก กรุณาเลือกหน่วยย่อยก่อน');
    // Reuse existing planning details without moving or modifying the source group.
    $template=(int)($d['template_id']??0);
    if(!$id&&$template) {
        $q=$db->prepare('SELECT * FROM budget_programs WHERE id=? AND responsible_unit_id=? AND active=1 FOR UPDATE');$q->bind_param('ii',$template,$unit);$q->execute();$source=$q->get_result()->fetch_assoc();
        if(!$source)throw new DomainException('ชุดแผนงานเดิมไม่ตรงกับหน่วยรองที่เลือก');
        foreach(['strategy_no','strategy_name','plan_name','activity_name'] as $key)if(!array_key_exists($key,$d))$d[$key]=$source[$key];
    }
    $code=organization_text($d,'program_code',50);$strategy=organization_text($d,'strategy_no',20);$strategyName=organization_text($d,'strategy_name',255,false);
    $plan=organization_text($d,'plan_name');$activity=organization_text($d,'activity_name');$group=organization_text($d,'work_group');$project=organization_text($d,'project_name',255,false);$active=isset($d['active'])?1:0;
    if($id) {
        $q=$db->prepare('SELECT responsible_unit_id FROM budget_programs WHERE id=? FOR UPDATE');$q->bind_param('i',$id);$q->execute();$old=$q->get_result()->fetch_assoc();
        if(!$old) throw new DomainException('ไม่พบแผนงาน');
        if((int)$old['responsible_unit_id']!==$unit) throw new DomainException('หากต้องการย้ายหน่วยรับผิดชอบ กรุณาสร้างแผนงานใหม่เพื่อรักษารายงานเดิม');
        $q=$db->prepare('UPDATE budget_programs SET program_code=?,responsible_unit_id=?,strategy_no=?,strategy_name=?,plan_name=?,activity_name=?,work_group=?,project_name=?,active=? WHERE id=?');
        $q->bind_param('sissssssii',$code,$unit,$strategy,$strategyName,$plan,$activity,$group,$project,$active,$id);
    } else {
        $q=$db->prepare('INSERT INTO budget_programs(program_code,responsible_unit_id,strategy_no,strategy_name,plan_name,activity_name,work_group,project_name,active) VALUES (?,?,?,?,?,?,?,?,?)');
        $q->bind_param('sissssssi',$code,$unit,$strategy,$strategyName,$plan,$activity,$group,$project,$active);
    }
    $q->execute();if(!$id)$id=(int)$db->insert_id;
    audit_event($db,'SAVE','budget_programs',$id,$unit,['code'=>$code,'strategy'=>$strategy,'plan'=>$plan,'activity'=>$activity,'group'=>$group,'project'=>$project,'active'=>$active]);return $id;
}
function classification_validate(mysqli $db,array $d,int $owner): array {
    if(!can_access_unit($owner)) throw new DomainException('ไม่มีสิทธิ์ทำรายการให้หน่วยนี้');
    $main=(int)($d['main_unit_id']??0);$sub=(int)($d['unit_id']??$owner);$secondary=(int)($d['secondary_unit_id']??0);$program=(int)($d['program_id']??0);$code=(string)($d['expense_category']??'');
    if($sub!==$owner) throw new DomainException('หน่วยเจ้าของงบประมาณ ต้องตรงกับหน่วยย่อยที่เลือก');
    $q=$db->prepare("SELECT u.*,s.* FROM units u JOIN unit_structure s ON s.unit_id=u.id WHERE u.id IN (?,?,?) FOR UPDATE");$q->bind_param('iii',$main,$sub,$secondary);$q->execute();$units=[];foreach($q->get_result() as $u)$units[(int)$u['id']]=$u;
    if(!isset($units[$main],$units[$sub],$units[$secondary])||$units[$main]['unit_level']!=='main'||$units[$sub]['unit_level']!=='sub'||$units[$secondary]['unit_level']!=='secondary'||(int)$units[$sub]['parent_unit_id']!==$main||(int)$units[$secondary]['parent_unit_id']!==$sub) throw new DomainException('สายหน่วยหลัก / หน่วยย่อย / หน่วยรองไม่สัมพันธ์กัน');
    foreach($units as $u)if(!(int)$u['active'])throw new DomainException('มีหน่วยที่ปิดใช้งานอยู่ในสายหน่วยนี้');
    require_once __DIR__.'/budget_catalog.php';$p=null;
    if(budget_catalog_ready($db)) {
        $choices=array_values(array_filter(budget_catalog_programs($db),fn($row)=>(int)$row['responsible_unit_id']===$secondary));
        if(!$choices)throw new DomainException('หน่วยรองนี้ยังไม่มีงบงานที่เปิดใช้งาน กรุณาให้ผู้ดูแลเชื่อมงบงานก่อนสร้างบัญชีคุมงบประมาณ');
        if(!$program) {
            if(count($choices)!==1)throw new DomainException('หน่วยรองนี้รับผิดชอบหลายงบงาน กรุณาเลือกงบงานที่จะรับงบ');
            $program=(int)$choices[0]['id'];
        }
        foreach($choices as $row)if((int)$row['id']===$program){$p=$row;break;}
    } else {
        $q=$db->prepare('SELECT * FROM budget_programs WHERE id=? AND responsible_unit_id=? AND active=1 FOR UPDATE');$q->bind_param('ii',$program,$secondary);$q->execute();$p=$q->get_result()->fetch_assoc();
    }
    if(!$p)throw new DomainException('แผนงาน / โครงการไม่ตรงกับหน่วยรองที่รับผิดชอบ');
    if(!preg_match('/^[0-9]{3}$/',$code))throw new DomainException('หมวดเงินต้องเป็นตัวเลข 3 หลัก');
    $q=$db->prepare('SELECT category_name FROM expense_categories WHERE code=? AND active=1 FOR UPDATE');$q->bind_param('s',$code);$q->execute();$cat=$q->get_result()->fetch_assoc();
    if(!$cat)throw new DomainException('กรุณาเลือกหมวดเงินที่ผู้ดูแลเปิดใช้งาน');
    return ['main_unit_id'=>$main,'sub_unit_id'=>$sub,'secondary_unit_id'=>$secondary,'program_id'=>$program,'expense_code'=>$code,'main_unit'=>$units[$main]['unit_name'],'sub_unit'=>$units[$sub]['unit_name'],'secondary_unit'=>$units[$secondary]['unit_name'],'cost_center'=>$units[$secondary]['cost_center'],'strategy_no'=>$p['strategy_no'],'strategy_name'=>$p['strategy_name'],'plan_name'=>$p['plan_name'],'activity_name'=>$p['activity_name'],'work_group'=>$p['work_group'],'project_name'=>$p['project_name'],'expense_name'=>$cat['category_name'],'work_id'=>isset($p['work_id'])?(int)$p['work_id']:null,'work_name'=>$p['work_name']??$p['project_name'],'group_id'=>isset($p['group_id'])?(int)$p['group_id']:null,'activity_id'=>isset($p['activity_id'])?(int)$p['activity_id']:null,'plan_id'=>isset($p['plan_id'])?(int)$p['plan_id']:null,'strategy_id'=>isset($p['strategy_id'])?(int)$p['strategy_id']:null];
}
function classification_store(mysqli $db,int $account,array $v): void {
    require_permission('budget.edit');
    $q=$db->prepare('SELECT id,unit_id FROM control_accounts WHERE id=? AND cancelled_at IS NULL FOR UPDATE');$q->bind_param('i',$account);$q->execute();$record=$q->get_result()->fetch_assoc();if(!$record||!can_access_unit((int)$record['unit_id'])||(int)$v['sub_unit_id']!==(int)$record['unit_id'])throw new DomainException('ไม่พบบัญชีคุมที่มีสิทธิ์จำแนก');
    $json=json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$actor=current_user_id();
    $q=$db->prepare('INSERT INTO budget_classifications(control_account_id,main_unit_id,sub_unit_id,secondary_unit_id,program_id,expense_code,snapshot_json,created_by) VALUES (?,?,?,?,?,?,?,?)');
    $q->bind_param('iiiiissi',$account,$v['main_unit_id'],$v['sub_unit_id'],$v['secondary_unit_id'],$v['program_id'],$v['expense_code'],$json,$actor);$q->execute();
    audit_event($db,'CLASSIFY','control_accounts',$account,$v['sub_unit_id'],$v);
}
function classification_get(mysqli $db,int $account): ?array {
    if(!organization_ready($db))return null;
    $q=$db->prepare('SELECT snapshot_json FROM budget_classifications WHERE control_account_id=?');$q->bind_param('i',$account);$q->execute();$r=$q->get_result()->fetch_assoc();
    return $r?json_decode($r['snapshot_json'],true,512,JSON_THROW_ON_ERROR):null;
}

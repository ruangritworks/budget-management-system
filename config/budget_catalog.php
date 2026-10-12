<?php
require_once __DIR__.'/organization.php';
function budget_catalog_ready(mysqli $db): bool {
    return (int)$db->query("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('budget_catalog_nodes','budget_work_assignments')")->fetch_assoc()['n']===2;
}
function budget_catalog_nodes(mysqli $db): array {
    $rows=$db->query('SELECT * FROM budget_catalog_nodes ORDER BY node_kind,node_code,id')->fetch_all(MYSQLI_ASSOC);
    // Natural ordering keeps W100 after W99 rather than between W10 and W11.
    usort($rows,fn($a,$b)=>strcmp($a['node_kind'],$b['node_kind'])?:strnatcasecmp($a['node_code'],$b['node_code'])?:((int)$a['id']<=>(int)$b['id']));
    return $rows;
}
function budget_catalog_parent_allowed(string $kind,array $parent): bool {
    $expected=['plan'=>'strategy','activity'=>'plan','group'=>'activity','work'=>'group'];
    if($kind==='group'&&$parent['node_kind']==='plan'&&$parent['parent_id']===null)return true;
    return isset($expected[$kind])&&$parent['node_kind']===$expected[$kind];
}
function budget_catalog_delete(mysqli $db,int $id): string {
    require_roles(['admin']);
    $q=$db->prepare('SELECT * FROM budget_catalog_nodes WHERE id=? FOR UPDATE');$q->bind_param('i',$id);$q->execute();$node=$q->get_result()->fetch_assoc();
    if(!$node)throw new DomainException('ไม่พบรายการที่ต้องการลบ หรือรายการถูกลบไปแล้ว');
    $q=$db->prepare('SELECT COUNT(*) n FROM budget_catalog_nodes WHERE parent_id=?');$q->bind_param('i',$id);$q->execute();
    if((int)$q->get_result()->fetch_assoc()['n'])throw new DomainException('รายการนี้มีรายการย่อยอยู่ กรุณาลบรายการย่อยก่อน หรือปิดใช้งานแทน');
    // Historic snapshots are references too, even if a link is no longer active.
    $path='$.'.$node['node_kind'].'_id';
    $q=$db->prepare("SELECT COUNT(*) n FROM budget_classifications WHERE CAST(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(snapshot_json),snapshot_json,'{}'),?)) AS UNSIGNED)=?");$q->bind_param('si',$path,$id);$q->execute();
    if((int)$q->get_result()->fetch_assoc()['n'])throw new DomainException('รายการนี้ถูกใช้ในบัญชีคุมงบประมาณแล้ว ลบไม่ได้ กรุณาปิดใช้งานแทนเพื่อรักษาประวัติและรายงานเดิม');
    $q=$db->prepare('SELECT a.*,p.program_code FROM budget_work_assignments a JOIN budget_programs p ON p.id=a.program_id WHERE a.work_id=? FOR UPDATE');$q->bind_param('i',$id);$q->execute();$links=$q->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach($links as $link) {
        $program=(int)$link['program_id'];
        $q=$db->prepare('SELECT COUNT(*) n FROM budget_classifications WHERE program_id=?');$q->bind_param('i',$program);$q->execute();
        if((int)$q->get_result()->fetch_assoc()['n'])throw new DomainException('งบงานนี้ถูกใช้ในบัญชีคุมงบประมาณแล้ว ลบไม่ได้ แม้จะยกเลิกการเชื่อมหน่วยแล้ว กรุณาปิดใช้งานแทน');
    }
    foreach($links as $link) {
        $unit=(int)$link['secondary_unit_id'];$program=(int)$link['program_id'];
        $q=$db->prepare('DELETE FROM budget_work_assignments WHERE work_id=? AND secondary_unit_id=?');$q->bind_param('ii',$id,$unit);$q->execute();
        $q=$db->prepare('DELETE FROM budget_programs WHERE id=?');$q->bind_param('i',$program);$q->execute();
        audit_event($db,'DELETE','budget_work_assignments',$id,$unit,$link);
        audit_event($db,'DELETE','budget_programs',$program,$unit,['program_code'=>$link['program_code']]);
    }
    $q=$db->prepare('DELETE FROM budget_catalog_nodes WHERE id=?');$q->bind_param('i',$id);$q->execute();
    audit_event($db,'DELETE','budget_catalog_nodes',$id,null,$node);return $node['node_kind'];
}
function budget_catalog_save(mysqli $db,array $d): int {
    require_roles(['admin']);$id=(int)($d['id']??0);$kind=(string)($d['node_kind']??'');
    $parents=['strategy'=>null,'plan'=>'strategy','activity'=>'plan','group'=>'activity','work'=>'group'];
    if(!array_key_exists($kind,$parents))throw new DomainException('ระดับโครงสร้างงบงานไม่ถูกต้อง');
    $parent=(int)($d['parent_id']??0)?:null;
    if($kind==='strategy')$parent=null;
    if($parent) {
        $q=$db->prepare('SELECT node_kind,parent_id,active FROM budget_catalog_nodes WHERE id=? FOR UPDATE');$q->bind_param('i',$parent);$q->execute();$p=$q->get_result()->fetch_assoc();
        if(!$p||!$p['active']||!budget_catalog_parent_allowed($kind,$p)||$parent===$id)throw new DomainException('กรุณาเลือกรายการระดับก่อนหน้าที่สัมพันธ์กัน');
    } elseif(!in_array($kind,['strategy','plan'],true))throw new DomainException('กรุณาเลือกรายการระดับก่อนหน้า');
    $code=organization_text($d,'node_code',$kind==='strategy'?20:50);$name=organization_text($d,'node_name');$active=isset($d['active'])?1:0;
    if($id) {
        $q=$db->prepare('SELECT * FROM budget_catalog_nodes WHERE id=? FOR UPDATE');$q->bind_param('i',$id);$q->execute();$old=$q->get_result()->fetch_assoc();
        if(!$old||$old['node_kind']!==$kind)throw new DomainException('ไม่พบข้อมูลระดับที่ต้องการแก้ไข');
        if((int)$old['parent_id']!==(int)$parent)throw new DomainException('รายการเดิมเปลี่ยนชื่อและรหัสได้ แต่การย้ายสายแผนงานให้สร้างใหม่เพื่อป้องกันการย้ายงานของหน่วยอื่น');
        $q=$db->prepare('UPDATE budget_catalog_nodes SET node_code=?,node_name=?,active=? WHERE id=?');$q->bind_param('ssii',$code,$name,$active,$id);
    } else {
        $q=$db->prepare('INSERT INTO budget_catalog_nodes(node_kind,node_code,node_name,parent_id,active) VALUES (?,?,?,?,?)');$q->bind_param('sssii',$kind,$code,$name,$parent,$active);
    }
    $q->execute();if(!$id)$id=(int)$db->insert_id;
    audit_event($db,'SAVE','budget_catalog_nodes',$id,null,['kind'=>$kind,'code'=>$code,'name'=>$name,'parent'=>$parent,'active'=>$active]);return $id;
}
function budget_catalog_works(mysqli $db,bool $activeOnly=true): array {
    $sql="SELECT w.id work_id,w.node_code work_code,w.node_name work_name,w.active work_active,
      g.id group_id,g.node_name work_group,g.active group_active,g.node_code _group_code,
      a.id activity_id,COALESCE(a.node_name,'-') activity_name,COALESCE(a.active,1) activity_active,
      p.id plan_id,p.node_name plan_name,p.active plan_active,p.node_code _plan_code,a.node_code _activity_code,
      s.id strategy_id,COALESCE(s.node_code,'-') strategy_no,COALESCE(s.node_name,'-') strategy_name,
      COALESCE(s.active,1) strategy_active
      FROM budget_catalog_nodes w JOIN budget_catalog_nodes g ON g.id=w.parent_id AND g.node_kind='group'
      LEFT JOIN budget_catalog_nodes a ON a.id=g.parent_id AND a.node_kind='activity'
      JOIN budget_catalog_nodes p ON p.id=CASE WHEN a.id IS NULL THEN g.parent_id ELSE a.parent_id END AND p.node_kind='plan'
      LEFT JOIN budget_catalog_nodes s ON s.id=p.parent_id AND s.node_kind='strategy'
      WHERE w.node_kind='work' AND (a.id IS NOT NULL OR p.parent_id IS NULL)";
    if($activeOnly)$sql.=' AND w.active=1 AND g.active=1 AND (a.id IS NULL OR a.active=1) AND p.active=1 AND (s.id IS NULL OR s.active=1)';
    $rows=$db->query($sql.' ORDER BY p.node_code,a.node_code,g.node_code,w.node_code')->fetch_all(MYSQLI_ASSOC);
    usort($rows,function($a,$b){
        foreach(['_plan_code','_activity_code','_group_code','work_code'] as $key){$order=strnatcasecmp((string)($a[$key]??''),(string)($b[$key]??''));if($order)return $order;}
        return (int)$a['work_id']<=>(int)$b['work_id'];
    });
    foreach($rows as &$row)unset($row['_plan_code'],$row['_activity_code'],$row['_group_code']);unset($row);
    return $rows;
}
function budget_catalog_programs(mysqli $db,bool $activeOnly=true): array {
    $works=[];foreach(budget_catalog_works($db,$activeOnly) as $w)$works[(int)$w['work_id']]=$w;
    $rows=$db->query('SELECT * FROM budget_work_assignments ORDER BY work_id,secondary_unit_id')->fetch_all(MYSQLI_ASSOC);$out=[];
    foreach($rows as $r) {
        if(!isset($works[(int)$r['work_id']])||($activeOnly&&!$r['active']))continue;
        $w=$works[(int)$r['work_id']];$out[]=array_merge($w,['id'=>$r['program_id'],'program_code'=>$w['work_code'],'responsible_unit_id'=>$r['secondary_unit_id'],'active'=>$r['active'],'project_name'=>$w['work_name']]);
    }return $out;
}
function budget_catalog_assign(mysqli $db,array $d): void {
    require_roles(['admin']);$sub=(int)($d['sub_unit_id']??0);$unit=(int)($d['secondary_unit_id']??0);
    $q=$db->prepare("SELECT s.unit_id FROM unit_structure s JOIN unit_structure p ON p.unit_id=s.parent_unit_id AND p.unit_level='sub' AND p.active=1 WHERE s.unit_id=? AND s.parent_unit_id=? AND s.unit_level='secondary' AND s.active=1 FOR UPDATE");$q->bind_param('ii',$unit,$sub);$q->execute();
    if(!$q->get_result()->fetch_assoc())throw new DomainException('หน่วยรองไม่อยู่ในหน่วยย่อยที่เลือก');
    $ids=$d['work_ids']??[];if(!is_array($ids)||!$ids)throw new DomainException('กรุณาเลือกงบงานอย่างน้อยหนึ่งรายการ');
    $works=[];foreach(budget_catalog_works($db) as $w)$works[(int)$w['work_id']]=$w;
    $ids=array_unique(array_map('intval',$ids));foreach($ids as $id)if(!isset($works[$id]))throw new DomainException('งบงานหรือแผนงานที่เลือกปิดใช้งานแล้ว');
    foreach($ids as $id) {
        $q=$db->prepare('SELECT program_id FROM budget_work_assignments WHERE work_id=? AND secondary_unit_id=? FOR UPDATE');$q->bind_param('ii',$id,$unit);$q->execute();$old=$q->get_result()->fetch_assoc();
        if(!$old) {
            // Stable adapter IDs preserve existing Bud foreign keys and financial workflows.
            $w=$works[$id];$code='CAT-'.$id.'-U-'.$unit;
            $q=$db->prepare('INSERT INTO budget_programs(program_code,responsible_unit_id,strategy_no,strategy_name,plan_name,activity_name,work_group,project_name,active) VALUES (?,?,?,?,?,?,?,?,1)');
            $q->bind_param('sissssss',$code,$unit,$w['strategy_no'],$w['strategy_name'],$w['plan_name'],$w['activity_name'],$w['work_group'],$w['work_name']);$q->execute();$program=(int)$db->insert_id;
            $q=$db->prepare('INSERT INTO budget_work_assignments(work_id,secondary_unit_id,program_id,active) VALUES (?,?,?,1)');$q->bind_param('iii',$id,$unit,$program);$q->execute();
        } else {
            $q=$db->prepare('UPDATE budget_work_assignments SET active=1 WHERE work_id=? AND secondary_unit_id=?');$q->bind_param('ii',$id,$unit);$q->execute();
        }
        audit_event($db,'SAVE','budget_work_assignments',$id,$unit,['active'=>1]);
    }
}
function budget_catalog_unassign(mysqli $db,array $d): void {
    require_roles(['admin']);$work=(int)($d['work_id']??0);$unit=(int)($d['secondary_unit_id']??0);
    $q=$db->prepare('UPDATE budget_work_assignments SET active=0 WHERE work_id=? AND secondary_unit_id=?');$q->bind_param('ii',$work,$unit);$q->execute();
    audit_event($db,'DISABLE','budget_work_assignments',$work,$unit,['active'=>0]);
}

<?php
require_once __DIR__.'/organization.php';
require_once __DIR__.'/ledger.php';
function report_signed_cents(string $amount): int { return str_starts_with($amount,'-')?-money_cents(substr($amount,1)):money_cents($amount); }
function report_period(int $year,int $month=0): array {
    if($year<2500||$year>2700||$month<0||$month>12)throw new DomainException('ปีงบประมาณหรือเดือนไม่ถูกต้อง');
    $ce=$year-543;$start=($ce-1).'-10-01';$end=$ce.'-09-30';
    if($month){$y=$month>=10?$ce-1:$ce;$start=sprintf('%04d-%02d-01',$y,$month);$end=date('Y-m-t',strtotime($start));}
    return [$start,$end];
}
function report_quarter(string $date): int { $m=(int)substr($date,5,2);return $m>=10?1:($m<=3?2:($m<=6?3:4)); }
function report_accounts(mysqli $db,array $filter): array {
    if(!organization_ready($db))throw new DomainException('กรุณาติดตั้งตารางจำแนกงบ v8 ก่อนใช้รายงานใหม่');
    $year=(int)($filter['year']??fiscal_year_for_date());$month=(int)($filter['month']??0);[$start,$end]=report_period($year,$month);
    $q=$db->prepare("SELECT ca.*,u.unit_name owner_name,bt.budget_types,COALESCE(bt.gfmis_date,bt.doc_date,DATE(ca.created_at)) receipt_date,bc.main_unit_id,bc.secondary_unit_id,bc.program_id,bc.expense_code,bc.snapshot_json FROM control_accounts ca JOIN units u ON u.id=ca.unit_id LEFT JOIN budget_transfers bt ON bt.id=ca.source_transfer_id LEFT JOIN budget_classifications bc ON bc.control_account_id=ca.id WHERE ca.fiscal_year=? AND ca.cancelled_at IS NULL AND (?=1 OR ca.unit_id=?) ORDER BY ca.id");
    $admin=is_admin()?1:0;$owner=current_unit_id();$q->bind_param('iii',$year,$admin,$owner);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);
    $selected=[];$result=[];
    foreach($rows as $r) {
        $s=$r['snapshot_json']?json_decode($r['snapshot_json'],true,512,JSON_THROW_ON_ERROR):[];
        if((int)($filter['unit_id']??0)&&(int)$r['unit_id']!==(int)$filter['unit_id'])continue;
        if((int)($filter['main_unit_id']??0)&&((int)$r['main_unit_id']!==(int)$filter['main_unit_id']))continue;
        if((int)($filter['secondary_unit_id']??0)&&((int)$r['secondary_unit_id']!==(int)$filter['secondary_unit_id']))continue;
        if((int)($filter['program_id']??0)&&((int)$r['program_id']!==(int)$filter['program_id']))continue;
        foreach(['work_id','group_id','activity_id','plan_id','strategy_id'] as $key)if((int)($filter[$key]??0)&&(int)($s[$key]??0)!==(int)$filter[$key])continue 2;
        foreach(['plan_name','work_group','project_name','cost_center'] as $key)if(($filter[$key]??'')!==''&&($s[$key]??'')!==$filter[$key])continue 2;
        if(($filter['budget_type']??'')!==''&&!in_array($filter['budget_type'],explode(',',(string)$r['budget_types']),true))continue;
        $r['classification']=$s;$selected[]=$r;
    }
    // Bound prefetch memory and avoid three queries for every individual account.
    foreach(array_chunk($selected,100) as $batch) {
      $data=report_account_batch($db,array_column($batch,'id'),$end);
      foreach($batch as $r) {
        $r['opening']=0;$r['increase']=0;$r['transfer_out']=0;$r['return_army']=0;$r['carry']=0;$r['request']=0;$r['obligation']=0;$r['payment']=0;$r['remittance']=0;
        $r['allocated']=0;$r['cash']=0;$r['available']=0;$r['gf_paid']=0;$r['gf_unconfirmed']=0;$r['gf_period']=0;$r['period_payment']=0;
        $r['quarters']=array_fill(1,4,['received'=>0,'payment'=>0,'obligation'=>0,'gf_paid'=>0]);$r['plans']=[];$r['entries']=[];
        $receipt=$r['receipt_date'];$received=report_signed_cents((string)$r['received_amount']);
        if($receipt<=$end){$r['allocated']=$received;$r['cash']=$received;if($receipt<$start)$r['opening']=$received;else $r['increase']=$received;$r['quarters'][report_quarter($receipt)]['received']+=$received;}
        foreach($data['entries'][$r['id']]??[] as $entry) {
            $c=report_signed_cents((string)$entry['amount']);$type=$entry['entry_type'];$date=$entry['entry_date'];$quarter=report_quarter($date);
            if($type==='increase'){$r['allocated']+=$c;$r['cash']+=$c;$r['quarters'][$quarter]['received']+=$c;}
            if(in_array($type,['transfer_out','return_army','payment','remittance'],true))$r['cash']-=$c;
            if(in_array($type,['transfer_out','return_army'],true))$r['allocated']-=$c;
            if(in_array($type,['carry','request','obligation'],true))$r[$type]+=$c;
            if($type==='payment'){$r['payment']+=$c;$r['quarters'][$quarter]['payment']+=$c;$confirmed=$entry['confirmed_amount']!==null&&$entry['confirmed_date']<=$end?report_signed_cents((string)$entry['confirmed_amount']):0;$r['gf_unconfirmed']+=$c-$confirmed;}
            if($type==='obligation')$r['quarters'][$quarter]['obligation']+=$c;
            if($date<$start){if($type==='increase')$r['opening']+=$c;elseif(in_array($type,['transfer_out','return_army','payment','remittance'],true))$r['opening']-=$c;}
            else {
                $r['entries'][]=$entry;
                if(in_array($type,['increase','transfer_out','return_army','remittance'],true))$r[$type]+=$c;
                if($type==='payment')$r['period_payment']+=$c;
            }
        }
        // A GFMIS confirmation may be recorded in a later month than payment.
        foreach($data['confirmations'][$r['id']]??[] as $confirmation){$c=report_signed_cents((string)$confirmation['confirmed_amount']);$r['gf_paid']+=$c;$r['quarters'][report_quarter($confirmation['confirmed_date'])]['gf_paid']+=$c;if($confirmation['confirmed_date']>=$start)$r['gf_period']+=$c;}
        $r['available']=$r['cash']-$r['carry']-$r['request']-$r['obligation'];
        foreach($data['plans'][$r['id']]??[] as $p)$r['plans'][(int)$p['quarter_no']]=$p;
        $result[]=$r;
      }
    }return $result;
}
function report_account_batch(mysqli $db,array $ids,string $end): array {
    if(!$ids||count($ids)>100)throw new InvalidArgumentException('Report batch must contain 1-100 accounts.');
    $ids=implode(',',array_map('intval',$ids));
    $queries=[
        'entries'=>"SELECT e.*,g.confirmed_date,g.confirmed_amount FROM active_control_entries e LEFT JOIN gfmis_payment_confirmations g ON g.ledger_entry_id=e.id WHERE e.control_account_id IN ($ids) AND e.entry_date<=? ORDER BY e.control_account_id,e.entry_date,e.id",
        'confirmations'=>"SELECT e.control_account_id,g.confirmed_date,g.confirmed_amount FROM gfmis_payment_confirmations g JOIN control_account_entries e ON e.id=g.ledger_entry_id WHERE e.control_account_id IN ($ids) AND e.voided_at IS NULL AND g.confirmed_date<=? ORDER BY e.control_account_id,g.ledger_entry_id",
        'plans'=>"SELECT * FROM budget_quarter_plans WHERE control_account_id IN ($ids) ORDER BY control_account_id,quarter_no"
    ];$data=[];
    foreach($queries as $key=>$sql){
        $q=$db->prepare($sql);if($key!=='plans')$q->bind_param('s',$end);$q->execute();$data[$key]=[];
        foreach($q->get_result() as $row){$account=$row['control_account_id'];if($key==='confirmations')unset($row['control_account_id']);$data[$key][$account][]=$row;}
        $q->close();
    }return $data;
}
function report_groups(array $rows,string $view): array {
    $groups=[];$fields=['opening','increase','transfer_out','return_army','carry','request','obligation','payment','period_payment','remittance','cash','available','allocated','gf_paid','gf_period','gf_unconfirmed'];
    foreach($rows as $r) {
        $s=$r['classification'];
        $key=match($view){'unit'=>(string)($r['main_unit_id']??'legacy-'.$r['unit_id']),'cost'=>(string)($s['cost_center']??'legacy-'.$r['unit_id']),'gfmis_cost'=>(string)($s['cost_center']??'legacy-'.$r['unit_id']).'|'.$r['gfmis_code'],default=>(string)$r['id']};
        $label=match($view){'unit'=>$s['main_unit']??('ยังไม่จำแนก · '.$r['owner_name']),'cost','gfmis_cost'=>($s['cost_center']??'ไม่ระบุศูนย์ต้นทุน').($view==='gfmis_cost'?' · '.$r['gfmis_code']:''),default=>$r['budget_code'].' · '.$r['item_name']};
        if(!isset($groups[$key])){$groups[$key]=['label'=>$label,'count'=>0,'quarters'=>array_fill(1,4,['received'=>0,'payment'=>0,'gf_paid'=>0,'obligation'=>0])];foreach($fields as $field)$groups[$key][$field]=0;}
        $g=&$groups[$key];$g['count']++;foreach($fields as $field)$g[$field]+=$r[$field];foreach($r['quarters'] as $quarter=>$q)foreach($q as $field=>$value)$g['quarters'][$quarter][$field]+=$value;unset($g);
    }return array_values($groups);
}
function report_plan_save(mysqli $db,int $account,array $input): void {
    require_permission('budget.edit');$q=$db->prepare('SELECT * FROM control_accounts WHERE id=? AND cancelled_at IS NULL FOR UPDATE');$q->bind_param('i',$account);$q->execute();$r=$q->get_result()->fetch_assoc();
    if(!$r||!can_access_unit((int)$r['unit_id']))throw new DomainException('ไม่มีสิทธิ์แก้แผนของบัญชีนี้');
    $lock=$db->prepare('CALL rpbs_assert_open(?,?,NULL)');$lock->bind_param('ii',$r['unit_id'],$r['fiscal_year']);$lock->execute();$lock->close();while($db->more_results()&&$db->next_result())if($res=$db->store_result())$res->free();
    foreach(range(1,4) as $quarter){$d=$input[$quarter]??[];$op=($d['obligation_plan']??'')===''?null:money_decimal(money_cents((string)$d['obligation_plan']));$pp=($d['payment_plan']??'')===''?null:money_decimal(money_cents((string)$d['payment_plan']));$unit=organization_text($d,'output_unit',50,false);$out=($d['output_plan']??'')===''?null:money_decimal(money_cents((string)$d['output_plan']));$actual=($d['output_actual']??'')===''?null:money_decimal(money_cents((string)$d['output_actual']));
        $q=$db->prepare('INSERT INTO budget_quarter_plans(control_account_id,quarter_no,obligation_plan,payment_plan,output_unit,output_plan,output_actual) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE obligation_plan=VALUES(obligation_plan),payment_plan=VALUES(payment_plan),output_unit=VALUES(output_unit),output_plan=VALUES(output_plan),output_actual=VALUES(output_actual)');$q->bind_param('iisssss',$account,$quarter,$op,$pp,$unit,$out,$actual);$q->execute();
    }audit_event($db,'PLAN','control_accounts',$account,(int)$r['unit_id'],$input);
}
function report_gfmis_confirm(mysqli $db,int $entry,array $d): void {
    require_permission('voucher.pay');$q=$db->prepare("SELECT e.*,ca.unit_id,ca.fiscal_year FROM control_account_entries e JOIN control_accounts ca ON ca.id=e.control_account_id WHERE e.id=? AND e.entry_type='payment' AND e.voided_at IS NULL AND ca.cancelled_at IS NULL FOR UPDATE");$q->bind_param('i',$entry);$q->execute();$r=$q->get_result()->fetch_assoc();
    if(!$r||!can_access_unit((int)$r['unit_id']))throw new DomainException('ไม่พบยอดตัดจ่ายที่มีสิทธิ์ยืนยัน');
    $date=organization_text($d,'confirmed_date',10);validate_fiscal_date($date,(int)$r['fiscal_year']);if($date<$r['entry_date'])throw new DomainException('วันที่ยืนยัน GF ต้องไม่ก่อนวันที่ตัดจ่าย');
    $c=money_cents((string)($d['confirmed_amount']??'0'));if($c<=0||$c>money_cents((string)$r['amount']))throw new DomainException('ยอดยืนยัน GF ต้องมากกว่า 0 และไม่เกินยอดตัดจ่าย');$amount=money_decimal($c);$ref=organization_text($d,'reference_no',100);$actor=current_user_id();
    $lock=$db->prepare('CALL rpbs_assert_open(?,?,?)');$lock->bind_param('iis',$r['unit_id'],$r['fiscal_year'],$date);$lock->execute();$lock->close();while($db->more_results()&&$db->next_result())if($res=$db->store_result())$res->free();
    $q=$db->prepare('INSERT INTO gfmis_payment_confirmations(ledger_entry_id,confirmed_date,confirmed_amount,reference_no,created_by) VALUES (?,?,?,?,?)');$q->bind_param('isssi',$entry,$date,$amount,$ref,$actor);$q->execute();audit_event($db,'GF_CONFIRM','control_account_entries',$entry,(int)$r['unit_id'],['date'=>$date,'amount'=>$amount,'reference'=>$ref]);
}

function report_gfmis_edit(mysqli $db, int $entry, array $d, bool $cancel = false): void
{
    require_permission('voucher.pay');
    $q=$db->prepare("SELECT e.*,ca.unit_id,ca.fiscal_year FROM control_account_entries e JOIN control_accounts ca ON ca.id=e.control_account_id WHERE e.id=? AND e.entry_type='payment' AND e.voided_at IS NULL AND ca.cancelled_at IS NULL FOR UPDATE");$q->bind_param('i',$entry);$q->execute();$row=$q->get_result()->fetch_assoc();
    if(!$row||!can_access_unit((int)$row['unit_id']))throw new RuntimeException('ไม่พบรายการหรือไม่มีสิทธิ์',403);
    $q=$db->prepare('SELECT * FROM gfmis_payment_confirmations WHERE ledger_entry_id=? FOR UPDATE');$q->bind_param('i',$entry);$q->execute();$old=$q->get_result()->fetch_assoc();
    if(!$old)throw new DomainException('ไม่พบยอดยืนยัน');
    $reason=organization_text($d,'reason',255);if(mb_strlen($reason)<5)throw new DomainException('กรุณาระบุเหตุผลอย่างน้อย 5 ตัวอักษร');
    ledger_assert_date_open($db,(int)$row['unit_id'],(int)$row['fiscal_year'],$old['confirmed_date']);
    if($cancel){$q=$db->prepare('DELETE FROM gfmis_payment_confirmations WHERE ledger_entry_id=?');$q->bind_param('i',$entry);}
    else{
        $date=organization_text($d,'confirmed_date',10);validate_fiscal_date($date,(int)$row['fiscal_year']);
        $amount=money_decimal(money_cents((string)($d['confirmed_amount']??'0')));$ref=organization_text($d,'reference_no',100);
        if($date<$row['entry_date']||money_cents($amount)<=0||money_cents($amount)>money_cents($row['amount']))throw new DomainException('วันที่หรือยอดยืนยันต้องสอดคล้องกับยอดตัดจ่าย');
        ledger_assert_date_open($db,(int)$row['unit_id'],(int)$row['fiscal_year'],$date);
        $q=$db->prepare('UPDATE gfmis_payment_confirmations SET confirmed_date=?,confirmed_amount=?,reference_no=? WHERE ledger_entry_id=?');$q->bind_param('sssi',$date,$amount,$ref,$entry);
    }
    $q->execute();audit_event($db,$cancel?'GF_CANCEL':'GF_EDIT','control_account_entries',$entry,(int)$row['unit_id'],['before'=>$old,'reason'=>$reason,'input'=>array_intersect_key($d,array_flip(['confirmed_date','confirmed_amount','reference_no']))]);
}

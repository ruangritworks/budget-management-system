<?php
require_once __DIR__ . '/ledger.php';
require_once __DIR__ . '/archive.php';

function unit_financial_summary(mysqli $conn, int $year, int $unit = 0): array
{
    $params = [$year]; $types = 'i';
    if ($unit > 0) { $params[]=$unit; $types.='i'; }
    $filter = $unit > 0 ? ' AND ca.unit_id=?' : '';
    $query = $conn->prepare("SELECT COALESCE(SUM(ca.received_amount),0) received FROM control_accounts ca WHERE ca.fiscal_year=? AND ca.cancelled_at IS NULL" . $filter);
    $query->bind_param($types,...$params);$query->execute();
    $received = money_cents($query->get_result()->fetch_assoc()['received']);
    $query = $conn->prepare("SELECT e.entry_type,COALESCE(SUM(e.amount),0) amount FROM active_control_entries e JOIN control_accounts ca ON ca.id=e.control_account_id WHERE ca.fiscal_year=? AND ca.cancelled_at IS NULL" . $filter . ' GROUP BY e.entry_type');
    $query->bind_param($types,...$params);$query->execute();
    $totals=array_fill_keys(['increase','transfer_out','return_army','carry','request','obligation','payment','remittance'], '0.00');
    foreach($query->get_result()->fetch_all(MYSQLI_ASSOC) as $row)$totals[$row['entry_type']]=$row['amount'];
    $query=$conn->prepare('SELECT COALESCE(SUM(base_amount),0) base FROM budget_allocations WHERE fiscal_year=?'.($unit>0?' AND unit_id=?':''));
    $query->bind_param($types,...$params);$query->execute();$base=money_cents($query->get_result()->fetch_assoc()['base']);
    $query=$conn->prepare("SELECT COALESCE(SUM(amount),0) legacy FROM budget_transactions WHERE fiscal_year=? AND ledger_entry_id IS NULL AND cancelled_at IS NULL AND trans_type IN ('transfer_out','use','advance','po_reserve','payment')".($unit>0?' AND unit_id=?':''));
    $query->bind_param($types,...$params);$query->execute();$legacy=money_cents($query->get_result()->fetch_assoc()['legacy']);
    $cash=ledger_balance_cents(money_decimal($received),$totals);
    $reserved=money_cents($totals['carry'])+money_cents($totals['request'])+money_cents($totals['obligation']);
    return ['base'=>money_decimal($base),'received'=>money_decimal($received),'increase'=>$totals['increase'],'paid'=>$totals['payment'],'outgoing'=>money_decimal(money_cents($totals['transfer_out'])+money_cents($totals['return_army'])+money_cents($totals['remittance'])),'cash_balance'=>money_decimal($cash),'reserved'=>money_decimal($reserved),'available'=>money_decimal($cash-$reserved),'legacy_unlinked'=>money_decimal($legacy),'unit_available'=>money_decimal($base+$cash-$reserved-$legacy),'totals'=>$totals];
}

function period_bounds(int $year, int $month): array
{
    if ($year < 2500 || $year > 3000 || $month < 0 || $month > 12) throw new RuntimeException('ปีหรือเดือนที่เลือกไม่ถูกต้อง');
    if ($month===0) return [($year-544).'-10-01',($year-543).'-09-30'];
    $start=sprintf('%04d-%02d-01',$year-543-($month>=10?1:0),$month);
    return [$start,(new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d')];
}

function period_close(mysqli $conn,int $unit,int $year,int $month,string $reason): void
{
    require_roles(['admin']);
    if($unit<1||mb_strlen(trim($reason))<5)throw new RuntimeException('กรุณาเลือกหน่วยและระบุเหตุผลอย่างน้อย 5 ตัวอักษร');
    [$start,$end]=period_bounds($year,$month);
    $conn->query('INSERT IGNORE INTO fiscal_periods(unit_id,fiscal_year,period_month) VALUES('.$unit.','.$year.',0),('.$unit.','.$year.','.$month.')');
    $stmt=$conn->prepare('SELECT * FROM fiscal_periods WHERE unit_id=? AND fiscal_year=? AND period_month IN (0,?) ORDER BY period_month FOR UPDATE');
    $stmt->bind_param('iii',$unit,$year,$month);$stmt->execute();
    foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $period)if($period['closed'])throw new RuntimeException('งวดหรือปีงบประมาณนี้ปิดแล้ว');
    foreach(['voucher','advance','po'] as $module){
        $cfg=financial_config($module);
        $sql='SELECT COUNT(*) count FROM '.$cfg['table'].' WHERE unit_id=? AND fiscal_year=? AND cancelled_at IS NULL AND '.$cfg['date'].' BETWEEN ? AND ? AND (control_account_id IS NULL'.($month===0?' OR status=\''.$cfg['open'].'\'':'').')';
        $stmt=$conn->prepare($sql);$stmt->bind_param('iiss',$unit,$year,$start,$end);$stmt->execute();
        if((int)$stmt->get_result()->fetch_assoc()['count'])throw new RuntimeException('ยังมี'.$cfg['title'].'รอผูกบัญชีคุมงบประมาณ'.($month===0?'หรือยังไม่ปิดรายการ':'').' กรุณาตรวจให้ครบก่อนปิดงวด');
    }
    $stmt=$conn->prepare("SELECT COUNT(*) count FROM budget_transactions WHERE unit_id=? AND fiscal_year=? AND trans_date BETWEEN ? AND ? AND ledger_entry_id IS NULL AND cancelled_at IS NULL AND trans_type IN ('transfer_out','use','advance','po_reserve','payment')");
    $stmt->bind_param('iiss',$unit,$year,$start,$end);$stmt->execute();
    if((int)$stmt->get_result()->fetch_assoc()['count'])throw new RuntimeException('ยังมีรายการการเงินเดิมรอผูกบัญชีคุมงบประมาณ กรุณาตรวจหน้ากระทบยอด');
    $summary=unit_financial_summary($conn,$year,$unit);
    $snapshot=['unit_id'=>$unit,'fiscal_year'=>$year,'month'=>$month,'from'=>$start,'to'=>$end,'closed_at'=>date(DATE_ATOM),'summary'=>$summary,'reason'=>$reason];
    $json=json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $user=current_user_id();
    $stmt=$conn->prepare('UPDATE fiscal_periods SET closed=1,closed_at=NOW(),closed_by=?,reason=?,summary_json=? WHERE unit_id=? AND fiscal_year=? AND period_month=?');
    $stmt->bind_param('issiii',$user,$reason,$json,$unit,$year,$month);$stmt->execute();
    snapshot_store($conn,'PERIOD_CLOSE',$year*100+$month,$unit,$year,'period-'.$unit.'-'.$year.'-'.$month.'.json','application/json',$json,$snapshot);
}

function period_reopen(mysqli $conn,int $unit,int $year,int $month,string $reason): void
{
    require_roles(['admin']);
    period_bounds($year,$month);
    if($unit<1||mb_strlen(trim($reason))<5)throw new RuntimeException('กรุณาระบุหน่วยและเหตุผลเปิดงวดอย่างน้อย 5 ตัวอักษร');
    $stmt=$conn->prepare('UPDATE fiscal_periods SET closed=0,reason=?,closed_by=? WHERE unit_id=? AND fiscal_year=? AND period_month=? AND closed=1');
    $user=current_user_id();$stmt->bind_param('siiii',$reason,$user,$unit,$year,$month);$stmt->execute();
    if($stmt->affected_rows!==1)throw new RuntimeException('ไม่พบงวดที่ปิดอยู่');
}

<?php
require_once __DIR__.'/ledger.php';

function allocation_save(mysqli $conn, int $unit, int $year, string $amount, string $reason = '', bool $cancel = false): void
{
    require_roles(['admin','clerk']);
    if(!can_access_unit($unit)||$unit<1)throw new RuntimeException('ไม่มีสิทธิ์ในหน่วยนี้',403);
    if($year<2500||$year>3000)throw new DomainException('ปีงบประมาณไม่ถูกต้อง');
    $q=$conn->prepare('SELECT * FROM budget_allocations WHERE unit_id=? AND fiscal_year=? FOR UPDATE');$q->bind_param('ii',$unit,$year);$q->execute();$old=$q->get_result()->fetch_assoc();
    if(($old||$cancel)&&(mb_strlen(trim($reason))<5||mb_strlen($reason)>255))throw new DomainException('กรุณาระบุเหตุผลแก้ไขหรือลบ 5–255 ตัวอักษร');
    if($cancel&&!$old)throw new DomainException('ไม่พบงบตั้งต้นที่จะลบ');
    $amount=$cancel?'0.00':money_decimal(money_cents($amount));$user=current_user_id();
    $q=$conn->prepare('INSERT INTO budget_allocations(unit_id,fiscal_year,base_amount,created_by) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE base_amount=VALUES(base_amount),created_by=VALUES(created_by)');$q->bind_param('iisi',$unit,$year,$amount,$user);$q->execute();
    $id=$old?(int)$old['id']:(int)$conn->insert_id;
    $date=($year-543).'-09-30';
    db_replace_transaction($conn,$unit,$year,$date,'base',(float)$amount,'budget_allocations',$id,'งบประมาณตั้งต้น',$user);
    $q=$conn->prepare("UPDATE budget_transactions SET cancelled_at=".($cancel?'NOW()':'NULL')." WHERE ref_table='budget_allocations' AND ref_id=?");$q->bind_param('i',$id);$q->execute();
    audit_event($conn,$cancel?'CANCEL':($old?'EDIT':'INSERT'),'budget_allocations',$id,$unit,['reason'=>$reason,'amount'=>$amount]);
}

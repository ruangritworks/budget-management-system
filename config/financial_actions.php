<?php
require_once __DIR__.'/ledger.php';

function financial_source_url(?string $table, int $id, int $year): ?string
{
    if ($id < 1) return null;
    $pages = ['payment_vouchers'=>'payment_vouchers.php','budget_advances'=>'budget_advances.php','po_reservations'=>'po_reservations.php','budget_disbursements'=>'transfer_round2.php'];
    if (isset($pages[$table ?? ''])) return $pages[$table].'?year='.$year.'&edit='.$id;
    if ($table === 'budget_transfers') return 'edit_budget.php?id='.$id;
    if ($table === 'budget_allocations') return 'allocations.php?year='.$year.'&edit='.$id;
    if ($table === 'reservation_releases') return 'financial_entry.php?origin=release&id='.$id;
    return null;
}

function financial_entry_url(array $entry, int $year): string
{
    return financial_source_url($entry['source_table'] ?? null, (int)($entry['source_id'] ?? 0), $year)
        ?? 'financial_entry.php?origin=ledger&id='.(int)$entry['id'];
}

function financial_legacy_edit(mysqli $conn, int $id, array $input, bool $cancel): void
{
    require_permission('control.manage');
    $q=$conn->prepare('SELECT * FROM budget_transactions WHERE id=? AND cancelled_at IS NULL FOR UPDATE');
    $q->bind_param('i',$id);$q->execute();$old=$q->get_result()->fetch_assoc();
    if(!$old||!can_access_unit((int)$old['unit_id']))throw new RuntimeException('ไม่พบรายการหรือไม่มีสิทธิ์',403);
    if($old['ref_table']||$old['ledger_entry_id'])throw new DomainException('กรุณาแก้ไขจากเอกสารต้นทาง');
    ledger_assert_date_open($conn,(int)$old['unit_id'],(int)$old['fiscal_year'],$old['trans_date']);
    $reason=trim((string)($input['reason']??''));
    if(mb_strlen($reason)<5||mb_strlen($reason)>255)throw new DomainException('กรุณาระบุเหตุผล 5–255 ตัวอักษร');
    if($cancel){
        $q=$conn->prepare('UPDATE budget_transactions SET cancelled_at=NOW() WHERE id=?');$q->bind_param('i',$id);
    }else{
        $date=(string)($input['entry_date']??'');validate_fiscal_date($date,(int)$old['fiscal_year']);
        $amount=money_decimal(money_cents((string)($input['amount']??'0')));if(money_cents($amount)<=0)throw new DomainException('จำนวนเงินต้องมากกว่า 0');
        $note=trim((string)($input['note']??''));
        $q=$conn->prepare('UPDATE budget_transactions SET trans_date=?,amount=?,remark=? WHERE id=?');$q->bind_param('sssi',$date,$amount,$note,$id);
    }
    $q->execute();audit_event($conn,$cancel?'CANCEL':'EDIT','budget_transactions',$id,(int)$old['unit_id'],['reason'=>$reason]);
}

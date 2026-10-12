<?php
require_once __DIR__ . '/ledger.php';

function transfer_cancel(mysqli $conn, int $id, string $reason): void
{
    require_permission('budget.delete');
    if (mb_strlen(trim($reason)) < 5) throw new RuntimeException('กรุณาระบุเหตุผลยกเลิกอย่างน้อย 5 ตัวอักษร');
    $stmt=$conn->prepare('SELECT * FROM budget_transfers WHERE id=? AND cancelled_at IS NULL FOR UPDATE');$stmt->bind_param('i',$id);$stmt->execute();$doc=$stmt->get_result()->fetch_assoc();
    if(!$doc||!can_access_unit((int)$doc['unit_id']))throw new RuntimeException('ไม่พบเอกสารหรือไม่มีสิทธิ์ในหน่วยงานนี้',403);
    ledger_assert_date_open($conn,(int)$doc['unit_id'],(int)$doc['fiscal_year'],$doc['doc_date']);
    $stmt=$conn->prepare('SELECT id FROM control_accounts WHERE source_transfer_id=? FOR UPDATE');$stmt->bind_param('i',$id);$stmt->execute();$control=$stmt->get_result()->fetch_assoc();
    if($control){
        foreach(['payment_vouchers','budget_advances','po_reservations'] as $table){
            $active=$conn->prepare('SELECT id FROM '.$table.' WHERE control_account_id=? AND cancelled_at IS NULL LIMIT 1 FOR UPDATE');$active->bind_param('i',$control['id']);$active->execute();
            if($active->get_result()->fetch_assoc())throw new DomainException('ยังมีฎีกา เงินยืม หรือ PO อ้างอิงบัญชีนี้ กรุณายกเลิกเอกสารต้นทางก่อนลบงบ');
        }
        $totals=ledger_totals($conn,(int)$control['id']);
        foreach($totals as $value)if(money_cents($value)!==0)throw new RuntimeException('บัญชีนี้ยังมีรายการเคลื่อนไหว ต้องตรวจและยกเลิกเอกสารต้นทางก่อน');
        $stmt=$conn->prepare('UPDATE control_accounts SET cancelled_at=NOW(),cancelled_by=?,cancel_reason=? WHERE id=?');$user=current_user_id();$stmt->bind_param('isi',$user,$reason,$control['id']);$stmt->execute();
    }
    $stmt=$conn->prepare('SELECT COUNT(*) n FROM budget_disbursements WHERE source_transfer_id=? AND cancelled_at IS NULL');$stmt->bind_param('i',$id);$stmt->execute();
    if((int)$stmt->get_result()->fetch_assoc()['n'])throw new RuntimeException('ยังมีรายงานเบิกรอบ 2 ที่ไม่ถูกยกเลิก');
    $stmt=$conn->prepare("UPDATE budget_transactions SET cancelled_at=NOW() WHERE ref_table='budget_transfers' AND ref_id=?");$stmt->bind_param('i',$id);$stmt->execute();
    $stmt=$conn->prepare('UPDATE budget_transfers SET cancelled_at=NOW(),cancelled_by=?,cancel_reason=? WHERE id=?');$user=current_user_id();$stmt->bind_param('isi',$user,$reason,$id);$stmt->execute();
}

function round2_create(mysqli $conn,array $input): int
{
    require_permission('round2.manage');
    $sourceId=(int)($input['source_transfer_id']??0);
    $stmt=$conn->prepare('SELECT bt.*,ca.id control_id FROM budget_transfers bt JOIN control_accounts ca ON ca.source_transfer_id=bt.id WHERE bt.id=? AND bt.cancelled_at IS NULL AND ca.cancelled_at IS NULL FOR UPDATE');$stmt->bind_param('i',$sourceId);$stmt->execute();$source=$stmt->get_result()->fetch_assoc();
    if(!$source||!can_access_unit((int)$source['unit_id']))throw new RuntimeException('ไม่พบเอกสารต้นทางหรือไม่มีสิทธิ์',403);
    $date=(string)($input['report_date']??'');validate_fiscal_date($date,(int)$source['fiscal_year']);
    $number=trim((string)($input['report_no']??''));$description=trim((string)($input['description']??''));$amount=money_decimal(money_cents((string)($input['amount']??'0')));
    if($number===''||$description===''||mb_strlen($description)>255||money_cents($amount)<=0)throw new RuntimeException('กรุณากรอกเลขรายงาน รายละเอียด และจำนวนเงินให้ครบ');
    $user=current_user_id();$stmt=$conn->prepare('INSERT INTO budget_disbursements(source_transfer_id,unit_id,fiscal_year,report_no,report_date,description,amount,created_by) VALUES(?,?,?,?,?,?,?,?)');$stmt->bind_param('iiissssi',$sourceId,$source['unit_id'],$source['fiscal_year'],$number,$date,$description,$amount,$user);$stmt->execute();$id=(int)$conn->insert_id;
    $entryId=ledger_add($conn,(int)$source['control_id'],$date,'payment',$amount,(int)$source['unit_id'],(string)$source['gfmis_code'],(string)$source['activity_code'],1,(string)$source['expense_category'],'รายงานรอบ 2 '.$number.': '.$description,$user,'budget_disbursements',$id);
    $stmt=$conn->prepare('UPDATE budget_disbursements SET ledger_entry_id=? WHERE id=?');$stmt->bind_param('ii',$entryId,$id);$stmt->execute();return $id;
}

function round2_cancel(mysqli $conn,int $id,string $reason): void
{
    require_permission('round2.manage');
    if(mb_strlen(trim($reason))<5)throw new RuntimeException('กรุณาระบุเหตุผลยกเลิก');
    $stmt=$conn->prepare('SELECT * FROM budget_disbursements WHERE id=? AND cancelled_at IS NULL FOR UPDATE');$stmt->bind_param('i',$id);$stmt->execute();$doc=$stmt->get_result()->fetch_assoc();
    if(!$doc||!can_access_unit((int)$doc['unit_id']))throw new RuntimeException('ไม่พบรายการหรือไม่มีสิทธิ์',403);
    if($doc['ledger_entry_id'])ledger_void($conn,(int)$doc['ledger_entry_id'],$reason,true);
    $stmt=$conn->prepare('UPDATE budget_disbursements SET cancelled_at=NOW() WHERE id=?');$stmt->bind_param('i',$id);$stmt->execute();
    $stmt=$conn->prepare("UPDATE budget_transactions SET cancelled_at=NOW() WHERE ref_table='budget_disbursements' AND ref_id=?");$stmt->bind_param('i',$id);$stmt->execute();
    audit_event($conn,'CANCEL','budget_disbursements',$id,(int)$doc['unit_id'],['reason'=>$reason]);
}

function round2_edit(mysqli $conn, int $id, array $input): void
{
    require_permission('round2.manage');
    $q=$conn->prepare('SELECT * FROM budget_disbursements WHERE id=? AND cancelled_at IS NULL FOR UPDATE');$q->bind_param('i',$id);$q->execute();$doc=$q->get_result()->fetch_assoc();
    if(!$doc||!can_access_unit((int)$doc['unit_id']))throw new RuntimeException('ไม่พบรายการหรือไม่มีสิทธิ์',403);
    $q=$conn->prepare('SELECT id FROM control_accounts WHERE source_transfer_id=? AND cancelled_at IS NULL FOR UPDATE');$q->bind_param('i',$doc['source_transfer_id']);$q->execute();$row=$q->get_result()->fetch_assoc();
    if(!$row)throw new DomainException('ไม่พบบัญชีคุมต้นทาง');
    $account=ledger_account($conn,(int)$row['id']);
    $date=(string)($input['report_date']??'');validate_fiscal_date($date,(int)$doc['fiscal_year']);
    $number=trim((string)($input['report_no']??''));$description=trim((string)($input['description']??''));$reason=trim((string)($input['reason']??''));$amount=money_decimal(money_cents((string)($input['amount']??'0')));
    if($number===''||mb_strlen($number)>50||$description===''||mb_strlen($description)>255||mb_strlen($reason)<5||mb_strlen($reason)>255||money_cents($amount)<=0)throw new DomainException('กรุณากรอกเลขรายงาน รายละเอียด ยอดเงิน และเหตุผลให้ครบ');
    $entryId=(int)$doc['ledger_entry_id'];
    if($entryId)ledger_edit($conn,$entryId,['entry_date'=>$date,'amount'=>$amount,'note'=>'รายงานรอบ 2 '.$number.': '.$description,'reason'=>$reason],true);
    else $entryId=ledger_add($conn,(int)$account['id'],$date,'payment',$amount,(int)$doc['unit_id'],(string)$account['gfmis_code'],(string)$account['activity_code'],1,'','รายงานรอบ 2 '.$number.': '.$description,current_user_id(),'budget_disbursements',$id);
    $q=$conn->prepare('UPDATE budget_disbursements SET report_no=?,report_date=?,description=?,amount=?,ledger_entry_id=? WHERE id=?');$q->bind_param('ssssii',$number,$date,$description,$amount,$entryId,$id);$q->execute();
    $q=$conn->prepare("UPDATE budget_transactions SET ledger_entry_id=?,cancelled_at=NOW() WHERE ref_table='budget_disbursements' AND ref_id=?");$q->bind_param('ii',$entryId,$id);$q->execute();
    audit_event($conn,'EDIT','budget_disbursements',$id,(int)$doc['unit_id'],['reason'=>$reason]);
}

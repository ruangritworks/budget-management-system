<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/organization.php';

function ledger_totals(mysqli $conn, int $controlId): array
{
    $stmt = $conn->prepare('SELECT entry_type,COALESCE(SUM(amount),0) amount FROM active_control_entries WHERE control_account_id=? GROUP BY entry_type');
    $stmt->bind_param('i', $controlId);
    $stmt->execute();
    $totals = array_fill_keys(['increase','transfer_out','return_army','carry','request','obligation','payment','remittance'], '0.00');
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $totals[$row['entry_type']] = $row['amount'];
    }
    return $totals;
}

function ledger_balance_cents(string|float $received, array $totals): int
{
    return money_cents($received) + money_cents($totals['increase']) - money_cents($totals['transfer_out']) - money_cents($totals['return_army']) - money_cents($totals['payment']) - money_cents($totals['remittance']);
}

function ledger_balance(string|float $received, array $totals): float
{
    return ledger_balance_cents($received, $totals) / 100;
}

function ledger_available_cents(string|float $received, array $totals): int
{
    return ledger_balance_cents($received, $totals) - money_cents($totals['carry']) - money_cents($totals['request']) - money_cents($totals['obligation']);
}

function ledger_account(mysqli $conn, int $id, bool $lock = true): array
{
    $stmt = $conn->prepare('SELECT * FROM control_accounts WHERE id=? AND cancelled_at IS NULL' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    if (!$account || !can_access_unit((int)$account['unit_id'])) {
        throw new RuntimeException('ไม่พบบัญชีคุมหรือไม่มีสิทธิ์ในหน่วยงานนี้', 403);
    }
    return $account;
}

function ledger_sync_summary(mysqli $conn, int $controlId): void
{
    $totals = ledger_totals($conn, $controlId);
    $paid = $totals['payment'];
    $returned = money_decimal(money_cents($totals['return_army']) + money_cents($totals['remittance']));
    $stmt = $conn->prepare('UPDATE control_accounts SET disbursed_amount=?,returned_amount=?,updated_at=NOW() WHERE id=?');
    $stmt->bind_param('ssi', $paid, $returned, $controlId);
    $stmt->execute();
}

function ledger_add(mysqli $conn, int $controlId, string $date, string $type, string|float $amount, int $spendingUnitId, string $gfmis, string $activity, int $sequence, string $category, string $note, int $userId, ?string $sourceTable = null, ?int $sourceId = null): int
{
    if (!(int)$conn->query('SELECT @@in_transaction')->fetch_row()[0]) {
        throw new LogicException('Ledger writes require a transaction.');
    }
    $valid = ['increase','transfer_out','return_army','carry','request','obligation','payment','remittance'];
    $cents = money_cents($amount);
    if (!in_array($type, $valid, true) || $cents <= 0 || $sequence < 1 || $spendingUnitId < 1 || $gfmis === '' || $activity === '') {
        throw new RuntimeException('กรุณากรอกข้อมูลรายการเงินให้ครบถ้วน');
    }
    if ($category !== '' && !preg_match('/^\d{3}$/D', $category)) {
        throw new RuntimeException('หมวดเงินต้องเป็นเลข 3 หลัก');
    }
    $account = ledger_account($conn, $controlId);
    $classification = classification_get($conn, $controlId);
    if ($classification) {
        if ($category === '') $category = $classification['expense_code'];
        if ($category !== $classification['expense_code']) throw new DomainException('หมวดเงินต้องตรงกับบัญชีคุมงบประมาณต้นทาง หากเป็นคนละหมวดให้สร้างบัญชีคุมงบประมาณแยก');
    }
    validate_fiscal_date($date, (int)$account['fiscal_year']);
    if (!is_admin() && $spendingUnitId !== current_unit_id()) {
        throw new RuntimeException('ไม่มีสิทธิ์ทำรายการให้หน่วยงานอื่น', 403);
    }
    if ($type !== 'increase' && $cents > ledger_available_cents($account['received_amount'], ledger_totals($conn, $controlId))) {
        throw new RuntimeException('จำนวนเงินเกินวงเงินที่ใช้ได้หลังหักยอดกันไว้/ขอใช้/ผูกพัน');
    }
    $decimal = money_decimal($cents);
    $stmt = $conn->prepare('INSERT INTO control_account_entries(control_account_id,entry_date,gfmis_code,activity_code,disbursement_no,entry_type,amount,spending_unit_id,expense_category,note,created_by,source_table,source_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->bind_param('isssississisi', $controlId, $date, $gfmis, $activity, $sequence, $type, $decimal, $spendingUnitId, $category, $note, $userId, $sourceTable, $sourceId);
    $stmt->execute();
    $id = (int)$conn->insert_id;
    ledger_sync_summary($conn, $controlId);
    return $id;
}

function ledger_release(mysqli $conn, int $entryId, string $date, string $reason): void
{
    $stmt = $conn->prepare('SELECT e.*,COALESCE((SELECT SUM(amount) FROM reservation_releases WHERE reserve_entry_id=e.id),0) released FROM control_account_entries e WHERE e.id=? AND e.voided_at IS NULL FOR UPDATE');
    $stmt->bind_param('i', $entryId);
    $stmt->execute();
    $entry = $stmt->get_result()->fetch_assoc();
    if (!$entry || !in_array($entry['entry_type'], ['carry','request','obligation'], true)) {
        throw new RuntimeException('ไม่พบรายการสงวนวงเงิน');
    }
    $account = ledger_account($conn, (int)$entry['control_account_id']);
    validate_fiscal_date($date, (int)$account['fiscal_year']);
    $remaining = money_cents($entry['amount']) - money_cents($entry['released']);
    if ($remaining <= 0) return;
    $amount = money_decimal($remaining);
    $user = current_user_id();
    $statement = $conn->prepare('INSERT INTO reservation_releases(reserve_entry_id,control_account_id,release_date,amount,reason,created_by) VALUES(?,?,?,?,?,?)');
    $statement->bind_param('iisssi', $entryId, $entry['control_account_id'], $date, $amount, $reason, $user);
    $statement->execute();
}

function ledger_void(mysqli $conn, int $id, string $reason, bool $documentAction = false): void
{
    if (!(int)$conn->query('SELECT @@in_transaction')->fetch_row()[0]) throw new LogicException('Ledger writes require a transaction.');
    if (!$documentAction) require_permission('control.manage');
    if (mb_strlen(trim($reason)) < 5) throw new RuntimeException('กรุณาระบุเหตุผลยกเลิกอย่างน้อย 5 ตัวอักษร');
    $stmt = $conn->prepare('SELECT * FROM control_account_entries WHERE id=? AND voided_at IS NULL FOR UPDATE');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $entry = $stmt->get_result()->fetch_assoc();
    if (!$entry) throw new RuntimeException('รายการนี้ถูกยกเลิกแล้วหรือไม่พบรายการ');
    if ($entry['source_table'] && !$documentAction) throw new RuntimeException('กรุณายกเลิกจากเอกสารต้นทางของรายการนี้');
    $account = ledger_account($conn, (int)$entry['control_account_id']);
    ledger_assert_unconfirmed($conn, $id);
    $user = current_user_id();
    $stmt = $conn->prepare('UPDATE control_account_entries SET voided_at=NOW(),voided_by=?,void_reason=? WHERE id=?');
    $stmt->bind_param('isi', $user, $reason, $id);
    $stmt->execute();
    if (ledger_available_cents($account['received_amount'], ledger_totals($conn, (int)$entry['control_account_id'])) < 0) throw new RuntimeException('ยกเลิกไม่ได้เพราะจะทำให้วงเงินติดลบ');
    ledger_sync_summary($conn, (int)$entry['control_account_id']);
}

function ledger_assert_unconfirmed(mysqli $conn, int $id): void
{
    if (!db_has_table($conn, 'gfmis_payment_confirmations')) return;
    $q = $conn->prepare('SELECT ledger_entry_id FROM gfmis_payment_confirmations WHERE ledger_entry_id=? FOR UPDATE');
    $q->bind_param('i', $id); $q->execute();
    if ($q->get_result()->fetch_assoc()) throw new DomainException('รายการนี้ยืนยัน GFMIS แล้ว กรุณายกเลิกยอดยืนยันในเมนูยืนยันผลเบิก GFMIS ก่อนลบหรือแก้ยอดตัดจ่าย');
}

function ledger_assert_date_open(mysqli $conn, int $unit, int $year, ?string $date): void
{
    $q=$conn->prepare('CALL rpbs_assert_open(?,?,?)');$q->bind_param('iis',$unit,$year,$date);$q->execute();$q->close();
    while($conn->more_results() && $conn->next_result()) if($result=$conn->store_result())$result->free();
}

function ledger_edit(mysqli $conn, int $id, array $input, bool $documentAction = false): void
{
    if (!(int)$conn->query('SELECT @@in_transaction')->fetch_row()[0]) throw new LogicException('Ledger writes require a transaction.');
    if (!$documentAction) require_permission('control.manage');
    $q = $conn->prepare('SELECT * FROM control_account_entries WHERE id=? AND voided_at IS NULL FOR UPDATE');
    $q->bind_param('i', $id); $q->execute(); $old = $q->get_result()->fetch_assoc();
    if (!$old) throw new DomainException('ไม่พบรายการที่แก้ไขได้');
    if ($old['source_table'] && !$documentAction) throw new DomainException('กรุณาแก้ไขจากเอกสารต้นทาง');
    $account = ledger_account($conn, (int)$old['control_account_id']);
    ledger_assert_date_open($conn, (int)$account['unit_id'], (int)$account['fiscal_year'], $old['entry_date']);
    $reason = trim((string)($input['reason'] ?? ''));
    if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) throw new DomainException('กรุณาระบุเหตุผลแก้ไข 5–255 ตัวอักษร');
    $new = array_replace($old, array_intersect_key($input, array_flip(['entry_date','entry_type','amount','gfmis_code','activity_code','disbursement_no','spending_unit_id','expense_category','note'])));
    $date = (string)$new['entry_date']; validate_fiscal_date($date, (int)$account['fiscal_year']);
    $amount = money_decimal(money_cents((string)$new['amount']));
    $type = (string)$new['entry_type']; $sequence = (int)$new['disbursement_no']; $unit = (int)$new['spending_unit_id'];
    $gfmis = trim((string)$new['gfmis_code']); $activity = trim((string)$new['activity_code']);
    $category = trim((string)$new['expense_category']); $note = trim((string)$new['note']);
    if (!in_array($type, ['increase','transfer_out','return_army','carry','request','obligation','payment','remittance'], true) || money_cents($amount) <= 0 || $sequence < 1 || $unit < 1 || $gfmis === '' || $activity === '') throw new DomainException('กรุณากรอกข้อมูลรายการเงินให้ครบถ้วน');
    if (!is_admin() && $unit !== current_unit_id()) throw new RuntimeException('ไม่มีสิทธิ์ทำรายการให้หน่วยอื่น', 403);
    if ($category !== '' && !preg_match('/^\d{3}$/D', $category)) throw new DomainException('หมวดเงินต้องเป็นเลข 3 หลัก');
    $class = classification_get($conn, (int)$account['id']);
    if ($class && $category !== $class['expense_code']) throw new DomainException('หมวดเงินต้องตรงกับบัญชีคุมต้นทาง');
    $q = $conn->prepare('SELECT COALESCE(SUM(amount),0) released FROM reservation_releases WHERE reserve_entry_id=?');
    $q->bind_param('i', $id); $q->execute(); $released = money_cents($q->get_result()->fetch_assoc()['released']);
    if ($released > 0 && ($type !== $old['entry_type'] || money_cents($amount) < $released)) throw new DomainException('ยอดใหม่ต้องไม่น้อยกว่ายอดที่ปลดวงเงินแล้ว และเปลี่ยนประเภทรายการไม่ได้');
    if ($type !== $old['entry_type'] || money_cents($amount) !== money_cents($old['amount']) || $date !== $old['entry_date']) ledger_assert_unconfirmed($conn, $id);
    $q = $conn->prepare('UPDATE control_account_entries SET entry_date=?,entry_type=?,amount=?,gfmis_code=?,activity_code=?,disbursement_no=?,spending_unit_id=?,expense_category=?,note=? WHERE id=?');
    $q->bind_param('sssssiissi', $date, $type, $amount, $gfmis, $activity, $sequence, $unit, $category, $note, $id); $q->execute();
    if (ledger_available_cents($account['received_amount'], ledger_totals($conn, (int)$account['id'])) < 0) throw new DomainException('แก้ไขไม่ได้เพราะวงเงินคงเหลือหลังหักยอดสงวนจะติดลบ');
    ledger_sync_summary($conn, (int)$account['id']);
    audit_event($conn, 'EDIT', 'control_account_entries', $id, (int)$account['unit_id'], ['reason'=>$reason]);
}

function ledger_release_edit(mysqli $conn, int $id, array $input, bool $cancel = false): void
{
    if (!(int)$conn->query('SELECT @@in_transaction')->fetch_row()[0]) throw new LogicException('Ledger writes require a transaction.');
    require_permission('control.manage');
    $q = $conn->prepare('SELECT r.*,e.source_table,e.amount reserved,e.voided_at FROM reservation_releases r JOIN control_account_entries e ON e.id=r.reserve_entry_id WHERE r.id=? FOR UPDATE');
    $q->bind_param('i', $id); $q->execute(); $old = $q->get_result()->fetch_assoc();
    if (!$old || $old['voided_at'] || money_cents($old['amount']) === 0) throw new DomainException('ไม่พบรายการปลดวงเงินที่แก้ไขได้');
    if ($old['source_table']) throw new DomainException('กรุณาแก้ไขจากเอกสารต้นทาง');
    $account = ledger_account($conn, (int)$old['control_account_id']);
    ledger_assert_date_open($conn, (int)$account['unit_id'], (int)$account['fiscal_year'], $old['release_date']);
    $reason = trim((string)($input['reason'] ?? ''));
    if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) throw new DomainException('กรุณาระบุเหตุผล 5–255 ตัวอักษร');
    $date = $cancel ? $old['release_date'] : (string)($input['release_date'] ?? '');
    validate_fiscal_date($date, (int)$account['fiscal_year']);
    $amount = $cancel ? '0.00' : money_decimal(money_cents((string)($input['amount'] ?? '0')));
    if (!$cancel && money_cents($amount) <= 0) throw new DomainException('จำนวนเงินต้องมากกว่า 0');
    $q = $conn->prepare('SELECT COALESCE(SUM(amount),0) total FROM reservation_releases WHERE reserve_entry_id=? AND id<>?');
    $q->bind_param('ii', $old['reserve_entry_id'], $id); $q->execute();
    if (money_cents($amount) + money_cents($q->get_result()->fetch_assoc()['total']) > money_cents($old['reserved'])) throw new DomainException('ปลดวงเงินเกินยอดที่สงวน');
    $q = $conn->prepare('UPDATE reservation_releases SET amount=?,release_date=?,reason=? WHERE id=?');
    $q->bind_param('sssi', $amount, $date, $reason, $id); $q->execute();
    if (ledger_available_cents($account['received_amount'], ledger_totals($conn, (int)$account['id'])) < 0) throw new DomainException('แก้ไขไม่ได้เพราะวงเงินจะติดลบ');
    ledger_sync_summary($conn, (int)$account['id']);
    audit_event($conn, $cancel ? 'CANCEL' : 'EDIT', 'reservation_releases', $id, (int)$account['unit_id'], ['before'=>$old,'reason'=>$reason]);
}

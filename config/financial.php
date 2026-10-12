<?php
require_once __DIR__ . '/ledger.php';

function financial_config(string $module): array
{
    $configs = [
        'voucher' => ['title'=>'ฎีกา','table'=>'payment_vouchers','number'=>'voucher_no','date'=>'voucher_date','detail'=>'description','permission'=>'voucher.receive','prefix'=>'PV','open'=>'received','done'=>'paid','reserve'=>'request'],
        'advance' => ['title'=>'เงินยืม','table'=>'budget_advances','number'=>'doc_no','date'=>'doc_date','detail'=>'purpose','permission'=>'advance.manage','prefix'=>'BM','open'=>'open','done'=>'closed','reserve'=>'request'],
        'po' => ['title'=>'PO','table'=>'po_reservations','number'=>'doc_no','date'=>'doc_date','detail'=>'purpose','permission'=>'po.manage','prefix'=>'PO','open'=>'reserved','done'=>'released','reserve'=>'obligation'],
    ];
    if (!isset($configs[$module])) throw new RuntimeException('ไม่พบประเภทเอกสาร');
    return $configs[$module];
}

function financial_document(mysqli $conn, string $module, int $id, bool $lock = true): array
{
    $cfg = financial_config($module);
    $stmt = $conn->prepare('SELECT * FROM ' . $cfg['table'] . ' WHERE id=?' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();
    if (!$doc || !can_access_unit((int)$doc['unit_id'])) throw new RuntimeException('ไม่พบเอกสารหรือไม่มีสิทธิ์ในหน่วยงานนี้', 403);
    if ($doc['cancelled_at']) throw new RuntimeException('เอกสารนี้ถูกยกเลิกแล้ว');
    return $doc;
}

function financial_legacy_link(mysqli $conn, string $table, int $id, ?int $ledgerId, bool $cancel = false): void
{
    $stmt = $conn->prepare('UPDATE budget_transactions SET ledger_entry_id=?,cancelled_at=' . ($cancel ? 'NOW()' : 'cancelled_at') . ' WHERE ref_table=? AND ref_id=?');
    $stmt->bind_param('isi', $ledgerId, $table, $id);
    $stmt->execute();
}

function financial_release_sources(mysqli $conn, string $table, int $id, string $date, string $reason): void
{
    $stmt = $conn->prepare("SELECT id FROM control_account_entries WHERE source_table=? AND source_id=? AND entry_type IN ('carry','request','obligation') AND voided_at IS NULL ORDER BY id FOR UPDATE");
    $stmt->bind_param('si', $table, $id);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $entry) ledger_release($conn, (int)$entry['id'], $date, $reason);
}

function financial_create(mysqli $conn, string $module, array $input): int
{
    $cfg = financial_config($module);
    require_permission($cfg['permission']);
    $control = ledger_account($conn, (int)($input['control_account_id'] ?? 0));
    $unit = (int)$control['unit_id'];
    $year = (int)$control['fiscal_year'];
    $date = (string)($input['doc_date'] ?? '');
    validate_fiscal_date($date, $year);
    $amount = money_decimal(money_cents((string)($input['amount'] ?? '0')));
    $description = trim((string)($input['description'] ?? ''));
    $category = trim((string)($input['expense_category'] ?? ''));
    $classification = classification_get($conn, (int)$control['id']);
    if ($classification && $category === '') $category = $classification['expense_code'];
    if ($classification && $category !== $classification['expense_code']) throw new DomainException('หมวดเงินต้องตรงกับบัญชีคุมงบประมาณ ที่เลือก');
    if (money_cents($amount) <= 0 || $description === '' || mb_strlen($description) > 255) throw new RuntimeException('กรุณากรอกจำนวนเงินและรายละเอียดไม่เกิน 255 ตัวอักษร');
    if ($category !== '' && !preg_match('/^\d{3}$/D', $category)) throw new RuntimeException('หมวดเงินต้องเป็นเลข 3 หลัก');
    $number = trim((string)($input['doc_no'] ?? ''));
    if ($module !== 'voucher' || $number === '') $number = db_next_document_no($conn, $cfg['prefix'], $year, $unit);
    $user = current_user_id();
    $accountId = (int)$control['id'];
    if ($module === 'po') {
        $po = trim((string)($input['po_no'] ?? ''));
        $vendor = trim((string)($input['vendor'] ?? ''));
        $stmt = $conn->prepare("INSERT INTO po_reservations(doc_no,po_no,doc_date,unit_id,fiscal_year,amount,vendor,purpose,created_by,control_account_id,expense_category) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('sssiisssiis', $number, $po, $date, $unit, $year, $amount, $vendor, $description, $user, $accountId, $category);
    } else {
        $actorColumn = $module === 'voucher' ? 'received_by' : 'created_by';
        $stmt = $conn->prepare('INSERT INTO ' . $cfg['table'] . '(' . $cfg['number'] . ',' . $cfg['date'] . ',unit_id,fiscal_year,amount,' . $cfg['detail'] . ',' . $actorColumn . ',control_account_id,expense_category) VALUES(?,?,?,?,?,?,?,?,?)');
        $stmt->bind_param('ssiissiis', $number, $date, $unit, $year, $amount, $description, $user, $accountId, $category);
    }
    $stmt->execute();
    $id = (int)$conn->insert_id;
    ledger_add($conn, $accountId, $date, $cfg['reserve'], $amount, $unit, (string)$control['gfmis_code'], (string)$control['activity_code'], 1, $category, $cfg['title'] . ' ' . $number . ': ' . $description, $user, $cfg['table'], $id);
    return $id;
}

function financial_transition(mysqli $conn, string $module, int $id, string $date, ?string $settledAmount = null): void
{
    $cfg = financial_config($module);
    require_permission($module === 'voucher' ? 'voucher.pay' : $cfg['permission']);
    $doc = financial_document($conn, $module, $id);
    if ($doc['status'] !== $cfg['open']) throw new RuntimeException('เอกสารนี้ดำเนินการแล้ว กรุณาตรวจประวัติ');
    if (!$doc['control_account_id']) throw new RuntimeException('เอกสารเก่าต้องให้ผู้ดูแลผูกบัญชีคุมงบประมาณ ก่อนดำเนินการ');
    validate_fiscal_date($date, (int)$doc['fiscal_year']);
    $control = ledger_account($conn, (int)$doc['control_account_id']);
    $user = current_user_id();
    $amount = $doc['amount'];
    if ($module === 'advance') {
        if ($settledAmount === null) throw new RuntimeException('กรุณาระบุยอดค่าใช้จ่ายจริงสำหรับปิดเงินยืม');
        $amount = money_decimal(money_cents($settledAmount));
        if (money_cents($amount) > money_cents($doc['amount'])) throw new RuntimeException('ยอดใช้จริงต้องไม่เกินยอดเงินยืม');
    }
    financial_release_sources($conn, $cfg['table'], $id, $date, 'ปลดวงเงิน ' . $doc[$cfg['number']]);
    $ledgerId = null;
    if ($module !== 'po' && money_cents($amount) > 0) {
        $ledgerId = ledger_add($conn, (int)$control['id'], $date, 'payment', $amount, (int)$doc['unit_id'], (string)$control['gfmis_code'], (string)$control['activity_code'], 1, (string)$doc['expense_category'], $cfg['title'] . ' ' . $doc[$cfg['number']] . ': ' . $doc[$cfg['detail']], $user, $cfg['table'], $id);
    }
    if ($module === 'voucher') {
        $stmt = $conn->prepare("UPDATE payment_vouchers SET status='paid',paid_by=?,paid_date=? WHERE id=? AND status='received' AND cancelled_at IS NULL");
        $stmt->bind_param('isi', $user, $date, $id);
    } elseif ($module === 'advance') {
        $stmt = $conn->prepare("UPDATE budget_advances SET status='closed',settled_amount=?,settled_date=? WHERE id=? AND status='open' AND cancelled_at IS NULL");
        $stmt->bind_param('ssi', $amount, $date, $id);
    } else {
        $stmt = $conn->prepare("UPDATE po_reservations SET status='released',released_date=? WHERE id=? AND status='reserved' AND cancelled_at IS NULL");
        $stmt->bind_param('si', $date, $id);
    }
    $stmt->execute();
    if ($stmt->affected_rows !== 1) throw new RuntimeException('สถานะเอกสารเปลี่ยนแล้ว กรุณาเปิดหน้าใหม่');
    financial_legacy_link($conn, $cfg['table'], $id, $ledgerId, true);
    ledger_sync_summary($conn, (int)$control['id']);
}

function financial_cancel(mysqli $conn, string $module, int $id, string $reason, string $date): void
{
    $cfg = financial_config($module);
    $doc = financial_document($conn, $module, $id);
    require_permission($module === 'voucher' && $doc['status'] === $cfg['done'] ? 'voucher.pay' : $cfg['permission']);
    ledger_assert_date_open($conn,(int)$doc['unit_id'],(int)$doc['fiscal_year'],$doc[$cfg['date']]);
    if (mb_strlen(trim($reason)) < 5) throw new RuntimeException('กรุณาระบุเหตุผลยกเลิกอย่างน้อย 5 ตัวอักษร');
    validate_fiscal_date($date, (int)$doc['fiscal_year']);
    if ($doc['control_account_id']) {
        ledger_account($conn, (int)$doc['control_account_id']);
        financial_release_sources($conn, $cfg['table'], $id, $date, 'ยกเลิก: ' . $reason);
        $stmt = $conn->prepare("SELECT id FROM control_account_entries WHERE source_table=? AND source_id=? AND entry_type='payment' AND voided_at IS NULL FOR UPDATE");
        $stmt->bind_param('si', $cfg['table'], $id);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $entry) ledger_void($conn, (int)$entry['id'], $reason, true);
    }
    $user = current_user_id();
    $stamp = $date . ' ' . date('H:i:s');
    $stmt = $conn->prepare('UPDATE ' . $cfg['table'] . ' SET cancelled_at=?,cancelled_by=?,cancel_reason=? WHERE id=? AND cancelled_at IS NULL');
    $stmt->bind_param('sisi', $stamp, $user, $reason, $id);
    $stmt->execute();
    financial_legacy_link($conn, $cfg['table'], $id, null, true);
}

function financial_link_existing(mysqli $conn, string $module, int $id, int $controlId, ?string $settledAmount = null): void
{
    require_roles(['admin']);
    $cfg = financial_config($module);
    $doc = financial_document($conn, $module, $id);
    if ($doc['control_account_id']) throw new RuntimeException('เอกสารนี้ผูกบัญชีแล้ว');
    $control = ledger_account($conn, $controlId);
    if ((int)$control['unit_id'] !== (int)$doc['unit_id'] || (int)$control['fiscal_year'] !== (int)$doc['fiscal_year']) throw new RuntimeException('บัญชีคุมงบประมาณ ต้องเป็นหน่วยและปีงบประมาณเดียวกับเอกสาร');
    $stmt = $conn->prepare('UPDATE ' . $cfg['table'] . ' SET control_account_id=? WHERE id=? AND control_account_id IS NULL');
    $stmt->bind_param('ii', $controlId, $id);
    $stmt->execute();
    $date = (string)$doc[$cfg['date']];
    $type = $cfg['reserve'];
    $amount = $doc['amount'];
    if ($doc['status'] === $cfg['done']) {
        if ($module === 'po') {
            financial_legacy_link($conn, $cfg['table'], $id, null, true);
            return;
        }
        $type = 'payment';
        if ($module === 'voucher') $date = $doc['paid_date'] ?: $date;
        if ($module === 'advance') {
            if ($settledAmount === null) throw new RuntimeException('เอกสารเงินยืมปิดแล้วต้องระบุยอดใช้จริงจากเอกสารเดิม');
            $amount = money_decimal(money_cents($settledAmount));
            if (money_cents($amount) > money_cents($doc['amount'])) throw new RuntimeException('ยอดใช้จริงเกินยอดเงินยืม');
            $stmt = $conn->prepare('UPDATE budget_advances SET settled_amount=?,settled_date=? WHERE id=?');
            $stmt->bind_param('ssi', $amount, $date, $id);
            $stmt->execute();
        }
    }
    $entryId = null;
    if (money_cents($amount) > 0) $entryId = ledger_add($conn, $controlId, $date, $type, $amount, (int)$doc['unit_id'], (string)$control['gfmis_code'], (string)$control['activity_code'], 1, (string)$doc['expense_category'], 'ผูกเอกสารเดิม ' . $doc[$cfg['number']], current_user_id(), $cfg['table'], $id);
    financial_legacy_link($conn, $cfg['table'], $id, $entryId, $entryId === null);
}

function financial_edit(mysqli $conn, string $module, int $id, array $input): void
{
    $cfg = financial_config($module);
    $doc = financial_document($conn, $module, $id);
    $done = $doc['status'] === $cfg['done'];
    require_permission($module === 'voucher' && $done ? 'voucher.pay' : $cfg['permission']);
    if (!$doc['control_account_id']) throw new RuntimeException('กรุณาผูกเอกสารเดิมกับบัญชีคุมก่อนแก้ไขยอด');
    $control = ledger_account($conn, (int)$doc['control_account_id']);
    $amount = money_decimal(money_cents((string)($input['amount'] ?? '0')));
    ledger_assert_date_open($conn, (int)$doc['unit_id'], (int)$doc['fiscal_year'], $doc[$cfg['date']]);
    if ($done) ledger_assert_date_open($conn, (int)$doc['unit_id'], (int)$doc['fiscal_year'], $module==='voucher'?$doc['paid_date']:($module==='advance'?$doc['settled_date']:$doc['released_date']));
    $description = trim((string)($input['description'] ?? ''));
    $reason = trim((string)($input['reason'] ?? ''));
    if (money_cents($amount) <= 0 || $description === '' || mb_strlen($description) > 255 || mb_strlen($reason) < 5 || mb_strlen($reason) > 255) throw new RuntimeException('กรุณากรอกจำนวนเงิน รายละเอียด และเหตุผลแก้ไขให้ครบ');
    $date = (string)($input['doc_date'] ?? $doc[$cfg['date']]);
    validate_fiscal_date($date, (int)$doc['fiscal_year']);
    $number = trim((string)($input['doc_no'] ?? $doc[$cfg['number']]));
    if ($number === '' || mb_strlen($number) > 50) throw new DomainException('เลขเอกสารต้องมี 1–50 ตัวอักษร');
    $settled = $module === 'advance' && $done ? money_decimal(money_cents((string)($input['settled_amount'] ?? $doc['settled_amount'] ?? '0'))) : $amount;
    if (money_cents($settled) > money_cents($amount)) throw new DomainException('ค่าใช้จ่ายจริงต้องไม่เกินยอดเงินยืม');
    $actionDate = $done ? (string)($input['action_date'] ?? ($module === 'voucher' ? $doc['paid_date'] : ($module === 'advance' ? $doc['settled_date'] : $doc['released_date'])) ?? $date) : $date;
    validate_fiscal_date($actionDate, (int)$doc['fiscal_year']);
    if ($actionDate < $date) throw new DomainException('วันที่ดำเนินการต้องไม่ก่อนวันที่เอกสาร');
    if (!$done) {
        financial_release_sources($conn, $cfg['table'], $id, $date, 'แก้ไขเอกสาร: ' . $reason);
        ledger_add($conn, (int)$control['id'], $date, $cfg['reserve'], $amount, (int)$doc['unit_id'], (string)$control['gfmis_code'], (string)$control['activity_code'], 1, (string)$doc['expense_category'], $cfg['title'].' '.$number.': '.$description, current_user_id(), $cfg['table'], $id);
    } elseif ($module !== 'po') {
        $stmt = $conn->prepare("SELECT id FROM control_account_entries WHERE source_table=? AND source_id=? AND entry_type='payment' AND voided_at IS NULL FOR UPDATE");
        $stmt->bind_param('si', $cfg['table'], $id); $stmt->execute(); $payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        if (count($payments) > 1) throw new DomainException('พบยอดตัดจ่ายหลายรายการ กรุณาให้ผู้ดูแลตรวจสอบก่อนแก้ไข');
        $entryId = $payments ? (int)$payments[0]['id'] : null;
        if ($entryId && money_cents($settled) > 0) ledger_edit($conn, $entryId, ['entry_date'=>$actionDate,'amount'=>$settled,'note'=>$cfg['title'].' '.$number.': '.$description,'reason'=>$reason], true);
        elseif ($entryId) { ledger_void($conn, $entryId, $reason, true); $entryId = null; }
        elseif (money_cents($settled) > 0) $entryId = ledger_add($conn, (int)$control['id'], $actionDate, 'payment', $settled, (int)$doc['unit_id'], (string)$control['gfmis_code'], (string)$control['activity_code'], 1, (string)$doc['expense_category'], $cfg['title'].' '.$number.': '.$description, current_user_id(), $cfg['table'], $id);
        financial_legacy_link($conn, $cfg['table'], $id, $entryId, true);
    }
    $stmt = $conn->prepare('UPDATE ' . $cfg['table'] . ' SET amount=?,' . $cfg['detail'] . '=?,' . $cfg['date'] . '=?,' . $cfg['number'] . '=? WHERE id=?');
    $stmt->bind_param('ssssi', $amount, $description, $date, $number, $id);
    $stmt->execute();
    if ($module === 'po') {
        $po = trim((string)($input['po_no'] ?? $doc['po_no'])); $vendor = trim((string)($input['vendor'] ?? $doc['vendor']));
        if (mb_strlen($po) > 50 || mb_strlen($vendor) > 255) throw new DomainException('เลข PO หรือชื่อผู้ขายยาวเกินกำหนด');
        $stmt = $conn->prepare('UPDATE po_reservations SET po_no=?,vendor=? WHERE id=?'); $stmt->bind_param('ssi', $po, $vendor, $id); $stmt->execute();
    }
    if ($done) {
        $column = $module === 'voucher' ? 'paid_date' : ($module === 'advance' ? 'settled_date' : 'released_date');
        $stmt = $conn->prepare('UPDATE '.$cfg['table'].' SET '.$column.'=? WHERE id=?'); $stmt->bind_param('si', $actionDate, $id); $stmt->execute();
        if ($module === 'advance') { $stmt = $conn->prepare('UPDATE budget_advances SET settled_amount=? WHERE id=?'); $stmt->bind_param('si', $settled, $id); $stmt->execute(); }
    }
    ledger_sync_summary($conn, (int)$control['id']);
    audit_event($conn, 'EDIT', $cfg['table'], $id, (int)$doc['unit_id'], ['reason'=>$reason]);
}

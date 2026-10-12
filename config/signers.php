<?php
function shared_signer_ready(mysqli $db): bool {
    return db_has_column($db, 'document_signers', 'shared_signer_slot');
}

function shared_budget_signer(mysqli $db, bool $activeOnly = true): ?array {
    if (!shared_signer_ready($db)) return null;
    $sql = 'SELECT * FROM document_signers WHERE unit_id IS NULL';
    if ($activeOnly) $sql .= ' AND active=1';
    return $db->query($sql . ' ORDER BY id LIMIT 1')->fetch_assoc();
}

function save_shared_budget_signer(mysqli $db, array $input): int {
    require_roles(['admin']);
    if (!shared_signer_ready($db)) throw new DomainException('กรุณาติดตั้งตารางผู้ลงนามกลาง v10 ก่อน');
    $name = trim((string)($input['full_name'] ?? ''));
    $position = trim((string)($input['position_name'] ?? ''));
    if ($name === '' || $position === '' || mb_strlen($name) > 255 || mb_strlen($position) > 255) {
        throw new DomainException('กรุณากรอกชื่อและยศ/ตำแหน่งให้ครบ ไม่เกินช่องละ 255 ตัวอักษร');
    }
    // The nullable-unit singleton is separate from all historic unit-specific signers.
    $old = $db->query('SELECT * FROM document_signers WHERE unit_id IS NULL FOR UPDATE')->fetch_assoc();
    if ($old) {
        $id = (int)$old['id'];
        $q = $db->prepare("UPDATE document_signers SET full_name=?,position_name=?,document_type='ALL',signer_key='approved',display_order=1,active=1,updated_at=NOW() WHERE id=? AND unit_id IS NULL");
        $q->bind_param('ssi', $name, $position, $id);
    } else {
        $actor = current_user_id();
        $q = $db->prepare("INSERT INTO document_signers(unit_id,document_type,signer_key,full_name,position_name,display_order,active,created_by) VALUES(NULL,'ALL','approved',?,?,1,1,?)");
        $q->bind_param('ssi', $name, $position, $actor);
    }
    $q->execute();
    return $old ? $id : (int)$db->insert_id;
}

function voucher_signer_options(mysqli $db): array {
    $shared = shared_budget_signer($db, false);
    if ($shared) return (int)$shared['active'] === 1 ? [$shared] : [];
    // Legacy choices remain available until the administrator names the shared officer.
    return $db->query('SELECT id,full_name,position_name FROM document_signers WHERE active=1 ORDER BY display_order,id')->fetch_all(MYSQLI_ASSOC);
}

function signer_record(mysqli $db, int $id, bool $lock = false): ?array {
    $q = $db->prepare('SELECT * FROM document_signers WHERE id=? AND active=1' . ($lock ? ' FOR UPDATE' : ''));
    $q->bind_param('i', $id);
    $q->execute();
    return $q->get_result()->fetch_assoc();
}

function update_budget_signer(mysqli $db, int $id, array $input): void {
    require_roles(['admin']);
    $name = trim((string)($input['full_name'] ?? ''));
    $position = trim((string)($input['position_name'] ?? ''));
    if ($name === '' || $position === '' || mb_strlen($name) > 255 || mb_strlen($position) > 255) {
        throw new DomainException('กรุณากรอกชื่อและยศ/ตำแหน่งให้ครบ ไม่เกินช่องละ 255 ตัวอักษร');
    }
    if (!signer_record($db, $id, true)) throw new DomainException('ไม่พบผู้ลงนาม หรือรายชื่อนี้ถูกลบแล้ว กรุณาเปิดหน้าใหม่');
    // Change the catalog only; signed documents retain their name/position snapshots.
    $q = $db->prepare('UPDATE document_signers SET full_name=?,position_name=?,updated_at=NOW() WHERE id=? AND active=1');
    $q->bind_param('ssi', $name, $position, $id);
    $q->execute();
}

function delete_budget_signer(mysqli $db, int $id): void {
    require_roles(['admin']);
    if (!signer_record($db, $id, true)) throw new DomainException('ไม่พบผู้ลงนาม หรือรายชื่อนี้ถูกลบแล้ว กรุณาเปิดหน้าใหม่');
    // Soft deletion preserves references from historic signature snapshots.
    $q = $db->prepare('UPDATE document_signers SET active=0,updated_at=NOW() WHERE id=? AND active=1');
    $q->bind_param('i', $id);
    $q->execute();
}

<?php
require_once __DIR__ . '/organization.php';

function print_account_context(mysqli $db, array $document): array
{
    if (!can_access_unit((int)$document['unit_id'])) throw new RuntimeException('ไม่มีสิทธิ์ดูเอกสารของหน่วยนี้', 403);
    $id = (int)($document['control_account_id'] ?? 0);
    if (!$id) return ['account' => null, 'warning' => 'เอกสารเดิมยังไม่ได้ผูกบัญชีคุมงบประมาณ กรุณาให้ผู้ดูแลตรวจและผูกบัญชีต้นทางก่อนใช้อ้างอิงการเบิกจ่าย'];
    // Do not infer a source from matching amounts/names or disclose another unit's account.
    $query = $db->prepare('SELECT ca.id,ca.budget_code,ca.item_name,ca.gfmis_code,ca.activity_code,u.unit_code,u.unit_name FROM control_accounts ca JOIN units u ON u.id=ca.unit_id WHERE ca.id=? AND ca.unit_id=? AND ca.fiscal_year=?');
    $query->bind_param('iii', $id, $document['unit_id'], $document['fiscal_year']);
    $query->execute();
    $account = $query->get_result()->fetch_assoc();
    if (!$account) return ['account' => null, 'warning' => 'บัญชีคุมที่อ้างอิงไม่สัมพันธ์กับหน่วยหรือปีงบประมาณของเอกสาร กรุณาให้ผู้ดูแลตรวจสอบ ไม่สามารถระบุบัญชีต้นทางโดยคาดเดา'];
    $classification = classification_get($db, $id) ?? [];
    $expense = trim((string)($document['expense_category'] ?? '')) ?: (string)($classification['expense_code'] ?? '');
    $expenseName = $expense === (string)($classification['expense_code'] ?? '') ? (string)($classification['expense_name'] ?? '') : '';
    return ['account' => $account, 'classification' => $classification, 'expense' => trim($expense . ' ' . $expenseName), 'warning' => ''];
}

function print_account_html(array $context): string
{
    $escape = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    if (!$context['account']) return '<section class="account-reference account-warning"><strong>บัญชีคุมงบประมาณต้นทาง</strong><p>' . $escape($context['warning']) . '</p></section>';
    $a = $context['account']; $c = $context['classification'];
    $value = static fn($v) => $escape(trim((string)$v) !== '' ? $v : '-');
    $html = '<section class="account-reference"><h2>บัญชีคุมงบประมาณต้นทาง</h2><table class="meta account-meta">';
    $html .= '<tr><td class="label">รหัสบัญชีคุม</td><td><strong>' . $value($a['budget_code']) . '</strong></td><td class="label">หมวดเงิน</td><td>' . $value($context['expense']) . '</td></tr>';
    $html .= '<tr><td class="label">ชื่องบประมาณ</td><td colspan="3">' . $value($a['item_name']) . '</td></tr>';
    $html .= '<tr><td class="label">รหัส GFMIS</td><td class="account-code">' . $value($a['gfmis_code']) . '</td><td class="label">รหัสกิจกรรมหลัก</td><td class="account-code">' . $value($a['activity_code']) . '</td></tr>';
    $html .= '<tr><td class="label">หน่วยเจ้าของบัญชี</td><td colspan="3">' . $value($a['unit_code'] . ' - ' . $a['unit_name']) . '</td></tr>';
    if ($c) {
        $units = implode(' / ', array_filter([$c['main_unit'] ?? '', $c['sub_unit'] ?? '', $c['secondary_unit'] ?? ''], static fn($v) => trim((string)$v) !== ''));
        $work = implode(' / ', array_unique(array_filter([$c['work_group'] ?? '', $c['work_name'] ?? ($c['project_name'] ?? '')], static fn($v) => trim((string)$v) !== '')));
        $html .= '<tr><td class="label">หน่วยรับผิดชอบ</td><td colspan="3">' . $value($units) . '</td></tr>';
        $html .= '<tr><td class="label">กลุ่มงบงาน / โครงการ</td><td colspan="3">' . $value($work) . '</td></tr>';
    }
    return $html . '</table></section>';
}

<?php
require 'config/auth.php';
require 'config/db.php';

if (!isset($_GET['id'])) {
    die('ไม่พบเอกสาร');
}

$transfer_id = (int)$_GET['id'];

$hasPayingUnit = db_has_column($conn, 'budget_transfers', 'paying_unit_id');
$hasReceiveDate = db_has_column($conn, 'budget_transfers', 'receive_date');
$hasItemName = db_has_column($conn, 'budget_transfers', 'item_name');
$hasActivityCode = db_has_column($conn, 'budget_transfers', 'activity_code');
$hasBudgetTypes = db_has_column($conn, 'budget_transfers', 'budget_types');
$hasDisbursements = db_has_table($conn, 'budget_disbursements');

$selectPaying = $hasPayingUnit
    ? 'pu.unit_code AS paying_unit_code, pu.unit_name AS paying_unit_name'
    : 'NULL AS paying_unit_code, NULL AS paying_unit_name';
$joinPaying = $hasPayingUnit
    ? 'LEFT JOIN units pu ON pu.id = bt.paying_unit_id'
    : '';

$sqlDoc = '
    SELECT bt.*,
           ' . ($hasReceiveDate ? 'bt.receive_date' : 'NULL AS receive_date') . ',
           ' . ($hasItemName ? 'bt.item_name' : 'NULL AS item_name') . ',
           ' . ($hasActivityCode ? 'bt.activity_code' : 'NULL AS activity_code') . ',
           ' . ($hasBudgetTypes ? 'bt.budget_types' : 'NULL AS budget_types') . ',
           u.unit_code, u.unit_name,
           ' . $selectPaying . '
    FROM budget_transfers bt
    LEFT JOIN units u ON u.id = bt.unit_id
    ' . $joinPaying . '
    WHERE bt.id = ?
';
$stmt = $conn->prepare($sqlDoc);
$stmt->bind_param('i', $transfer_id);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();

if (!$doc) {
    die('ไม่พบเอกสาร');
}
if (!can_access_unit((int)$doc['unit_id'])) {
    http_response_code(403);
    die('ไม่มีสิทธิ์ดูเอกสารนี้');
}

$sqlItem = '
    SELECT description, amount
    FROM budget_transfer_items
    WHERE transfer_id = ?
';
$stmt = $conn->prepare($sqlItem);
$stmt->bind_param('i', $transfer_id);
$stmt->execute();
$items = $stmt->get_result();

$disbursements = null;
$usedAmount = 0.0;
if ($hasDisbursements) {
    $sqlDisbursement = '
        SELECT id, report_no, report_date, description, amount
        FROM budget_disbursements
        WHERE source_transfer_id = ? AND cancelled_at IS NULL
        ORDER BY report_date DESC, id DESC
    ';
    $stmt = $conn->prepare($sqlDisbursement);
    $stmt->bind_param('i', $transfer_id);
    $stmt->execute();
    $disbursements = $stmt->get_result();

    $sqlUsed = '
        SELECT COALESCE(SUM(amount), 0) AS used_amount
        FROM budget_disbursements
        WHERE source_transfer_id = ? AND cancelled_at IS NULL
    ';
    $stmt = $conn->prepare($sqlUsed);
    $stmt->bind_param('i', $transfer_id);
    $stmt->execute();
    $usedAmount = (float)($stmt->get_result()->fetch_assoc()['used_amount'] ?? 0);
}

$remainingAmount = (float)$doc['total_amount'] - $usedAmount;

function budgetTypeLabel(string $value): string
{
    $map = [
        'Annual' => 'งบประจำปี',
        'Investment' => 'งบลงทุน',
        'Central' => 'งบกลาง',
        'Compensation' => 'งบบุคลากร',
        'Carryover' => 'กันไว้เบิกเหลื่อมปี',
        'Operating' => 'งบดำเนินงาน',
        'Additional' => 'เพิ่มเติม/อื่นๆ',
    ];
    return $map[$value] ?? $value;
}

function formatBudgetTypes(?string $csv): string
{
    if ($csv === null || trim($csv) === '') {
        return '-';
    }

    $parts = array_filter(array_map('trim', explode(',', $csv)), static fn($v) => $v !== '');
    if (count($parts) === 0) {
        return '-';
    }

    $labels = array_map('budgetTypeLabel', $parts);
    return implode(', ', $labels);
}
?>

<!DOCTYPE html>
<html lang="th">
<head><meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="UTF-8">
<title>รายละเอียดเอกสารโอนงบ</title>
<link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body>
<?php require 'template/nav.php'; ?>

<div class="container">
    <div class="panel">
        <h1 class="page-title">รายละเอียดเอกสารโอนงบประมาณ</h1>
        <div class="toolbar compact">
            <a href="budget_menu.php?year=<?= (int)$doc['fiscal_year'] ?>" class="btn btn-outline">ย้อนกลับ</a>
            <a href="exports/export_budget.php?id=<?= $transfer_id ?>" class="btn btn-success">ส่งออก Excel</a>
            <?php if (has_permission('budget.edit')): ?><a href="edit_budget.php?id=<?= $transfer_id ?>" class="btn btn-primary">แก้ไข</a><?php endif; ?>
            <?php if (has_permission('budget.delete')): ?><a href="delete_budget.php?id=<?= $transfer_id ?>" class="btn btn-danger" onclick="return confirm('ยืนยันการลบเอกสารนี้?')">ลบ</a><?php endif; ?>
            <?php if ((int)$doc['transfer_round'] === 1 && $hasDisbursements): ?>
                <a class="btn btn-primary" href="transfer_round2.php?year=<?= $doc['fiscal_year'] ?>&source_id=<?= $transfer_id ?>">เพิ่มรายการโอนรอบที่ 2</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="panel">
        <div class="form-grid form-grid-2">
            <label>เลขที่เอกสาร</label>
            <div><?= htmlspecialchars((string)$doc['doc_no']) ?></div>
            <label>วันที่เอกสาร</label>
            <div><?= htmlspecialchars((string)$doc['doc_date']) ?></div>

            <label>ปีงบประมาณ</label>
            <div><?= htmlspecialchars((string)$doc['fiscal_year']) ?></div>
            <label>รอบโอน</label>
            <div><?= htmlspecialchars((string)$doc['transfer_round']) ?></div>

            <label>หน่วยเจ้าของงบ</label>
            <div><?= htmlspecialchars(trim(($doc['unit_code'] ?? '') . ' - ' . ($doc['unit_name'] ?? ''))) ?></div>
            <label>หน่วยผู้จ่ายงบ</label>
            <div>
                <?php
                $paying = trim(($doc['paying_unit_code'] ?? '') . ' - ' . ($doc['paying_unit_name'] ?? ''));
                echo $paying !== '-' && $paying !== '' ? htmlspecialchars($paying) : '-';
                ?>
            </div>

            <label>วันที่รับเรื่อง</label>
            <div><?= htmlspecialchars((string)($doc['receive_date'] ?? '-')) ?></div>
            <label>วันที่บันทึก GFMIS</label>
            <div><?= htmlspecialchars((string)($doc['gfmis_date'] ?? '-')) ?></div>

            <label>รหัส GFMIS</label>
            <div><?= htmlspecialchars((string)($doc['gfmis_code'] ?? '-')) ?></div>
            <label>รหัสกิจกรรมหลัก</label>
            <div><?= htmlspecialchars((string)($doc['activity_code'] ?? '-')) ?></div>

            <label>เรื่อง</label>
            <div><?= htmlspecialchars((string)($doc['subject'] ?? '-')) ?></div>
            <label>ประเภทงบประมาณ</label>
            <div><?= htmlspecialchars(formatBudgetTypes($doc['budget_types'] ?? null)) ?></div>

            <label>เลขบัญชีคุม</label>
            <div><?= htmlspecialchars((string)($doc['control_account_no'] ?: '-')) ?></div>
            <label>รหัสงบประมาณ ทบ.</label>
            <div><?= htmlspecialchars((string)($doc['budget_code'] ?: '-')) ?></div>

            <label>แหล่งของเงิน</label>
            <div><?= htmlspecialchars((string)($doc['source_fund'] ?: '-')) ?></div>
            <label>หมวดเงิน</label>
            <div><?= htmlspecialchars((string)($doc['expense_category'] ?: '-')) ?></div>

            <label>ประเภท/ของ</label>
            <div><?= htmlspecialchars((string)($doc['item_type'] ?: '-')) ?></div>
        </div>

        <div style="margin-top:12px;">
            <label>ชื่อรายการ</label>
            <div><?= nl2br(htmlspecialchars((string)($doc['item_name'] ?? '-'))) ?></div>
        </div>

        <div class="kpi-grid" style="margin-top:12px;">
            <div class="kpi-card">
                <p class="label">ยอดเงินรวม</p>
                <p class="value"><?= number_format((float)$doc['total_amount'], 2) ?></p>
            </div>
            <div class="kpi-card">
                <p class="label">ยอดที่ใช้ในรอบ 2</p>
                <p class="value"><?= number_format($usedAmount, 2) ?></p>
            </div>
            <div class="kpi-card">
                <p class="label">คงเหลือ</p>
                <p class="value"><?= number_format($remainingAmount, 2) ?></p>
            </div>
        </div>
    </div>

    <div class="panel">
        <h3>รายการโอน</h3>
        <div class="table-wrap">
            <table>
                <tr>
                    <th style="width:60px">#</th>
                    <th>รายละเอียด</th>
                    <th class="text-right" style="width:180px">จำนวนเงิน</th><th>จัดการ</th>
                </tr>
                <?php $i = 1; $hasItems = false; while ($row = $items->fetch_assoc()): ?>
                    <?php $hasItems = true; ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><?= htmlspecialchars((string)$row['description']) ?></td>
                        <td class="text-right"><?= number_format((float)$row['amount'], 2) ?></td>
                    </tr>
                <?php endwhile; ?>
                <?php if (!$hasItems): ?>
                    <tr><td colspan="3" class="text-danger text-center">ไม่มีข้อมูลรายการโอน</td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <?php if ((int)$doc['transfer_round'] === 1): ?>
    <div class="panel">
        <h3>รายการโอนรอบที่ 2</h3>
        <div class="table-wrap">
            <table>
                <tr>
                    <th style="width:120px">เลขที่รายงาน</th>
                    <th style="width:120px">วันที่</th>
                    <th>รายละเอียด</th>
                    <th class="text-right" style="width:180px">จำนวนเงิน</th><th>จัดการ</th>
                </tr>
                <?php if ($hasDisbursements): ?>
                    <?php $hasReports = false; while ($report = $disbursements->fetch_assoc()): ?>
                        <?php $hasReports = true; ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$report['report_no']) ?></td>
                            <td><?= htmlspecialchars((string)$report['report_date']) ?></td>
                            <td><?= htmlspecialchars((string)$report['description']) ?></td>
                            <td class="text-right"><?= number_format((float)$report['amount'], 2) ?></td><td><?php if(has_permission('round2.manage')):?><a href="transfer_round2.php?year=<?=(int)$doc['fiscal_year']?>&edit=<?=(int)$report['id']?>">แก้ไข / ลบ</a><?php endif;?></td>
                        </tr>
                    <?php endwhile; ?>
                    <?php if (!$hasReports): ?>
                        <tr><td colspan="4" class="text-danger text-center">ยังไม่มีรายการโอนรอบที่ 2</td></tr>
                    <?php endif; ?>
                <?php else: ?>
                    <tr><td colspan="4" class="text-danger text-center">ยังไม่เปิดใช้งานโมดูลโอนรอบที่ 2 ในฐานข้อมูล</td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

</body>
</html>

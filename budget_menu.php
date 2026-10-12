<?php
require 'config/auth.php';
require 'config/db.php';
require_permission('budget.view');

$unitId = is_admin() ? (int)($_GET['unit_id'] ?? 0) : current_unit_id();
$units = is_admin() ? $conn->query('SELECT id, unit_code, unit_name FROM units ORDER BY unit_code')->fetch_all(MYSQLI_ASSOC) : [];
$year = isset($_GET['year']) ? (int)$_GET['year'] : fiscal_year_for_date();
$round = isset($_GET['round']) ? (int)$_GET['round'] : 0;
$hasDisbursements = db_has_table($conn, 'budget_disbursements');
$success = isset($_GET['success']) ? 'บันทึกเอกสารโอนงบเรียบร้อยแล้ว' : '';

$sql = '
    SELECT bt.id, bt.doc_no, bt.doc_date, bt.transfer_round, bt.total_amount, u.unit_code,
           ' . ($hasDisbursements ? 'COALESCE(SUM(bd.amount), 0)' : '0') . ' AS round2_used
    FROM budget_transfers bt
    JOIN units u ON u.id = bt.unit_id
    ' . ($hasDisbursements ? 'LEFT JOIN budget_disbursements bd ON bd.source_transfer_id = bt.id AND bd.cancelled_at IS NULL' : '') . '
    WHERE bt.cancelled_at IS NULL AND bt.fiscal_year = ?
';

if ($unitId > 0) {
    $sql .= ' AND bt.unit_id = ? ';
}

if ($round > 0) {
    $sql .= ' AND bt.transfer_round = ? ';
}

$sql .= '
    GROUP BY bt.id, bt.doc_no, bt.doc_date, bt.transfer_round, bt.total_amount, u.unit_code
    ORDER BY bt.doc_date DESC, bt.id DESC
';

$stmt = $conn->prepare($sql);
if ($unitId > 0 && $round > 0) {
    $stmt->bind_param('iii', $year, $unitId, $round);
} elseif ($unitId > 0) {
    $stmt->bind_param('ii', $year, $unitId);
} elseif ($round > 0) {
    $stmt->bind_param('ii', $year, $round);
} else {
    $stmt->bind_param('i', $year);
}
$stmt->execute();
$rows = $stmt->get_result();

$round1Count = 0;
$round2Count = 0;
$data = [];
while ($row = $rows->fetch_assoc()) {
    if ((int)$row['transfer_round'] === 1) {
        $round1Count++;
    }
    if ((int)$row['transfer_round'] === 2) {
        $round2Count++;
    }
    $data[] = $row;
}
?>
<!DOCTYPE html>
<html lang="th">
<head><meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="UTF-8">
<title>เมนูงบประมาณ</title>
<link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body>
<?php require 'template/nav.php'; ?>

<div class="container">
    <div class="panel">
        <h1 class="page-title">เมนูงบประมาณ</h1>
        <p class="page-subtitle">จัดการรายการโอนงบรอบที่ 1 และรอบที่ 2</p>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if (!$hasDisbursements): ?>
            <div class="alert alert-danger">ยังไม่มีตาราง <code>budget_disbursements</code> กรุณา Import ไฟล์ <code>database/alter_v2_add_disbursements.sql</code></div>
        <?php endif; ?>

        <div class="toolbar">
            <?php if (has_permission('budget.create')): ?><a class="btn btn-primary" href="add_budget.php">เพิ่มงบโอนรอบที่ 1</a><?php endif; ?>
            <?php if ($hasDisbursements): ?>
                <a class="btn btn-primary" href="transfer_round2.php">เพิ่มรายการโอนรอบที่ 2</a>
            <?php endif; ?>
            <a class="btn btn-outline" href="budget_menu.php?year=<?= $year ?>">ทั้งหมด</a>
            <?php if (is_admin()): ?>
                <form method="get" class="toolbar compact" style="margin:0">
                    <input type="hidden" name="year" value="<?= $year ?>">
                    <select name="unit_id" style="width:260px" onchange="this.form.submit()">
                        <option value="0">ทุกหน่วยงาน</option>
                        <?php foreach ($units as $unit): ?><option value="<?= (int)$unit['id'] ?>" <?= $unitId === (int)$unit['id'] ? 'selected' : '' ?>><?= htmlspecialchars($unit['unit_code'] . ' - ' . $unit['unit_name']) ?></option><?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
            <a class="btn btn-outline" href="budget_menu.php?year=<?= $year ?>&round=1">รอบที่ 1</a>
            <a class="btn btn-outline" href="budget_menu.php?year=<?= $year ?>&round=2">รอบที่ 2</a>
        </div>

        <form method="get" class="form-grid" style="max-width:420px; margin-top:10px;">
            <label>ปีงบประมาณ</label>
            <input type="number" name="year" value="<?= $year ?>" min="2500" max="3000">
            <?php if ($round > 0): ?>
                <input type="hidden" name="round" value="<?= $round ?>">
            <?php endif; ?>
            <div></div>
            <button class="btn btn-primary" type="submit">ค้นหา</button>
        </form>

        <div class="kpi-grid">
            <div class="kpi-card">
                <p class="label">จำนวนรายการรอบที่ 1</p>
                <p class="value"><?= $round1Count ?></p>
            </div>
            <div class="kpi-card">
                <p class="label">จำนวนรายการรอบที่ 2</p>
                <p class="value"><?= $round2Count ?></p>
            </div>
            <div class="kpi-card">
                <p class="label">ตัวกรอง</p>
                <p class="value"><?= $round > 0 ? ('รอบที่ ' . $round) : 'ทั้งหมด' ?></p>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="table-wrap">
            <table>
                <tr>
                    <th style="width:150px">เลขที่เอกสาร</th>
                    <th style="width:120px">วันที่</th>
                    <?php if (is_admin()): ?><th>หน่วย</th><?php endif; ?>
                    <th style="width:90px">รอบ</th>
                    <th class="text-right" style="width:160px">จำนวนเงิน</th>
                    <th class="text-right" style="width:160px">ใช้ในรอบ 2</th>
                    <th class="text-right" style="width:160px">คงเหลือ</th>
                    <th style="width:280px">จัดการ</th>
                </tr>
                <?php if (count($data) === 0): ?>
                    <tr>
                        <td colspan="<?= is_admin() ? 8 : 7 ?>" class="text-danger text-center">ไม่มีข้อมูล</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($data as $row): ?>
                    <?php $remaining = (float)$row['total_amount'] - (float)$row['round2_used']; ?>
                    <tr>
                        <td><?= htmlspecialchars($row['doc_no']) ?></td>
                        <td><?= htmlspecialchars($row['doc_date']) ?></td>
                        <?php if (is_admin()): ?><td><?= htmlspecialchars($row['unit_code']) ?></td><?php endif; ?>
                        <td><?= (int)$row['transfer_round'] ?></td>
                        <td class="text-right"><?= number_format((float)$row['total_amount'], 2) ?></td>
                        <td class="text-right"><?= number_format((float)$row['round2_used'], 2) ?></td>
                        <td class="text-right"><?= number_format($remaining, 2) ?></td>
                        <td>
                            <div class="action-links">
                                <a href="budget_detail.php?id=<?= (int)$row['id'] ?>">ดูรายละเอียด</a>
                                <a href="exports/export_budget.php?id=<?= (int)$row['id'] ?>">ส่งออก Excel</a>
                                <?php if (has_permission('budget.edit')): ?><a href="edit_budget.php?id=<?= (int)$row['id'] ?>">แก้ไข</a><?php endif; ?>
                                <?php if (has_permission('budget.delete')): ?><a class="text-danger" href="delete_budget.php?id=<?= (int)$row['id'] ?>" onclick="return confirm('ยืนยันการลบเอกสารนี้? รายการที่มีเอกสารอ้างอิงต้องยกเลิกจากต้นทางก่อน')">ลบ</a><?php endif; ?>
                                <?php if ((int)$row['transfer_round'] === 1 && $hasDisbursements): ?>
                                    <a href="transfer_round2.php?year=<?= $year ?>&source_id=<?= (int)$row['id'] ?>">เพิ่มรายการโอนรอบที่ 2</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</div>

</body>
</html>

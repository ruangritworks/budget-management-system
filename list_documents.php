<?php
require 'config/auth.php';
require 'config/db.php';

$unitId = (int)($_SESSION['user']['unit_id'] ?? 0);
$year = isset($_GET['year']) ? (int)$_GET['year'] : fiscal_year_for_date();

$sql = '
    SELECT bt.id, bt.doc_no, bt.doc_date, bt.transfer_round, bt.total_amount, u.unit_code
    FROM budget_transfers bt
    LEFT JOIN units u ON u.id = bt.unit_id
    WHERE bt.cancelled_at IS NULL AND bt.unit_id = ? AND bt.fiscal_year = ?
    ORDER BY bt.doc_date DESC, bt.id DESC
';
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $unitId, $year);
$stmt->execute();
$list = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="th">
<head><meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="UTF-8">
<title>รายการเอกสารโอน</title>
<link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body>
<?php require 'template/nav.php'; ?>

<div class="container">
    <div class="panel">
        <h1 class="page-title">รายการเอกสารโอน</h1>
        <p class="page-subtitle">ปีงบประมาณ <?= $year ?></p>
        <div class="toolbar">
            <a href="add_budget.php" class="btn btn-primary">+ เพิ่มเอกสารโอน</a>
            <a href="budget_menu.php?year=<?= $year ?>" class="btn btn-outline">กลับเมนูงบประมาณ</a>
        </div>
    </div>

    <div class="panel">
        <div class="table-wrap">
            <table>
                <tr>
                    <th style="width:120px">เลขที่เอกสาร</th>
                    <th style="width:120px">วันที่</th>
                    <th style="width:80px">รอบ</th>
                    <th class="text-right" style="width:160px">จำนวนเงิน</th>
                    <th style="width:260px">จัดการ</th>
                </tr>
                <?php $hasRows = false; while ($row = $list->fetch_assoc()): ?>
                <?php $hasRows = true; ?>
                <tr>
                    <td><?= htmlspecialchars((string)$row['doc_no']) ?></td>
                    <td><?= htmlspecialchars((string)$row['doc_date']) ?></td>
                    <td><?= htmlspecialchars((string)$row['transfer_round']) ?></td>
                    <td class="text-right"><?= number_format((float)$row['total_amount'], 2) ?></td>
                    <td>
                        <div class="action-links">
                            <a href="budget_detail.php?id=<?= (int)$row['id'] ?>">ดูรายละเอียด</a>
                            <?php if(has_permission('budget.edit')):?><a href="edit_budget.php?id=<?= (int)$row['id'] ?>">แก้ไข</a><?php endif;?>
                            <?php if(has_permission('budget.delete')):?><a href="delete_budget.php?id=<?= (int)$row['id'] ?>">ลบ</a><?php endif;?>
                            <a href="exports/export_budget.php?id=<?= (int)$row['id'] ?>">ส่งออก</a>
                            <?php if ((int)$row['transfer_round'] === 1): ?>
                            <a href="transfer_round2.php?year=<?= $year ?>&source_id=<?= (int)$row['id'] ?>">เพิ่มรายการโอนรอบที่ 2</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php if (!$hasRows): ?>
                    <tr><td colspan="5" class="text-center text-danger">ไม่มีข้อมูล</td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

</body>
</html>

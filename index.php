<?php
require 'config/auth.php';
require 'config/db.php';

$unitId = is_admin() ? (int)($_GET['unit_id'] ?? 0) : current_unit_id();
$year = isset($_GET['year']) ? (int)$_GET['year'] : fiscal_year_for_date();
$units = is_admin() ? $conn->query('SELECT id,unit_code,unit_name FROM units ORDER BY unit_code')->fetch_all(MYSQLI_ASSOC) : [];

// งบตั้งต้น
$sql = 'SELECT COALESCE(SUM(base_amount),0) base_amount FROM budget_allocations WHERE fiscal_year=?'.($unitId>0?' AND unit_id=?':'');
$stmt = $conn->prepare($sql);
$unitId>0?$stmt->bind_param('ii',$year,$unitId):$stmt->bind_param('i',$year);
$stmt->execute();
$base = (float)($stmt->get_result()->fetch_assoc()['base_amount'] ?? 0);

// เงินโอนเข้า
$sql="SELECT COALESCE(SUM(amount),0) s FROM budget_transactions WHERE cancelled_at IS NULL AND ledger_entry_id IS NULL AND fiscal_year=? AND trans_type='transfer_in'".($unitId>0?' AND unit_id=?':'');
$stmt=$conn->prepare($sql);
$unitId>0?$stmt->bind_param('ii',$year,$unitId):$stmt->bind_param('i',$year);
$stmt->execute();
$add = (float)($stmt->get_result()->fetch_assoc()['s'] ?? 0);

// ใช้ไป / สงวน
$sql="SELECT COALESCE(SUM(amount),0) s FROM budget_transactions WHERE cancelled_at IS NULL AND ledger_entry_id IS NULL AND fiscal_year=? AND trans_type IN ('transfer_out','use','payment','advance','po_reserve')".($unitId>0?' AND unit_id=?':'');
$stmt=$conn->prepare($sql);
$unitId>0?$stmt->bind_param('ii',$year,$unitId):$stmt->bind_param('i',$year);
$stmt->execute();
$used = (float)($stmt->get_result()->fetch_assoc()['s'] ?? 0);

// Detailed control entries are not duplicated in budget_transactions.
$sql="SELECT COALESCE(SUM(CASE WHEN e.entry_type='increase' THEN e.amount ELSE 0 END),0) extra,
COALESCE(SUM(CASE WHEN e.entry_type IN ('transfer_out','return_army','payment','remittance') THEN e.amount ELSE 0 END),0) spent
FROM active_control_entries e JOIN control_accounts ca ON ca.id=e.control_account_id WHERE ca.cancelled_at IS NULL AND ca.fiscal_year=?".($unitId>0?' AND ca.unit_id=?':'');
$stmt=$conn->prepare($sql);$unitId>0?$stmt->bind_param('ii',$year,$unitId):$stmt->bind_param('i',$year);$stmt->execute();
$ledger=$stmt->get_result()->fetch_assoc();
$add+=(float)$ledger['extra'];$used+=(float)$ledger['spent'];

$sql='SELECT ca.id,ca.budget_code,ca.item_name,ca.received_amount,ca.status,u.unit_code,COALESCE(SUM(CASE WHEN e.entry_type="increase" THEN e.amount WHEN e.entry_type IN ("transfer_out","return_army","payment","remittance") THEN -e.amount ELSE 0 END),0) movement FROM control_accounts ca JOIN units u ON u.id=ca.unit_id LEFT JOIN active_control_entries e ON e.control_account_id=ca.id WHERE ca.cancelled_at IS NULL AND ca.fiscal_year=?'.($unitId>0?' AND ca.unit_id=?':'').' GROUP BY ca.id ORDER BY ca.id DESC';
$stmt=$conn->prepare($sql);$unitId>0?$stmt->bind_param('ii',$year,$unitId):$stmt->bind_param('i',$year);$stmt->execute();$accounts=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$remain = ($base + $add) - $used;
$budgetTotal = $base + $add;
$usedPercent = $budgetTotal > 0 ? ($used / $budgetTotal) * 100 : 0;
$remainPercent = $budgetTotal > 0 ? ($remain / $budgetTotal) * 100 : 0;
?>
<!DOCTYPE html>
<html lang="th">
<head><meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="UTF-8">
<title>หน้าหลักงบประมาณ</title>
<link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body>

<?php require 'template/nav.php'; ?>

<div class="container">
<section class="panel mdbs-hero"><p class="eyebrow">35th Military District / Budget Management</p><h1 class="page-title">35MDBS</h1><p>ระบบบริหารงบประมาณ มณฑลทหารบกที่ 35</p><a class="btn btn-primary" href="add_budget.php">สร้างบัญชีคุมงบประมาณ</a> <a class="btn btn-outline" href="overview.php">ดูภาพรวมหน่วย</a></section>
    <div class="panel">
        <h1 class="page-title">งบประมาณประจำปี <?= $year ?></h1>
        <p class="page-subtitle">รายละเอียดงบประมาณประจำปี</p>
        <form method="get" class="toolbar" style="margin-top:12px;">
            <label for="year" style="padding-top:8px;">ปีงบประมาณ</label>
            <input id="year" type="number" name="year" value="<?= $year ?>" min="2500" max="3000" style="max-width:140px;">
            <?php if(is_admin()):?><label for="unit_id">หน่วย</label><select id="unit_id" name="unit_id" style="width:300px"><option value="0">ทุกหน่วย</option><?php foreach($units as $unit):?><option value="<?=(int)$unit['id']?>" <?=$unitId===(int)$unit['id']?'selected':''?>><?=htmlspecialchars($unit['unit_code'].' '.$unit['unit_name'])?></option><?php endforeach;?></select><?php endif;?>
            <button type="submit" class="btn btn-primary">โหลดข้อมูล</button>
        </form>
    </div>

    <div class="panel"><h2>บัญชีคุมในปีงบประมาณ <?= $year ?></h2><div class="table-wrap"><table><tr><th>หน่วย</th><th>รหัสงบประมาณ</th><th>รายการ</th><th class="text-right">เงินรับ</th><th class="text-right">คงเหลือ</th><th>รายการการเงิน</th></tr><?php foreach($accounts as $account):?><tr><td><?=htmlspecialchars($account['unit_code'])?></td><td><a href="control_account_detail.php?id=<?=(int)$account['id']?>"><?=htmlspecialchars($account['budget_code'])?></a></td><td><?=htmlspecialchars($account['item_name'])?></td><td class="text-right"><?=number_format((float)$account['received_amount'],2)?></td><td class="text-right"><?=number_format((float)$account['received_amount']+(float)$account['movement'],2)?></td><td><a href="transactions.php?year=<?=$year?>&unit_id=<?=$unitId?>&account_id=<?=(int)$account['id']?>">เลือกบัญชีนี้</a></td></tr><?php endforeach;?><?php if(!$accounts):?><tr><td colspan="6">ยังไม่มีบัญชีคุม</td></tr><?php endif;?></table></div></div>

    <div class="panel">
        <div class="kpi-grid">
            <div class="kpi-card">
                <p class="label">ฐานงบประมาณ</p>
                <p class="value"><?= number_format($base, 2) ?></p>
            </div>
            <div class="kpi-card">
                <p class="label">เงินโอนเข้า</p>
                <p class="value"><?= number_format($add, 2) ?></p>
            </div>
            <div class="kpi-card">
                <p class="label">ใช้ไป / สงวน</p>
                <p class="value"><?= number_format($used, 2) ?></p>
            </div>
            <div class="kpi-card">
                <p class="label">เหลือ</p>
                <p class="value"><?= number_format($remain, 2) ?></p>
            </div>
            <div class="kpi-card">
                <p class="label">ใช้ไป %</p>
                <p class="value"><?= number_format($usedPercent, 2) ?>%</p>
            </div>
            <div class="kpi-card">
                <p class="label">เหลือ %</p>
                <p class="value"><?= number_format($remainPercent, 2) ?>%</p>
            </div>
        </div>
    </div>

    <div class="panel">
        <h3>ปีงบประมาณ <?= $year ?></h3>
        <div class="budget-chart">
            <div class="budget-donut <?=$budgetTotal<=0?'is-empty':''?>" style="--usage:<?=number_format(max(0,min(100,$usedPercent)),2,'.','')?>%" role="img" aria-label="ใช้ไป <?=number_format($usedPercent,2)?> เปอร์เซ็นต์ คงเหลือ <?=number_format($remainPercent,2)?> เปอร์เซ็นต์">
                <span><?=number_format($usedPercent,2)?>%<small>ใช้ไป / สงวน</small></span>
            </div>
            <div><p>ใช้ไป / สงวน <strong><?=number_format($used,2)?> บาท</strong></p><p>คงเหลือ <strong><?=number_format($remain,2)?> บาท</strong></p><?php if($budgetTotal<=0):?><p>ยังไม่มียอดจัดสรรในเงื่อนไขที่เลือก</p><?php endif;?></div>
        </div>
    </div>
</div>



</body>
</html>

<?php
require '../config/auth.php';
require '../config/db.php';
require '../config/organization.php';
require '../vendor/autoload.php';
require_once '../config/document_signatures.php';
require_permission('report.export');
require_once '../config/export_guard.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

if (ob_get_level()) {
    ob_end_clean();
}

$transferId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($transferId <= 0) {
    die('ไม่พบรหัสเอกสาร');
}

$templatePath = realpath(__DIR__ . '/../template/account_template(A).xlsx');
if(!$templatePath) $templatePath = realpath(__DIR__ . '/../template/ex.ทบ.500-001.xlsx');
if ($templatePath === false || !file_exists($templatePath)) {
    die('ไม่พบไฟล์ต้นแบบ: template/account_template(A).xlsx');
}

$hasPayingUnit = db_has_column($conn, 'budget_transfers', 'paying_unit_id');
$hasReceiveDate = db_has_column($conn, 'budget_transfers', 'receive_date');
$hasItemName = db_has_column($conn, 'budget_transfers', 'item_name');
$hasActivityCode = db_has_column($conn, 'budget_transfers', 'activity_code');
$hasBudgetTypes = db_has_column($conn, 'budget_transfers', 'budget_types');
$hasDisbursements = db_has_table($conn, 'budget_disbursements');

$selectPaying = $hasPayingUnit
    ? "pu.unit_code AS paying_unit_code, pu.unit_name AS paying_unit_name"
    : "NULL AS paying_unit_code, NULL AS paying_unit_name";
$joinPaying = $hasPayingUnit
    ? "LEFT JOIN units pu ON pu.id = bt.paying_unit_id"
    : "";

$sqlDoc = "
    SELECT bt.*,
           " . ($hasReceiveDate ? "bt.receive_date" : "NULL AS receive_date") . ",
           " . ($hasItemName ? "bt.item_name" : "NULL AS item_name") . ",
           " . ($hasActivityCode ? "bt.activity_code" : "NULL AS activity_code") . ",
           " . ($hasBudgetTypes ? "bt.budget_types" : "NULL AS budget_types") . ",
           u.unit_code, u.unit_name,
           ca.budget_code AS account_no, ca.army_code AS account_army_code,
           ca.gfmis_code AS account_gfmis_code, ca.activity_code AS account_activity_code,
           $selectPaying
    FROM budget_transfers bt
    LEFT JOIN units u ON u.id = bt.unit_id
    LEFT JOIN control_accounts ca ON ca.source_transfer_id = bt.id
    $joinPaying
    WHERE bt.id = ?
";
$stmt = $conn->prepare($sqlDoc);
$stmt->bind_param('i', $transferId);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();

if (!$doc) {
    die('ไม่พบข้อมูลเอกสาร');
}
if (!can_access_unit((int)$doc['unit_id'])) {
    http_response_code(403);
    die('ไม่มีสิทธิ์ส่งออกเอกสารนี้');
}

$sqlItem = "
    SELECT description, amount
    FROM budget_transfer_items
    WHERE transfer_id = ?
    ORDER BY id ASC
";
$stmt = $conn->prepare($sqlItem);
$stmt->bind_param('i', $transferId);
$stmt->execute();
$itemsResult = $stmt->get_result();
$items = [];
while ($row = $itemsResult->fetch_assoc()) {
    $items[] = $row;
}

$reports = [];
if ($hasDisbursements) {
    $sqlDisbursement = "
        SELECT report_no, report_date, description, amount
        FROM budget_disbursements
        WHERE source_transfer_id = ? AND ledger_entry_id IS NULL AND cancelled_at IS NULL
        ORDER BY report_date ASC, id ASC
    ";
    $stmt = $conn->prepare($sqlDisbursement);
    $stmt->bind_param('i', $transferId);
    $stmt->execute();
    $reportResult = $stmt->get_result();
    while ($row = $reportResult->fetch_assoc()) {
        $reports[] = $row;
    }
}

$ledgerEntries = [];
if (db_has_table($conn, 'control_account_entries')) {
    $sqlLedger = "
        SELECT e.entry_date, e.disbursement_no, e.entry_type, e.amount, e.note,
               e.gfmis_code, e.activity_code, e.expense_category, u.unit_code, u.unit_name
        FROM active_control_entries e
        INNER JOIN control_accounts ca ON ca.id = e.control_account_id
        LEFT JOIN units u ON u.id = e.spending_unit_id
        WHERE ca.source_transfer_id = ?
        ORDER BY e.entry_date, e.id
    ";
    $stmt = $conn->prepare($sqlLedger);
    $stmt->bind_param('i', $transferId);
    $stmt->execute();
    $ledgerEntries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$spreadsheet = IOFactory::load($templatePath);
$sheet = $spreadsheet->getActiveSheet();

// Write only to the value cells; the neighboring cells are merged label ranges.
$typeNames = [
    'Annual' => 'งบประจำปี', 'Investment' => 'งบลงทุน', 'Central' => 'งบกลาง',
    'Interagency' => 'งบเบิกแทนกัน', 'Carryover' => 'กันไว้เบิกเหลื่อมปี',
    'Operating' => 'งบดำเนินงาน', 'Additional' => 'เพิ่มเติม/อื่น ๆ',
    'Compensation' => 'งบบุคลากร',
];
$budgetTypeLabels = array_map(
    static fn(string $type): string => $typeNames[$type] ?? $type,
    array_filter(array_map('trim', explode(',', (string)$doc['budget_types'])))
);
$sheet->setCellValue('A4', 'รหัส GFMIS : ');
$header = [
    'D3' => $doc['control_account_no'] ?: ($doc['account_no'] ?: $doc['doc_no']),
    'H3' => $doc['budget_code'] ?: $doc['account_army_code'],
    'D4' => $doc['gfmis_code'] ?: $doc['account_gfmis_code'],
    'H4' => $doc['activity_code'] ?: $doc['account_activity_code'],
    'D5' => $doc['item_name'] ?: $doc['subject'],
    'D6' => $doc['source_fund'] ?: '',
    'H6' => $doc['expense_category'] ?: '',
    'D7' => trim(($doc['paying_unit_code'] ?: $doc['unit_code']) . ' ' . ($doc['paying_unit_name'] ?: $doc['unit_name'])),
    'H7' => trim($doc['unit_code'] . ' ' . $doc['unit_name']),
    'D8' => $doc['paying_unit_code'] ?: $doc['unit_code'],
    'H8' => $doc['item_type'] ?: implode(', ', $budgetTypeLabels),
];
foreach ($header as $cell => $value) {
    $sheet->setCellValueExplicit($cell, (string)($value ?? ''), DataType::TYPE_STRING);
}
$sheet->setCellValue('A10', (int)$doc['fiscal_year']);

$baseDate = strtotime((string)$doc['doc_date']);
$month = $baseDate ? (int)date('n', $baseDate) : '';
$day = $baseDate ? (int)date('j', $baseDate) : '';

$rows = [];
$defaultDescription = trim((string)($doc['item_name'] ?: $doc['subject']));

if (count($items) === 0) {
    $rows[] = [
        'month' => $month,
        'day' => $day,
        'doc_no' => (string)$doc['doc_no'],
        'description' => $defaultDescription,
        'increase' => (float)$doc['total_amount'],
        'transfer_out' => 0.0,
        'return_army' => 0.0,
        'carry' => 0.0,
        'request' => 0.0,
        'obligation' => 0.0,
        'disburse' => 0.0,
        'remit' => 0.0,
        'note' => 'โอนเข้า',
    ];
} else {
    foreach ($items as $item) {
        $rows[] = [
            'month' => $month,
            'day' => $day,
            'doc_no' => (string)$doc['doc_no'],
            'description' => (string)$item['description'],
            'increase' => (float)$item['amount'],
            'transfer_out' => 0.0,
            'return_army' => 0.0,
            'carry' => 0.0,
            'request' => 0.0,
            'obligation' => 0.0,
            'disburse' => 0.0,
            'remit' => 0.0,
            'note' => 'โอนเข้า',
        ];
    }
}

foreach ($reports as $report) {
    $ts = strtotime((string)$report['report_date']);
    $rows[] = [
        'month' => $ts ? (int)date('n', $ts) : '',
        'day' => $ts ? (int)date('j', $ts) : '',
        'doc_no' => (string)$report['report_no'],
        'description' => (string)$report['description'],
        'increase' => 0.0,
        'transfer_out' => 0.0,
        'return_army' => 0.0,
        'carry' => 0.0,
        'request' => 0.0,
        'obligation' => 0.0,
        'disburse' => (float)$report['amount'],
        'remit' => 0.0,
        'note' => 'รายงานรอบที่ 2',
    ];
}

foreach ($ledgerEntries as $entry) {
    $ts = strtotime((string)$entry['entry_date']);
    $row = [
        'month' => $ts ? (int)date('n', $ts) : '', 'day' => $ts ? (int)date('j', $ts) : '',
        'doc_no' => (string)$entry['disbursement_no'], 'description' => (string)($entry['note'] ?: $defaultDescription),
        'increase' => 0.0, 'transfer_out' => 0.0, 'return_army' => 0.0, 'carry' => 0.0,
        'request' => 0.0, 'obligation' => 0.0, 'disburse' => 0.0, 'remit' => 0.0,
        'note' => (string)$entry['note'],
    ];
    $columnMap = ['increase'=>'increase','transfer_out'=>'transfer_out','return_army'=>'return_army','carry'=>'carry','request'=>'request','obligation'=>'obligation','payment'=>'disburse','remittance'=>'remit'];
    if (isset($columnMap[$entry['entry_type']])) {
        $row[$columnMap[$entry['entry_type']]] = (float)$entry['amount'];
    }
    $rows[] = $row;
}

$startRow = 13;
$line = $startRow;
$runningBalance = 0.0;
$sumIncrease = 0.0;
$sumTransferOut = 0.0;
$sumReturnArmy = 0.0;
$sumCarry = 0.0;
$sumRequest = 0.0;
$sumObligation = 0.0;
$sumDisburse = 0.0;
$sumRemit = 0.0;

foreach ($rows as $row) {
    $runningBalance += $row['increase'];
    $runningBalance -= $row['transfer_out'];
    $runningBalance -= $row['return_army'];
    // These are workflow stages, not additional cash reductions.
    $runningBalance -= $row['disburse'];
    $runningBalance -= $row['remit'];

    $sumIncrease += $row['increase'];
    $sumTransferOut += $row['transfer_out'];
    $sumReturnArmy += $row['return_army'];
    $sumCarry += $row['carry'];
    $sumRequest += $row['request'];
    $sumObligation += $row['obligation'];
    $sumDisburse += $row['disburse'];
    $sumRemit += $row['remit'];

    $sheet->setCellValue('A' . $line, $row['month']);
    $sheet->setCellValue('B' . $line, $row['day']);
    $sheet->setCellValue('C' . $line, $row['doc_no']);
    $sheet->setCellValue('D' . $line, $row['description']);
    $sheet->setCellValue('E' . $line, $row['increase']);
    $sheet->setCellValue('F' . $line, $row['transfer_out']);
    $sheet->setCellValue('G' . $line, $row['return_army']);
    $sheet->setCellValue('H' . $line, $row['carry']);
    $sheet->setCellValue('I' . $line, $row['request']);
    $sheet->setCellValue('J' . $line, $row['obligation']);
    $sheet->setCellValue('K' . $line, $row['disburse']);
    $sheet->setCellValue('L' . $line, $row['remit']);
    $sheet->setCellValue('M' . $line, $runningBalance);
    $sheet->setCellValue('N' . $line, $row['note']);
    $line++;
}

// Summary row
$sheet->setCellValue('D' . $line, 'รวม');
$sheet->setCellValue('E' . $line, $sumIncrease);
$sheet->setCellValue('F' . $line, $sumTransferOut);
$sheet->setCellValue('G' . $line, $sumReturnArmy);
$sheet->setCellValue('H' . $line, $sumCarry);
$sheet->setCellValue('I' . $line, $sumRequest);
$sheet->setCellValue('J' . $line, $sumObligation);
$sheet->setCellValue('K' . $line, $sumDisburse);
$sheet->setCellValue('L' . $line, $sumRemit);
$sheet->setCellValue('M' . $line, $runningBalance);
$sheet->getStyle('D' . $line)->getFont()->setBold(true);

$signers = db_document_signers($conn, (int)$doc['unit_id'], 'TB500-001');
if ($signers) {
    document_signatures_sheet($sheet, $signers, max($line, $sheet->getHighestRow()), 14);
}

$sheet->getStyle('E' . $startRow . ':M' . $line)
    ->getNumberFormat()
    ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
$sheet->getStyle($sheet->calculateWorksheetDimension())->getFont()->setName('TH SarabunPSK')->setSize(16);

// Keep the printed account layout intact and include every entry's own codes.
if ($ledgerEntries) {
    $details = $spreadsheet->createSheet();
    $details->setTitle('รายละเอียด GFMIS');
    $details->fromArray(['วันที่','บัญชีคุม','รหัส GFMIS','รหัสกิจกรรมหลัก','ครั้งที่เบิก','ประเภท','จำนวนเงิน','หน่วยผู้เบิก','หมวดเงิน','หมายเหตุ'], null, 'A1');
    $entryNames = ['increase'=>'เงินเพิ่ม','transfer_out'=>'โอนไป','return_army'=>'ส่งคืน ทบ.','carry'=>'กันไว้เบิก','request'=>'ขอใช้','obligation'=>'ผูกพัน','payment'=>'ตัดจ่าย','remittance'=>'นำส่ง'];
    foreach ($ledgerEntries as $index => $entry) {
        $r = $index + 2;
        $details->setCellValue('A'.$r, $entry['entry_date']);
        $details->setCellValueExplicit('B'.$r, (string)$header['D3'], DataType::TYPE_STRING);
        $details->setCellValueExplicit('C'.$r, (string)$entry['gfmis_code'], DataType::TYPE_STRING);
        $details->setCellValueExplicit('D'.$r, (string)$entry['activity_code'], DataType::TYPE_STRING);
        $details->setCellValue('E'.$r, $entry['disbursement_no']);
        $details->setCellValue('F'.$r, $entryNames[$entry['entry_type']] ?? $entry['entry_type']);
        $details->setCellValue('G'.$r, (float)$entry['amount']);
        $details->setCellValue('H'.$r, trim((string)$entry['unit_code'].' '.(string)$entry['unit_name']));
        $details->setCellValueExplicit('I'.$r, (string)$entry['expense_category'], DataType::TYPE_STRING);
        $details->setCellValue('J'.$r, (string)$entry['note']);
    }
    $details->getStyle('A1:J1')->getFont()->setBold(true);
    $details->getStyle('A1:J'.(count($ledgerEntries)+1))->getFont()->setName('TH SarabunPSK')->setSize(16);
    $details->getStyle('G2:G'.(count($ledgerEntries)+1))->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
    foreach (['A'=>16,'B'=>18,'C'=>24,'D'=>24,'E'=>14,'F'=>20,'G'=>18,'H'=>26,'I'=>14,'J'=>40] as $col=>$width) $details->getColumnDimension($col)->setWidth($width);
    $details->getStyle('A1:J1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $details->freezePane('A2');
    document_signatures_sheet($details, $signers, count($ledgerEntries)+1, 10);
    $spreadsheet->setActiveSheetIndex(0);
}

$filename = 'TB500-001_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)$doc['doc_no']) . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$findClassification=$conn->prepare('SELECT id FROM control_accounts WHERE source_transfer_id=?');$findClassification->bind_param('i',$transferId);$findClassification->execute();$classificationAccount=$findClassification->get_result()->fetch_assoc();
if($classificationAccount && ($classification=classification_get($conn,(int)$classificationAccount['id']))) {
 $classSheet=$spreadsheet->createSheet();$classSheet->setTitle('หน่วยและแผนงาน');$line=1;
 foreach(['main_unit'=>'หน่วยหลัก','sub_unit'=>'หน่วยย่อย','secondary_unit'=>'หน่วยรอง','cost_center'=>'ศูนย์ต้นทุน','strategy_no'=>'ยุทธศาสตร์ที่','strategy_name'=>'ยุทธศาสตร์','plan_name'=>'แผนงาน','activity_name'=>'กิจกรรม','work_group'=>'กลุ่มงบงาน','project_name'=>'งบงานย่อย / โครงการ','expense_code'=>'หมวดเงิน','expense_name'=>'ชื่อหมวดเงิน'] as $key=>$label) {
  $classSheet->setCellValueExplicit('A'.$line,$label,DataType::TYPE_STRING);$classSheet->setCellValueExplicit('B'.$line,(string)($classification[$key]??''),DataType::TYPE_STRING);$line++;
 }
 $classSheet->getColumnDimension('A')->setWidth(24);$classSheet->getColumnDimension('B')->setWidth(65);$classSheet->getStyle('A1:B'.$line)->getFont()->setName('TH SarabunPSK')->setSize(16);
 document_signatures_sheet($classSheet, $signers, $line-1, 2);
 $spreadsheet->setActiveSheetIndex(0);
}
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;

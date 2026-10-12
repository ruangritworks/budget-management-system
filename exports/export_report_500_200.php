<?php
require '../config/auth.php';
require '../config/db.php';
require '../vendor/autoload.php';
require_once '../config/document_signatures.php';
require_permission('report.export');
require_once '../config/export_guard.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

if (ob_get_level()) {
    ob_end_clean();
}

$unitId = is_admin() ? (int)($_GET['unit_id'] ?? 0) : current_unit_id();
$year = isset($_GET['year']) ? (int)$_GET['year'] : fiscal_year_for_date();

if (!db_has_table($conn, 'control_accounts')) {
    die('ยังไม่มีตาราง control_accounts กรุณา Import ไฟล์ database/alter_v3_add_control_accounts.sql');
}

$sql = "
    SELECT ca.id, ca.budget_code, ca.item_name, ca.gfmis_code,
           ca.received_amount + COALESCE(SUM(CASE WHEN e.entry_type='increase' THEN e.amount ELSE 0 END),0) AS received_amount,
           COALESCE(SUM(CASE WHEN e.entry_type='payment' THEN e.amount ELSE 0 END),0) AS disbursed_amount,
           COALESCE(SUM(CASE WHEN e.entry_type IN ('transfer_out','return_army','remittance') THEN e.amount ELSE 0 END),0) AS returned_amount,
           ca.received_amount + COALESCE(SUM(CASE WHEN e.entry_type='increase' THEN e.amount ELSE 0 END),0) - COALESCE(SUM(CASE WHEN e.entry_type IN ('transfer_out','return_army','payment','remittance') THEN e.amount ELSE 0 END),0) AS remaining_amount
    FROM control_accounts ca LEFT JOIN active_control_entries e ON e.control_account_id=ca.id
    WHERE ca.fiscal_year = ? AND ca.status IN ('ready', 'exported')" . ($unitId > 0 ? " AND ca.unit_id = ?" : "") . "
    GROUP BY ca.id ORDER BY ca.budget_code,ca.id
";
$stmt = $conn->prepare($sql);
$unitId > 0 ? $stmt->bind_param('ii', $year, $unitId) : $stmt->bind_param('i', $year);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}

if (count($rows) === 0) {
    die('ไม่มีข้อมูลสถานะพร้อมส่งออกสำหรับสร้างรายงาน');
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('TB500-200');

$sheet->setCellValue('A1', 'รายงาน ทบ.500-200');
$sheet->setCellValue('A2', 'ปีงบประมาณ: ' . $year);
$sheet->setCellValue('A4', 'ลำดับ');
$sheet->setCellValue('B4', 'รหัสงบ');
$sheet->setCellValue('C4', 'รายการ');
$sheet->setCellValue('D4', 'รหัส GFMIS');
$sheet->setCellValue('E4', 'รับ');
$sheet->setCellValue('F4', 'เบิก');
$sheet->setCellValue('G4', 'คืน');
$sheet->setCellValue('H4', 'คงเหลือ');
$sheet->setCellValue('I4', 'ร้อยละเบิก');

$line = 5;
$index = 1;
$totalReceived = 0.0;
$totalDisbursed = 0.0;
$totalReturned = 0.0;
$totalRemaining = 0.0;

foreach ($rows as $row) {
    $received = (float)$row['received_amount'];
    $disbursed = (float)$row['disbursed_amount'];
    $returned = (float)$row['returned_amount'];
    $remaining = (float)$row['remaining_amount'];
    $pct = $received > 0 ? $disbursed / $received : 0;

    $sheet->setCellValue('A' . $line, $index++);
    $sheet->setCellValue('B' . $line, $row['budget_code']);
    $sheet->setCellValue('C' . $line, $row['item_name']);
    $sheet->setCellValueExplicit('D' . $line, (string)$row['gfmis_code'], DataType::TYPE_STRING);
    $sheet->setCellValue('E' . $line, $received);
    $sheet->setCellValue('F' . $line, $disbursed);
    $sheet->setCellValue('G' . $line, $returned);
    $sheet->setCellValue('H' . $line, $remaining);
    $sheet->setCellValue('I' . $line, $pct);

    $totalReceived += $received;
    $totalDisbursed += $disbursed;
    $totalReturned += $returned;
    $totalRemaining += $remaining;
    $line++;
}

$sheet->setCellValue('D' . $line, 'รวม');
$sheet->setCellValue('E' . $line, $totalReceived);
$sheet->setCellValue('F' . $line, $totalDisbursed);
$sheet->setCellValue('G' . $line, $totalReturned);
$sheet->setCellValue('H' . $line, $totalRemaining);
$sheet->setCellValue('I' . $line, $totalReceived > 0 ? $totalDisbursed / $totalReceived : 0);

$sheet->getStyle('E5:H' . $line)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
$sheet->getStyle('I5:I' . $line)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_PERCENTAGE_00);

$sheet->getColumnDimension('A')->setWidth(6);
$sheet->getColumnDimension('B')->setWidth(14);
$sheet->getColumnDimension('C')->setWidth(50);
$sheet->getColumnDimension('D')->setWidth(20);
$sheet->getColumnDimension('E')->setWidth(14);
$sheet->getColumnDimension('F')->setWidth(14);
$sheet->getColumnDimension('G')->setWidth(14);
$sheet->getColumnDimension('H')->setWidth(14);
$sheet->getColumnDimension('I')->setWidth(12);
document_signatures_sheet($sheet, db_document_signers($conn,$unitId,'TB500-200'), $line, 9);



$filename = 'TB500-200_' . $year . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$sheet->getStyle($sheet->calculateWorksheetDimension())->getFont()->setName('TH SarabunPSK');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;

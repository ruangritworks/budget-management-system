<?php
require '../config/auth.php';
require '../config/db.php';
require '../vendor/autoload.php';
require_once '../config/document_signatures.php';
require_permission('report.export');
require_once '../config/export_guard.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$unitId = (int)($_SESSION['user']['unit_id'] ?? 0);
$year = isset($_GET['year']) ? (int)$_GET['year'] : fiscal_year_for_date();

$book = new Spreadsheet();
$sheet = $book->getActiveSheet();
$sheet->setTitle('รายการการเงิน');
$sheet->setCellValue('A1', 'รายการการเงิน ปีงบประมาณ '.$year);
$sheet->fromArray(['วันที่','ประเภทรายการ','จำนวนเงิน','หมายเหตุ'], null, 'A3');

$typeMap = [
    'base' => 'งบตั้งต้น',
    'transfer_in' => 'เงินโอนเข้า',
    'transfer_out' => 'เงินโอนออก',
    'use' => 'ใช้จ่าย',
    'advance' => 'เงินยืม',
    'po_reserve' => 'กันเงิน PO',
    'payment' => 'ตัดจ่าย',
];

$sql = "
    SELECT trans_date, trans_type, amount, remark
    FROM budget_transactions
    WHERE unit_id = ? AND fiscal_year = ?
    ORDER BY trans_date ASC, id ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $unitId, $year);
$stmt->execute();
$result = $stmt->get_result();

$line = 4;
while ($row = $result->fetch_assoc()) {
    $date = $row['trans_date'];
    $type = $typeMap[$row['trans_type']] ?? $row['trans_type'];
    $remark = str_replace(["\t", "\n", "\r"], ' ', (string)$row['remark']);
    $sheet->setCellValueExplicit('A'.$line, (string)$date, DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('B'.$line, (string)$type, DataType::TYPE_STRING);
    $sheet->setCellValue('C'.$line, (float)$row['amount']);
    $sheet->setCellValueExplicit('D'.$line, $remark, DataType::TYPE_STRING);
    $line++;
}
$endRow = max(3, $line-1);
$sheet->getStyle('A1:D'.$endRow)->getFont()->setName('TH SarabunPSK')->setSize(16);
$sheet->getStyle('A3:D3')->getFont()->setBold(true);
$sheet->getStyle('A3:D'.$endRow)->getBorders()->getAllBorders()->setBorderStyle('thin');
$sheet->getStyle('C4:C'.max(4,$endRow))->getNumberFormat()->setFormatCode('#,##0.00');
foreach (['A'=>18,'B'=>24,'C'=>18,'D'=>65] as $column=>$width) $sheet->getColumnDimension($column)->setWidth($width);
$sheet->freezePane('A4');
document_signatures_sheet($sheet, db_document_signers($conn,$unitId,'TRANSACTIONS'), $endRow, 4);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="transactions_'.$year.'.xlsx"');
header('Cache-Control: no-store');
(new Xlsx($book))->save('php://output');
exit;

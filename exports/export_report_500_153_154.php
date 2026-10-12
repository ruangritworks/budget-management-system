<?php
// Retain the legacy combined download with current template-backed reports.
require '../config/auth.php';require '../config/db.php';require '../vendor/autoload.php';
require '../config/reporting_35.php';require '../config/report_workbook_35.php';
require_permission('report.export');
require_once '../config/export_guard.php';
try {
    $year=(int)($_GET['year']??fiscal_year_for_date());
    $unit=is_admin()?(int)($_GET['unit_id']??0):current_unit_id();
    $filter=array_merge($_GET,['year'=>$year,'unit_id'=>$unit]);
    $rows=array_values(array_filter(report_accounts($conn,$filter),fn($row)=>in_array($row['status'],['ready','exported'],true)));
    if(!$rows)throw new DomainException('ไม่พบรายการพร้อมส่งออกตามปีและหน่วยที่เลือก');
    $book=report_workbook($rows,'153',$filter,db_document_signers($conn,$unit,'TB500-153'));
    $other=report_workbook($rows,'154',$filter,db_document_signers($conn,$unit,'TB500-154'));
    foreach($other->getAllSheets() as $sheet){
        if($book->getSheetByName($sheet->getTitle()))$sheet->setTitle('154 '.$sheet->getTitle());
        $book->addExternalSheet($sheet);
    }
    $book->setActiveSheetIndex(0);
    if(ob_get_level())ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="TB500-153-154_'.$year.'.xlsx"');
    header('Cache-Control: no-store');
    (new PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');
}catch(Throwable $error){http_response_code(400);header('Content-Type: text/plain; charset=UTF-8');echo operation_error($error);}

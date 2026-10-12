<?php
require '../config/auth.php';require '../config/db.php';require '../vendor/autoload.php';require '../config/reporting_35.php';require '../config/report_workbook_35.php';require '../config/archive.php';require_permission('report.export');
require_once '../config/export_guard.php';
try {
 $kind=(string)($_GET['report']??'002');$rows=report_accounts($conn,$_GET);if(!$rows)throw new DomainException('ไม่พบข้อมูลตามเงื่อนไข จึงยังไม่สร้างรายงาน');
 $signers=db_document_signers($conn,is_admin()?(int)($_GET['unit_id']??0):current_unit_id(),'TB500-'.$kind);
 $book=report_workbook($rows,$kind,$_GET,$signers);$filename='35MDBS_500-'.$kind.'_'.(int)($_GET['year']??fiscal_year_for_date()).'.xlsx';
 $writer=new PhpOffice\PhpSpreadsheet\Writer\Xlsx($book);$bytes=archive_spreadsheet($conn,$writer,'REPORT_35_'.$kind,0,is_admin()?0:current_unit_id(),(int)($_GET['year']??fiscal_year_for_date()),$filename,['filters'=>$_GET,'accounts'=>array_column($rows,'id'),'template_reference'=>$kind==='153'?'TB500-153-1':'TB500-'.$kind,'requires_layout_acceptance'=>true,'signers'=>$signers]);
 header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="'.$filename.'"');header('Cache-Control: no-store');echo $bytes;
} catch(Throwable $e){http_response_code(400);header('Content-Type: text/plain; charset=UTF-8');echo operation_error($e);}

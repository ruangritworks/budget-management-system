<?php
require_once __DIR__.'/document_signatures.php';
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
function report_cell($sheet,int $column,int $row,$value): void {
    $cell=Coordinate::stringFromColumnIndex($column).$row;
    if(is_int($value)||is_float($value))$sheet->setCellValue($cell,$value);
    else $sheet->setCellValueExplicit($cell,(string)$value,DataType::TYPE_STRING);
}
function report_details_sheet(Spreadsheet $book,array $rows): void {
    $sheet=$book->createSheet();$sheet->setTitle('ข้อมูลบัญชีคุม');
    $headers=['รหัสงบประมาณ','ปีงบประมาณ','หน่วยหลัก','หน่วยย่อย','หน่วยรอง','ศูนย์ต้นทุน','ยุทธศาสตร์','แผนงาน','กิจกรรม','กลุ่มงบงาน','งบงานย่อย / โครงการ','รายการ','รหัส GFMIS','รหัสกิจกรรมหลัก','หมวดเงิน','ชื่อหมวดเงิน','ประเภทเงิน','รับสุทธิสะสม','ตัดจ่ายสะสม','GF ยืนยันแล้ว','คงเหลือเงินสด','วงเงินใช้ได้'];
    foreach($headers as $i=>$v)report_cell($sheet,$i+1,1,$v);
    foreach($rows as $i=>$r){$s=$r['classification'];$values=[$r['budget_code'],$r['fiscal_year'],$s['main_unit']??'ยังไม่จำแนก',$s['sub_unit']??$r['owner_name'],$s['secondary_unit']??'',$s['cost_center']??'',$s['strategy_no']??'',$s['plan_name']??'',$s['activity_name']??'',$s['work_group']??'',$s['project_name']??'',$r['item_name'],$r['gfmis_code'],$r['activity_code'],$r['expense_code']??'',$s['expense_name']??'',$r['budget_types'],$r['allocated']/100,$r['payment']/100,$r['gf_paid']/100,$r['cash']/100,$r['available']/100];foreach($values as $j=>$v)report_cell($sheet,$j+1,$i+2,$v);}
    $sheet->getStyle('A1:V'.max(2,count($rows)+1))->getFont()->setName('TH SarabunPSK')->setSize(16);$sheet->getStyle('A1:V1')->getFont()->setBold(true);$sheet->freezePane('C2');$sheet->setAutoFilter('A1:V'.max(2,count($rows)+1));
    foreach(range(1,22) as $c)$sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(in_array($c,[8,10,12],true)?40:24);
    $sheet->getStyle('R2:V'.max(2,count($rows)+1))->getNumberFormat()->setFormatCode('#,##0.00');
}
function report_workbook(array $rows,string $kind,array $filter,array $signers=[]): Spreadsheet {
    $templates=['002'=>['ex.ทบ.500-002.xlsx',6,12],'007'=>['ex.ทบ.500-007.xlsx',8,11],'154'=>['ex.ทบ.500-154_1.xlsx',6,12],'153'=>['ex.ทบ.500-153-1.xlsx',8,22],'153-1'=>['ex.ทบ.500-153-1.xlsx',8,22],'status'=>['ex.รายงานสถานภาพ มทบ.35.xlsx',5,6]];
    if(!isset($templates[$kind]))throw new DomainException('ชนิดรายงานไม่ถูกต้อง');
    [$name,$header,$width]=$templates[$kind];$path=__DIR__.'/../template/'.$name;
    if(!is_file($path))throw new DomainException('ไม่พบต้นแบบ '.$name);
    $book=IOFactory::load($path);$book->setActiveSheetIndex(0);$sheet=$book->getActiveSheet();
    // Examples contain real-looking sample figures and log tabs: remove all of them.
    while($book->getSheetCount()>1)$book->removeSheetByIndex(1);
    foreach($sheet->getMergeCells() as $range){[$from,$to]=Coordinate::rangeBoundaries($range);if($to[1]>$header)$sheet->unmergeCells($range);}
    $max=$sheet->getHighestRow();if($max>$header)$sheet->removeRow($header+1,$max-$header);
    $sheet->setTitle($kind==='status'?'สถานภาพหน่วย':'ทบ.500-'.$kind);
    $year=(int)($filter['year']??fiscal_year_for_date());$month=(int)($filter['month']??0);report_period($year,$month);
    $scope=[];foreach($rows as $r)$scope[$r['classification']['main_unit']??$r['owner_name']]=true;$scope=implode(', ',array_keys($scope));
    $title=match($kind){'002'=>'รายงานสถานภาพงบประมาณ ทบ.500-002','007'=>'รายงานการเบิกเงินงบประมาณประจำเดือน ทบ.500-007','153'=>'แผน / ผลการปฏิบัติงานและการใช้จ่าย ทบ.500-153','154'=>'แผน / ผลการใช้จ่ายงบประมาณ ทบ.500-154','153-1'=>'แผน / ผลการปฏิบัติงานและการใช้จ่าย ทบ.500-153-1',default=>'สถานภาพงบประมาณของหน่วยหลัก'};
    $sheet->setCellValueExplicit('A1',$title,DataType::TYPE_STRING);$sheet->setCellValueExplicit('A2','หน่วย: '.$scope.' · ปีงบประมาณ '.$year.' · '.($month?'เดือน '.$month:'ทั้งปีงบประมาณ'),DataType::TYPE_STRING);
    if($kind!=='status')$sheet->setCellValueExplicit('A3','35MDBS · คงเหลือแสดงวงเงินใช้ได้หลังหักยอดสงวน · นำส่งหักจากเงินสด · ตรวจรับก่อนลงนาม',DataType::TYPE_STRING);
    if($kind==='007')$sheet->setCellValueExplicit('A4','ยอดเบิกจ่ายดึงจากบัญชีคุม ไม่แทนยอด GF ที่ยังไม่ยืนยัน',DataType::TYPE_STRING);
    if(in_array($kind,['153','153-1'],true))$sheet->setCellValueExplicit('A5',($kind==='153'?'โครงแบบอ้างอิง 153-1 ตามที่หน่วยกำหนด; ตรวจรับก่อนส่งรายงาน; ':'').'แผนที่ยังไม่กรอกแสดงว่า ยังไม่ระบุ; ผลผูกพันเป็นยอดสุทธิเพิ่ม/ปลดในไตรมาส ไม่ใช่แผน',DataType::TYPE_STRING);
    $line=$header+1;
    foreach($rows as $i=>$r) {
        $s=$r['classification'];$description=implode(' / ',array_filter([$s['plan_name']??'ยังไม่จำแนก',$s['work_group']??'',$s['project_name']??'',$r['budget_code'],$r['item_name'],$s['secondary_unit']??$r['owner_name']]));
        if($kind==='002')$values=[$i+1,$description,$r['expense_code']??'',$r['opening']/100,$r['increase']/100,$r['transfer_out']/100,$r['return_army']/100,$r['request']/100,$r['obligation']/100,$r['period_payment']/100,$r['remittance']/100,$r['available']/100];
        elseif($kind==='007')$values=[$i+1,$description,$r['expense_code']??'',$r['opening']/100,$r['increase']/100,$r['transfer_out']/100,$r['return_army']/100,$r['carry']/100,$r['period_payment']/100,$r['remittance']/100,$r['available']/100];
        elseif($kind==='status')$values=[$description,$r['allocated']/100,$r['obligation']/100,$r['payment']/100,$r['available']/100,$r['allocated']>0?$r['payment']/$r['allocated']:0];
        elseif($kind==='154') {
            $values=[$description,$r['allocated']/100];$totalPlan=0;$totalActual=0;$complete=true;
            foreach(range(1,4) as $quarter){$plan=$r['plans'][$quarter]['payment_plan']??null;$actual=$r['quarters'][$quarter]['payment'];$values[]=$plan===null?'ยังไม่ระบุ':report_signed_cents((string)$plan)/100;$values[]=$actual/100;if($plan===null)$complete=false;else $totalPlan+=report_signed_cents((string)$plan);$totalActual+=$actual;}
            $values[]=$complete?$totalPlan/100:'ยังไม่ระบุ';$values[]=$totalActual/100;
        } else {
            $values=[$description,'บาท'];$sumOP=0;$sumPP=0;$sumOA=0;$sumPA=0;$complete=true;
            foreach(range(1,4) as $quarter){$p=$r['plans'][$quarter]??null;$op=isset($p['obligation_plan'])?report_signed_cents((string)$p['obligation_plan']):null;$pp=isset($p['payment_plan'])?report_signed_cents((string)$p['payment_plan']):null;$oa=$r['quarters'][$quarter]['obligation'];$pa=$r['quarters'][$quarter]['payment'];$values[]=$op===null?'ยังไม่ระบุ':$op/100;$values[]=$pp===null?'ยังไม่ระบุ':$pp/100;$values[]=$oa/100;$values[]=$pa/100;$sumOP+=$op??0;$sumPP+=$pp??0;$sumOA+=$oa;$sumPA+=$pa;if($op===null||$pp===null)$complete=false;}
            array_push($values,$complete?$sumOP/100:'ยังไม่ระบุ',$complete?$sumPP/100:'ยังไม่ระบุ',$sumOA/100,$sumPA/100);
        }
        foreach($values as $c=>$v)report_cell($sheet,$c+1,$line,$v);$sheet->getRowDimension($line)->setRowHeight(44);$line++;
    }
    $last=Coordinate::stringFromColumnIndex($width);$sheet->getStyle('A1:'.$last.max($header,$line-1))->getFont()->setName('TH SarabunPSK')->setSize(16);$sheet->getStyle('A'.($header+1).':'.$last.max($header+1,$line-1))->getAlignment()->setWrapText(true);
    $sheet->getStyle('A'.($header+1).':'.$last.max($header+1,$line-1))->getBorders()->getAllBorders()->setBorderStyle('thin');$sheet->getStyle('C'.($header+1).':'.$last.max($header+1,$line-1))->getNumberFormat()->setFormatCode('#,##0.00');
    if($kind==='status')$sheet->getStyle('F'.($header+1).':F'.max($header+1,$line-1))->getNumberFormat()->setFormatCode('0.00%');
    $sheet->getColumnDimension(in_array($kind,['002','007'],true)?'B':'A')->setWidth(70);$sheet->freezePane('C'.($header+1));$sheet->getPageSetup()->setPrintArea('A1:'.$last.max($header,$line-1));$sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);$sheet->getPageSetup()->setOrientation('landscape');
    report_details_sheet($book,$rows);
    if(in_array($kind,['153','153-1'],true)){
        $output=$book->createSheet();$output->setTitle('แผนและผลปฏิบัติงาน');$headers=['รหัสงบประมาณ','แผนงาน','กลุ่มงบงาน / โครงการ','ไตรมาส','หน่วยนับ','แผนผลงาน','ผลปฏิบัติงาน'];foreach($headers as $c=>$v)report_cell($output,$c+1,1,$v);$line=2;foreach($rows as $r)foreach(range(1,4) as $quarter){$p=$r['plans'][$quarter]??[];$s=$r['classification'];$values=[$r['budget_code'],$s['plan_name']??'ยังไม่จำแนก',($s['work_group']??'').' / '.($s['project_name']??''),$quarter,$p['output_unit']??'',isset($p['output_plan'])?(float)$p['output_plan']:'ยังไม่ระบุ',isset($p['output_actual'])?(float)$p['output_actual']:'ยังไม่ระบุ'];foreach($values as $c=>$v)report_cell($output,$c+1,$line,$v);$line++;}$output->getStyle('A1:G'.$line)->getFont()->setName('TH SarabunPSK')->setSize(16);foreach(range('A','G') as $c)$output->getColumnDimension($c)->setWidth(30);
    }
    document_signatures_workbook($book,$signers,$width);
    $book->setActiveSheetIndex(0);return $book;
}
function overview_workbook(array $rows,string $view,int $year,array $signers=[]): Spreadsheet {
    $book=new Spreadsheet();$sheet=$book->getActiveSheet();$sheet->setTitle('ภาพรวม');$groups=report_groups($rows,$view);
    $headers=['หน่วย / ศูนย์ต้นทุน / รหัสงบประมาณ','รับสุทธิสะสม','ตัดจ่ายสะสม','GF ยืนยันแล้ว','คงเหลือเงินสด','วงเงินใช้ได้','% ตัดจ่าย','% GF'];
    if($view==='quarter'){$headers=['หน่วย / รหัสงบประมาณ'];foreach(range(1,4) as $q)array_push($headers,'Q'.$q.' รับงบ','Q'.$q.' ตัดจ่าย','Q'.$q.' ร้อยละ');}
    foreach($headers as $c=>$v)report_cell($sheet,$c+1,1,$v);
    foreach($groups as $i=>$g){$values=[$g['label']];if($view==='quarter')foreach($g['quarters'] as $q)array_push($values,$q['received']/100,$q['payment']/100,$q['received']>0?$q['payment']/$q['received']:0);else array_push($values,$g['allocated']/100,$g['payment']/100,$g['gf_paid']/100,$g['cash']/100,$g['available']/100,$g['allocated']>0?$g['payment']/$g['allocated']:0,$g['allocated']>0?$g['gf_paid']/$g['allocated']:0);foreach($values as $c=>$v)report_cell($sheet,$c+1,$i+2,$v);}
    $last=Coordinate::stringFromColumnIndex(count($headers));$sheet->getStyle('A1:'.$last.max(2,count($groups)+1))->getFont()->setName('TH SarabunPSK')->setSize(16);$sheet->getStyle('A1:'.$last.'1')->getFont()->setBold(true);$sheet->getColumnDimension('A')->setWidth(60);foreach(range(2,count($headers)) as $c){$col=Coordinate::stringFromColumnIndex($c);$sheet->getColumnDimension($col)->setWidth(22);$sheet->getStyle($col.'2:'.$col.max(2,count($groups)+1))->getNumberFormat()->setFormatCode($view==='quarter'?($c%3===1?'0.00%':'#,##0.00'):($c>=7?'0.00%':'#,##0.00'));}$sheet->freezePane('B2');report_details_sheet($book,$rows);
    document_signatures_workbook($book,$signers,count($headers));
    $book->setActiveSheetIndex(0);return $book;
}

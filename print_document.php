<?php
require_once 'config/auth.php';
require_once 'config/db.php';
require_once 'config/document_signatures.php';
require_once 'config/print_account.php';
require_permission('budget.view');

$type = $_GET['type'] ?? '';
$id = (int)($_GET['id'] ?? 0);
$config = [
    'voucher' => ['table'=>'payment_vouchers','number'=>'voucher_no','date'=>'voucher_date','detail'=>'description','title'=>'ข้อมูลฎีกา','back'=>'payment_vouchers.php'],
    'advance' => ['table'=>'budget_advances','number'=>'doc_no','date'=>'doc_date','detail'=>'purpose','title'=>'รายการเงินยืม','back'=>'budget_advances.php'],
    'po' => ['table'=>'po_reservations','number'=>'doc_no','date'=>'doc_date','detail'=>'purpose','title'=>'รายการจัดซื้อจัดจ้าง','back'=>'po_reservations.php'],
];
if (!isset($config[$type]) || $id < 1) { http_response_code(404); exit('ไม่พบเอกสาร'); }
$cfg = $config[$type];
$sql = 'SELECT d.*,u.unit_code,u.unit_name,creator.full_name AS creator_name FROM '.$cfg['table'].' d JOIN units u ON u.id=d.unit_id LEFT JOIN users creator ON creator.id=d.'.($type==='voucher'?'received_by':'created_by').' WHERE d.id=?';
$stmt=$conn->prepare($sql);$stmt->bind_param('i',$id);$stmt->execute();$doc=$stmt->get_result()->fetch_assoc();
if (!$doc || !can_access_unit((int)$doc['unit_id'])) { http_response_code(404); exit('ไม่พบเอกสาร'); }
$accountContext = print_account_context($conn, $doc);
function h($value):string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

if ($type === 'voucher') {
    $stmt=$conn->prepare("SELECT signer_key,full_name,position_name FROM voucher_signatures WHERE voucher_id=? ORDER BY FIELD(signer_key,'prepared','checked','approved')");
    $stmt->bind_param('i',$id);$stmt->execute();$signers=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    if (!$signers) { header('Location: voucher_signatures.php?id='.$id); exit; }
} else {
    $signers=db_document_signers($conn,(int)$doc['unit_id'],$type==='advance'?'BM':'PO');
}
$date = date_create((string)$doc[$cfg['date']]);
$dateText = $date ? $date->format('d/m/').((int)$date->format('Y')+543) : '-';
?>
<!doctype html><html lang="th"><head><meta name="viewport" content="width=device-width, initial-scale=1"><meta charset="utf-8"><title><?=h($cfg['title'])?></title><link rel="stylesheet" href="assets/css/document-signatures.css?v=<?=filemtime(__DIR__.'/assets/css/document-signatures.css')?>"><style>
@font-face{font-family:"TH SarabunPSK";src:url("font/THSARABUN.TTF") format("truetype")}
@font-face{font-family:"TH SarabunPSK";src:url("font/THSARABUN BOLD.TTF") format("truetype");font-weight:700}
@page{size:A5 landscape;margin:8mm}
*{box-sizing:border-box}
body{font-family:"TH SarabunPSK",sans-serif;color:#111;font-size:19px;line-height:1.05;margin:0}
.toolbar{display:flex;flex-wrap:wrap;gap:12px;max-width:194mm;margin:16px auto}
.toolbar a,.toolbar button{font:inherit;border:1px solid #64748b;border-radius:6px;background:white;padding:5px 14px;color:#111;text-decoration:none;cursor:pointer}
.toolbar button{background:#41643e;color:white;border:0}
.print-help{max-width:194mm;margin:0 auto 16px;color:#475569}
.paper{display:flex;flex-direction:column;max-width:194mm;margin:auto;padding:0 3mm}
.title{font-size:28px;font-weight:700;text-align:center;margin:1.5mm 0}
.meta{width:100%;border-collapse:collapse;table-layout:fixed}
.meta td{padding:1px 6px;border-bottom:1px solid #cbd5e1;vertical-align:top;overflow-wrap:anywhere}
.meta .label{width:20%;font-weight:700}
.account-reference{margin-top:2mm}
.account-reference h2{font-size:23px;margin:0 0 1mm}
.account-meta{font-size:18px;line-height:1.05}
.account-meta td{padding:1px 6px}
.account-code{font-variant-numeric:tabular-nums}
.account-warning{border:1px solid #9a6b24;padding:3mm;font-size:18px}
.account-warning p{margin:1mm 0 0}
.amount{border:1px solid #334155;padding:3px 12px;margin:2mm 0 0;font-size:22px;break-inside:avoid}
.footer{margin-top:1mm;color:#475569;font-size:14px}
/* Compact only these three document types; reports keep their existing layout. */
.paper>.document-signatures{margin-top:2mm;padding:0;gap:4mm}
.paper .document-signature{width:85mm;min-height:0;line-height:1.05}
.paper .signature-role{margin-bottom:1.5mm}
.paper .signature-line{margin-bottom:.5mm}
/* Keep every historical signer without stretching the page to a full A4. */
.paper.multiple-signers>.document-signatures{flex-direction:row;align-items:flex-start;justify-content:flex-end;flex-wrap:wrap}
.paper.multiple-signers .document-signature{width:calc((100% - 8mm)/3)}
@media screen and (max-width:600px){
    .paper{padding:0 8px}
    .toolbar,.print-help{margin-left:8px;margin-right:8px}
    .paper.multiple-signers>.document-signatures{flex-direction:column;align-items:flex-end}
    .paper.multiple-signers .document-signature{width:85mm;max-width:100%}
}
@media print{
    .toolbar,.print-help{display:none}
    .paper{max-width:none;padding:0}
    .meta tr{break-inside:avoid}
}
</style></head><body>
<div class="toolbar"><button onclick="window.print()">พิมพ์เอกสาร</button><?php if($type==='voucher'):?><a href="voucher_signatures.php?id=<?=$id?>">เปลี่ยนผู้ลงนาม</a><?php endif;?><a href="<?=h($cfg['back'])?>">กลับรายการ</a></div>
<p class="print-help">ขนาดพิมพ์ A5 แนวนอน (ครึ่ง A4) หากใช้กระดาษ A4 ให้เลือก 2 หน้าต่อแผ่นในหน้าต่างพิมพ์ และปิดหัวกระดาษ/ท้ายกระดาษ รายการที่ยาวจะต่อหน้าโดยไม่ตัดข้อมูล</p>
<article class="paper<?=count($signers)>1?' multiple-signers':''?>"><h1 class="title"><?=h($cfg['title'])?></h1>
<table class="meta"><tr><td class="label">เลขที่เอกสาร</td><td><?=h($doc[$cfg['number']])?></td><td class="label">วันที่</td><td><?=h($dateText)?></td></tr>
<tr><td class="label">ปีงบประมาณ</td><td><?=h($doc['fiscal_year'])?></td><td class="label">หน่วยงาน</td><td><?=h($doc['unit_code'].' - '.$doc['unit_name'])?></td></tr>
<?php if($type==='po'):?><tr><td class="label">เลข PO</td><td><?=h($doc['po_no']?:'-')?></td><td class="label">ผู้ขาย</td><td><?=h($doc['vendor']?:'-')?></td></tr><?php endif;?>
<tr><td class="label">ผู้บันทึก</td><td><?=h($doc['creator_name']?:'-')?></td><td class="label">สถานะ</td><td><?=h($doc['status'])?></td></tr>
<tr><td class="label">รายการ</td><td colspan="3"><?=nl2br(h($doc[$cfg['detail']]?:'-'))?></td></tr></table>
<?=print_account_html($accountContext)?>
<div class="amount"><strong>จำนวนเงิน</strong> <?=number_format((float)$doc['amount'],2)?> บาท</div>
<div class="footer">ข้อมูลตามที่บันทึกในระบบ 35MDBS</div>
<?=document_signatures_html($signers)?></article></body></html>

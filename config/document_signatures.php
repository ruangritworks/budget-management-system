<?php
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

function document_signatures_html(array $signers): string {
    if (!$signers) return '';
    $escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $roles = ['prepared'=>'ผู้จัดทำ', 'checked'=>'ผู้ตรวจสอบ', 'approved'=>'ผู้รับรอง'];
    $html = '<section class="document-signatures" aria-label="ส่วนลงนาม">';
    foreach ($signers as $signer) {
        $html .= '<div class="document-signature"><div class="signature-role">' . $escape($roles[$signer['signer_key'] ?? 'approved'] ?? 'ผู้รับรอง') . '</div>';
        $html .= '<div class="signature-line">ลงชื่อ ........................................................</div>';
        $html .= '<div>(' . $escape($signer['full_name']) . ')</div><div>' . $escape($signer['position_name']) . '</div></div>';
    }
    return $html . '</section>';
}

function document_signatures_sheet(Worksheet $sheet, array $signers, int $tableEndRow, int $lastColumn): int {
    if (!$signers) return $tableEndRow;
    // Anchor by physical column width, not count: description columns can be very wide.
    $widths = [];
    for ($column = 1; $column <= $lastColumn; $column++) {
        $width = $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->getWidth();
        $widths[$column] = $width > 0 ? $width : 8.43;
    }
    $target = max(35, array_sum($widths) * .42);
    $startColumn = $lastColumn;
    $width = $widths[$lastColumn];
    while ($startColumn > 1 && $width < $target) $width += $widths[--$startColumn];
    $from = Coordinate::stringFromColumnIndex($startColumn);
    $to = Coordinate::stringFromColumnIndex($lastColumn);
    $firstRow = $tableEndRow + 3;
    $lastRow = $firstRow + (count($signers) - 1) * 5 + 2;
    $sheet->getStyle('A'.($tableEndRow + 1).':'.$to.$lastRow)->applyFromArray([
        'borders'=>['allBorders'=>['borderStyle'=>Border::BORDER_NONE]],
        'fill'=>['fillType'=>'solid', 'startColor'=>['rgb'=>'FFFFFF']],
        'font'=>['name'=>'TH SarabunPSK', 'size'=>16, 'bold'=>false, 'color'=>['rgb'=>'000000']],
        'alignment'=>['horizontal'=>'center', 'vertical'=>'center', 'wrapText'=>true],
    ]);
    foreach ($signers as $index => $signer) {
        $row = $firstRow + $index * 5;
        foreach (['ลงชื่อ ........................................................', '('.$signer['full_name'].')', $signer['position_name']] as $offset => $value) {
            $line = $row + $offset;
            if ($startColumn !== $lastColumn) $sheet->mergeCells($from.$line.':'.$to.$line);
            $sheet->setCellValueExplicit($from.$line, (string)$value, DataType::TYPE_STRING);
            $lines = max(1, (int)ceil(mb_strlen((string)$value) / max(1, $width * .8)));
            $sheet->getRowDimension($line)->setRowHeight(min(409, max($offset === 2 ? 36 : 24, $lines * 24)));
        }
    }
    $sheet->setShowGridlines(false);
    $sheet->setPrintGridlines(false);
    $sheet->getPageSetup()->setPrintArea('A1:'.$to.$lastRow)->setFitToWidth(1)->setFitToHeight(0);
    return $lastRow;
}

function document_signatures_workbook(PhpOffice\PhpSpreadsheet\Spreadsheet $book, array $signers, int $firstSheetColumns): void {
    foreach ($book->getAllSheets() as $index => $sheet) {
        $columns = $index === 0 ? $firstSheetColumns : Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        document_signatures_sheet($sheet, $signers, $sheet->getHighestRow(), $columns);
    }
}

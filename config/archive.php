<?php
function archive_assert_packet_size(int $limit, string $bytes, string $json, string $filename, string $mime): void
{
    // Allow protocol/parameter overhead; never send an oversized blob and lose the connection.
    if (strlen($bytes) + strlen($json) + strlen($filename) + strlen($mime) + 8192 >= $limit) {
        throw new DomainException('ไฟล์เอกสารใหญ่เกินขนาดที่ฐานข้อมูลรองรับ กรุณาเลือกช่วงรายงานเล็กลง หรือติดต่อผู้ดูแลเพื่อขยายขนาดเก็บเอกสาร ระบบยังไม่ได้บันทึกเอกสารฉบับนี้');
    }
}
function snapshot_store(mysqli $conn, string $type, int $documentId, int $unit, int $year, string $filename, string $mime, string $bytes, array $metadata): int
{
    if ($unit !== 0 && !can_access_unit($unit)) throw new RuntimeException('ไม่มีสิทธิ์จัดเก็บเอกสารของหน่วยนี้', 403);
    if ($unit === 0 && !is_admin()) throw new RuntimeException('รายงานทุกหน่วยต้องใช้สิทธิ์ผู้ดูแล', 403);
    $hash = hash('sha256', $bytes);
    $query = $conn->prepare('SELECT id FROM document_snapshots WHERE document_type=? AND document_id=? AND sha256=?');
    $query->bind_param('sis', $type, $documentId, $hash);
    $query->execute();
    $existing = $query->get_result()->fetch_assoc();
    if ($existing) return (int)$existing['id'];
    $user = current_user_id();
    $json = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $packetLimit = (int)$conn->query('SELECT @@max_allowed_packet')->fetch_row()[0];
    archive_assert_packet_size($packetLimit, $bytes, $json, $filename, $mime);
    $stmt = $conn->prepare('INSERT INTO document_snapshots(document_type,document_id,unit_id,fiscal_year,filename,content_type,sha256,payload,metadata_json,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)');
    $stmt->bind_param('siiisssssi', $type, $documentId, $unit, $year, $filename, $mime, $hash, $bytes, $json, $user);
    try { $stmt->execute(); }
    catch (mysqli_sql_exception $error) {
        if ($error->getCode() !== 1062) throw $error;
        $query->execute();
        return (int)$query->get_result()->fetch_assoc()['id'];
    }
    $id = (int)$conn->insert_id;
    audit_event($conn, 'ISSUE', 'document_snapshots', $id, $unit ?: null, ['document_type'=>$type,'document_id'=>$documentId,'sha256'=>$hash,'filename'=>$filename]);
    return $id;
}

function archive_spreadsheet(mysqli $conn, object $writer, string $type, int $id, int $unit, int $year, string $filename, array $metadata): string
{
    $temp = tempnam(sys_get_temp_dir(), 'rpbs-export-');
    try {
        $writer->save($temp);
        $bytes = file_get_contents($temp);
        snapshot_store($conn, $type, $id, $unit, $year, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $bytes, $metadata);
        return $bytes;
    } finally {
        unlink($temp);
    }
}

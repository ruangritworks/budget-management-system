<?php
require_once __DIR__ . '/environment.php';

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function request_field(): string
{
    return '<input type="hidden" name="_request_key" value="' . bin2hex(random_bytes(24)) . '">';
}

function csrf_require(): void
{
    $provided = $_POST['_csrf'] ?? '';
    if (!is_string($provided) || !hash_equals(csrf_token(), $provided)) {
        http_response_code(403);
        exit('คำขอหมดอายุหรือไม่ถูกต้อง กรุณาเปิดหน้าใหม่แล้วลองอีกครั้ง');
    }
}

function money_cents(string|int|float $value): int
{
    $text = is_float($value) ? number_format($value, 2, '.', '') : trim((string)$value);
    if (!preg_match('/^(0|[1-9]\d{0,13})(?:\.(\d{1,2}))?$/D', $text, $matches)) {
        throw new InvalidArgumentException('จำนวนเงินต้องเป็นตัวเลขไม่ติดลบและมีทศนิยมไม่เกิน 2 ตำแหน่ง');
    }
    return ((int)$matches[1] * 100) + (int)str_pad($matches[2] ?? '', 2, '0');
}

function money_decimal(int $cents): string
{
    $negative = $cents < 0 ? '-' : '';
    $cents = abs($cents);
    return $negative . intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
}

function fiscal_year_for_date(?string $date = null): int
{
    $date = $date ?? date('Y-m-d');
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('วันที่ไม่ถูกต้อง');
    }
    return (int)$parsed->format('Y') + 543 + ((int)$parsed->format('n') >= 10 ? 1 : 0);
}

function validate_fiscal_date(string $date, int $year): void
{
    if ($year < 2500 || $year > 3000 || fiscal_year_for_date($date) !== $year) {
        throw new InvalidArgumentException('วันที่ต้องอยู่ในปีงบประมาณที่เลือก (1 ต.ค. ถึง 30 ก.ย.)');
    }
}

function begin_financial_transaction(mysqli $conn): void
{
    $conn->begin_transaction();
    if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    $key = $_POST['_request_key'] ?? '';
    if (!is_string($key) || !preg_match('/^[a-f0-9]{48}$/D', $key)) {
        $conn->rollback();
        throw new RuntimeException('กรุณาเปิดแบบฟอร์มใหม่ก่อนบันทึก');
    }
    try {
        $stmt = $conn->prepare('INSERT INTO mutation_requests (request_key,user_id,action_path) VALUES (?,?,?)');
        $user = current_user_id();
        $path = substr($_SERVER['SCRIPT_NAME'] ?? 'unknown', 0, 255);
        $stmt->bind_param('sis', $key, $user, $path);
        $stmt->execute();
    } catch (mysqli_sql_exception $error) {
        $conn->rollback();
        if ($error->getCode() === 1062) {
            throw new RuntimeException('รายการนี้ถูกบันทึกแล้ว กรุณาตรวจประวัติก่อนทำรายการใหม่');
        }
        throw $error;
    }
}

function audit_event(mysqli $conn, string $action, string $entity, int $id, ?int $unitId, array $detail = []): void
{
    $statement = $conn->prepare('INSERT INTO audit_events (actor_id,actor_name,actor_role,unit_id,action,entity_type,entity_id,after_data,request_id,client_ip) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $user = current_user();
    $userId = (int)($user['id'] ?? 0) ?: null;
    $name = (string)($user['full_name'] ?? 'system');
    $role = (string)($user['role'] ?? 'system');
    $json = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $request = (string)($GLOBALS['appRequestId'] ?? 'cli');
    $ip = substr($_SERVER['REMOTE_ADDR'] ?? 'local', 0, 45);
    $statement->bind_param('issississs', $userId, $name, $role, $unitId, $action, $entity, $id, $json, $request, $ip);
    $statement->execute();
}

function operation_error(Throwable $error): string
{
    if ($error instanceof mysqli_sql_exception) {
        error_log('RPBS mutation failure: ' . $error->getMessage());
        if ($error->getCode() === 1644) return 'งวดหรือปีงบประมาณนี้ปิดแล้ว หรือรายการนี้ต้องยกเลิกพร้อมเหตุผลแทนการลบ กรุณาตรวจประวัติและติดต่อผู้ดูแล';
        if (in_array($error->getCode(), [1205,1213], true)) return 'มีผู้ใช้อื่นกำลังทำรายการเดียวกัน กรุณาเปิดหน้าใหม่แล้วตรวจประวัติก่อนลองอีกครั้ง';
        if ($error->getCode() === 1062) return 'เลขเอกสารหรือรายการนี้มีอยู่แล้ว กรุณาตรวจสอบก่อนบันทึก';
        return 'บันทึกไม่สำเร็จ กรุณาติดต่อผู้ดูแลพร้อมวันเวลาและเลขที่เอกสาร';
    }
    return $error->getMessage();
}

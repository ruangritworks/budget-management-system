<?php
require_once __DIR__ . '/environment.php';
try {
    $conn = new mysqli($appSettings['host'], $appSettings['username'], $appSettings['password'], $appSettings['database']);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $error) {
    $reference = bin2hex(random_bytes(8));
    error_log('35MDBS database unavailable env=' . APP_ENVIRONMENT . ' ref=' . $reference . ' code=' . $error->getCode());
    app_runtime_error_response($reference, 503);
    exit;
}
require_once __DIR__ . '/security.php';
$appRequestId = bin2hex(random_bytes(16));
if (!empty($_SESSION['user']['id'])) {
    $check = $conn->prepare('SELECT id,username,full_name,role,unit_id,active FROM users WHERE id=?');
    $check->bind_param('i', $_SESSION['user']['id']);
    $check->execute();
    $liveUser = $check->get_result()->fetch_assoc();
    if (!$liveUser || !(int)$liveUser['active'] || (isset($_SESSION['last_activity']) && time() - (int)$_SESSION['last_activity'] > 1800)) {
        $_SESSION = [];
        if (PHP_SAPI !== 'cli') {
            session_destroy();
            header('Location: ' . APP_BASE_PATH . '/login.php');
            exit;
        }
        throw new RuntimeException('Account is inactive or the session expired.');
    }
    $_SESSION['user'] = $liveUser;
    $_SESSION['last_activity'] = time();
}
$auditUser = $_SESSION['user']['id'] ?? null;
$auditName = $_SESSION['user']['full_name'] ?? 'system';
$auditRole = $_SESSION['user']['role'] ?? 'system';
$auditIp = substr($_SERVER['REMOTE_ADDR'] ?? 'local', 0, 45);
$auditContext = $conn->prepare('SET @app_user_id=?,@app_user_name=?,@app_user_role=?,@app_request_id=?,@app_client_ip=?');
$auditContext->bind_param('issss', $auditUser, $auditName, $auditRole, $appRequestId, $auditIp);
$auditContext->execute();

if (!function_exists('db_has_table')) {
    function db_has_table(mysqli $conn, string $table): bool
    {
        static $cache = [];
        $key = 't:' . $table;
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $sql = "
            SELECT COUNT(*) AS c
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $cache[$key] = $count > 0;
        return $cache[$key];
    }
}

if (!function_exists('db_has_column')) {
    function db_has_column(mysqli $conn, string $table, string $column): bool
    {
        static $cache = [];
        $key = 'c:' . $table . ':' . $column;
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $sql = "
            SELECT COUNT(*) AS c
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $cache[$key] = $count > 0;
        return $cache[$key];
    }
}

if (!function_exists('db_next_document_no')) {
    function db_next_document_no(mysqli $conn, string $docType, int $fiscalYear, int $unitId): string
    {
        $docType = strtoupper($docType);
        $prefixes = ['BM' => 'BM', 'PO' => 'PO', 'PV' => 'ฎก', 'TR' => 'งป'];
        if (!isset($prefixes[$docType])) {
            throw new InvalidArgumentException('ชนิดเอกสารไม่ถูกต้อง');
        }

        $sql = "
            INSERT INTO document_counters (doc_type, fiscal_year, unit_id, last_no, updated_at)
            VALUES (?, ?, ?, LAST_INSERT_ID(1), NOW())
            ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1), updated_at = NOW()
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sii', $docType, $fiscalYear, $unitId);
        $stmt->execute();

        $counterResult = $conn->query('SELECT LAST_INSERT_ID() AS running_no');
        $runningNo = (int)($counterResult->fetch_assoc()['running_no'] ?? 0);
        $digits = $docType === 'BM' ? 6 : 5;
        return $prefixes[$docType] . 'U' . str_pad((string)$unitId, 4, '0', STR_PAD_LEFT) . '-' . str_pad((string)$runningNo, $digits, '0', STR_PAD_LEFT) . '/' . substr((string)$fiscalYear, -2);
    }
}

if (!function_exists('db_document_signers')) {
    function db_document_signers(mysqli $conn, int $unitId, string $documentType): array
    {
        if (!db_has_table($conn, 'document_signers')) {
            return [];
        }
        require_once __DIR__ . '/signers.php';
        $shared = shared_budget_signer($conn, false);
        if ($shared) {
            // An explicitly removed shared signer must not revive legacy signers.
            if ((int)$shared['active'] !== 1) return [];
            return [['signer_key'=>'approved','full_name'=>$shared['full_name'],'position_name'=>$shared['position_name']]];
        }
        $stmt = $conn->prepare('
            SELECT signer_key, full_name, position_name
            FROM document_signers
            WHERE unit_id = ? AND document_type = ? AND active = 1
            ORDER BY display_order, id
        ');
        $stmt->bind_param('is', $unitId, $documentType);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        if ($rows) {
            return $rows;
        }

        // Use the unit's configured signers as a fallback so cover sheets are not blank.
        $fallback = $conn->prepare('
            SELECT signer_key, full_name, position_name
            FROM document_signers
            WHERE unit_id = ? AND active = 1
            ORDER BY display_order, id
        ');
        $fallback->bind_param('i', $unitId);
        $fallback->execute();
        return $fallback->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

if (!function_exists('db_sync_control_account')) {
    function db_sync_control_account(mysqli $conn, int $transferId): int
    {
        $stmt = $conn->prepare('SELECT * FROM budget_transfers WHERE id = ?');
        $stmt->bind_param('i', $transferId);
        $stmt->execute();
        $transfer = $stmt->get_result()->fetch_assoc();
        if (!$transfer) {
            throw new RuntimeException('ไม่พบเอกสารโอนงบประมาณ');
        }

        $budNo = trim((string)($transfer['control_account_no'] ?? ''));
        if ($budNo === '') {
            $budNo = 'Bud' . str_pad((string)$transferId, 6, '0', STR_PAD_LEFT);
            $update = $conn->prepare('UPDATE budget_transfers SET control_account_no = ? WHERE id = ?');
            $update->bind_param('si', $budNo, $transferId);
            $update->execute();
        }

        $itemName = trim((string)($transfer['item_name'] ?? $transfer['subject'] ?? ''));
        $armyCode = trim((string)($transfer['budget_code'] ?? ''));
        $gfmisCode = trim((string)($transfer['gfmis_code'] ?? ''));
        $activityCode = trim((string)($transfer['activity_code'] ?? ''));
        $installment = trim((string)($transfer['installment_code'] ?? $transfer['transfer_round'] ?? '1'));
        $amount = (string)$transfer['total_amount'];

        $find = $conn->prepare('SELECT id FROM control_accounts WHERE source_transfer_id = ? LIMIT 1');
        $find->bind_param('i', $transferId);
        $find->execute();
        $existing = $find->get_result()->fetch_assoc();
        if ($existing) {
            $controlId = (int)$existing['id'];
            $update = $conn->prepare('
                UPDATE control_accounts
                SET fiscal_year=?, unit_id=?, source_round=?, budget_code=?, item_name=?, army_code=?,
                    gfmis_code=?, activity_code=?, installment_no=?, received_amount=?, updated_at=NOW()
                WHERE id=?
            ');
            $update->bind_param('iiisssssssi', $transfer['fiscal_year'], $transfer['unit_id'], $transfer['transfer_round'], $budNo, $itemName, $armyCode, $gfmisCode, $activityCode, $installment, $amount, $controlId);
            $update->execute();
            return $controlId;
        }

        $status = 'draft';
        $insert = $conn->prepare('
            INSERT INTO control_accounts
            (fiscal_year,unit_id,source_round,source_transfer_id,budget_code,item_name,army_code,gfmis_code,activity_code,installment_no,received_amount,status,created_by)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)
        ');
        $insert->bind_param('iiiissssssssi', $transfer['fiscal_year'], $transfer['unit_id'], $transfer['transfer_round'], $transferId, $budNo, $itemName, $armyCode, $gfmisCode, $activityCode, $installment, $amount, $status, $transfer['created_by']);
        $insert->execute();
        return (int)$conn->insert_id;
    }
}

if (!function_exists('db_replace_transaction')) {
    function db_replace_transaction(
        mysqli $conn,
        int $unitId,
        int $fiscalYear,
        string $transDate,
        string $transType,
        float $amount,
        string $refTable,
        int $refId,
        string $remark,
        int $createdBy
    ): void {
        $find = $conn->prepare('SELECT id FROM budget_transactions WHERE ref_table=? AND ref_id=? FOR UPDATE');
        $find->bind_param('si', $refTable, $refId);
        $find->execute();
        $existing = $find->get_result()->fetch_assoc();
        if ($existing) {
            $update = $conn->prepare('UPDATE budget_transactions SET unit_id=?,fiscal_year=?,trans_date=?,trans_type=?,amount=?,remark=?,created_by=? WHERE id=?');
            $update->bind_param('iissdsii', $unitId, $fiscalYear, $transDate, $transType, $amount, $remark, $createdBy, $existing['id']);
            $update->execute();
            return;
        }

        $insert = $conn->prepare('
            INSERT INTO budget_transactions
            (unit_id, fiscal_year, trans_date, trans_type, amount, ref_table, ref_id, remark, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $insert->bind_param(
            'iissdsisi',
            $unitId,
            $fiscalYear,
            $transDate,
            $transType,
            $amount,
            $refTable,
            $refId,
            $remark,
            $createdBy
        );
        $insert->execute();
    }
}

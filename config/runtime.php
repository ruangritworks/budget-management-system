<?php
// Production-only error handling; never disclose SQL, credentials or a stack trace.
function app_runtime_error_response(string $reference, int $status = 500): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
        if ($status === 503) header('Retry-After: 60');
    }
    $reference = htmlspecialchars($reference, ENT_QUOTES, 'UTF-8');
    $busy = $reference === 'export-busy' ? '<p>มีงานส่งออก Excel อยู่ครบจำนวนแล้ว กรุณารอให้งานเดิมเสร็จแล้วลองอีกครั้ง การทำรายการการเงินอื่นยังใช้งานได้</p>' : '';
    echo '<!doctype html><html lang="th"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>35MDBS — ระบบไม่พร้อม</title><body><main><h1>ระบบยังไม่พร้อมทำรายการ</h1>' . $busy . '<p>กรุณารอสักครู่หรือติดต่อผู้ดูแลระบบ พร้อมรหัสอ้างอิง ' . $reference . '</p><p>หากเกิดระหว่างบันทึก กรุณาตรวจประวัติรายการก่อนส่งซ้ำ เพื่อป้องกันรายการซ้ำ</p><p>อย่าปิด MySQL หรือบังคับปิดเครื่องขณะที่มีผู้ใช้งาน</p></main></body></html>';
}

function app_runtime_bootstrap(): void
{
    if (PHP_SAPI === 'cli') return;
    ini_set('memory_limit', '256M');
    if (APP_ENVIRONMENT !== 'production') return;
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    $logs = APP_OPS_ROOT . '/logs';
    if (is_dir($logs) && is_writable($logs)) ini_set('error_log', $logs . '/application-production.log');
    $GLOBALS['appRuntimeReserve'] = str_repeat(' ', 65536);
    set_exception_handler(static function (Throwable $error): void {
        $reference = bin2hex(random_bytes(8));
        error_log('35MDBS error=' . $reference . ' class=' . get_class($error) . ' code=' . $error->getCode() . ' file=' . basename($error->getFile()) . ' line=' . $error->getLine());
        $unavailable = $error instanceof mysqli_sql_exception && in_array((int)$error->getCode(), [1040,1205,1213,2002,2006,2013], true);
        app_runtime_error_response($reference, $unavailable ? 503 : 500);
    });
    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if (!$error || !in_array($error['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR], true)) return;
        $GLOBALS['appRuntimeReserve'] = null;
        $reference = 'fatal-' . dechex(time());
        error_log('35MDBS error=' . $reference . ' fatal_type=' . $error['type'] . ' file=' . basename($error['file']) . ' line=' . $error['line']);
        app_runtime_error_response($reference);
    });
}

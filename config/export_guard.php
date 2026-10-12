<?php
function export_slot_acquire(string $root, string $environment)
{
    if (!in_array($environment, ['production','development'], true)) throw new InvalidArgumentException('Invalid export environment.');
    for ($slot = 1; $slot <= 2; $slot++) {
        $handle = @fopen($root . '/export-' . $environment . '-' . $slot . '.lock', 'c');
        if (!$handle) throw new RuntimeException('Export lock directory unavailable.');
        if (flock($handle, LOCK_EX | LOCK_NB)) return $handle;
        fclose($handle);
    }
    return null;
}

// Limit heavy Excel builders, not ordinary forms or financial transactions.
if (PHP_SAPI !== 'cli') {
    try { $GLOBALS['appExportSlot'] = export_slot_acquire(APP_OPS_ROOT, APP_ENVIRONMENT); }
    catch (Throwable $error) { $GLOBALS['appExportSlot'] = null; error_log('35MDBS export lock unavailable'); }
    if (!$GLOBALS['appExportSlot']) {
        app_runtime_error_response('export-busy', 503);
        exit;
    }
    register_shutdown_function(static function (): void {
        if (isset($GLOBALS['appExportSlot']) && is_resource($GLOBALS['appExportSlot'])) {
            flock($GLOBALS['appExportSlot'], LOCK_UN);
            fclose($GLOBALS['appExportSlot']);
        }
    });
    // Permission and account status have already been checked by the caller.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
}

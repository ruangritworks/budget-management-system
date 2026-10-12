<?php
require_once __DIR__ . '/environment.php';
require_once __DIR__ . '/security.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user'])) {
    header('Location: ' . APP_BASE_PATH . '/login.php');
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_require();
}

if (!function_exists('current_user')) {
    function current_user(): array
    {
        return $_SESSION['user'] ?? [];
    }
}

if (!function_exists('current_user_id')) {
    function current_user_id(): int
    {
        return (int)(current_user()['id'] ?? 0);
    }
}

if (!function_exists('current_unit_id')) {
    function current_unit_id(): int
    {
        return (int)(current_user()['unit_id'] ?? 0);
    }
}

if (!function_exists('current_role')) {
    function current_role(): string
    {
        return (string)(current_user()['role'] ?? 'clerk');
    }
}

if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        return current_role() === 'admin';
    }
}

if (!function_exists('require_roles')) {
    function require_roles(array $roles): void
    {
        if (!in_array(current_role(), $roles, true)) {
            http_response_code(403);
            die('คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
        }
    }
}

if (!function_exists('role_label')) {
    function role_label(string $role): string
    {
        return [
            'admin' => 'ผู้ดูแลระบบ',
            'clerk' => 'เจ้าหน้าที่การเงิน',
            'officer' => 'นายทหารตรวจสอบ',
        ][$role] ?? $role;
    }
}

if (!function_exists('has_permission')) {
    function has_permission(string $permission): bool
    {
        $permissions = [
            'admin' => ['*'],
            'clerk' => [
                'budget.view', 'budget.create', 'budget.edit', 'budget.delete',
                'round2.manage', 'control.manage', 'voucher.receive',
                'advance.manage', 'po.manage', 'report.view', 'report.export',
            ],
            'officer' => [
                'budget.view', 'control.view', 'voucher.pay',
                'report.view', 'report.export',
            ],
        ];
        $allowed = $permissions[current_role()] ?? [];
        return in_array('*', $allowed, true) || in_array($permission, $allowed, true);
    }
}

if (!function_exists('require_permission')) {
    function require_permission(string $permission): void
    {
        if (!has_permission($permission)) {
            http_response_code(403);
            die('คุณไม่มีสิทธิ์ดำเนินการนี้');
        }
    }
}

if (!function_exists('can_access_unit')) {
    function can_access_unit(int $unitId): bool
    {
        return is_admin() || $unitId === current_unit_id();
    }
}

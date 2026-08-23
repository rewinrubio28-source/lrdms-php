<?php
/**
 * Audit Trail — cross-cutting and passive. Every other module calls
 * log_action() after anything meaningful happens; nothing else reads
 * this table except audit_trail.php. There is deliberately no
 * delete_log_entry() function anywhere in this codebase.
 */
require_once __DIR__ . '/../config/database.php';

function log_action($module, $action, $detail = '') {
    $pdo = get_db();
    $user = function_exists('current_user') ? current_user() : null;

    $stmt = $pdo->prepare(
        'INSERT INTO audit_log (user_id, username_snapshot, module, action, detail, ip_address)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $user['id'] ?? null,
        $user['username'] ?? 'system',
        $module,
        $action,
        $detail,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

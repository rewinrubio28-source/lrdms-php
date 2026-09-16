<?php
/**
 * Exports the audit log as CSV for compliance reporting
 * (the "Log Export/Reporting Sub-module" from the module breakdown).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/database.php';

require_permission('audit', 'view');
$pdo = get_db();

$moduleFilter = $_GET['module'] ?? 'All';
<<<<<<< HEAD
$sql = 'SELECT a.*, u.full_name AS actor_full_name, r.name AS actor_role
        FROM audit_log a
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN roles r ON r.id = u.role_id';
$params = [];
if ($moduleFilter !== 'All') {
    $sql .= ' WHERE a.module = ?';
    $params[] = $moduleFilter;
}
$sql .= ' ORDER BY a.created_at DESC LIMIT 1000';
=======
$sql = 'SELECT * FROM audit_log';
$params = [];
if ($moduleFilter !== 'All') {
    $sql .= ' WHERE module = ?';
    $params[] = $moduleFilter;
}
$sql .= ' ORDER BY created_at DESC LIMIT 1000';
>>>>>>> origin/main
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="audit_log.csv"');

$out = fopen('php://output', 'w');
<<<<<<< HEAD
fputcsv($out, ['Timestamp', 'Username', 'Full Name', 'Role', 'Module', 'Action', 'Detail', 'IP Address']);
foreach ($logs as $l) {
    fputcsv($out, [
        $l['created_at'],
        $l['username_snapshot'],
        $l['actor_full_name'] ?? '',
        $l['actor_role'] ?? '',
        $l['module'],
        $l['action'],
        $l['detail'],
        $l['ip_address'] ?? '',
    ]);
}
fclose($out);
=======
fputcsv($out, ['Timestamp', 'User', 'Module', 'Action', 'Detail']);
foreach ($logs as $l) {
    fputcsv($out, [$l['created_at'], $l['username_snapshot'], $l['module'], $l['action'], $l['detail']]);
}
fclose($out);
>>>>>>> origin/main

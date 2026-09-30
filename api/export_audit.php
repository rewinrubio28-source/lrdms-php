<?php
/**
 * Exports the audit log as CSV for compliance reporting
 * (the "Log Export/Reporting Sub-module" from the module breakdown).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/database.php';

require_permission('audit', 'view');
require_permission('audit', 'export');
$pdo = get_db();

$moduleFilter = $_GET['module'] ?? 'All';
$eventFilter = $_GET['event'] ?? 'All';
$roleFilter = $_GET['role'] ?? 'All';
$q = trim($_GET['q'] ?? '');

$where = [];
$params = [];
if ($moduleFilter !== 'All') {
    $where[] = 'a.module = ?';
    $params[] = $moduleFilter;
}
if ($eventFilter !== 'All') {
    $where[] = 'a.action = ?';
    $params[] = $eventFilter;
}
if ($roleFilter !== 'All') {
    if ($roleFilter === 'System') {
        $where[] = 'r.name IS NULL';
    } else {
        $where[] = 'r.name = ?';
        $params[] = $roleFilter;
    }
}
if ($q !== '') {
    $where[] = '(a.action LIKE ? OR a.username_snapshot LIKE ? OR u.full_name LIKE ? OR a.detail LIKE ? OR a.ip_address LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
require __DIR__ . '/../includes/audit_filters.php';
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$sql = 'SELECT a.*, u.full_name AS actor_full_name, r.name AS actor_role
        FROM audit_log a
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN roles r ON r.id = u.role_id'
        . $whereSql .
        ' ORDER BY a.created_at DESC LIMIT 1000';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="audit_log.csv"');

$out = fopen('php://output', 'w');
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

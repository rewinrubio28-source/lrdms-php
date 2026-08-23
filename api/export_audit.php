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
$sql = 'SELECT * FROM audit_log';
$params = [];
if ($moduleFilter !== 'All') {
    $sql .= ' WHERE module = ?';
    $params[] = $moduleFilter;
}
$sql .= ' ORDER BY created_at DESC LIMIT 1000';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="audit_log.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Timestamp', 'User', 'Module', 'Action', 'Detail']);
foreach ($logs as $l) {
    fputcsv($out, [$l['created_at'], $l['username_snapshot'], $l['module'], $l['action'], $l['detail']]);
}
fclose($out);

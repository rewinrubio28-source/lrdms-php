<?php
/**
 * Exports the audit log as CSV for compliance reporting
 * (the "Log Export/Reporting Sub-module" from the module breakdown).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csv_export.php';

require_permission('audit', 'view');
require_permission('audit', 'export');
if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') { http_response_code(405); header('Allow: GET'); exit('Use GET to export.'); }
foreach (['module','event','role','q','from','to','actor','record','office','outcome'] as $key) {
    if (isset($_GET[$key]) && (!is_string($_GET[$key]) || strlen($_GET[$key])>1000)) { http_response_code(422); exit('Invalid export filter.'); }
}
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
        ' ORDER BY a.created_at DESC, a.id DESC';
// Build on disk before sending headers: bounded PHP memory, no silent row cap,
// and query/write failures cannot masquerade as a successful partial download.
session_write_close();
$out=tmpfile();
if (!$out) { http_response_code(503); exit('Export is temporarily unavailable. Please retry.'); }
$buffered=$pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
try {
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
    $stmt=$pdo->prepare($sql); $stmt->execute($params);
    $count=write_audit_csv($out,$stmt);
    $stmt->closeCursor(); rewind($out);
    header('Cache-Control: no-store');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="audit_log.csv"');
    header('X-Export-Row-Count: '.$count);
    header('Content-Length: '.fstat($out)['size']);
    fpassthru($out);
} catch (Throwable $e) {
    error_log('Audit export: '.$e->getMessage());
    http_response_code(500); echo 'Export failed. Please retry or contact your administrator.';
} finally {
    if (isset($stmt)) $stmt->closeCursor();
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered);
    fclose($out);
}

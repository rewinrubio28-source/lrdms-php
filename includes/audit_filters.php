<?php
// Shared by the audit workspace and its CSV export.
$auditExtra = [];
foreach (['from', 'to'] as $key) {
    $value = (string)($_GET[$key] ?? '');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $auditExtra[$key] = $date && $date->format('Y-m-d') === $value ? $value : '';
}
foreach (['actor', 'record', 'office', 'outcome'] as $key) $auditExtra[$key] = trim((string)($_GET[$key] ?? ''));
$auditOutcomeSql = "CASE WHEN LOWER(a.action) REGEXP 'fail|denied|blocked|locked|reject' THEN 'Attention' ELSE 'Recorded' END";
if ($auditExtra['from']) { $where[] = 'a.created_at >= ?'; $params[] = $auditExtra['from'] . ' 00:00:00'; }
if ($auditExtra['to']) { $where[] = 'a.created_at < ?'; $params[] = (new DateTimeImmutable($auditExtra['to']))->modify('+1 day')->format('Y-m-d'); }
if ($auditExtra['actor'] !== '') {
    $where[] = '(u.full_name LIKE ? OR a.username_snapshot LIKE ?)';
    array_push($params, '%' . $auditExtra['actor'] . '%', '%' . $auditExtra['actor'] . '%');
}
if ($auditExtra['record'] !== '') { $where[] = 'a.detail LIKE ?'; $params[] = '%' . $auditExtra['record'] . '%'; }
if ($auditExtra['office'] !== '') { $where[] = 'u.office_id = ?'; $params[] = (int)$auditExtra['office']; }
if (in_array($auditExtra['outcome'], ['Recorded', 'Attention'], true)) { $where[] = "($auditOutcomeSql) = ?"; $params[] = $auditExtra['outcome']; }
else $auditExtra['outcome'] = '';

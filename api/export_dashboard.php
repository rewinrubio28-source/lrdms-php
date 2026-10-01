<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard_data.php';
require_once __DIR__ . '/../includes/dashboard_reports.php';
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD']!=='GET') { header('Allow: GET'); http_response_code(405); exit('Use GET.'); }
$user=current_user();
if (!$user || !empty($user['must_change_password'])) { http_response_code(401); exit('Sign in again and complete any required password change.'); }
session_write_close();
try {
    $format=$_GET['format']??'csv';
    if (!is_string($format) || !in_array($format,['pdf','xlsx','csv'],true)) throw new InvalidArgumentException('Choose PDF, Excel or CSV.');
    $filters=dashboard_filters($_GET);
    $data=dashboard_snapshot(get_db(),$user,$filters);
    $bytes=dashboard_report_bytes(dashboard_report_rows($data,$user),$format);
    log_action('dashboard','export_report','format='.$format.'; '.http_build_query($filters));
    $types=['csv'=>'text/csv; charset=utf-8','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','pdf'=>'application/pdf'];
    header('Content-Type: '.$types[$format]); header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="dashboard-'.$filters['from'].'-'.$filters['to'].'.'.$format.'"');
    header('Content-Length: '.strlen($bytes)); echo $bytes;
} catch (InvalidArgumentException $e) { http_response_code(422); header('Content-Type: text/plain; charset=utf-8'); echo $e->getMessage(); }
catch (Throwable $e) { error_log('Dashboard export failed: '.$e->getMessage()); http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); echo 'Report could not be generated. Contact the administrator or try again shortly.'; }

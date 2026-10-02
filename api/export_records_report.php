<?php
require_once __DIR__.'/../includes/record_reports.php';
header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD']??'')!=='GET') { http_response_code(405); header('Allow: GET'); exit('Use GET.'); }
$user=current_user();
if (!$user || !empty($user['must_change_password'])) { http_response_code(401); exit('Sign in and complete any required password change.'); }
$format=$_GET['format']??'pdf';
if (!is_string($format) || !in_array($format,['pdf','csv','xlsx','print'],true)) { http_response_code(422); exit('Invalid report format.'); }
if (!record_report_allowed($user) || !record_report_allowed($user,$format==='print'?'print':'export')) { http_response_code(403); exit('Your role cannot export or print this report.'); }
session_write_close();
try {
    $report=record_report_build(get_db(),$user,$_GET);
    $bytes=$format==='print'?record_report_html($report,true):record_report_bytes($report,$format);
    log_action('reports','export_records_report','format='.$format.'; count='.$report['count']);
    $types=['pdf'=>'application/pdf','csv'=>'text/csv; charset=UTF-8','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','print'=>'text/html; charset=UTF-8'];
    header('Content-Type: '.$types[$format]);
    if ($format!=='print') header('Content-Disposition: attachment; filename="records-report.'.$format.'"');
    header('Content-Length: '.strlen($bytes)); echo $bytes;
} catch (InvalidArgumentException $e) { http_response_code(422); echo record_report_escape($e->getMessage()); }
catch (Throwable $e) { error_log('Records report export: '.$e->getMessage()); http_response_code(500); echo 'The report could not be generated. Please retry or contact your administrator.'; }

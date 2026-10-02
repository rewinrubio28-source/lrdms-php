<?php
require_once __DIR__.'/../includes/record_reports.php';
header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD']??'')!=='GET') { http_response_code(405); header('Allow: GET'); exit('Use GET.'); }
$user=current_user();
if (!$user || !empty($user['must_change_password'])) { http_response_code(401); exit('Sign in and complete any required password change.'); }
$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if (!$id) { http_response_code(422); exit('Invalid report ID.'); }
session_write_close();
try {
    $query=get_db()->prepare("SELECT snapshot_json,pdf_bytes FROM report_runs WHERE id=? AND user_id=? AND status='Ready' AND created_at>=?");
    $query->execute([$id,$user['id'],date('Y-m-d H:i:s',strtotime('-30 days'))]); $run=$query->fetch();
    if (!$run) { http_response_code(404); exit('Report unavailable or expired.'); }
    $snapshot=json_decode($run['snapshot_json'],true,32,JSON_THROW_ON_ERROR);
    if (!record_report_snapshot_allowed(get_db(),$user,$snapshot)) { http_response_code(403); exit('Access to records in this report has changed. Generate a new report with your current permissions.'); }
    log_action('reports','download_scheduled_report','Run '.$id);
    header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="scheduled-report-'.$id.'.pdf"'); header('Content-Length: '.strlen($run['pdf_bytes'])); echo $run['pdf_bytes'];
} catch (Throwable $e) { error_log('Scheduled report download: '.$e->getMessage()); http_response_code(500); echo 'Report download is temporarily unavailable.'; }

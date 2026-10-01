<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard_data.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD']!=='GET') { header('Allow: GET'); http_response_code(405); echo json_encode(['error'=>'Use GET.']); exit; }
$user=current_user();
if (!$user || !empty($user['must_change_password'])) { http_response_code(401); echo json_encode(['error'=>'Sign in again and complete any required password change.']); exit; }
session_write_close();
try {
    $filters=dashboard_filters($_GET);
    $data=dashboard_snapshot(get_db(),$user,$filters);
    extract($data,EXTR_SKIP);
    ob_start(); include __DIR__ . '/../includes/dashboard_panels.php'; $html=ob_get_clean();
    echo json_encode(['html'=>$html,'generated_at'=>$generated_at],JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $e) { http_response_code(422); echo json_encode(['error'=>$e->getMessage()]); }
catch (Throwable $e) { error_log('Dashboard refresh failed: '.$e->getMessage()); http_response_code(500); echo json_encode(['error'=>'Dashboard could not refresh. Try again shortly.']); }

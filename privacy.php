<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/privacy.php';
ensure_csrf_token();
$user=current_user();
if ($user) require_login();
$pdo=get_db();
$available=privacy_available($pdo);
$reviewer=$user && has_permission('access','manage_users') && privileged_mfa_required($user);
$error=''; $success='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!$user || !validate_csrf()) { http_response_code(403); $error='Sign in and refresh this page before submitting.'; }
    elseif (!$available) { $error='Privacy requests are not available yet. Contact your system administrator.'; }
    else {
        try {
            $action=$_POST['action']??'';
            $auditDetail='user_id=' . $user['id'];
            $pdo->beginTransaction();
            if ($action==='acknowledge') {
                privacy_event($pdo,(int)$user['id'],'notice','acknowledged');
                $auditDetail.=' notice_version=' . PRIVACY_NOTICE_VERSION;
            } elseif ($action==='request') {
                privacy_request_create($pdo,(int)$user['id'],(string)($_POST['request_type']??''),(string)($_POST['details']??''));
                $auditDetail.=' request_id=' . $pdo->lastInsertId();
            } elseif ($action==='review' && $reviewer) {
                privacy_request_review($pdo,$user,(int)($_POST['id']??0),(string)($_POST['status']??''),(string)($_POST['response']??''));
                $auditDetail.=' request_id=' . (int)($_POST['id']??0) . ' status=' . (string)($_POST['status']??'');
            } else { throw new InvalidArgumentException('Invalid action.'); }
            log_action('privacy',$action,$auditDetail);
            $pdo->commit();
            header('Location: privacy.php?saved=1'); exit;
        } catch (InvalidArgumentException | RuntimeException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error=$e instanceof PDOException ? 'Could not save the request. Please try again.' : $e->getMessage();
        }
    }
}
$requests=[]; $events=[];
if ($user && $available) {
    $stmt=$pdo->prepare('SELECT * FROM privacy_requests' . ($reviewer?'':' WHERE user_id=?') . ' ORDER BY id DESC LIMIT 100');
    $stmt->execute($reviewer?[]:[$user['id']]); $requests=$stmt->fetchAll();
    $stmt=$pdo->prepare('SELECT purpose,decision,notice_version,created_at FROM privacy_events WHERE user_id=? ORDER BY id DESC LIMIT 20');
    $stmt->execute([$user['id']]); $events=$stmt->fetchAll();
}
$contact=(string)env_optional('PRIVACY_CONTACT','Contact your system administrator.');
$controller=(string)env_optional('PRIVACY_OFFICE','The office operating this LRDMS installation');
require __DIR__ . '/includes/privacy_view.php';

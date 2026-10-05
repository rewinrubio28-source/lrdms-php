<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/session_workflow.php';
require_login();
if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); exit('POST required.'); }
if (!validate_csrf()) { http_response_code(403); exit('Security token expired. Refresh and try again.'); }
$id=(int)($_POST['document_id'] ?? 0);
try {
    if (!is_string($_POST['session_action'] ?? null) || !is_string($_POST['note'] ?? null)) throw new InvalidArgumentException('Invalid session action.');
    session_process(get_db(),current_user(),$id,$_POST['session_action'],(int)($_POST['revision'] ?? -1),trim($_POST['note']),$_FILES['session_file'] ?? null,(int)($_POST['committee_id'] ?? 0));
    $_SESSION['session_success']='Session tracking updated.';
    unset($_SESSION['session_form']);
} catch (Throwable $e) {
    error_log('Session action: '.$e->getMessage());
    $_SESSION['session_error']=$e instanceof PDOException ? 'Could not save the workflow. Refresh and try again.' : $e->getMessage();
    $_SESSION['session_form']=['document_id'=>$id,'action'=>is_string($_POST['session_action'] ?? null) ? $_POST['session_action'] : '',
        'note'=>is_string($_POST['note'] ?? null) ? mb_substr($_POST['note'],0,4000) : '', 'committee_id'=>(int)($_POST['committee_id'] ?? 0)];
}
header('Location: document.php?id='.$id.'&tab=tracking');
exit;

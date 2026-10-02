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
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Privacy and data requests — LRDMS</title><link rel="stylesheet" href="Arsha/assets/vendor/bootstrap/css/bootstrap.min.css"><link rel="stylesheet" href="assets/css/action-links.css?v=1">
</head>
<body><main class="container py-4" style="max-width:960px">
<a class="action-link" href="<?= $user?'profile.php':'public.php' ?>">Back to <?= $user?'profile':'sign in' ?></a>
<h1 class="h3 mt-3">Privacy and data requests</h1>
<p class="text-muted">Notice version <?= htmlspecialchars(PRIVACY_NOTICE_VERSION) ?></p>
<p><?= htmlspecialchars($controller) ?> uses account identity, contact details, role/office information, documents, request history and security activity to provide records management, access control, recovery and accountability.</p>
<p>Authorized staff can access information according to their duties. Publicly released documents may be visible to the public. Email verification uses the configured mail provider; document text and files may be processed by the configured OCR, search and storage services.</p>
<p>Account/session cookies support sign-in and request protection. Records, audit history and backups follow the operating office's approved retention rules. The office must confirm the applicable retention periods and processing basis; this application does not set a blanket deletion deadline.</p>
<p>A profile photo is optional. Upload consent is recorded, and removing the photo withdraws permission for its current display. Historical backups may retain earlier copies until the approved backup retention period ends.</p>
<p>You can request access, correction or deletion below. Requests are reviewed by authorized staff; submitting a request does not automatically erase legislative records or audit history. Staff must explain any retention restriction and record the action taken.</p>
<p><strong>Contact:</strong> <?= htmlspecialchars($contact) ?></p>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><div class="alert alert-success" role="status">Your update has been recorded.</div><?php endif; ?>
<?php if (!$user): ?><p><a class="action-link" href="public.php">Sign in</a> to record your acknowledgement or submit a request. If you cannot sign in, use the contact above.</p>
<?php elseif (!$available): ?><p class="alert alert-warning">Request tracking is not available yet. Please use the contact above.</p>
<?php else: ?>
<form method="post" class="mb-4"><?php csrf_field(); ?><input type="hidden" name="action" value="acknowledge"><button class="btn btn-outline-primary">I have read this notice</button><p class="form-text">Acknowledgement records receipt of the notice. It is not blanket consent to all processing.</p></form>
<h2 class="h5">Submit a data request</h2><form method="post" class="mb-4"><?php csrf_field(); ?><input type="hidden" name="action" value="request"><label for="request-type">Request type</label><select id="request-type" name="request_type" class="form-select"><option>Access</option><option>Correction</option><option>Deletion</option></select><label for="request-details" class="mt-2">Details (do not include passwords or verification codes)</label><textarea id="request-details" name="details" class="form-control" required maxlength="2000" rows="3"></textarea><button class="btn btn-primary mt-2">Submit request</button></form>
<h2 class="h5"><?= $reviewer?'Request review queue':'Your requests' ?> (latest 100)</h2>
<?php foreach ($requests as $request): ?><article class="border rounded p-3 mb-3"><h3 class="h6">#<?= (int)$request['id'] ?> — <?= htmlspecialchars($request['request_type']) ?> — <?= htmlspecialchars($request['status']) ?></h3><p>User #<?= (int)$request['user_id'] ?> · <?= htmlspecialchars($request['created_at']) ?></p><p><?= nl2br(htmlspecialchars($request['details'])) ?></p><?php if ($request['response']): ?><p><strong>Response:</strong> <?= nl2br(htmlspecialchars($request['response'])) ?></p><?php endif; ?>
<?php if ($reviewer && in_array($request['status'],['Open','Under Review'],true)): ?><form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="review"><input type="hidden" name="id" value="<?= (int)$request['id'] ?>"><label for="status-<?= (int)$request['id'] ?>">New status</label><select id="status-<?= (int)$request['id'] ?>" name="status" class="form-select"><option>Under Review</option><option>Completed</option><option>Declined</option></select><label for="response-<?= (int)$request['id'] ?>">Action taken or reason (only mark completed after performing the approved action)</label><textarea id="response-<?= (int)$request['id'] ?>" name="response" class="form-control" required maxlength="2000"></textarea><button class="btn btn-outline-primary mt-2">Save review</button></form><?php endif; ?></article><?php endforeach; ?>
<h2 class="h5">Your notice and consent history (latest 20)</h2><ul><?php foreach ($events as $event): ?><li><?= htmlspecialchars(implode(' · ', $event)) ?></li><?php endforeach; ?></ul>
<?php endif; ?></main></body></html>

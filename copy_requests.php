<?php
require_once __DIR__ . '/includes/retrieval.php';
require_once __DIR__ . '/includes/audit.php';
require_login();
$user = current_user();
$pdo = get_db();
$reviewer = has_permission('repository', 'review_copy_requests');
$errors = [];
$searchReturn = (string)($_GET['return'] ?? '');
if (!preg_match('/\A(?:search|version)\.php(?:\?[^#\r\n]*)?\z/', $searchReturn)) $searchReturn = '';
$copyReturnSuffix = $searchReturn !== '' ? '&return=' . rawurlencode($searchReturn) : '';
$fromDocument = max(0, (int)($_GET['from_document'] ?? $_GET['document_id'] ?? 0));
$copyBackUrl = $fromDocument ? 'document.php?id=' . $fromDocument . $copyReturnSuffix : ($searchReturn ?: 'search.php');
$copyRedirectSuffix = $copyReturnSuffix . ($fromDocument ? '&from_document=' . $fromDocument : '');
$docId = (int)($_GET['document_id'] ?? 0);
$doc = null;
if ($docId) {
    $stmt = $pdo->prepare('SELECT * FROM documents WHERE id=?');
    $stmt->execute([$docId]); $doc = $stmt->fetch();
    if (!$doc || empty($doc['verified_at']) || !can_view_document($user, $doc)) { http_response_code(404); exit('Record unavailable.'); }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) $errors[] = 'Security token expired. Refresh the page.';
    elseif (($_POST['action'] ?? '') === 'request' && $doc) {
        $reason = trim($_POST['reason'] ?? '');
        if ($reason === '' || mb_strlen($reason) > 4000) $errors[] = 'Provide a reason of up to 4,000 characters.';
        else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE')->execute([$user['id']]);
                $check = $pdo->prepare("SELECT id FROM document_copy_requests WHERE document_id=? AND requester_id=? AND status IN ('Pending','Approved')");
                $check->execute([$docId, $user['id']]);
                if ($check->fetchColumn()) { $pdo->rollBack(); $errors[] = 'You already have a pending or approved request for this record.'; }
                else {
                    $pdo->prepare('INSERT INTO document_copy_requests (document_id,requester_id,reason) VALUES (?,?,?)')->execute([$docId,$user['id'],$reason]);
                    log_action('repository','requested_document_copy',$doc['doc_number']);
                    $pdo->commit(); header('Location: copy_requests.php?saved=1' . $copyRedirectSuffix); exit;
                }
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        }
    } elseif (($_POST['action'] ?? '') === 'review' && $reviewer) {
        $decision = $_POST['decision'] ?? '';
        $note = trim($_POST['note'] ?? '');
        if (!in_array($decision,['Approved','Denied'],true) || $note === '' || mb_strlen($note)>4000) $errors[] = 'Choose a decision and enter a review note of up to 4,000 characters.';
        else {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('SELECT * FROM document_copy_requests WHERE id=? FOR UPDATE');
                $stmt->execute([(int)($_POST['request_id'] ?? 0)]); $request = $stmt->fetch();
                $stmt = $pdo->prepare('SELECT * FROM documents WHERE id=?');
                $stmt->execute([$request['document_id'] ?? 0]); $record = $stmt->fetch();
                if (!$request || $request['status'] !== 'Pending' || (int)$request['requester_id'] === (int)$user['id'] || !$record || !can_view_document($user,$record)) { $pdo->rollBack(); $errors[]='This request cannot be reviewed by your account.'; }
                else {
                    $pdo->prepare('UPDATE document_copy_requests SET status=?,reviewed_by=?,review_note=?,reviewed_at=NOW() WHERE id=?')->execute([$decision,$user['id'],$note,$request['id']]);
                    log_action('repository','reviewed_document_copy',$record['doc_number'] . ' — ' . $decision);
                    $pdo->commit(); header('Location: copy_requests.php?saved=1' . $copyRedirectSuffix); exit;
                }
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        }
    } else $errors[] = 'Action unavailable.';
}
[$scope,$params] = document_visibility_clause($user);
$sql = 'SELECT cr.*,d.doc_number,d.title,u.full_name FROM document_copy_requests cr JOIN documents d ON d.id=cr.document_id JOIN users u ON u.id=cr.requester_id WHERE ';
if ($reviewer) $sql .= '(' . $scope . ')';
else { $sql .= 'cr.requester_id=? AND (' . $scope . ')'; array_unshift($params,$user['id']); }
$stmt=$pdo->prepare($sql . ' ORDER BY cr.created_at DESC, cr.id DESC'); $stmt->execute($params); $requests=$stmt->fetchAll();
include __DIR__ . '/includes/layout_top.php';
?>
<div class="topbar" data-banner-date="<?= date('M j, Y') ?>">
  <div>
    <a class="d-inline-flex align-items-center gap-2 small text-decoration-none mb-2" href="<?= htmlspecialchars($copyBackUrl, ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i><?= $fromDocument ? 'Back to Document' : 'Back to Search' ?></a>
    <h1 class="topbar__title">Document Copy Requests</h1>
      <p class="module-banner-description">Track and process requests for document copies.</p>
  </div>
</div>
<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Request updated.</div><?php endif; ?>
<?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endforeach; ?>
<?php if ($doc): ?><div class="card"><h2 class="h6">Request a copy: <?= htmlspecialchars($doc['doc_number']) ?></h2><p><?= htmlspecialchars($doc['title']) ?></p><form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="request"><label for="copy-reason">Purpose of request</label><textarea id="copy-reason" class="form-control mb-3" name="reason" maxlength="4000" required></textarea><button class="btn btn-primary btn-sm">Submit request</button></form></div><?php endif; ?>
<?php if (!$requests): ?><div class="card text-muted">No copy requests yet.</div><?php endif; ?>
<?php foreach ($requests as $request): ?><div class="card mb-3"><h2 class="h6"><?= htmlspecialchars($request['doc_number']) ?> · <?= htmlspecialchars($request['status']) ?></h2><p><?= htmlspecialchars($request['full_name']) ?> · <?= htmlspecialchars($request['created_at']) ?></p><p><?= nl2br(htmlspecialchars($request['reason'])) ?></p><?php if ($request['review_note']): ?><p>Review: <?= htmlspecialchars($request['review_note']) ?></p><?php endif; ?><a href="document.php?id=<?= (int)$request['document_id'] ?><?= htmlspecialchars($copyReturnSuffix, ENT_QUOTES, 'UTF-8') ?>">Open document</a>
<?php if ($reviewer && $request['status']==='Pending' && (int)$request['requester_id'] !== (int)$user['id']): ?><form method="post" class="mt-3"><?php csrf_field(); ?><input type="hidden" name="action" value="review"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><label class="d-block">Review note<textarea class="form-control mb-2" name="note" required maxlength="4000"></textarea></label><button name="decision" value="Approved" class="btn btn-primary btn-sm">Approve copy</button> <button name="decision" value="Denied" class="btn btn-outline-danger btn-sm">Deny</button></form><?php endif; ?></div><?php endforeach; ?>
<?php include __DIR__ . '/includes/layout_bottom.php'; ?>

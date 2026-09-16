<?php
/**
 * DOCUMENT ENCODING & SUBMISSION MODULE
 * ──────────────────────────────────────
 * Scope: this module receives legislative documents that already exist (an
 * enacted Ordinance/Resolution, official Minutes, a Committee Report, etc.)
 * pushed in automatically by an upstream system (System 1 / System 2, via
 * api/upload_document.php). It does NOT draft or compose legal content, and
 * it no longer offers manual metadata entry — every document a Records
 * Officer sees here already arrived through the API integration.
 *
 * What happens here:
 *   1. List documents an upstream system has pushed in but that haven't
 *      been reviewed yet (verified_at IS NULL, source_system <> Manual).
 *   2. A Records Officer reviews each one on document_review.php — a page
 *      dedicated to this queue, kept separate from document.php (which
 *      handles Version Control) so an internally-amended new version can
 *      never re-enter this queue.
 *   3. Verify & release it here, which stamps verified_at and (optionally)
 *      flips it to publicly visible.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/config/database.php';

require_permission('encoding', 'create');
$user = current_user();
$pdo = get_db();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $errors[] = 'Security token expired. Please refresh the page and try again.';
    }
    if (validate_csrf() && isset($_POST['action']) && $_POST['action'] === 'verify_incoming') {
        // ── Verify & release a document that arrived from an upstream system ──
        $incomingId = (int)($_POST['doc_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE documents SET is_public = 1, verified_at = NOW() WHERE id = ? AND verified_at IS NULL AND source_system <> 'Manual Encoding'");
        $stmt->execute([$incomingId]);
        if ($stmt->rowCount() > 0) {
            log_action('encoding', 'verified_incoming_document', "Document #$incomingId released after verification.");
            mark_notifications_read_for_document($incomingId, 'incoming_document');
            $_SESSION['flash_success'] = 'Document verified and released.';
        }
        header('Location: encoding.php#awaiting-verification');
        exit;
    }
}

// Documents pushed by upstream systems that a Records Officer hasn't
// verified/released yet — the queue behind the "awaiting verification" alert.
$awaitingVerification = $pdo->query(
    "SELECT id, doc_number, title, doc_type, source_system, enactment_date, created_at
     FROM documents
     WHERE verified_at IS NULL AND source_system <> 'Manual Encoding'
     ORDER BY created_at ASC"
)->fetchAll();

include __DIR__ . '/includes/layout_top.php';
?>
<div class="topbar">
  <div class="d-flex align-items-center gap-2">
    <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="Open menu">
      <i class="bi bi-list"></i>
    </button>
    <div>
      <h1 class="topbar__title">Document Encoding &amp; Submission</h1>
    </div>
  </div>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?></ul>
  </div>
<?php endif; ?>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash_success']) ?></div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<div class="card" id="awaiting-verification">
  <h3>Documents Awaiting Verification <span class="badge bg-secondary"><?= count($awaitingVerification) ?></span></h3>
  <p class="text-muted small">Documents another system has sent in. They're already on file (status: Enacted) but stay hidden from public view until you verify their details here. Click <strong>Review</strong> to open the document, or verify straight from this list.</p>
  <?php if (!$awaitingVerification): ?>
    <p class="text-muted small mb-0">Nothing waiting right now.</p>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr>
            <th>Reference</th>
            <th>Title</th>
            <th>Type</th>
            <th>Source</th>
            <th>Received</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($awaitingVerification as $doc): ?>
          <tr>
            <td class="text-nowrap"><a href="document_review.php?id=<?= (int)$doc['id'] ?>"><?= htmlspecialchars($doc['doc_number']) ?></a></td>
            <td><?= htmlspecialchars($doc['title']) ?></td>
            <td><?= htmlspecialchars($doc['doc_type']) ?></td>
            <td><?= htmlspecialchars($doc['source_system']) ?></td>
            <td class="text-nowrap text-muted small"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($doc['created_at']))) ?></td>
            <td class="text-end">
              <a href="document_review.php?id=<?= (int)$doc['id'] ?>" class="btn btn-outline-secondary btn-sm">Review</a>
              <form method="post" style="display:inline;" onsubmit="return confirm('Release this document to the public repository?');">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="verify_incoming">
                <input type="hidden" name="doc_id" value="<?= (int)$doc['id'] ?>">
                <button type="submit" class="btn btn-success btn-sm">Verify &amp; Release</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
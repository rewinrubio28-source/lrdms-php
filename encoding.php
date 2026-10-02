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
require_once __DIR__ . '/includes/record_processing.php';
require_once __DIR__ . '/config/database.php';

require_permission('encoding', 'create');
$user = current_user();
$pdo = get_db();

$encodingTab = ($_GET['tab'] ?? '') === 'followups' ? 'followups' : 'incoming';
if ($encodingTab === 'followups' || ($_POST['action'] ?? '') === 'record_followup') {
    $encodingTab = 'followups';
    require __DIR__ . '/includes/record_followups.php';
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $errors[] = 'Security token expired. Please refresh the page and try again.';
    }
    if (validate_csrf() && isset($_POST['action']) && in_array($_POST['action'], ['register_private', 'release_public'], true)) {
        try {
            $incomingId = (int)($_POST['doc_id'] ?? 0);
            process_record($pdo, $user, $incomingId, $_POST['action'] === 'release_public' ? 'register_public' : 'register_private');
            mark_notifications_read_for_document($incomingId, 'incoming_document');
            $_SESSION['flash_success'] = 'Record registered.';
            header('Location: document.php?id=' . $incomingId);
            exit;
        } catch (Throwable $e) {
            error_log('Registration: ' . $e->getMessage());
            $errors[] = $e instanceof PDOException ? 'Could not register this record. Refresh and try again.' : $e->getMessage();
        }
    }
}

// Documents pushed by upstream systems that a Records Officer hasn't
// verified/released yet — the queue behind the "awaiting verification" alert.
$awaitingVerification = $pdo->query(
    "SELECT * FROM documents
     WHERE verified_at IS NULL AND source_system <> 'Manual Encoding'
     ORDER BY created_at ASC, id ASC"
)->fetchAll();

$awaitingVerification = array_values(array_filter($awaitingVerification, static function ($record) use ($user) { return can_view_document($user, $record); }));

$intakeTotal = count($awaitingVerification);
include __DIR__ . '/includes/layout_top.php';
?>
<link rel="stylesheet" href="assets/css/encoding-workspace.css?v=2">
<div class="topbar" data-banner-date="<?= date('M j, Y') ?>">
  <div class="d-flex align-items-center gap-2">
    <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="Open menu">
      <i class="bi bi-list"></i>
    </button>
    <div>
      <h1 class="topbar__title">Document Intake</h1>
      <p class="module-banner-description">Receive, verify, and register legislative documents.</p>
    </div>
  </div>
</div>

<div id="encoding-content">
<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?></ul>
  </div>
<?php endif; ?>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash_success']) ?></div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<nav class="nav nav-pills gap-2 mb-4" aria-label="Encoding sections">
  <a class="nav-link" href="import_records.php"><i class="bi bi-upload me-2" aria-hidden="true"></i>Import dataset</a>
  <a class="nav-link <?= $encodingTab === 'incoming' ? 'active' : '' ?>" href="encoding.php" <?= $encodingTab === 'incoming' ? 'aria-current="page"' : '' ?>><i class="bi bi-inbox me-2" aria-hidden="true"></i>Incoming records <span class="intake-count"><?= $intakeTotal ?></span></a>
  <a class="nav-link <?= $encodingTab === 'followups' ? 'active' : '' ?>" href="encoding.php?tab=followups" <?= $encodingTab === 'followups' ? 'aria-current="page"' : '' ?>><i class="bi bi-clock-history me-2" aria-hidden="true"></i>Pending Records &amp; Follow-up</a>
</nav>
<?php if ($encodingTab === 'followups'): ?>
<?php include __DIR__ . '/includes/record_followups_view.php'; ?>
<?php else: ?>
<div class="card" id="awaiting-verification">
  <div class="intake-heading"><div><h2>Incoming records</h2><p>Open a record to check its file, validate details, and register it.</p></div><span><i class="bi bi-sort-up me-1" aria-hidden="true"></i>Oldest submissions first</span></div>
  <p class="intake-results" role="status"><?= $intakeTotal ?> records</p>
  <?php if (!$awaitingVerification): ?>
    <div class="intake-empty"><i class="bi bi-inbox" aria-hidden="true"></i><h3>No incoming records</h3><p>New submissions will appear here when they are received.</p></div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr>
            <th>Document</th>
            <th>Records status</th>
            <th>Source</th>
            <th>Received</th>
            <th><span class="visually-hidden">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($awaitingVerification as $doc): ?>
          <tr>
            <td class="intake-document"><span class="intake-reference"><?= htmlspecialchars($doc['doc_number']) ?></span><a class="action-link" href="document.php?id=<?= (int)$doc['id'] ?>"><?= htmlspecialchars($doc['title']) ?></a><small><?= htmlspecialchars($doc['doc_type']) ?></small></td>
            <td><span class="intake-status <?= $doc['records_status'] === 'Validated' ? 'is-ready' : (in_array($doc['records_status'], ['Returned for Correction', 'Duplicate', 'Unauthorized Submission'], true) ? 'is-attention' : '') ?>"><?= htmlspecialchars($doc['records_status'] ?: 'Not specified') ?></span></td>
            <td><?= htmlspecialchars($doc['source_system']) ?></td>
            <td class="text-nowrap text-muted small"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($doc['created_at']))) ?></td>
            <td class="text-end">
              <div class="d-inline-flex flex-wrap gap-2 justify-content-end">
                <a href="document.php?id=<?= (int)$doc['id'] ?>" class="btn btn-outline-primary btn-sm" aria-label="Review <?= htmlspecialchars($doc['doc_number'], ENT_QUOTES, 'UTF-8') ?>">Review <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i></a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php endif; ?>
</div>
<script src="assets/js/encoding.js?v=1" defer></script>
<?php include __DIR__ . '/includes/layout_bottom.php'; ?>

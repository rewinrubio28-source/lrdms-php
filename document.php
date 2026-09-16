<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/ocr.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/workflow.php';

require_login();
$user = current_user();
$pdo = get_db();
$id = (int)($_GET['id'] ?? 0);

function fetch_document($pdo, $id) {
    $stmt = $pdo->prepare('SELECT d.*, u.full_name AS owner_name, u.email AS owner_email FROM documents d JOIN users u ON u.id = d.owner_id WHERE d.id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

$doc = fetch_document($pdo, $id);
if (!$doc || !can_view_document($user, $doc)) {
    http_response_code(404);
    include __DIR__ . '/includes/layout_top.php';
    echo '<div class="alert alert-warning">Document not found, or you do not have access to view it.</div>';
    include __DIR__ . '/includes/layout_bottom.php';
    exit;
}

<<<<<<< HEAD
// A document that has never been verified doesn't belong on this page yet —
// document.php's status-change and amend panels assume the document is
// already a confirmed part of the repository. Point to the dedicated review
// page instead of showing those panels — deliberately NOT an automatic
// header() redirect: an automatic redirect here previously combined badly
// with some session/edge-case data states to bounce the browser back and
// forth (ERR_TOO_MANY_REDIRECTS). A manual link can never loop.
$needsReview = ($doc['verified_at'] === null && $doc['source_system'] !== 'Manual Encoding');
if ($needsReview) {
    include __DIR__ . '/includes/layout_top.php';
    echo '<div class="mb-2"><a href="repository.php" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left"></i> Back to Repository</a></div>';
    echo '<div class="alert" style="background:#EEF2F7;border-left:3px solid #0B2E59;color:#0B2E59;">'
        . 'This document (' . htmlspecialchars($doc['doc_number']) . ') hasn\'t been verified yet, so its status/version controls aren\'t available here. '
        . '<a href="document_review.php?id=' . (int)$doc['id'] . '" style="font-weight:700;">Go to the review page →</a>'
        . '</div>';
    include __DIR__ . '/includes/layout_bottom.php';
    exit;
}

// Track recently viewed (upsert to keep latest timestamp per user-document pair)
$pdo->prepare(
    'INSERT INTO recently_viewed_documents (user_id, document_id, viewed_at)
     VALUES (?, ?, NOW())
     ON DUPLICATE KEY UPDATE viewed_at = NOW()'
)->execute([$user['id'], $doc['id']]);

=======
>>>>>>> origin/main
$message = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
<<<<<<< HEAD
    $csrfValid = validate_csrf();
    if (!$csrfValid) {
        $errors[] = 'Security token expired. Please refresh the page and try again.';
    } else {
=======
    if (!validate_csrf()) {
        $errors[] = 'Security token expired. Please refresh the page and try again.';
    }
    if (validate_csrf()) {
>>>>>>> origin/main
    $action = $_POST['action'] ?? '';

    if ($action === 'add_note' && trim($_POST['note'] ?? '') !== '') {
        $stmt = $pdo->prepare('INSERT INTO document_change_notes (document_id, note, created_by) VALUES (?,?,?)');
        $stmt->execute([$doc['id'], trim($_POST['note']), $user['id']]);
        log_action('version', 'added_change_note', $doc['doc_number']);
        $message = 'Note added.';
        $doc = fetch_document($pdo, $id);

<<<<<<< HEAD
    } elseif ($action === 'update_visibility') {
        // Deliberately NOT a status-change action anymore. Whether an
        // enacted record is "Amended," "Withdrawn," or "Superseded" is a
        // legislative determination — that has to come from System 1 (or
        // whichever upstream system owns that legal fact), not from a
        // dropdown inside LRDMS. LRDMS is a records archive, not the
        // legislature; it doesn't get to decide a law's status. What LRDMS
        // legitimately DOES own: whether ITS COPY of an already-verified
        // record is shown in the public repository — that's a
        // records-access decision, not a legal one, so it's the only
        // control kept here.
        if (!has_permission('repository', 'edit_metadata')) {
            $errors[] = 'Your role cannot change document visibility.';
        } else {
            $isPublic = isset($_POST['is_public']) ? 1 : 0;
            $stmt = $pdo->prepare('UPDATE documents SET is_public = ? WHERE id = ?');
            $stmt->execute([$isPublic, $doc['id']]);
            log_action('repository', 'updated_visibility', $doc['doc_number'] . ' → ' . ($isPublic ? 'public' : 'private'));
            $message = 'Visibility updated.';
            $doc = fetch_document($pdo, $id);
=======
    } elseif ($action === 'change_status') {
        if (!has_permission('repository', 'edit_metadata')) {
            $errors[] = 'Your role cannot change document status.';
        } else {
            $newStatus = $_POST['new_status'] ?? $doc['status'];
            if (!can_transition_status($doc['status'], $newStatus)) {
                $errors[] = 'Cannot change status from "' . $doc['status'] . '" to "' . $newStatus . '". This transition is not allowed.';
            } else {
                $oldStatus = $doc['status'];
                $isPublic = isset($_POST['is_public']) ? 1 : 0;
                $stmt = $pdo->prepare('UPDATE documents SET status = ?, is_public = ? WHERE id = ?');
                $stmt->execute([$newStatus, $isPublic, $doc['id']]);
                log_action('repository', 'changed_status', $doc['doc_number'] . ' → ' . $newStatus);
                notify_status_change($doc, $oldStatus, $newStatus, $user);
                $message = 'Status updated to ' . $newStatus . '.';
                $doc = fetch_document($pdo, $id);
            }
>>>>>>> origin/main
        }

    } elseif ($action === 'amend') {
        if (!has_permission('version', 'amend')) {
            $errors[] = 'Your role cannot amend documents.';
        } elseif (in_array($doc['status'], ['Superseded', 'Withdrawn'], true)) {
            $errors[] = 'This document is closed and cannot be amended further.';
        } else {
<<<<<<< HEAD
            $newDocNumber = trim($_POST['new_doc_number'] ?? '');
            $newTitle = trim($_POST['new_title'] ?? '') !== '' ? trim($_POST['new_title']) : $doc['title'];
            $newDate = ($_POST['amendment_date'] ?? '') !== '' ? $_POST['amendment_date'] : date('Y-m-d');
            $note = trim($_POST['amend_note'] ?? '');
            // An amendment is its own legislative instrument, not an edited copy of the
            // original — same as an "Ordinance further amending Ordinance No. X" is
            // itself numbered and filed separately from Ordinance No. X. So it needs its
            // own doc_number, never a carried-over copy of the original's. Version
            // Control files this new instrument; it does not author or hand-edit legal
            // content. Carry the prior content forward unchanged; only OCR (below) may
            // update it, and only if a real replacement file was attached.
            $newBody = $doc['body'] ?? '';
=======
            $newTitle = trim($_POST['new_title'] ?? '') !== '' ? trim($_POST['new_title']) : $doc['title'];
            $newDate = ($_POST['amendment_date'] ?? '') !== '' ? $_POST['amendment_date'] : date('Y-m-d');
            $note = trim($_POST['amend_note'] ?? '');
            $newBody = trim($_POST['new_body'] ?? '') !== '' ? trim($_POST['new_body']) : ($doc['body'] ?? '');
>>>>>>> origin/main

            $filePath = $doc['file_path'];
            $ocrText = $doc['ocr_text'];
            if (isset($_FILES['new_file']) && $_FILES['new_file']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/uploads/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $originalName = $_FILES['new_file']['name'];
                $safeName = date('Ymd_His') . '_' . preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $originalName);
                if (move_uploaded_file($_FILES['new_file']['tmp_name'], $uploadDir . $safeName)) {
                    $filePath = 'uploads/' . $safeName;
                    $ocrText = ocr_extract($uploadDir . $safeName, $originalName);
                }
            }

<<<<<<< HEAD
            // Guard: the new doc_number is what makes this a real, distinct legislative
            // instrument (e.g. "Ordinance No. 25-02 amending Ordinance No. 24-11") —
            // it's required, and it must not collide with any existing doc_number,
            // same rule the upstream API ingest uses in api/upload_document.php.
            if ($newDocNumber === '') {
                $errors[] = 'New document number is required — an amendment is filed as its own numbered instrument, not a copy of the original.';
            } else {
                $dupStmt = $pdo->prepare('SELECT id FROM documents WHERE doc_number = ?');
                $dupStmt->execute([$newDocNumber]);
                if ($dupStmt->fetch()) {
                    $errors[] = 'A document with doc number "' . $newDocNumber . '" already exists. Amendments need their own, unused document number.';
                }
            }

            // Guard: do not create an identical amendment record when nothing actually changed.
            $titleChanged = $newTitle !== $doc['title'];
            $oldDateNorm = $doc['enactment_date'] ? date('Y-m-d', strtotime($doc['enactment_date'])) : null;
            $newDateNorm = date('Y-m-d', strtotime($newDate));
            $dateChanged = $oldDateNorm !== $newDateNorm;
            $fileChanged = $filePath !== $doc['file_path'];

            if (!$titleChanged && !$dateChanged && !$fileChanged) {
                $errors[] = 'No changes detected in title, enactment date, or file — nothing to save as a new amending document.';
            }

            if (!$errors) {

=======
>>>>>>> origin/main
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO documents
<<<<<<< HEAD
                       (doc_number, title, doc_type, sponsor, committee_id, owner_id, status, is_public, verified_at,
                        source_system, enactment_date, file_path, ocr_text, body, previous_version_id)
                     VALUES (?,?,?,?,?,?,?,?,NOW(),?,?,?,?,?,?)'
                );
                // verified_at is stamped NOW() here (not carried from the prior version) —
                // this new version was just created internally by a logged-in Records
                // Officer via Version Control, so it's already reviewed by definition and
                // must never show up in encoding.php's "Awaiting Verification" queue, which
                // is only for documents an upstream system pushed in and no one has looked
                // at yet.
                $stmt->execute([
                    $newDocNumber, $newTitle, $doc['doc_type'], $doc['sponsor'], $doc['committee_id'],
=======
                       (doc_number, title, doc_type, sponsor, committee_id, owner_id, status, is_public,
                        source_system, enactment_date, file_path, ocr_text, body, previous_version_id)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $doc['doc_number'], $newTitle, $doc['doc_type'], $doc['sponsor'], $doc['committee_id'],
>>>>>>> origin/main
                    $user['id'], 'Enacted', $doc['is_public'], $doc['source_system'], $newDate,
                    $filePath, $ocrText, $newBody, $doc['id'],
                ]);
                $newId = $pdo->lastInsertId();

                $pdo->prepare('UPDATE documents SET status = ?, next_version_id = ? WHERE id = ?')
                    ->execute(['Amended', $newId, $doc['id']]);

                if ($note !== '') {
                    $pdo->prepare('INSERT INTO document_change_notes (document_id, note, created_by) VALUES (?,?,?)')
                        ->execute([$newId, $note, $user['id']]);
                }
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Amendment failed: ' . $e->getMessage();
                $newId = null;
            }

            if (!empty($newId)) {
<<<<<<< HEAD
                log_action('version', 'amended_document', $doc['doc_number'] . ' → new document ' . $newDocNumber . ' (#' . $newId . ')');
                $_SESSION['flash_amended'] = true;
                header('Location: document.php?id=' . $newId);
                exit;
            }

            }
=======
                log_action('version', 'amended_document', $doc['doc_number'] . ' → new version #' . $newId);
                header('Location: document.php?id=' . $newId . '&amended=1');
                exit;
            }
>>>>>>> origin/main
        }
    }
    }
}

// Walk the full version chain from the oldest ancestor to the newest descendant.
$chain = [];
$head = $doc;
while ($head['previous_version_id']) {
    $head = fetch_document($pdo, $head['previous_version_id']);
}
$walker = $head;
while ($walker) {
    $chain[] = $walker;
    $walker = $walker['next_version_id'] ? fetch_document($pdo, $walker['next_version_id']) : null;
}

$notesStmt = $pdo->prepare('SELECT n.*, u.full_name FROM document_change_notes n JOIN users u ON u.id = n.created_by WHERE document_id = ? ORDER BY n.created_at DESC');
$notesStmt->execute([$doc['id']]);
$notes = $notesStmt->fetchAll();

$docAuditStmt = $pdo->prepare('SELECT * FROM audit_log WHERE detail LIKE ? ORDER BY created_at DESC LIMIT 20');
$docAuditStmt->execute(['%' . $doc['doc_number'] . '%']);
$docAudit = $docAuditStmt->fetchAll();

<<<<<<< HEAD
// Load all attachment files for this document. Fall back to the legacy file_path
// column so older records remain fully compatible.
$attStmt = $pdo->prepare('SELECT file_path FROM document_attachments WHERE document_id = ? ORDER BY sort_order');
$attStmt->execute([$doc['id']]);
$docFiles = array_column($attStmt->fetchAll(), 'file_path');
if (!$docFiles && $doc['file_path']) $docFiles = [$doc['file_path']];

=======
>>>>>>> origin/main
include __DIR__ . '/includes/layout_top.php';

// Show flash success message (set by encoding.php after save)
$flashSuccess = $_SESSION['flash_success'] ?? null;
if ($flashSuccess) {
    unset($_SESSION['flash_success']);
    echo '<div style="position:fixed;top:0;left:0;right:0;z-index:9999;display:flex;justify-content:center;padding:16px;pointer-events:none;">
        <div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:14px 24px;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.15);font-size:14px;font-weight:500;pointer-events:auto;display:flex;align-items:center;gap:10px;animation:slideDown .4s ease;">
            <i class="bi bi-check-circle-fill" style="font-size:18px;"></i>
            ' . $flashSuccess . ' <a href="repository.php" style="color:#047857;font-weight:700;white-space:nowrap;">View in repository →</a>
        </div>
    </div>
    <style>@keyframes slideDown{from{opacity:0;transform:translateY(-20px)}to{opacity:1;transform:translateY(0)}}</style>';
}
?>
<<<<<<< HEAD
<div class="mb-2">
  <a href="repository.php" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left"></i> Back to Repository</a>
</div>
=======
>>>>>>> origin/main
<div class="topbar">
  <div class="d-flex align-items-center gap-2">
    <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="Open menu">
      <i class="bi bi-list"></i>
    </button>
    <div>
      <div class="topbar__eyebrow"><?= htmlspecialchars($doc['doc_type']) ?> · <?= htmlspecialchars($doc['doc_number']) ?></div>
      <h1 class="topbar__title" style="font-size:21px;"><?= htmlspecialchars($doc['title']) ?></h1>
      <span class="stamp stamp--<?= strtolower(str_replace(' ', '-', $doc['status'])) ?>"><?= htmlspecialchars($doc['status']) ?></span>
    </div>
  </div>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<<<<<<< HEAD
<?php if (!empty($_SESSION['flash_amended'])): unset($_SESSION['flash_amended']); ?><div class="alert alert-success">New amending document saved and linked to the original.</div><?php endif; ?>
=======
<?php if (isset($_GET['amended'])): ?><div class="alert alert-success">New version saved and linked to the previous one.</div><?php endif; ?>
>>>>>>> origin/main
<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <h3 style="font-size:16px;">Metadata</h3>
      <dl class="meta-grid">
        <div><dt>Sponsor</dt><dd><?= htmlspecialchars($doc['sponsor'] ?: '—') ?></dd></div>
        <div><dt>Enactment date</dt><dd><?= $doc['enactment_date'] ? htmlspecialchars(date('M j, Y', strtotime($doc['enactment_date']))) : '—' ?></dd></div>
        <div><dt>Owner</dt><dd><?= htmlspecialchars($doc['owner_name']) ?></dd></div>
        <div><dt>Source system</dt><dd><?= htmlspecialchars($doc['source_system']) ?></dd></div>
        <div><dt>Public</dt><dd><?= $doc['is_public'] ? 'Yes' : 'No' ?></dd></div>
<<<<<<< HEAD
        <div><dt>File</dt><dd><?= $docFiles ? '<a href="#" data-files="' . htmlspecialchars(json_encode($docFiles), ENT_QUOTES, 'UTF-8') . '" data-bs-toggle="modal" data-bs-target="#filePreviewModal" class="open-file-modal">Open file' . (count($docFiles) > 1 ? 's (' . count($docFiles) . ')' : '') . '</a>' : '—' ?></dd></div>
=======
        <div><dt>File</dt><dd><?= $doc['file_path'] ? '<a href="' . htmlspecialchars($doc['file_path']) . '" data-file="' . htmlspecialchars($doc['file_path']) . '" data-bs-toggle="modal" data-bs-target="#filePreviewModal" class="open-file-modal">Open file</a>' : '—' ?></dd></div>
>>>>>>> origin/main
      </dl>
      <?php if (!empty($doc['body'])): ?>
      <h3 style="font-size:14px;">Document content</h3>
      <div class="ocr-box"><?= nl2br(htmlspecialchars($doc['body'])) ?></div>
      <?php endif; ?>
<<<<<<< HEAD
      <div class="d-flex justify-content-between align-items-center">
        <h3 style="font-size:14px;" class="mb-0">OCR / extracted text</h3>
        <?php if (!empty($doc['ocr_text'])): ?>
          <a href="document_text.php?id=<?= (int)$doc['id'] ?>" class="btn btn-outline-secondary btn-sm">View Full Text (As Filed)</a>
        <?php endif; ?>
      </div>
=======
      <h3 style="font-size:14px;">OCR / extracted text</h3>
>>>>>>> origin/main
      <div class="ocr-box"><?= nl2br(htmlspecialchars($doc['ocr_text'] ?: 'No extracted text on file.')) ?></div>
    </div>

    <div class="card">
<<<<<<< HEAD
      <h3 style="font-size:16px;">Amendment history</h3>
      <?php if (count($chain) <= 1): ?>
        <p class="text-muted small">No amending instruments on file yet.</p>
=======
      <h3 style="font-size:16px;">Version history</h3>
      <?php if (count($chain) <= 1): ?>
        <p class="text-muted small">Only one version on file — no amendments yet.</p>
>>>>>>> origin/main
      <?php else: ?>
        <div class="chain">
          <?php foreach ($chain as $i => $node): ?>
            <a class="chain__node <?= $node['id'] == $doc['id'] ? 'chain__node--current' : '' ?>" href="document.php?id=<?= $node['id'] ?>">
<<<<<<< HEAD
              <span class="doc-title" style="font-size:13px;"><?= htmlspecialchars($node['doc_number']) ?> — <?= htmlspecialchars($node['title']) ?></span>
=======
              <span class="doc-title" style="font-size:13px;"><?= htmlspecialchars($node['title']) ?></span>
>>>>>>> origin/main
              <div class="doc-number"><?= $node['enactment_date'] ? htmlspecialchars(date('M j, Y', strtotime($node['enactment_date']))) : '—' ?></div>
              <div style="margin-top:6px;"><span class="stamp stamp--<?= strtolower(str_replace(' ', '-', $node['status'])) ?>"><?= htmlspecialchars($node['status']) ?></span></div>
            </a>
            <?php if ($i < count($chain) - 1): ?><span class="chain__arrow">→</span><?php endif; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3 style="font-size:16px;">Change notes</h3>
      <?php foreach ($notes as $n): ?>
        <div class="note-item">
          <strong><?= htmlspecialchars($n['full_name']) ?></strong> — <?= htmlspecialchars($n['note']) ?>
          <div class="doc-number"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($n['created_at']))) ?></div>
        </div>
      <?php endforeach; ?>
      <?php if (!$notes): ?><p class="text-muted small mb-2">No notes yet.</p><?php endif; ?>
      <form method="post" class="mt-2">
        <input type="hidden" name="action" value="add_note">
        <div class="input-group">
          <input type="text" name="note" class="form-control" placeholder="Add a note…" required>
          <button class="btn btn-outline-primary">Add</button>
        </div>
      </form>
    </div>
  </div>

  <div class="col-lg-5">
    <?php if (has_permission('repository', 'edit_metadata')): ?>
    <div class="card">
<<<<<<< HEAD
      <h3 style="font-size:15px;">Public visibility</h3>
      <p class="text-muted small">This document's status (<?= htmlspecialchars($doc['status']) ?>) is a legislative fact — it can only come from the upstream system that owns it, not from LRDMS. What LRDMS does control is whether its own copy is shown in the public repository.</p>
      <form method="post">
        <input type="hidden" name="action" value="update_visibility">
=======
      <h3 style="font-size:15px;">Change status</h3>
      <form method="post">
        <input type="hidden" name="action" value="change_status">
        <select name="new_status" class="form-select form-select-sm mb-2">
          <option value="<?= htmlspecialchars($doc['status']) ?>" disabled><?= htmlspecialchars($doc['status']) ?> (current)</option>
          <?php foreach (valid_next_statuses($doc['status']) as $s): ?>
            <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
          <?php endforeach; ?>
        </select>
>>>>>>> origin/main
        <div class="form-check mb-2">
          <input type="checkbox" name="is_public" value="1" class="form-check-input" id="pub" <?= $doc['is_public'] ? 'checked' : '' ?>>
          <label class="form-check-label small" for="pub">Publicly visible</label>
        </div>
<<<<<<< HEAD
        <button class="btn btn-outline-primary btn-sm w-100">Update visibility</button>
=======
        <button class="btn btn-outline-primary btn-sm w-100">Update status</button>
>>>>>>> origin/main
      </form>
    </div>
    <?php endif; ?>

    <?php if (has_permission('version', 'amend') && !in_array($doc['status'], ['Superseded', 'Withdrawn'], true)): ?>
    <div class="card">
      <h3 style="font-size:15px;">Amend this document</h3>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="amend">
        <div class="mb-2">
<<<<<<< HEAD
          <label class="form-label small">New document number</label>
          <input type="text" name="new_doc_number" class="form-control form-control-sm" placeholder="e.g. 2026-045" required>
          <div class="form-text">The amendment is its own instrument (e.g. "Ordinance No. 2026-045 amending Ordinance No. <?= htmlspecialchars($doc['doc_number']) ?>") — it needs its own number, not <?= htmlspecialchars($doc['doc_number']) ?> again.</div>
        </div>
        <div class="mb-2">
=======
>>>>>>> origin/main
          <label class="form-label small">New title</label>
          <input type="text" name="new_title" class="form-control form-control-sm" value="<?= htmlspecialchars($doc['title']) ?>">
        </div>
        <div class="mb-2">
          <label class="form-label small">Amendment date</label>
          <input type="date" name="amendment_date" class="form-control form-control-sm">
        </div>
        <div class="mb-2">
          <label class="form-label small">Replacement file (optional)</label>
          <input type="file" name="new_file" class="form-control form-control-sm">
        </div>
        <div class="mb-2">
          <label class="form-label small">What changed?</label>
          <textarea name="amend_note" class="form-control form-control-sm" rows="2"></textarea>
        </div>
<<<<<<< HEAD
        <div class="form-text mb-2">Content updates automatically from the replacement file's extracted text — this form doesn't edit the document's content directly.</div>
        <button class="btn btn-primary btn-sm w-100">Save as new amending document</button>
=======
        <div class="mb-2">
          <label class="form-label small">Document content <span class="text-muted">(pre-filled — edit kung nagbago)</span></label>
          <textarea name="new_body" class="form-control form-control-sm" rows="6"><?= htmlspecialchars($doc['body'] ?? '') ?></textarea>
        </div>
        <button class="btn btn-primary btn-sm w-100">Save new version</button>
>>>>>>> origin/main
      </form>
    </div>
    <?php endif; ?>

    <?php if (has_permission('audit', 'view')): ?>
    <div class="card">
      <h3 style="font-size:15px;">Document audit trail</h3>
      <ul class="audit-list">
        <?php foreach ($docAudit as $a): ?>
          <li>
            <time><?= htmlspecialchars(date('M j, g:i A', strtotime($a['created_at']))) ?></time>
            <span class="actor"><?= htmlspecialchars($a['username_snapshot'] ?? 'system') ?></span>
            <span><?= htmlspecialchars($a['action']) ?></span>
          </li>
        <?php endforeach; ?>
        <?php if (!$docAudit): ?><li class="text-muted small">No logged actions reference this document yet.</li><?php endif; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="modal fade" id="filePreviewModal" tabindex="-1" aria-labelledby="filePreviewModalLabel" aria-hidden="true">
<<<<<<< HEAD
  <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-md-down" style="height:min(95dvh, 95vh);">
    <div class="modal-content" style="height:100%; display:flex; flex-direction:column;">
      <div class="modal-header" style="flex:0 0 auto;">
        <h5 class="modal-title" id="filePreviewModalLabel">Document file</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div id="filePreviewNav" class="justify-content-between align-items-center px-3 py-2 border-bottom" style="display:none; flex:0 0 auto;">
        <button type="button" id="filePreviewPrev" class="btn btn-outline-secondary btn-sm">&larr; Prev</button>
        <span id="filePreviewCounter" class="small text-muted"></span>
        <button type="button" id="filePreviewNext" class="btn btn-outline-secondary btn-sm">Next &rarr;</button>
      </div>
      <div class="modal-body p-0 d-flex justify-content-center align-items-center" style="flex:1 1 auto; min-height:0; overflow:auto;">
        <iframe id="filePreviewIframe" style="width:100%; height:100%; border:none; display:none;" title="Document file preview"></iframe>
        <img id="filePreviewImg" style="max-width:100%; max-height:100%; object-fit:contain; display:none;" alt="Document image preview">
      </div>
      <div class="modal-footer" style="flex:0 0 auto;">
=======
  <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-md-down">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="filePreviewModalLabel">Document file</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-0 d-flex justify-content-center align-items-center" style="min-height:70vh;">
        <iframe id="filePreviewIframe" style="width:100%; height:70vh; border:none; display:none;" title="Document file preview"></iframe>
        <img id="filePreviewImg" style="max-width:100%; max-height:70vh; object-fit:contain; display:none;" alt="Document image preview">
      </div>
      <div class="modal-footer">
>>>>>>> origin/main
        <a id="filePreviewFullLink" href="#" target="_blank" class="btn btn-outline-primary btn-sm">Open in new tab</a>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
<script>
(function () {
  var modalEl = document.getElementById('filePreviewModal');
  if (!modalEl) return;
<<<<<<< HEAD

  var files = [];
  var currentIndex = 0;

=======
>>>>>>> origin/main
  function isImage(fp) {
    var ext = fp.split('.').pop().toLowerCase();
    return ['jpg','jpeg','png','gif','webp','bmp','svg'].indexOf(ext) !== -1;
  }
<<<<<<< HEAD

  function showFile(index) {
    if (!files.length) return;

    currentIndex = Math.max(0, Math.min(index, files.length - 1));
    var filePath = files[currentIndex];
    var iframe = document.getElementById('filePreviewIframe');
    var img = document.getElementById('filePreviewImg');
    var fullLink = document.getElementById('filePreviewFullLink');
    var nav = document.getElementById('filePreviewNav');
    var prev = document.getElementById('filePreviewPrev');
    var next = document.getElementById('filePreviewNext');
    var counter = document.getElementById('filePreviewCounter');
=======
  modalEl.addEventListener('show.bs.modal', function (event) {
    var trigger = event.relatedTarget;
    var filePath = trigger.getAttribute('data-file');
    var iframe = document.getElementById('filePreviewIframe');
    var img = document.getElementById('filePreviewImg');
    var fullLink = document.getElementById('filePreviewFullLink');
>>>>>>> origin/main

    if (isImage(filePath)) {
      img.src = filePath;
      img.style.display = 'block';
      iframe.style.display = 'none';
      iframe.src = 'about:blank';
    } else {
      iframe.src = filePath;
      iframe.style.display = 'block';
      img.style.display = 'none';
      img.src = '';
    }
<<<<<<< HEAD

    fullLink.href = filePath;

    if (files.length > 1) {
      nav.style.display = 'flex';
      counter.textContent = (currentIndex + 1) + ' of ' + files.length;
      prev.disabled = currentIndex === 0;
      next.disabled = currentIndex === files.length - 1;
    } else {
      nav.style.display = 'none';
    }
  }

  modalEl.addEventListener('show.bs.modal', function (event) {
    var trigger = event.relatedTarget;
    var dataFiles = trigger.getAttribute('data-files');

    try {
      files = dataFiles ? JSON.parse(dataFiles) : [];
    } catch (e) {
      files = [];
    }

    if (!files.length) {
      var single = trigger.getAttribute('data-file');
      if (single) files = [single];
    }

    currentIndex = 0;
    showFile(0);
  });

  document.getElementById('filePreviewPrev').addEventListener('click', function () {
    showFile(currentIndex - 1);
  });

  document.getElementById('filePreviewNext').addEventListener('click', function () {
    showFile(currentIndex + 1);
  });

=======
    fullLink.href = filePath;
  });
>>>>>>> origin/main
  modalEl.addEventListener('hidden.bs.modal', function () {
    var iframe = document.getElementById('filePreviewIframe');
    var img = document.getElementById('filePreviewImg');
    iframe.src = 'about:blank';
    img.src = '';
<<<<<<< HEAD
    files = [];
    currentIndex = 0;
  });
})();
</script>
=======
  });
})();
</script>
>>>>>>> origin/main

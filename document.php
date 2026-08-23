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

$message = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $errors[] = 'Security token expired. Please refresh the page and try again.';
    }
    if (validate_csrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_note' && trim($_POST['note'] ?? '') !== '') {
        $stmt = $pdo->prepare('INSERT INTO document_change_notes (document_id, note, created_by) VALUES (?,?,?)');
        $stmt->execute([$doc['id'], trim($_POST['note']), $user['id']]);
        log_action('version', 'added_change_note', $doc['doc_number']);
        $message = 'Note added.';
        $doc = fetch_document($pdo, $id);

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
        }

    } elseif ($action === 'amend') {
        if (!has_permission('version', 'amend')) {
            $errors[] = 'Your role cannot amend documents.';
        } elseif (in_array($doc['status'], ['Superseded', 'Withdrawn'], true)) {
            $errors[] = 'This document is closed and cannot be amended further.';
        } else {
            $newTitle = trim($_POST['new_title'] ?? '') !== '' ? trim($_POST['new_title']) : $doc['title'];
            $newDate = ($_POST['amendment_date'] ?? '') !== '' ? $_POST['amendment_date'] : date('Y-m-d');
            $note = trim($_POST['amend_note'] ?? '');
            $newBody = trim($_POST['new_body'] ?? '') !== '' ? trim($_POST['new_body']) : ($doc['body'] ?? '');

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

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO documents
                       (doc_number, title, doc_type, sponsor, committee_id, owner_id, status, is_public,
                        source_system, enactment_date, file_path, ocr_text, body, previous_version_id)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $doc['doc_number'], $newTitle, $doc['doc_type'], $doc['sponsor'], $doc['committee_id'],
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
                log_action('version', 'amended_document', $doc['doc_number'] . ' → new version #' . $newId);
                header('Location: document.php?id=' . $newId . '&amended=1');
                exit;
            }
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
<?php if (isset($_GET['amended'])): ?><div class="alert alert-success">New version saved and linked to the previous one.</div><?php endif; ?>
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
        <div><dt>File</dt><dd><?= $doc['file_path'] ? '<a href="' . htmlspecialchars($doc['file_path']) . '" data-file="' . htmlspecialchars($doc['file_path']) . '" data-bs-toggle="modal" data-bs-target="#filePreviewModal" class="open-file-modal">Open file</a>' : '—' ?></dd></div>
      </dl>
      <?php if (!empty($doc['body'])): ?>
      <h3 style="font-size:14px;">Document content</h3>
      <div class="ocr-box"><?= nl2br(htmlspecialchars($doc['body'])) ?></div>
      <?php endif; ?>
      <h3 style="font-size:14px;">OCR / extracted text</h3>
      <div class="ocr-box"><?= nl2br(htmlspecialchars($doc['ocr_text'] ?: 'No extracted text on file.')) ?></div>
    </div>

    <div class="card">
      <h3 style="font-size:16px;">Version history</h3>
      <?php if (count($chain) <= 1): ?>
        <p class="text-muted small">Only one version on file — no amendments yet.</p>
      <?php else: ?>
        <div class="chain">
          <?php foreach ($chain as $i => $node): ?>
            <a class="chain__node <?= $node['id'] == $doc['id'] ? 'chain__node--current' : '' ?>" href="document.php?id=<?= $node['id'] ?>">
              <span class="doc-title" style="font-size:13px;"><?= htmlspecialchars($node['title']) ?></span>
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
      <h3 style="font-size:15px;">Change status</h3>
      <form method="post">
        <input type="hidden" name="action" value="change_status">
        <select name="new_status" class="form-select form-select-sm mb-2">
          <option value="<?= htmlspecialchars($doc['status']) ?>" disabled><?= htmlspecialchars($doc['status']) ?> (current)</option>
          <?php foreach (valid_next_statuses($doc['status']) as $s): ?>
            <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-check mb-2">
          <input type="checkbox" name="is_public" value="1" class="form-check-input" id="pub" <?= $doc['is_public'] ? 'checked' : '' ?>>
          <label class="form-check-label small" for="pub">Publicly visible</label>
        </div>
        <button class="btn btn-outline-primary btn-sm w-100">Update status</button>
      </form>
    </div>
    <?php endif; ?>

    <?php if (has_permission('version', 'amend') && !in_array($doc['status'], ['Superseded', 'Withdrawn'], true)): ?>
    <div class="card">
      <h3 style="font-size:15px;">Amend this document</h3>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="amend">
        <div class="mb-2">
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
        <div class="mb-2">
          <label class="form-label small">Document content <span class="text-muted">(pre-filled — edit kung nagbago)</span></label>
          <textarea name="new_body" class="form-control form-control-sm" rows="6"><?= htmlspecialchars($doc['body'] ?? '') ?></textarea>
        </div>
        <button class="btn btn-primary btn-sm w-100">Save new version</button>
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
  function isImage(fp) {
    var ext = fp.split('.').pop().toLowerCase();
    return ['jpg','jpeg','png','gif','webp','bmp','svg'].indexOf(ext) !== -1;
  }
  modalEl.addEventListener('show.bs.modal', function (event) {
    var trigger = event.relatedTarget;
    var filePath = trigger.getAttribute('data-file');
    var iframe = document.getElementById('filePreviewIframe');
    var img = document.getElementById('filePreviewImg');
    var fullLink = document.getElementById('filePreviewFullLink');

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
    fullLink.href = filePath;
  });
  modalEl.addEventListener('hidden.bs.modal', function () {
    var iframe = document.getElementById('filePreviewIframe');
    var img = document.getElementById('filePreviewImg');
    iframe.src = 'about:blank';
    img.src = '';
  });
})();
</script>

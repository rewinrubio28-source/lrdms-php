<?php
/**
 * DOCUMENT REVIEW (Awaiting Verification)
 * ─────────────────────────────────────────
 * Dedicated page for reviewing ONE document an upstream system pushed in
 * that a Records Officer hasn't verified yet. Deliberately separate from
 * document.php: that page also handles Version Control (Save new version),
 * and an internally-created new version must never be mistaken for — or
 * re-enter — the incoming-verification queue. This page only ever shows
 * documents where verified_at IS NULL, and its only action is Verify &
 * Release (or Reject, ending the intake).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/ocr.php';
require_once __DIR__ . '/includes/storage.php';
require_once __DIR__ . '/config/database.php';

require_permission('encoding', 'create');
$user = current_user();
$pdo = get_db();
$id = (int)($_GET['id'] ?? 0);

function fetch_incoming_document($pdo, $id) {
    $stmt = $pdo->prepare(
        "SELECT d.*, u.full_name AS owner_name
         FROM documents d
         JOIN users u ON u.id = d.owner_id
         WHERE d.id = ? AND d.verified_at IS NULL AND d.source_system <> 'Manual Encoding'"
    );
    $stmt->execute([$id]);
    return $stmt->fetch();
}

$doc = fetch_incoming_document($pdo, $id);
if (!$doc) {
    include __DIR__ . '/includes/layout_top.php';
    echo '<div class="alert alert-warning">This document is not waiting for verification (it may already have been reviewed, or does not exist). <a href="encoding.php">Back to the queue →</a></div>';
    include __DIR__ . '/includes/layout_bottom.php';
    exit;
}

// Load all files attached to this document; fall back to legacy file_path.
$attStmt = $pdo->prepare(
    'SELECT file_path FROM document_attachments WHERE document_id = ? ORDER BY sort_order, id'
);
$attStmt->execute([$doc['id']]);
$docFiles = array_column($attStmt->fetchAll(), 'file_path');
if (!$docFiles && !empty($doc['file_path'])) {
    $docFiles = [$doc['file_path']];
}

// Files the OCR service can actually read (it only accepts these types).
$ocrFiles = array_values(array_filter($docFiles, function ($p) {
    return in_array(strtolower(pathinfo($p, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'pdf'], true);
}));

// One-time messages left behind by a redirect (e.g. after "Run OCR").
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $errors[] = 'Security token expired. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'verify_release') {
            $stmt = $pdo->prepare('UPDATE documents SET is_public = 1, verified_at = NOW() WHERE id = ? AND verified_at IS NULL');
            $stmt->execute([$doc['id']]);
            log_action('encoding', 'verified_incoming_document', $doc['doc_number'] . ' — released to public repository.');
            mark_notifications_read_for_document($doc['id'], 'incoming_document');
            $_SESSION['flash_success'] = 'Document "' . $doc['doc_number'] . '" verified and released.';
            header('Location: encoding.php#awaiting-verification');
            exit;

        } elseif ($action === 'verify_keep_private') {
            $stmt = $pdo->prepare('UPDATE documents SET verified_at = NOW() WHERE id = ? AND verified_at IS NULL');
            $stmt->execute([$doc['id']]);
            log_action('encoding', 'verified_incoming_document', $doc['doc_number'] . ' — verified, kept private.');
            mark_notifications_read_for_document($doc['id'], 'incoming_document');
            $_SESSION['flash_success'] = 'Document "' . $doc['doc_number'] . '" verified (kept private for now).';
            header('Location: encoding.php#awaiting-verification');
            exit;

        } elseif ($action === 'reject') {
            $reason = trim($_POST['reject_reason'] ?? '');
            if ($reason === '') {
                $errors[] = 'A reason is required to reject an incoming document.';
            } else {
                // Rejected still leaves the queue (verified_at set) but the
                // document is never released — status flips to Rejected
                // instead of staying Enacted, and the reason is kept as a
                // change note (same table Version Control uses) plus the
                // audit log, so nothing about the intake is silently lost.
                $stmt = $pdo->prepare("UPDATE documents SET status = 'Rejected', is_public = 0, verified_at = NOW() WHERE id = ? AND verified_at IS NULL");
                $stmt->execute([$doc['id']]);
                if ($stmt->rowCount() > 0) {
                    $noteStmt = $pdo->prepare('INSERT INTO document_change_notes (document_id, note, created_by) VALUES (?, ?, ?)');
                    $noteStmt->execute([$doc['id'], 'Rejected on intake: ' . $reason, $user['id']]);
                    log_action('encoding', 'rejected_incoming_document', $doc['doc_number'] . ' — rejected: ' . mb_substr($reason, 0, 200));
                    mark_notifications_read_for_document($doc['id'], 'incoming_document');
                    $_SESSION['flash_success'] = 'Document "' . $doc['doc_number'] . '" rejected.';
                }
                header('Location: encoding.php#awaiting-verification');
                exit;
            }

        } elseif ($action === 'run_ocr') {
            // On-demand OCR. Files pushed by System 1 are stored as-is (no OCR at
            // intake - see api/upload_document.php), so the Records Officer runs it
            // here while reviewing. That keeps the push itself instant and lets a
            // failed run be retried. Each file becomes one "page" for Text As Filed.
            if (!$ocrFiles) {
                $_SESSION['flash_error'] = 'This document has no PNG, JPG, or PDF file that OCR can read.';
            } else {
                ignore_user_abort(true); // finish and save even if the proxy stops waiting
                @set_time_limit(90 * count($ocrFiles));

                $pages = [];
                $problems = [];
                foreach ($ocrFiles as $relPath) {
                    $text = storage_run_ocr($relPath);   // works for local paths and bucket URLs
                    if (ocr_result_is_placeholder($text)) {
                        $problems[] = $text;   // never store an error message as document text
                    } else {
                        $pages[] = $text;
                    }
                }

                if ($pages) {
                    $stmt = $pdo->prepare('UPDATE documents SET ocr_text = ? WHERE id = ? AND verified_at IS NULL');
                    $stmt->execute([implode("\n\n[PAGE BREAK]\n\n", $pages), $doc['id']]);
                    log_action('encoding', 'ran_ocr_incoming_document', $doc['doc_number'] . ' — text read from ' . count($pages) . ' of ' . count($ocrFiles) . ' file(s).');
                    $_SESSION['flash_success'] = 'OCR finished — text read from ' . count($pages) . ' of ' . count($ocrFiles) . ' file(s).';
                }
                if ($problems) {
                    $_SESSION['flash_error'] = ($pages ? 'Some files could not be read: ' : 'OCR could not read this document: ') . implode(' ', $problems);
                }
            }
            header('Location: document_review.php?id=' . (int)$doc['id']);
            exit;
        }
    }
}

include __DIR__ . '/includes/layout_top.php';
?>
<div class="topbar">
  <div class="d-flex align-items-center gap-2">
    <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="Open menu">
      <i class="bi bi-list"></i>
    </button>
    <div>
      <div class="topbar__eyebrow"><?= htmlspecialchars($doc['doc_type']) ?> · <?= htmlspecialchars($doc['doc_number']) ?> · Awaiting Verification</div>
      <h1 class="topbar__title" style="font-size:21px;"><?= htmlspecialchars($doc['title']) ?></h1>
      <span class="stamp stamp--<?= strtolower(str_replace(' ', '-', $doc['status'])) ?>"><?= htmlspecialchars($doc['status']) ?></span>
      <span class="text-muted" style="font-size:12.5px;">
        Pushed by <strong><?= htmlspecialchars($doc['source_system']) ?></strong> · not yet reviewed — verify below, with or without releasing publicly.
      </span>
    </div>
  </div>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?></div><?php endif; ?>
<?php if ($flashSuccess): ?><div class="alert alert-success"><?= htmlspecialchars($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-warning"><?= htmlspecialchars($flashError) ?></div><?php endif; ?>


<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <h3 style="font-size:16px;">Metadata</h3>
      <dl class="meta-grid">
        <div><dt>Sponsor</dt><dd><?= htmlspecialchars($doc['sponsor'] ?: '—') ?></dd></div>
        <div><dt>Enactment date</dt><dd><?= $doc['enactment_date'] ? htmlspecialchars(date('M j, Y', strtotime($doc['enactment_date']))) : '—' ?></dd></div>
        <div><dt>Received by</dt><dd><?= htmlspecialchars($doc['owner_name']) ?></dd></div>
        <div><dt>Source system</dt><dd><?= htmlspecialchars($doc['source_system']) ?></dd></div>
        <div><dt>Received</dt><dd><?= htmlspecialchars(date('M j, Y g:i A', strtotime($doc['created_at']))) ?></dd></div>
        <div><dt>File</dt><dd><?= $docFiles ? '<a href="#" data-files=\'' . htmlspecialchars(json_encode($docFiles), ENT_QUOTES, 'UTF-8') . '\' data-bs-toggle="modal" data-bs-target="#filePreviewModal" class="open-file-modal">Open file' . (count($docFiles) > 1 ? 's (' . count($docFiles) . ')' : '') . '</a>' : '—' ?></dd></div>
      </dl>
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h3 style="font-size:14px;" class="mb-0">OCR / extracted text</h3>
        <div class="d-flex align-items-center gap-2">
          <?php if ($ocrFiles): ?>
            <form method="post" id="ocrForm" class="d-inline" data-has-text="<?= !empty($doc['ocr_text']) ? '1' : '0' ?>">
              <?php csrf_field(); ?>
              <input type="hidden" name="action" value="run_ocr">
              <button type="submit" id="ocrBtn" class="btn btn-outline-primary btn-sm"><?= !empty($doc['ocr_text']) ? 'Re-run OCR' : 'Run OCR' ?></button>
            </form>
          <?php elseif ($docFiles): ?>
            <span class="text-muted small">OCR only reads PNG, JPG, or PDF files.</span>
          <?php endif; ?>
          <?php if (!empty($doc['ocr_text'])): ?>
            <a href="document_text.php?id=<?= (int)$doc['id'] ?>" class="btn btn-outline-secondary btn-sm">View Full Text (As Filed)</a>
          <?php endif; ?>
        </div>
      </div>
      <div class="ocr-box"><?= nl2br(htmlspecialchars($doc['ocr_text'] ?: 'No extracted text on file.')) ?></div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card">
      <h3 style="font-size:15px;">Verify this document</h3>
      <p class="text-muted small">Confirm the metadata above is correct before this enters the repository as an official record.</p>
      <form method="post" onsubmit="return confirm('Verify and release this document to the public repository?');">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="verify_release">
        <button type="submit" class="btn btn-success btn-sm w-100 mb-2">Verify &amp; Release publicly</button>
      </form>
      <form method="post" onsubmit="return confirm('Verify this document but keep it private for now?');">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="verify_keep_private">
        <button type="submit" class="btn btn-outline-secondary btn-sm w-100">Verify, keep private</button>
      </form>
      <div class="form-text mt-2">Once verified, this document leaves the Awaiting Verification queue and future amendments to it are handled from Version Control, not here.</div>
    </div>

    <div class="card">
      <h3 style="font-size:15px;">Reject this intake</h3>
      <p class="text-muted small">Use this if the metadata is wrong, it's a duplicate push, or it shouldn't have arrived here. It leaves the queue as <strong>Rejected</strong> instead of Enacted — nothing is deleted, and the reason is kept on record.</p>
      <form method="post" onsubmit="return confirm('Reject this document? It will not be released and will leave the queue as Rejected.');">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="reject">
        <textarea name="reject_reason" class="form-control form-control-sm mb-2" rows="2" placeholder="Reason for rejecting (required)" required></textarea>
        <button type="submit" class="btn btn-outline-danger btn-sm w-100">Reject</button>
      </form>
    </div>

    <div class="card">
      <a href="encoding.php#awaiting-verification" class="btn btn-outline-secondary btn-sm w-100">← Back to queue</a>
    </div>
  </div>
</div>

<div class="modal fade" id="filePreviewModal" tabindex="-1" aria-labelledby="filePreviewModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-md-down">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="filePreviewModalLabel">Document file</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div id="filePreviewNav" class="justify-content-between align-items-center px-3 py-2 border-bottom" style="display:none;">
        <button type="button" id="filePreviewPrev" class="btn btn-outline-secondary btn-sm">&larr; Prev</button>
        <span id="filePreviewCounter" class="small text-muted"></span>
        <button type="button" id="filePreviewNext" class="btn btn-outline-secondary btn-sm">Next &rarr;</button>
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

  var files = [];
  var index = 0;

  function isImage(fp) {
    var ext = fp.split('?')[0].split('.').pop().toLowerCase();
    return ['jpg','jpeg','png','gif','webp','bmp','svg'].indexOf(ext) !== -1;
  }

  function renderFile() {
    if (!files.length) return;

    var filePath = files[index];
    var iframe = document.getElementById('filePreviewIframe');
    var img = document.getElementById('filePreviewImg');
    var fullLink = document.getElementById('filePreviewFullLink');
    var nav = document.getElementById('filePreviewNav');
    var counter = document.getElementById('filePreviewCounter');
    var prev = document.getElementById('filePreviewPrev');
    var next = document.getElementById('filePreviewNext');

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

    if (files.length > 1) {
      nav.style.display = 'flex';
      counter.textContent = 'File ' + (index + 1) + ' of ' + files.length;
      prev.disabled = index === 0;
      next.disabled = index === files.length - 1;
    } else {
      nav.style.display = 'none';
    }
  }

  modalEl.addEventListener('show.bs.modal', function (event) {
    var trigger = event.relatedTarget;
    if (!trigger) return;

    var raw = trigger.getAttribute('data-files');
    if (raw) {
      try { files = JSON.parse(raw); } catch (e) { files = []; }
    } else {
      var single = trigger.getAttribute('data-file');
      files = single ? [single] : [];
    }

    index = 0;
    renderFile();
  });

  document.getElementById('filePreviewPrev').addEventListener('click', function () {
    if (index > 0) { index--; renderFile(); }
  });

  document.getElementById('filePreviewNext').addEventListener('click', function () {
    if (index < files.length - 1) { index++; renderFile(); }
  });

  modalEl.addEventListener('hidden.bs.modal', function () {
    document.getElementById('filePreviewIframe').src = 'about:blank';
    document.getElementById('filePreviewImg').src = '';
    document.getElementById('filePreviewNav').style.display = 'none';
    files = [];
    index = 0;
  });
})();
</script>
<script>
// "Run OCR": confirm before replacing existing text, and show progress —
// OCR can take several seconds per file, so stop double-clicks.
(function () {
  var form = document.getElementById('ocrForm');
  var btn = document.getElementById('ocrBtn');
  if (!form || !btn) return;
  form.addEventListener('submit', function (e) {
    if (form.getAttribute('data-has-text') === '1' &&
        !confirm('Re-run OCR? This replaces the text currently on file.')) {
      e.preventDefault();
      return;
    }
    btn.disabled = true;
    btn.textContent = 'Reading files… please wait';
  });
})();
</script>

<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/ocr.php';
require_once __DIR__ . '/includes/storage.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/workflow.php';
require_once __DIR__ . '/includes/record_processing.php';

require_login();
$user = current_user();
$pdo = get_db();
$id = (int)($_GET['id'] ?? 0);
$searchReturn = (string)($_GET['return'] ?? '');
if (!preg_match('/\A(?:search|version)\.php(?:\?[^#\r\n]*)?\z/', $searchReturn)) $searchReturn = '';
$documentReturnLabel = strpos($searchReturn, 'version.php') === 0 ? 'Back to Version Control' : 'Back to Search';
$documentReturnSuffix = $searchReturn !== '' ? '&return=' . rawurlencode($searchReturn) : '';

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

// A document that has never been verified doesn't belong on this page yet —
// document.php's status-change and amend panels assume the document is
// already a confirmed part of the repository. Point to the dedicated review
// page instead of showing those panels — deliberately NOT an automatic
// header() redirect: an automatic redirect here previously combined badly
// with some session/edge-case data states to bounce the browser back and
// forth (ERR_TOO_MANY_REDIRECTS). A manual link can never loop.
require __DIR__ . '/includes/document_council_term.php';
$needsReview = ($doc['verified_at'] === null && $doc['source_system'] !== 'Manual Encoding');
if ($needsReview) {
    $incomingMetadataPage = 'document.php';
    require __DIR__ . '/includes/incoming_metadata.php';
    $reviewErrors = [];
    $reviewSuccess = $_SESSION['flash_success'] ?? '';
    $reviewFlashError = $_SESSION['flash_error'] ?? '';
    unset($_SESSION['flash_success'], $_SESSION['flash_error']);
    $canValidateRecord = has_permission('encoding', 'create');
    $reviewFilesStmt = $pdo->prepare('SELECT file_path FROM document_attachments WHERE document_id=? ORDER BY sort_order, id');
    $reviewFilesStmt->execute([$doc['id']]);
    $reviewFiles = array_column($reviewFilesStmt->fetchAll(), 'file_path');
    if (!$reviewFiles && !empty($doc['file_path'])) $reviewFiles = [$doc['file_path']];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canValidateRecord) $reviewErrors[] = 'Your role cannot validate incoming records.';
        elseif (!validate_csrf()) $reviewErrors[] = 'Security token expired. Please refresh the page and try again.';
        else {
            $reviewAction = $_POST['review_action'] ?? '';
            if ($reviewAction === 'attach_correction') {
                if (!has_permission('repository','edit_metadata') || in_array($doc['records_status'], ['Duplicate','Unauthorized Submission'], true)) $reviewErrors[] = 'Your role cannot attach corrections to this submission.';
                elseif (empty($_FILES['correction']) || $_FILES['correction']['error'] !== UPLOAD_ERR_OK) $reviewErrors[] = 'Select a correction file.';
                else {
                    $original = basename($_FILES['correction']['name']);
                    if (!in_array(strtolower(pathinfo($original, PATHINFO_EXTENSION)), ['pdf','png','jpg','jpeg','doc','docx','txt'], true)) $reviewErrors[] = 'Unsupported correction file type.';
                    else {
                        $local = null;
                        $stored = storage_store_upload($_FILES['correction']['tmp_name'], bin2hex(random_bytes(12)) . '_' . preg_replace('/[^A-Za-z0-9._-]/','_', $original), $local);
                        if (!$stored) $reviewErrors[] = 'Could not store the correction file.';
                        else {
                            try {
                                $pdo->beginTransaction();
                                $lock = $pdo->prepare('SELECT verified_at,records_status FROM documents WHERE id=? FOR UPDATE');
                                $lock->execute([$doc['id']]); $state = $lock->fetch();
                                if (!$state || $state['verified_at'] !== null || in_array($state['records_status'], ['Duplicate','Unauthorized Submission'], true)) throw new RuntimeException('The record has already been processed.');
                                $order = $pdo->prepare('SELECT COALESCE(MAX(sort_order),-1)+1 FROM document_attachments WHERE document_id=?'); $order->execute([$doc['id']]);
                                $pdo->prepare('INSERT INTO document_attachments (document_id,file_path,display_name,sort_order) VALUES (?,?,?,?)')->execute([$doc['id'],$stored,$original,(int)$order->fetchColumn()]);
                                $pdo->prepare("UPDATE documents SET file_path=COALESCE(file_path,?),ocr_text=NULL,records_status='Pending Validation' WHERE id=?")->execute([$stored,$doc['id']]);
                                log_action('encoding','correction_attached',$doc['doc_number'].': '.$original);
                                $pdo->commit();
                                $_SESSION['flash_success']='Correction attached. Review the updated files before registering.';
                                header('Location: document.php?id='.(int)$doc['id'].$documentReturnSuffix); exit;
                            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $reviewErrors[]='Could not save correction. Refresh and try again.'; }
                        }
                    }
                }
            } elseif ($reviewAction === 'run_ocr') {
                $ocrPaths = array_values(array_filter($reviewFiles, function ($path) {
                    return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'pdf'], true);
                }));
                if (!$ocrPaths) $reviewErrors[] = 'This record has no PDF or image file that OCR can read.';
                else {
                    $pages = [];
                    $ocrErrors = [];
                    foreach ($ocrPaths as $path) {
                        $text = storage_run_ocr($path);
                        if (ocr_result_is_placeholder($text)) $ocrErrors[] = $text;
                        else $pages[] = $text;
                    }
                    if ($pages) {
                        $pdo->prepare('UPDATE documents SET ocr_text=? WHERE id=? AND verified_at IS NULL')->execute([implode("\n\n[PAGE BREAK]\n\n", $pages), $doc['id']]);
                        log_action('encoding', 'ran_ocr_incoming_document', $doc['doc_number'] . ' — text read from ' . count($pages) . ' file(s).');
                        $_SESSION['flash_success'] = 'OCR finished. Extracted text was saved to this record.';
                    }
                    if ($ocrErrors) $_SESSION['flash_error'] = implode(' ', $ocrErrors);
                    header('Location: document.php?id=' . (int)$doc['id'] . $documentReturnSuffix);
                    exit;
                }
            } elseif (in_array($reviewAction, ['register_public', 'register_private', 'Validated', 'Returned for Correction', 'Duplicate', 'Unauthorized Submission'], true)) {
                try {
                    process_record($pdo, $user, (int)$doc['id'], $reviewAction, trim((string)($_POST['review_note'] ?? '')));
                    if (str_starts_with($reviewAction, 'register_')) mark_notifications_read_for_document($doc['id'], 'incoming_document');
                    $_SESSION['flash_success'] = 'Record review saved.';
                    header('Location: document.php?id=' . (int)$doc['id'] . $documentReturnSuffix);
                    exit;
                } catch (Throwable $e) {
                    error_log('Record review: ' . $e->getMessage());
                    $reviewErrors[] = $e instanceof PDOException ? 'Could not save the review. Refresh and try again.' : $e->getMessage();
                }
            }
        }
    }

    $receiptStmt = $pdo->prepare('SELECT * FROM integration_receipts WHERE lrdms_record_id=? ORDER BY received_at DESC LIMIT 1');
    $receiptStmt->execute([$doc['id']]);
    $reviewReceipt = $receiptStmt->fetch() ?: null;
    $ocrReadableFiles = array_filter($reviewFiles, function ($path) {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'pdf'], true);
    });
    include __DIR__ . '/includes/layout_top.php';
    ?>
    <link rel="stylesheet" href="assets/css/document-workspace.css?v=10">
    <div class="mb-2">
      <a href="<?= htmlspecialchars($searchReturn ?: 'encoding.php#awaiting-verification', ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left"></i> <?= $searchReturn !== '' ? $documentReturnLabel : 'Back to incoming records' ?></a>
    </div>
    <div class="topbar" data-banner-date="<?= date('M j, Y') ?>">
      <div class="d-flex align-items-center gap-2">
        <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="Open menu"><i class="bi bi-list"></i></button>
        <div><div class="topbar__eyebrow">Incoming record · <?= htmlspecialchars($doc['doc_number']) ?></div><h1 class="topbar__title" style="font-size:21px;"><?= htmlspecialchars($doc['title']) ?></h1>
      <p class="module-banner-description">Review document details, attachments, and record activity.</p></div>
      </div>
    </div>
    <div class="verification-workspace registered-workspace intake-review-workspace">
      <div class="intake-file-column">
      <section class="verification-preview card">
        <div class="verification-preview__toolbar"><div class="verification-preview__filename"><i class="bi bi-file-earmark-pdf-fill"></i> <?= htmlspecialchars($reviewFiles ? basename((string)$reviewFiles[0]) : 'No file attached') ?></div><div class="d-flex gap-1"><?php if ($reviewFiles): ?><button type="button" class="btn btn-light btn-sm" id="reviewZoomIn" aria-label="Zoom in"><i class="bi bi-zoom-in"></i></button><button type="button" class="btn btn-light btn-sm" id="reviewZoomReset" aria-label="Reset zoom"><i class="bi bi-arrow-counterclockwise"></i></button><?php endif; ?></div></div>
        <?php if ($reviewFiles): ?><div class="verification-preview__stage"><iframe id="reviewDocumentFrame" src="<?= htmlspecialchars(record_file_url((string)$reviewFiles[0]), ENT_QUOTES, 'UTF-8') ?>" title="Incoming legislative record preview"></iframe></div><?php if (count($reviewFiles) > 1): ?><label class="verification-preview__file-select">Attached files<select id="reviewFileSelect" class="form-select form-select-sm"><?php foreach ($reviewFiles as $file): ?><option value="<?= htmlspecialchars(record_file_url((string)$file), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(basename((string)$file)) ?></option><?php endforeach; ?></select></label><?php endif; ?><?php else: ?><div class="verification-preview__empty"><i class="bi bi-file-earmark-x"></i><span>No document file was attached to this intake.</span></div><?php endif; ?>
      </section>
      <section class="intake-ocr card" aria-labelledby="intake-ocr-title">
        <h2 id="intake-ocr-title"><i class="bi bi-text-paragraph me-2" aria-hidden="true"></i>Extracted document text</h2>
        <p class="text-muted small">OCR results for this record's scanned attachments. Check the original file when verifying the text.</p>
        <div class="verification-processing"><div class="verification-processing__title"><h3>OCR scan</h3><span><?= !empty($doc['ocr_text']) ? 'OCR on file' : ($ocrReadableFiles ? 'Not yet scanned' : 'No scannable file') ?></span></div><div class="d-flex gap-2"><?php if ($canValidateRecord && $ocrReadableFiles): ?><form method="post" class="flex-fill"><?php csrf_field(); ?><input type="hidden" name="review_action" value="run_ocr"><button type="submit" class="btn btn-light btn-sm w-100"><i class="bi bi-magic me-1"></i>Run OCR Sync</button></form><?php endif; ?></div></div>
        <?php if (trim((string)($doc['ocr_text'] ?? '')) !== ''): ?>
          <pre class="intake-ocr__text" tabindex="0" aria-label="Extracted OCR text"><?= htmlspecialchars($doc['ocr_text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
        <?php else: ?>
          <p class="intake-ocr__empty">No extracted text yet.<?= $canValidateRecord && $ocrReadableFiles ? ' Use Run OCR Sync to scan this record.' : '' ?></p>
        <?php endif; ?>
      </section>
      </div>
      <section class="verification-panel card">
        <div class="verification-panel__heading"><h2>Encoding &amp; Submission</h2><p>Review document details and complete the record metadata.</p></div>
        <?php if ($reviewSuccess): ?><div class="alert alert-success py-2 small"><?= htmlspecialchars($reviewSuccess) ?></div><?php endif; ?><?php if ($reviewFlashError): ?><div class="alert alert-warning py-2 small"><?= htmlspecialchars($reviewFlashError) ?></div><?php endif; ?><?php if ($reviewErrors): ?><div class="alert alert-danger py-2 small"><?php foreach ($reviewErrors as $error): ?><div><?= htmlspecialchars($error) ?></div><?php endforeach; ?></div><?php endif; ?>
        <div class="verification-metadata"><h3>Metadata validation</h3><div class="verification-metadata__grid"><div><span>Incoming legislative status</span><strong><?= htmlspecialchars($doc['source_status'] ?: $doc['status']) ?></strong></div><div><span>Source subsystem</span><strong><?= htmlspecialchars($doc['source_system']) ?></strong></div><div><span>Source record ID</span><strong><?= htmlspecialchars($doc['source_record_id'] ?: 'Not provided') ?></strong></div><div><span>Records status</span><strong><?= htmlspecialchars($doc['records_status']) ?></strong></div></div><?php if (!empty($doc['validation_note'])): ?><p class="verification-note"><b>Previous return note:</b> <?= nl2br(htmlspecialchars($doc['validation_note'])) ?></p><?php endif; ?></div>
        <?php include __DIR__ . '/includes/incoming_metadata_form.php'; ?>
        <?php include __DIR__ . '/includes/document_council_term_form.php'; ?>
        <?php if (has_permission('repository','edit_metadata') && !in_array($doc['records_status'], ['Duplicate','Unauthorized Submission'], true)): ?>
        <form method="post" enctype="multipart/form-data" class="verification-metadata">
          <?php csrf_field(); ?><input type="hidden" name="review_action" value="attach_correction">
          <h3>Supporting correction</h3><label for="correction-file" class="form-label small mt-2">Add the received correction or missing attachment</label>
          <input type="file" id="correction-file" name="correction" accept=".pdf,.png,.jpg,.jpeg,.doc,.docx,.txt" class="form-control form-control-sm" required>
          <button class="btn btn-outline-primary btn-sm mt-2">Attach for review</button>
        </form>
        <?php endif; ?>


        <?php if ($canValidateRecord && has_permission('encoding', 'register_record')): ?>
          <?php if (!in_array($doc['records_status'], ['Duplicate', 'Unauthorized Submission'], true)): ?>
          <form method="post" class="verification-metadata">
            <?php csrf_field(); ?>
            <h3>Review outcome</h3>
            <label for="review-outcome" class="form-label small mt-2">Decision</label>
            <select id="review-outcome" name="review_action" class="form-select form-select-sm">
              <option value="Validated">Validated / corrections checked</option>
              <option value="Returned for Correction">Return for correction</option>
              <option value="Duplicate">Close as duplicate</option>
              <option value="Unauthorized Submission">Close as unauthorized submission</option>
            </select>
            <label for="review-note" class="form-label small mt-2">Review note</label>
            <textarea id="review-note" name="review_note" class="form-control form-control-sm" maxlength="4000" required></textarea>
            <button class="btn btn-outline-primary btn-sm mt-2">Save review outcome</button>
          </form>
          <?php else: ?><p class="alert alert-warning mt-3">This submission is closed. It cannot be registered.</p><?php endif; ?>

          <?php $metadataComplete = in_array($doc['records_status'], ['Submitted', 'Pending Validation', 'Validated'], true) && trim((string)$doc['doc_number']) !== '' && trim((string)$doc['title']) !== '' && trim((string)$doc['doc_type']) !== ''; ?>
          <form method="post" class="mt-3">
            <?php csrf_field(); ?>
            <input type="hidden" name="review_action" value="register_private">
            <button type="submit" class="btn btn-primary btn-sm w-100" <?= $metadataComplete ? '' : 'disabled title="Complete the required record metadata above before registering."' ?>><i class="bi bi-check2-circle me-1"></i>Register</button>
            <?php if (!$metadataComplete): ?>
              <p class="text-muted small mt-1 mb-0">Complete the required metadata and resolve the review outcome. Returned records must be validated again; closed submissions cannot be registered.</p>
            <?php endif; ?>
          </form>
        <?php else: ?>
          <div class="mt-3">
            <button type="button" class="btn btn-primary btn-sm w-100" disabled aria-describedby="register-permission-note"><i class="bi bi-check2-circle me-1"></i>Register</button>
            <p id="register-permission-note" class="text-muted small mt-1 mb-0">Your role needs Encoding access and Validate / Register Record permission to register this document. Ask your role administrator to enable these permissions.</p>
          </div>
        <?php endif; ?>
      </section>
    </div>
    <script>(function(){var frame=document.getElementById('reviewDocumentFrame'),zoom=100,select=document.getElementById('reviewFileSelect');if(select&&frame)select.addEventListener('change',function(){frame.src=this.value;});var zi=document.getElementById('reviewZoomIn'),zr=document.getElementById('reviewZoomReset');function apply(){if(frame)frame.style.transform='scale('+(zoom/100)+')';}if(zi)zi.addEventListener('click',function(){zoom=Math.min(160,zoom+10);apply();});if(zr)zr.addEventListener('click',function(){zoom=100;apply();});})();</script>
    <?php
    include __DIR__ . '/includes/layout_bottom.php';
    exit;
}


// Track recently viewed (upsert to keep latest timestamp per user-document pair)
$pdo->prepare(
    'INSERT INTO recently_viewed_documents (user_id, document_id, viewed_at)
     VALUES (?, ?, NOW())
     ON DUPLICATE KEY UPDATE viewed_at = NOW()'
)->execute([$user['id'], $doc['id']]);

$message = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfValid = validate_csrf();
    if (!$csrfValid) {
        $errors[] = 'Security token expired. Please refresh the page and try again.';
    } else {
    $action = $_POST['action'] ?? '';

    if ($action === 'run_record_ocr') {
        if (!has_permission('repository', 'edit_metadata')) {
            $errors[] = 'Your role cannot run OCR on this record.';
        } else {
            $filesStmt = $pdo->prepare('SELECT file_path FROM document_attachments WHERE document_id=? ORDER BY sort_order, id');
            $filesStmt->execute([$id]);
            $paths = array_column($filesStmt->fetchAll(), 'file_path');
            if (!$paths && !empty($doc['file_path'])) $paths = [$doc['file_path']];
            $paths = array_filter($paths, function ($path) {
                return in_array(strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?: $path, PATHINFO_EXTENSION)), ['pdf', 'png', 'jpg', 'jpeg'], true);
            });
            if (!$paths) $errors[] = 'No scannable file is attached to this record.';
            $pages = [];
            foreach ($paths as $path) {
                $text = storage_run_ocr($path);
                if (ocr_result_is_placeholder($text) || trim((string)$text) === '') {
                    $errors[] = 'OCR could not extract text from ' . basename(parse_url($path, PHP_URL_PATH) ?: $path) . '. Please try again.';
                } else $pages[] = $text;
            }
            if ($pages && !$errors) {
                $pdo->prepare('UPDATE documents SET ocr_text=? WHERE id=?')->execute([implode("\n\n[PAGE BREAK]\n\n", $pages), $id]);
                log_action('repository', 'ran_ocr', $doc['doc_number'] . ': extracted text from ' . count($pages) . ' attachment(s).');
                $message = 'OCR finished. Extracted text saved.';
                $doc = fetch_document($pdo, $id);
            }
        }
    } elseif (has_permission('version', 'amend') && $action === 'add_note' && trim($_POST['note'] ?? '') !== '') {
        $stmt = $pdo->prepare('INSERT INTO document_change_notes (document_id, note, created_by) VALUES (?,?,?)');
        $stmt->execute([$doc['id'], trim($_POST['note']), $user['id']]);
        log_action('version', 'added_change_note', $doc['doc_number']);
        $message = 'Note added.';
        $doc = fetch_document($pdo, $id);

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
        if (!has_permission('repository', 'manage_visibility')) {
            $errors[] = 'Your role cannot change document visibility.';
        } else {
            $isPublic = isset($_POST['is_public']) ? 1 : 0;
            $stmt = $pdo->prepare('UPDATE documents SET is_public = ?, classification = ? WHERE id = ?');
            $stmt->execute([$isPublic, $isPublic ? 'PUBLIC' : ($doc['classification'] === 'PUBLIC' ? 'INTERNAL' : $doc['classification']), $doc['id']]);
            $pdo->prepare('INSERT INTO record_validation_history (document_id,actor_id,action,note) VALUES (?,?,?,?)')->execute([$doc['id'],$user['id'],'Visibility Changed',$isPublic ? 'Public copy released.' : 'Copy made private.']);
            log_action('repository', 'updated_visibility', $doc['doc_number'] . ' → ' . ($isPublic ? 'public' : 'private'));
            $message = 'Visibility updated.';
            $doc = fetch_document($pdo, $id);
        }

    } elseif ($action === 'amend') {
        if (!has_permission('version', 'amend') || !has_permission('encoding', 'create')) {
            $errors[] = 'Your role cannot amend documents.';
        } elseif (!empty($doc['next_version_id']) || !$doc['verified_at'] || in_array($doc['status'], ['Superseded', 'Withdrawn'], true)) {
            $errors[] = 'This document is closed and cannot be amended further.';
        } else {
            $newDocNumber = trim($_POST['new_doc_number'] ?? '');
            $newTitle = trim($_POST['new_title'] ?? '') !== '' ? trim($_POST['new_title']) : $doc['title'];
            $newDate = $doc['enactment_date'];
            $note = trim($_POST['amend_note'] ?? '');
            // An amendment is its own legislative instrument, not an edited copy of the
            // original — same as an "Ordinance further amending Ordinance No. X" is
            // itself numbered and filed separately from Ordinance No. X. So it needs its
            // own doc_number, never a carried-over copy of the original's. Version
            // Control files this new instrument; it does not author or hand-edit legal
            // content. Carry the prior content forward unchanged; only OCR (below) may
            // update it, and only if a real replacement file was attached.
            $newBody = $doc['body'] ?? '';

            if ($note === '' || mb_strlen($note) > 4000) $errors[] = 'Enter a source reference or change note of up to 4,000 characters.';
            if (empty($_FILES['new_file']) || $_FILES['new_file']['error'] !== UPLOAD_ERR_OK) $errors[] = 'A received revision file is required.';
            $filePath = $doc['file_path'];
            $ocrText = $doc['ocr_text'];
            if (!$errors && isset($_FILES['new_file']) && $_FILES['new_file']['error'] === UPLOAD_ERR_OK) {
                $originalName = $_FILES['new_file']['name'];
                if (!in_array(strtolower(pathinfo($originalName, PATHINFO_EXTENSION)), ['pdf','png','jpg','jpeg','doc','docx','txt'], true)) $errors[] = 'Unsupported revision file type.';
                $safeName = date('Ymd_His') . '_' . preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $originalName);
                $localReadable = null;
                $storedPath = !$errors ? storage_store_upload($_FILES['new_file']['tmp_name'], $safeName, $localReadable) : null;
                if ($storedPath === null) $errors[] = 'The revision attachment could not be saved.';
                if ($storedPath !== null) {
                    $filePath = $storedPath;
                    $ocrText = ocr_extract($localReadable, $originalName);
                }
            }

            // Guard: the new doc_number is what makes this a real, distinct legislative
            // instrument (e.g. "Ordinance No. 25-02 amending Ordinance No. 24-11") —
            // it's required, and it must not collide with any existing doc_number,
            // same rule the upstream API ingest uses in api/upload_document.php.
            if ($newDocNumber === '' || mb_strlen($newDocNumber) > 60) {
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
            $newDateNorm = $newDate ? date('Y-m-d', strtotime($newDate)) : null;
            $dateChanged = $oldDateNorm !== $newDateNorm;
            $fileChanged = $filePath !== $doc['file_path'];

            if (!$titleChanged && !$dateChanged && !$fileChanged) {
                $errors[] = 'No changes detected in title, enactment date, or file — nothing to save as a new amending document.';
            }

            if (!$errors) {

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO documents
                       (doc_number, title, doc_type, sponsor, committee_id, owner_id, status, is_public, verified_at,
                        source_system, enactment_date, file_path, ocr_text, body, previous_version_id)
                     VALUES (?,?,?,?,?,?,?,?,NULL,?,?,?,?,?,?)'
                );
                // New copies remain pending until the shared registration service links them.
                    $stmt->execute([
                    $newDocNumber, $newTitle, $doc['doc_type'], $doc['sponsor'], $doc['committee_id'],
                    $user['id'], $doc['status'], 0, 'Records revision', $newDate,
                    $filePath, $ocrText, $fileChanged ? null : $newBody, $doc['id'],
                ]);
                $newId = $pdo->lastInsertId();

                // Version lineage is a records-management relationship. It
                // must not rewrite either instrument's authoritative source
                // status; that status arrives through the source-system feed.
                $pdo->prepare('UPDATE documents SET council_term=? WHERE id=?')->execute([$doc['council_term'] ?? null, $newId]);
                if (!$fileChanged) $pdo->prepare('INSERT INTO document_attachments (document_id,file_path,display_name,sort_order) SELECT ?,file_path,display_name,sort_order FROM document_attachments WHERE document_id=?')->execute([$newId,$doc['id']]);
                else $pdo->prepare('INSERT INTO document_attachments (document_id,file_path,display_name,sort_order) VALUES (?,?,?,0)')->execute([$newId,$filePath,basename($filePath)]);
                $pdo->prepare('UPDATE documents SET records_status=?, classification=?, originating_office=?, originating_division=?, submitter_position=?, responsible_custodian=?, related_legislative_item=?, source_record_id=?, source_status=?, source_status_date=?, status_last_synced=NOW(), received_at=NOW(), pending_since=NOW(), registered_at=NULL WHERE id=?')
                    ->execute(['Pending Validation', $doc['classification'], $doc['originating_office'], $doc['originating_division'], $doc['submitter_position'], $doc['responsible_custodian'], $doc['related_legislative_item'], $doc['source_record_id'], $doc['source_status'], $doc['source_status_date'], $newId]);

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
                log_action('version', 'amended_document', $doc['doc_number'] . ' → new document ' . $newDocNumber . ' (#' . $newId . ')');
                $_SESSION['flash_success'] = 'Revision submitted for validation. The current version has not changed.';
                header('Location: document.php?id=' . $newId . $documentReturnSuffix);
                exit;
            }

            }
        }
    }
    }
}

$notesStmt = $pdo->prepare('SELECT n.*, u.full_name FROM document_change_notes n JOIN users u ON u.id = n.created_by WHERE document_id = ? ORDER BY n.created_at DESC');
$notesStmt->execute([$doc['id']]);
$notes = $notesStmt->fetchAll();

$docAuditStmt = $pdo->prepare('SELECT * FROM audit_log WHERE detail LIKE ? ORDER BY created_at DESC LIMIT 20');
$docAuditStmt->execute(['%' . $doc['doc_number'] . '%']);
$docAudit = $docAuditStmt->fetchAll();

// Load all attachment files for this document. Fall back to the legacy file_path
// column so older records remain fully compatible.
$attStmt = $pdo->prepare('SELECT file_path FROM document_attachments WHERE document_id = ? ORDER BY sort_order');
$attStmt->execute([$doc['id']]);
$docFiles = array_column($attStmt->fetchAll(), 'file_path');
if (!$docFiles && $doc['file_path']) $docFiles = [$doc['file_path']];

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
<div class="mb-2">
  <a href="<?= htmlspecialchars($searchReturn ?: 'repository.php', ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left"></i> <?= $searchReturn !== '' ? $documentReturnLabel : 'Back to Repository' ?></a>
</div>
<div class="topbar" data-banner-date="<?= date('M j, Y') ?>">
  <div class="d-flex align-items-center gap-2">
    <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="Open menu">
      <i class="bi bi-list"></i>
    </button>
    <div>
      <div class="topbar__eyebrow"><?= htmlspecialchars($doc['doc_type']) ?> · <?= htmlspecialchars($doc['doc_number']) ?></div>
      <h1 class="topbar__title" style="font-size:21px;"><?= htmlspecialchars($doc['title']) ?></h1>
      <p class="module-banner-description">Review document details, attachments, and record activity.</p>
      <span class="stamp stamp--<?= strtolower(str_replace(' ', '-', $doc['status'])) ?>"><?= htmlspecialchars($doc['status']) ?></span>
    </div>
  </div>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if (!empty($_SESSION['flash_amended'])): unset($_SESSION['flash_amended']); ?><div class="alert alert-success">New amending document saved and linked to the original.</div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?></div><?php endif; ?>

<div class="nav nav-pills gap-2 mb-4" role="tablist" aria-label="Document sections">
  <button class="nav-link active" id="document-details-tab" data-bs-toggle="pill" data-bs-target="#document-details-panel" type="button" role="tab" aria-controls="document-details-panel" aria-selected="true">Document Details</button>
  <button class="nav-link" id="document-history-tab" data-bs-toggle="pill" data-bs-target="#document-history-panel" type="button" role="tab" aria-controls="document-history-panel" aria-selected="false">Tracking &amp; History</button>
</div>
<div class="tab-content">
  <div class="tab-pane fade show active" id="document-details-panel" role="tabpanel" aria-labelledby="document-details-tab" tabindex="0">
    <?php include __DIR__ . '/includes/document_workspace.php'; ?>
  </div>
  <div class="tab-pane fade" id="document-history-panel" role="tabpanel" aria-labelledby="document-history-tab" tabindex="0">
    <?php
      $trackingStmt = $pdo->prepare('SELECT h.action, h.note, h.created_at, u.full_name FROM record_validation_history h LEFT JOIN users u ON u.id=h.actor_id WHERE h.document_id=? ORDER BY h.created_at DESC, h.id DESC');
      $trackingStmt->execute([(int)$doc['id']]);
      $trackingEvents = $trackingStmt->fetchAll();
    ?>
    <div class="registered-workspace">
      <?php include __DIR__ . '/includes/document_tracking.php'; ?>
    </div>
  </div>
</div>

<div class="modal fade" id="filePreviewModal" tabindex="-1" aria-labelledby="filePreviewModalLabel" aria-hidden="true">
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
  var currentIndex = 0;

  function isImage(fp) {
    var ext = fp.split('.').pop().toLowerCase();
    return ['jpg','jpeg','png','gif','webp','bmp','svg'].indexOf(ext) !== -1;
  }

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

  modalEl.addEventListener('hidden.bs.modal', function () {
    var iframe = document.getElementById('filePreviewIframe');
    var img = document.getElementById('filePreviewImg');
    iframe.src = 'about:blank';
    img.src = '';
    files = [];
    currentIndex = 0;
  });
})();
</script>

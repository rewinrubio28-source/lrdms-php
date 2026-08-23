<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/ocr.php';
require_once __DIR__ . '/config/database.php';

require_permission('encoding', 'create');
$user = current_user();
$pdo = get_db();

$previewData = $_SESSION['encoding_preview'] ?? null;

if (isset($_GET['action']) && $_GET['action'] === 'cancel' && isset($_SESSION['encoding_preview'])) {
    unset($_SESSION['encoding_preview']);
    header('Location: encoding.php');
    exit;
}

$committees = $pdo->query('SELECT id, name FROM committees ORDER BY name')->fetchAll();

// Persist the chosen type across validation errors so the right fields stay visible.
$docType = trim($_POST['doc_type'] ?? 'Ordinance');

/**
 * Composes the type-specific form fields into a readable "body" text
 * that is stored on the document. Each document type has its own format
 * (sections for an Ordinance, attendance/agenda for Minutes, etc.).
 */
function _compose_body($type, $p) {
    $out = [];

    // Split a textarea into non-empty lines.
    $each = function ($key) use ($p) {
        $list = [];
        foreach (preg_split('/\r\n|\r|\n/', trim((string)($p[$key] ?? ''))) as $l) {
            $l = trim($l);
            if ($l !== '') $list[] = $l;
        }
        return $list;
    };
    // Blank separator between sections (only if content already exists).
    $blank = function () use (&$out) { if ($out) $out[] = ''; };

    switch ($type) {
        case 'Ordinance':
            foreach ($each('whereas') as $cl) $out[] = 'WHEREAS, ' . rtrim($cl, ';') . ';';
            $i = 0;
            foreach ($each('sections') as $sec) { $blank(); $out[] = 'SECTION ' . (++$i) . '. ' . $sec; }
            $repeal = trim((string)($p['repealing'] ?? ''));
            if ($repeal !== '') { $blank(); $out[] = 'REPEALING CLAUSE. ' . $repeal; }
            break;

        case 'Resolution':
            foreach ($each('whereas') as $cl) $out[] = 'WHEREAS, ' . rtrim($cl, ';') . ';';
            foreach ($each('resolved') as $cl) { $blank(); $out[] = 'RESOLVED, THAT ' . rtrim($cl, '.') . '.'; }
            break;

        case 'Committee Report':
            $re = trim((string)($p['cr_re'] ?? ''));
            if ($re !== '') $out[] = 'RE: ' . $re;
            $findings = trim((string)($p['cr_findings'] ?? ''));
            if ($findings !== '') { $blank(); $out[] = 'FINDINGS & DISCUSSION'; $out[] = $findings; }
            $rec = trim((string)($p['cr_recommendation'] ?? ''));
            if ($rec !== '') { $blank(); $out[] = 'RECOMMENDATION: ' . $rec; }
            break;

        case 'Minutes':
            $sessType = trim((string)($p['mnt_session_type'] ?? ''));
            $venue = trim((string)($p['mnt_venue'] ?? ''));
            $presiding = trim((string)($p['mnt_presiding'] ?? ''));
            $adjourned = trim((string)($p['mnt_adjourned'] ?? ''));
            if ($sessType)  $out[] = 'Session type: ' . $sessType;
            if ($venue)     $out[] = 'Venue: ' . $venue;
            if ($presiding) $out[] = 'Presiding officer: ' . $presiding;
            $attendance = trim((string)($p['mnt_attendance'] ?? ''));
            if ($attendance !== '') { $blank(); $out[] = 'ATTENDANCE & QUORUM'; $out[] = $attendance; }
            $agenda = $each('mnt_agenda');
            if ($agenda) { $blank(); $out[] = 'AGENDA'; foreach ($agenda as $i => $a) $out[] = ($i + 1) . '. ' . $a; }
            $motions = trim((string)($p['mnt_motions'] ?? ''));
            if ($motions !== '') { $blank(); $out[] = 'MOTIONS & DECISIONS'; $out[] = $motions; }
            $votes = trim((string)($p['mnt_votes'] ?? ''));
            if ($votes !== '') { $blank(); $out[] = 'VOTING RESULTS'; $out[] = $votes; }
            if ($adjourned) { $blank(); $out[] = 'Adjourned at: ' . $adjourned; }
            break;

        default:
            $c = trim((string)($p['content'] ?? ''));
            if ($c !== '') $out[] = $c;
    }

    return trim(implode("\n", $out));
}

/**
 * Generates the next document number based on type and year.
 * E.g., Ordinance in 2026 → ORD-2026-001, ORD-2026-002, ...
 */
function _next_doc_number($pdo, $type) {
    $year = date('Y');
    $prefix = [
        'Ordinance'        => 'ORD',
        'Resolution'       => 'RES',
        'Committee Report' => 'CR',
        'Minutes'          => 'MIN',
    ][$type] ?? 'DOC';
    $like = "$prefix-$year-%";
    $stmt = $pdo->prepare("SELECT doc_number FROM documents WHERE doc_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$like]);
    $last = $stmt->fetchColumn();
    $seq = 1;
    if ($last && preg_match('/(\d+)$/', $last, $m)) {
        $seq = (int)$m[1] + 1;
    }
    return sprintf('%s-%s-%03d', $prefix, $year, $seq);
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $errors[] = 'Security token expired. Please refresh the page and try again.';
    }
    if (validate_csrf()) {
    // ── Formatted preview (no save) ────────────────────────────────
    if (isset($_POST['action']) && $_POST['action'] === 'preview') {
        $previewBody = _compose_body($_POST['doc_type'] ?? 'Ordinance', $_POST);
        $_SESSION['encoding_preview_body'] = [
            'doc_type'   => $_POST['doc_type'] ?? 'Ordinance',
            'title'      => trim($_POST['title'] ?? ''),
            'doc_number' => trim($_POST['doc_number'] ?? ''),
            'body'       => $previewBody,
        ];
        header('Location: encoding.php#preview-section');
        exit;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'confirm' && $previewData) {
        $title = $previewData['title'];
        $docNumber = $previewData['docNumber'];
        $docType = $previewData['docType'];
        $sponsor = $previewData['sponsor'];
        $description = $previewData['description'] ?? '';
        $committeeId = $previewData['committeeId'];
        $enactmentDate = $previewData['enactmentDate'];
        $status = $previewData['status'];
        $isPublic = $previewData['isPublic'];
        $filePath = $previewData['filePath'];
        $ocrText = $previewData['ocrText'];
        $body = $previewData['body'] ?? null;

        if (!$errors) {
            $stmt = $pdo->prepare(
                'INSERT INTO documents
                   (doc_number, title, description, doc_type, sponsor, committee_id, owner_id, status, is_public,
                    source_system, enactment_date, file_path, ocr_text, body)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $docNumber, $title, $description ?: null, $docType, $sponsor ?: null, $committeeId, $user['id'], $status, $isPublic,
                'Manual Encoding', $enactmentDate, $filePath, $ocrText, $body,
            ]);
            $newDocId = $pdo->lastInsertId();
            log_action('encoding', 'encoded_document', "$docNumber — $title");
            if ($status !== 'Draft') {
                notify_status_change([
                    'id' => $newDocId, 'doc_number' => $docNumber, 'title' => $title,
                    'owner_id' => $user['id'], 'owner_email' => $user['email'] ?? null,
                    'owner_name' => $user['full_name'], 'committee_id' => $committeeId,
                ], 'Draft', $status, $user);
            }
            unset($_SESSION['encoding_preview']);
            $_SESSION['flash_success'] = 'Document "' . htmlspecialchars($docNumber) . '" has been encoded successfully.';
            header('Location: document.php?id=' . $newDocId);
            exit;
        }
    } else {
        $title = trim($_POST['title'] ?? '');
        $docNumber = trim($_POST['doc_number'] ?? '');
        $docType = $_POST['doc_type'] ?? 'Other';
        $sponsor = trim($_POST['sponsor'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $committeeId = ($_POST['committee_id'] ?? '') !== '' ? (int)$_POST['committee_id'] : null;
        $enactmentDate = ($_POST['enactment_date'] ?? '') !== '' ? $_POST['enactment_date'] : null;
        $status = $_POST['status'] ?? 'Draft';
        $isPublic = isset($_POST['is_public']) ? 1 : 0;
        $body = _compose_body($docType, $_POST);

        $allowedStatuses = in_array($user['role_name'], ['Super Admin', 'Administrator', 'Records Officer'], true)
            ? ['Draft', 'Submitted', 'Under Review', 'Enacted', 'Withdrawn']
            : ['Draft', 'Submitted'];
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'Draft';
        }

        if ($title === '') $errors[] = 'Title is required.';
        if ($docNumber === '') $errors[] = 'Document number is required.';

        $filePath = null;
        $ocrText = null;

        if (isset($_FILES['source_file']) && $_FILES['source_file']['error'] === UPLOAD_ERR_OK) {
            $allowedExt = ['pdf', 'png', 'jpg', 'jpeg', 'docx'];
            $originalName = $_FILES['source_file']['name'];
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            if (!in_array($ext, $allowedExt, true)) {
                $errors[] = 'Unsupported file type. Allowed: ' . implode(', ', $allowedExt) . '.';
            } else {
                $uploadDir = __DIR__ . '/uploads/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $safeName = date('Ymd_His') . '_' . preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $originalName);
                $destination = $uploadDir . $safeName;

                if (move_uploaded_file($_FILES['source_file']['tmp_name'], $destination)) {
                    $filePath = 'uploads/' . $safeName;
                    $ocrText = ocr_extract($destination, $originalName);
                } else {
                    $errors[] = 'File upload failed. Check that the uploads/ folder is writable.';
                }
            }
        }

        if (!$errors) {
            if ($filePath) {
                $_SESSION['encoding_preview'] = [
                    'title'       => $title,
                    'docNumber'   => $docNumber,
                    'docType'     => $docType,
                    'sponsor'     => $sponsor,
                    'description' => $description,
                    'committeeId' => $committeeId,
                    'enactmentDate'=> $enactmentDate,
                    'status'      => $status,
                    'isPublic'    => $isPublic,
                    'filePath'    => $filePath,
                    'ocrText'     => $ocrText,
                    'body'        => $body,
                ];
                $previewData = $_SESSION['encoding_preview'];
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO documents
                       (doc_number, title, description, doc_type, sponsor, committee_id, owner_id, status, is_public,
                        source_system, enactment_date, file_path, ocr_text, body)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $docNumber, $title, $description ?: null, $docType, $sponsor ?: null, $committeeId, $user['id'], $status, $isPublic,
                    'Manual Encoding', $enactmentDate, $filePath, $ocrText, $body,
                ]);
                $newDocId = $pdo->lastInsertId();
                log_action('encoding', 'encoded_document', "$docNumber — $title");
                if ($status !== 'Draft') {
                    notify_status_change([
                        'id' => $newDocId, 'doc_number' => $docNumber, 'title' => $title,
                        'owner_id' => $user['id'], 'owner_email' => $user['email'] ?? null,
                        'owner_name' => $user['full_name'], 'committee_id' => $committeeId,
                    ], 'Draft', $status, $user);
                }
                $_SESSION['flash_success'] = 'Document "' . htmlspecialchars($docNumber) . '" has been encoded successfully.';
                header('Location: document.php?id=' . $newDocId);
                exit;
            }
        }
    }
    }
}

// Auto-generate document number for the current type (user may override)
$autoDocNumber = _next_doc_number($pdo, $docType);

include __DIR__ . '/includes/layout_top.php';
?>
<div class="topbar">
  <div class="d-flex align-items-center gap-2">
    <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="Open menu">
      <i class="bi bi-list"></i>
    </button>
    <div>
      <h1 class="topbar__title">Encoding &amp; Submission</h1>
    </div>
  </div>
</div>

<style>
  .field-error { border-color: #dc3545 !important; box-shadow: 0 0 0 2px rgba(220,53,69,.12) !important; }
  .field-error-msg { color: #dc3545; font-size: 12px; margin-top: 4px; display: block; }
</style>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?></ul>
  </div>
<?php endif; ?>

<?php if ($previewData): ?>
<div class="card mb-4">
  <h3>OCR Preview — Scan Results</h3>
  <p class="text-muted small">Review the extracted text below before encoding this document. Confirm to save or go back to edit.</p>
  <div class="mb-3">
    <label class="form-label"><strong>Original file:</strong> <?= htmlspecialchars(basename($previewData['filePath'])) ?></label>
  </div>
  <div class="mb-3">
    <label class="form-label"><strong>Extracted text:</strong></label>
    <pre class="bg-light border rounded p-3" style="max-height: 400px; overflow-y: auto; white-space: pre-wrap; word-wrap: break-word;"><?= htmlspecialchars($previewData['ocrText'] ?? '') ?></pre>
  </div>
  <?php if (!empty($previewData['body'])): ?>
  <div class="mb-3">
    <label class="form-label"><strong>Document content:</strong></label>
    <pre class="bg-light border rounded p-3" style="max-height: 400px; overflow-y: auto; white-space: pre-wrap; word-wrap: break-word;"><?= htmlspecialchars($previewData['body']) ?></pre>
  </div>
  <?php endif; ?>
  <div class="d-flex gap-2 mb-3">
    <form method="post" style="display:inline;">
      <input type="hidden" name="action" value="confirm">
      <button type="submit" class="btn btn-success">Confirm &amp; Encode</button>
    </form>
    <a href="encoding.php?action=cancel" class="btn btn-outline-secondary">Back to Edit</a>
  </div>
</div>
<?php endif; ?>

<?php
// ── Formatted preview card ─────────────────────────────────────
$previewBody = $_SESSION['encoding_preview_body'] ?? null;
if ($previewBody) unset($_SESSION['encoding_preview_body']);
?>
<?php if ($previewBody && trim($previewBody['body'] ?? '') !== ''): ?>
<div class="card mb-4" id="preview-section" style="border-left:4px solid var(--lrdms-gold);">
  <h3><i class="bi bi-eye"></i> Document Preview — <?= htmlspecialchars($previewBody['doc_type']) ?></h3>
  <p class="text-muted small">This is how the composed document body will appear. Review before saving.</p>
  <div class="mb-2"><strong><?= htmlspecialchars($previewBody['doc_number']) ?></strong> — <?= htmlspecialchars($previewBody['title']) ?></div>
  <pre class="bg-light border rounded p-3" style="max-height:500px;overflow-y:auto;white-space:pre-wrap;word-wrap:break-word;font-size:14px;line-height:1.7;"><?= htmlspecialchars($previewBody['body']) ?></pre>
  <a href="encoding.php" class="btn btn-outline-secondary btn-sm">← Back to Edit</a>
</div>
<?php endif; ?>

<div class="card">
  <h3>Encode a document</h3>

  <form method="post" enctype="multipart/form-data" novalidate>
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Title *</label>
        <input type="text" name="title" class="form-control" maxlength="500" value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Document No. *</label>
        <input type="text" name="doc_number" class="form-control" value="<?= htmlspecialchars($autoDocNumber) ?>" readonly>
      </div>
      <div class="col-md-3">
        <label class="form-label">Type</label>
        <select name="doc_type" id="docTypeSelect" class="form-select">
          <?php foreach (['Ordinance', 'Resolution', 'Committee Report', 'Minutes', 'Other'] as $t): ?>
            <option value="<?= $t ?>" <?= $docType === $t ? 'selected' : '' ?>><?= $t ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-4">
        <label class="form-label">Sponsor</label>
        <input type="text" name="sponsor" class="form-control" maxlength="150" value="<?= htmlspecialchars($_POST['sponsor'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Committee</label>
        <select name="committee_id" class="form-select">
          <option value="">— None —</option>
          <?php foreach ($committees as $c): ?>
            <option value="<?= $c['id'] ?>" <?= (int)($_POST['committee_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Enactment date</label>
        <input type="date" name="enactment_date" class="form-control" value="<?= htmlspecialchars($_POST['enactment_date'] ?? '') ?>">
      </div>

      <div class="col-md-4">
        <label class="form-label">Status</label>
        <?php $currentStatus = $_POST['status'] ?? 'Draft'; ?>
        <select name="status" class="form-select">
          <option value="Draft" <?= $currentStatus === 'Draft' ? 'selected' : '' ?>>Draft</option>
          <option value="Submitted" <?= $currentStatus === 'Submitted' ? 'selected' : '' ?>>Submitted</option>
          <?php if (in_array($user['role_name'], ['Super Admin', 'Administrator', 'Records Officer'], true)): ?>
            <option value="Under Review" <?= $currentStatus === 'Under Review' ? 'selected' : '' ?>>Under Review</option>
            <option value="Enacted" <?= $currentStatus === 'Enacted' ? 'selected' : '' ?>>Enacted</option>
          <?php endif; ?>
        </select>
      </div>
      <div class="col-md-4 d-flex align-items-center">
        <div class="form-check mt-4">
          <input type="checkbox" name="is_public" value="1" class="form-check-input" id="isPublic" <?= isset($_POST['is_public']) ? 'checked' : '' ?>>
          <label class="form-check-label" for="isPublic">Publicly visible once enacted</label>
        </div>
      </div>
      <div class="col-12">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="3" maxlength="1000" placeholder="Brief summary of the document (shown in public view)" style="resize:vertical;"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
      </div>

      <div class="col-md-4">
        <label class="form-label">Source file</label>
        <div class="input-group">
          <input type="file" name="source_file" id="sourceFile" class="form-control" accept=".pdf,.png,.jpg,.jpeg,.docx">
          <button type="button" id="scanBtn" class="btn btn-outline-secondary" disabled title="Scan file for OCR text">
            <i class="bi bi-upc-scan"></i> Scan
          </button>
        </div>
      </div>
    </div>

    <!-- OCR Scan Preview -->
    <div id="ocrPreview" style="display:none;margin-top:16px;">
      <label class="form-label fw-semibold"><i class="bi bi-eye"></i> Scanned Text Preview</label>
      <pre id="ocrText" class="bg-light border rounded p-3" style="max-height:250px;overflow-y:auto;white-space:pre-wrap;word-wrap:break-word;font-size:13px;line-height:1.6;margin:0;"></pre>
      <div id="entitySection" style="display:none;margin-top:10px;">
        <label class="form-label small fw-semibold"><i class="bi bi-tags"></i> Detected Entities</label>
        <div id="entityChips" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
      </div>
    </div>

    <h4 class="form-section-title mt-4">Document details</h4>

    <!-- Ordinance -->
    <div class="type-fields" data-type="Ordinance" <?= $docType === 'Ordinance' ? '' : 'style="display:none;"' ?>>
      <div class="row g-3">
        <div class="col-12">
          <label class="form-label">WHEREAS clauses</label>
          <textarea name="whereas" class="form-control" rows="4" placeholder="WHEREAS, the City Council ..."><?= htmlspecialchars($_POST['whereas'] ?? '') ?></textarea>
        </div>
        <div class="col-12">
          <label class="form-label">Sections</label>
          <textarea name="sections" class="form-control" rows="5" placeholder="Enter each section on its own line"><?= htmlspecialchars($_POST['sections'] ?? '') ?></textarea>
        </div>
        <div class="col-12">
          <label class="form-label">Repealing clause</label>
          <textarea name="repealing" class="form-control" rows="2" placeholder="REPEALING CLAUSE. All ordinances inconsistent herewith are hereby repealed."><?= htmlspecialchars($_POST['repealing'] ?? '') ?></textarea>
        </div>
      </div>
    </div>

    <!-- Resolution -->
    <div class="type-fields" data-type="Resolution" <?= $docType === 'Resolution' ? '' : 'style="display:none;"' ?>>
      <div class="row g-3">
        <div class="col-12">
          <label class="form-label">WHEREAS clauses</label>
          <textarea name="whereas" class="form-control" rows="4" placeholder="WHEREAS, ..."><?= htmlspecialchars($_POST['whereas'] ?? '') ?></textarea>
        </div>
        <div class="col-12">
          <label class="form-label">RESOLVED clauses</label>
          <textarea name="resolved" class="form-control" rows="4" placeholder="RESOLVED, THAT ..."><?= htmlspecialchars($_POST['resolved'] ?? '') ?></textarea>
        </div>
      </div>
    </div>

    <!-- Committee Report -->
    <div class="type-fields" data-type="Committee Report" <?= $docType === 'Committee Report' ? '' : 'style="display:none;"' ?>>
      <div class="row g-3">
        <div class="col-12">
          <label class="form-label">Re:</label>
          <input type="text" name="cr_re" class="form-control" placeholder="e.g. Resolution No. 2026-005" value="<?= htmlspecialchars($_POST['cr_re'] ?? '') ?>">
        </div>
        <div class="col-12">
          <label class="form-label">Findings &amp; discussion</label>
          <textarea name="cr_findings" class="form-control" rows="5" placeholder="Committee findings and discussion..."><?= htmlspecialchars($_POST['cr_findings'] ?? '') ?></textarea>
        </div>
        <div class="col-md-6">
          <label class="form-label">Recommendation</label>
          <?php $crRec = $_POST['cr_recommendation'] ?? ''; ?>
          <select name="cr_recommendation" class="form-select">
            <option value="">— Select —</option>
            <option <?= $crRec === 'Approve' ? 'selected' : '' ?>>Approve</option>
            <option <?= $crRec === 'Approve with Amendments' ? 'selected' : '' ?>>Approve with Amendments</option>
            <option <?= $crRec === 'Disapprove' ? 'selected' : '' ?>>Disapprove</option>
            <option <?= $crRec === 'Recommit' ? 'selected' : '' ?>>Recommit</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Minutes -->
    <div class="type-fields" data-type="Minutes" <?= $docType === 'Minutes' ? '' : 'style="display:none;"' ?>>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Session type</label>
          <?php $mntType = $_POST['mnt_session_type'] ?? 'Regular'; ?>
          <select name="mnt_session_type" class="form-select">
            <option <?= $mntType === 'Regular' ? 'selected' : '' ?>>Regular</option>
            <option <?= $mntType === 'Special' ? 'selected' : '' ?>>Special</option>
            <option <?= $mntType === 'Joint' ? 'selected' : '' ?>>Joint</option>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Venue</label>
          <input type="text" name="mnt_venue" class="form-control" placeholder="Session Hall" value="<?= htmlspecialchars($_POST['mnt_venue'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Presiding officer</label>
          <input type="text" name="mnt_presiding" class="form-control" placeholder="Vice Mayor / Presiding Officer" value="<?= htmlspecialchars($_POST['mnt_presiding'] ?? '') ?>">
        </div>
        <div class="col-12">
          <label class="form-label">Attendance &amp; quorum</label>
          <textarea name="mnt_attendance" class="form-control" rows="3" placeholder="Present: ... / Absent: ..."><?= htmlspecialchars($_POST['mnt_attendance'] ?? '') ?></textarea>
        </div>
        <div class="col-12">
          <label class="form-label">Agenda items</label>
          <textarea name="mnt_agenda" class="form-control" rows="4" placeholder="1. ...&#10;2. ..."><?= htmlspecialchars($_POST['mnt_agenda'] ?? '') ?></textarea>
        </div>
        <div class="col-12">
          <label class="form-label">Motions &amp; decisions</label>
          <textarea name="mnt_motions" class="form-control" rows="3" placeholder="Motion to approve Resolution No. ... — carried"><?= htmlspecialchars($_POST['mnt_motions'] ?? '') ?></textarea>
        </div>
        <div class="col-12">
          <label class="form-label">Voting results</label>
          <textarea name="mnt_votes" class="form-control" rows="3" placeholder="Ayes: ... Nays: ... Abstentions: ..."><?= htmlspecialchars($_POST['mnt_votes'] ?? '') ?></textarea>
        </div>
        <div class="col-md-4">
          <label class="form-label">Adjourned at</label>
          <input type="text" name="mnt_adjourned" class="form-control" placeholder="5:30 PM" value="<?= htmlspecialchars($_POST['mnt_adjourned'] ?? '') ?>">
        </div>
      </div>
    </div>

    <!-- Other -->
    <div class="type-fields" data-type="Other" <?= $docType === 'Other' ? '' : 'style="display:none;"' ?>>
      <div class="row g-3">
        <div class="col-12">
          <label class="form-label">Content / Remarks</label>
          <textarea name="content" class="form-control" rows="5" placeholder="Document content or notes..."><?= htmlspecialchars($_POST['content'] ?? '') ?></textarea>
        </div>
      </div>
    </div>

    <div class="d-flex gap-2 mt-3">
      <button type="submit" class="btn btn-primary">Encode document</button>
      <button type="submit" name="action" value="preview" formnovalidate class="btn btn-outline-secondary"><i class="bi bi-eye"></i> Preview</button>
    </div>
  </form>
</div>

<script>
  // Show only the field group matching the selected document type.
  (function () {
    var sel = document.getElementById('docTypeSelect');
    if (!sel) return;
    var groups = Array.prototype.slice.call(document.querySelectorAll('.type-fields'));
    function apply() {
      groups.forEach(function (g) {
        g.style.display = (g.dataset.type === sel.value) ? '' : 'none';
      });
    }
    sel.addEventListener('change', apply);
    apply();
  })();

  // ── Word count for textareas ──
  (function () {
    document.querySelectorAll('textarea').forEach(function (ta) {
      var counter = document.createElement('span');
      counter.className = 'form-text text-muted';
      counter.style.cssText = 'font-size:11px;margin-top:2px;display:block;';
      ta.parentNode.appendChild(counter);

      function update() {
        var text = ta.value.trim();
        var words = text === '' ? 0 : text.split(/\s+/).length;
        counter.textContent = words + ' word' + (words !== 1 ? 's' : '');
      }
      ta.addEventListener('input', update);
      update();
    });
  })();

  // ── OCR Scan button ──
  (function () {
    var fileInput = document.getElementById('sourceFile');
    var scanBtn = document.getElementById('scanBtn');
    var preview = document.getElementById('ocrPreview');
    var ocrText = document.getElementById('ocrText');
    if (!fileInput || !scanBtn) return;

    fileInput.addEventListener('change', function () {
      scanBtn.disabled = !fileInput.files.length;
    });

    scanBtn.addEventListener('click', function () {
      if (!fileInput.files.length) return;
      var formData = new FormData();
      formData.append('scan_file', fileInput.files[0]);
      // Pass the selected document type for template-based field parsing
      var docTypeSel = document.getElementById('docTypeSelect');
      formData.append('doc_type', docTypeSel ? docTypeSel.value : 'Ordinance');

      scanBtn.disabled = true;
      scanBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Scanning...';
      preview.style.display = 'none';

      fetch('api/ocr_scan.php', { method: 'POST', body: formData })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.text) {
            ocrText.textContent = data.text;
            preview.style.display = '';
            // Display detected entities as colored chips
            var chipWrap = document.getElementById('entityChips');
            var entitySec = document.getElementById('entitySection');
            chipWrap.innerHTML = '';
            if (data.entities && data.entities.length > 0) {
              var colors = { person:'#6366f1', date:'#0891b2', amount:'#16a34a', percentage:'#ea580c', legal:'#9333ea', doc_number:'#ca8a04' };
              var labels = { person:'👤 Person', date:'📅 Date', amount:'💰 Amount', percentage:'📊 Percentage', legal:'📄 Legal', doc_number:'📋 Doc No.' };
              data.entities.forEach(function (e) {
                var chip = document.createElement('span');
                chip.style.cssText = 'display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600;background:' + (colors[e.type] || '#6b7280') + '22;color:' + (colors[e.type] || '#6b7280') + ';border:1px solid ' + (colors[e.type] || '#6b7280') + '44;';
                chip.textContent = (labels[e.type] || e.type) + ': ' + e.text;
                chipWrap.appendChild(chip);
              });
              entitySec.style.display = '';
            } else {
              entitySec.style.display = 'none';
            }
            // Auto-fill form fields from parsed template
            if (data.fields) {
              var filled = 0;
              Object.keys(data.fields).forEach(function (key) {
                var val = data.fields[key];
                if (!val) return;
                var el = document.querySelector('[name="' + key + '"]');
                if (el) { el.value = val; filled++; }
              });
              if (filled > 0) {
                scanBtn.innerHTML = '<i class="bi bi-check-lg"></i> Filled ' + filled + ' field(s)';
              }
            }
          } else {
            ocrText.textContent = data.error || 'Scan failed.';
            preview.style.display = '';
          }
        })
        .catch(function () {
          ocrText.textContent = 'Could not reach the scan service.';
          preview.style.display = '';
        })
        .finally(function () {
          scanBtn.disabled = false;
          scanBtn.innerHTML = '<i class="bi bi-upc-scan"></i> Scan';
        });
    });
  })();

  // ── Inline validation ──
  (function () {
    var form = document.querySelector('form[method="post"][enctype]');
    if (!form) return;
    var isPreview = false;
    var previewBtn = document.querySelector('[name="action"][value="preview"]');
    var encodeBtn = form.querySelector('.btn-primary[type="submit"]');
    if (previewBtn) previewBtn.addEventListener('click', function () { isPreview = true; });
    if (encodeBtn) encodeBtn.addEventListener('click', function () { isPreview = false; });

    function getRequired() {
      return ['title'];
    }

    function clearErrors() {
      form.querySelectorAll('.field-error').forEach(function (el) { el.classList.remove('field-error'); });
      form.querySelectorAll('.field-error-msg').forEach(function (el) { el.remove(); });
    }

    function validate() {
      clearErrors();
      var valid = true;
      getRequired().forEach(function (name) {
        var el = form.querySelector('[name="' + name + '"]');
        if (!el) return;
        // Skip hidden elements (e.g. WHEREAS in a hidden type-fields group)
        var container = el.closest('.type-fields');
        if (container && container.style.display === 'none') return;
        if (el.value.trim()) return;
        valid = false;
        el.classList.add('field-error');
        var msg = document.createElement('span');
        msg.className = 'field-error-msg';
        msg.textContent = 'This field is required.';
        el.parentNode.appendChild(msg);
      });
      return valid;
    }

    form.addEventListener('submit', function (e) {
      if (isPreview) { isPreview = false; return; }
      // Clear hidden type fields so PHP doesn't receive their empty values
      document.querySelectorAll('.type-fields').forEach(function (group) {
        if (group.style.display === 'none') {
          group.querySelectorAll('input, textarea, select').forEach(function (el) {
            if (el.tagName === 'SELECT') el.selectedIndex = 0;
            else el.value = '';
          });
        }
      });
      if (!validate()) {
        e.preventDefault();
        var first = form.querySelector('.field-error');
        if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    });

    // Clear error on input
    form.querySelectorAll('input, textarea, select').forEach(function (el) {
      el.addEventListener('input', function () {
        this.classList.remove('field-error');
        var msg = this.parentNode.querySelector('.field-error-msg');
        if (msg) msg.remove();
      });
    });
  })();
</script>

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>

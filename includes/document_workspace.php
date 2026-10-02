<?php
// Registered-document workspace. The caller has already checked visibility.
$workspaceFile = $docFiles[0] ?? null;
$workspaceExtension = $workspaceFile ? strtolower(pathinfo(parse_url($workspaceFile, PHP_URL_PATH) ?: $workspaceFile, PATHINFO_EXTENSION)) : '';
$workspaceImage = in_array($workspaceExtension, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true);
$workspacePublic = (bool)$doc['is_public'];
$workspaceOcrFiles = array_filter($docFiles, function ($path) {
    return in_array(strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?: $path, PATHINFO_EXTENSION)), ['pdf', 'png', 'jpg', 'jpeg'], true);
});
// Metadata (title/doc number/type/sponsor) is editable only while a record
// is still in Encoding & Submission's pre-verification review — see
// includes/incoming_metadata.php / incoming_metadata_form.php. Once a
// record is registered, this workspace is the view Version Control,
// Repository, and Search all land on, and none of those may change a
// record's metadata — so it's shown here read-only, with no edit control.
// $canRunOcr still uses the repository/edit_metadata permission — that's
// a distinct capability (re-extracting text from the attached file), not
// metadata editing.
$canRunOcr = has_permission('repository', 'edit_metadata');
require_once __DIR__ . '/retrieval.php';
$canPreviewOriginal = can_download_record(current_user(), $doc);
?>
<link rel="stylesheet" href="assets/css/document-workspace.css?v=11">
<div class="verification-workspace registered-workspace registered-workspace--document">
  <section class="verification-preview card">
    <div class="verification-preview__toolbar">
      <div class="verification-preview__filename"><i class="bi bi-file-earmark-pdf-fill"></i> <?= htmlspecialchars($workspaceFile ? basename(parse_url($workspaceFile, PHP_URL_PATH) ?: $workspaceFile) : $doc['doc_number'] . ' — Record summary') ?></div>
      <?php if ($docFiles && $canPreviewOriginal): ?>
      <a href="#filePreviewModal" class="btn btn-light btn-sm open-file-modal" data-files="<?= htmlspecialchars(json_encode(array_map('record_file_url', $docFiles)), ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="modal" data-bs-target="#filePreviewModal" aria-label="Enlarge document preview"><i class="bi bi-zoom-in"></i></a>
      <?php endif; ?>
    </div>
    <div class="verification-preview__stage">
      <?php if ($workspaceFile && !$canPreviewOriginal): ?>
        <div class="verification-preview__empty"><i class="bi bi-lock" aria-hidden="true"></i><p>Original file access requires an approved copy request.</p><a class="btn btn-light btn-sm" href="copy_requests.php?document_id=<?= (int)$doc['id'] ?>&amp;from_document=<?= (int)$doc['id'] ?>">Request a copy</a></div>
      <?php elseif ($workspaceImage): ?>
        <img class="registered-preview-image" src="<?= htmlspecialchars(record_file_url($workspaceFile), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($doc['title'], ENT_QUOTES, 'UTF-8') ?>">
      <?php elseif ($workspaceFile && $workspaceExtension === 'pdf'): ?>
        <iframe src="<?= htmlspecialchars(record_file_url($workspaceFile), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($doc['title'], ENT_QUOTES, 'UTF-8') ?>"></iframe>
      <?php else: ?>
        <article class="registered-summary-sheet">
          <div class="registered-summary-sheet__heading">CITY OF MANILA</div>
          <p class="registered-summary-sheet__subtitle">Legislative Records</p>
          <div class="registered-summary-sheet__reference">DOCUMENT NO: <?= htmlspecialchars($doc['doc_number']) ?></div>
          <h2><?= htmlspecialchars($doc['title']) ?></h2>
          <p class="registered-summary-sheet__type"><?= htmlspecialchars($doc['doc_type']) ?></p>
          <hr>
          <p>This is a summary of the registered metadata.</p>
          <p><?= $workspaceFile ? 'Open the attachment below to view its original contents.' : 'No original file is attached to this record.' ?></p>
          <?php if ($workspaceFile): ?><a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars(record_file_url($workspaceFile), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Open attachment</a><?php endif; ?>
        </article>
      <?php endif; ?>
    </div>
    <?php if (count($docFiles) > 1 && $canPreviewOriginal): ?>
      <a href="#filePreviewModal" class="registered-all-files open-file-modal action-link" data-files="<?= htmlspecialchars(json_encode(array_map('record_file_url', $docFiles)), ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="modal" data-bs-target="#filePreviewModal">View all <?= count($docFiles) ?> attachments <i class="bi bi-arrow-right"></i></a>
    <?php endif; ?>
  </section>
  <section class="verification-panel card">
    <div class="verification-panel__heading"><h2>Record Details</h2><p>View document information and access document tools.</p></div>
    <div class="registered-state"><i class="bi bi-check-circle-fill"></i><span><?= htmlspecialchars($doc['records_status'] ?? 'Registered') ?></span><b><?= $workspacePublic ? 'PUBLIC COPY' : 'PRIVATE COPY' ?></b></div>
    <div class="registered-complete"><i class="bi bi-shield-check"></i><div><strong>Registration complete</strong><p><?= !empty($doc['verified_at']) ? 'Verified on ' . htmlspecialchars(date('M j, Y · g:i A', strtotime($doc['verified_at']))) : 'This document is on file in the repository.' ?></p></div></div>
    <div class="verification-metadata">
      <div class="d-flex justify-content-between align-items-center">
        <h3>Record metadata</h3>
      </div>
      <div class="verification-metadata__grid">
        <div><span>Legislative status</span><strong><?= htmlspecialchars($doc['source_status'] ?: $doc['status']) ?></strong></div>
        <div><span>Source subsystem</span><strong><?= htmlspecialchars($doc['source_system']) ?></strong></div>
        <div><span>Received by</span><strong><?= htmlspecialchars($doc['owner_name']) ?></strong></div>
        <div><span>Received date</span><strong><?= htmlspecialchars(date('M j, Y', strtotime($doc['received_at'] ?? $doc['created_at']))) ?></strong></div>
      </div>
      <div class="mt-3">
        <div class="row g-2 mb-2">
          <div class="col-sm-6"><label for="record-number" class="form-label small">Document number</label><input id="record-number" class="form-control form-control-sm" readonly value="<?= htmlspecialchars($doc['doc_number'], ENT_QUOTES, 'UTF-8') ?>"></div>
          <div class="col-sm-6"><label for="record-type" class="form-label small">Document type</label><input id="record-type" class="form-control form-control-sm" readonly value="<?= htmlspecialchars($doc['doc_type'] === 'Minutes' ? 'Session Minutes' : $doc['doc_type'], ENT_QUOTES, 'UTF-8') ?>"></div>
        </div>
        <label for="record-title" class="form-label small">Title</label>
        <textarea id="record-title" class="form-control form-control-sm mb-2" rows="3" readonly><?= htmlspecialchars($doc['title'], ENT_QUOTES, 'UTF-8') ?></textarea>
        <label for="record-sponsor" class="form-label small">Sponsor</label>
        <input type="text" id="record-sponsor" class="form-control form-control-sm" readonly value="<?= htmlspecialchars($doc['sponsor'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Not provided">
        <p class="text-muted small mt-2 mb-0">Metadata for a registered record can only be corrected through Document Encoding &amp; Submission, before it's verified.</p>
      </div>
    </div>
    <div class="verification-processing">
      <div class="verification-processing__title"><h3>On-demand processing</h3><span><?= !empty($doc['ocr_text']) ? 'OCR on file' : ($workspaceOcrFiles ? 'Not yet scanned' : 'No scannable file') ?></span></div>
      <?php include __DIR__ . '/ocr_progress.php'; ?>
      <div class="registered-tools">
        <?php if ($canRunOcr && $workspaceOcrFiles): ?>
        <form method="post" class="flex-fill"><?php csrf_field(); ?><input type="hidden" name="action" value="run_record_ocr"><button type="submit" class="btn btn-light btn-sm w-100"><i class="bi bi-magic me-1"></i>Run OCR</button></form>
        <?php endif; ?>
        <?php if (has_permission('repository', 'print_record')): ?>
        <button type="button" class="btn btn-primary btn-sm" id="printRepositoryRecord"><i class="bi bi-printer me-1"></i>Print Record Details</button>
        <?php endif; ?>
        <?php if (!empty($doc['ocr_text'])): ?><a href="document_text.php?id=<?= (int)$doc['id'] ?>" class="btn btn-light btn-sm"><i class="bi bi-file-text"></i> View extracted text</a><?php endif; ?>
      </div>
    </div>
  </section>
  <section class="verification-panel card registered-document-details">
    <div class="verification-metadata" aria-labelledby="record-details-heading">
      <h3 id="record-details-heading">Record details</h3>
      <div class="verification-metadata__grid">
        <?php foreach ([
            'records_status' => 'Records status',
            'classification' => 'Classification',
            'originating_office' => 'Originating office',
            'originating_division' => 'Originating division',
            'submitter_position' => 'Submitted by',
            'responsible_custodian' => 'Responsible custodian',
            'related_legislative_item' => 'Related legislative item',
            'source_system' => 'Source system',
            'source_record_id' => 'Source record ID',
            'source_status' => 'Source status',
            'received_at' => 'Received at',
            'registered_at' => 'Registered at',
        ] as $field => $label): ?>
          <?php
            $value = trim((string)($doc[$field] ?? ''));
            if ($value === '') continue;
            if (in_array($field, ['received_at', 'registered_at'], true)) {
                $timestamp = strtotime($value);
                if ($timestamp !== false) $value = date('M j, Y, g:i A', $timestamp);
            }
          ?>
          <div><span><?= htmlspecialchars($label) ?></span>
          <strong>
            <?php if ($field === 'classification'): ?>
              <b class="classification-tag classification-tag--<?= htmlspecialchars(strtolower($value), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?></b>
            <?php else: ?>
              <?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
          </strong></div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if (has_permission('repository','manage_visibility')): ?>
    <form method="post" class="verification-metadata">
      <?php csrf_field(); ?><input type="hidden" name="action" value="update_visibility">
      <h3>Copy visibility</h3>
      <label class="form-check mt-2"><input class="form-check-input" type="checkbox" name="is_public" value="1" <?= $doc['is_public'] ? 'checked' : '' ?>><span class="form-check-label">Release as a public copy</span></label>
      <p class="small text-muted">Public release sets classification to Public. Making a Public copy private sets it to Internal.</p>
      <button class="btn btn-outline-primary btn-sm">Save visibility</button>
    </form>
    <?php endif; ?>
    <?php include __DIR__ . '/retrieval_tools.php'; ?>
    <?php include __DIR__ . '/document_council_term_form.php'; ?>
  </section>
</div>
<?php if (has_permission('repository', 'print_record')): ?>
<section id="repositoryPrintSheet" hidden>
  <h1>LRDMS Record Details</h1>
  <h2><?= htmlspecialchars($doc['title']) ?></h2>
  <dl>
    <?php foreach (['Document number' => $doc['doc_number'], 'Document type' => $doc['doc_type'], 'Sponsor' => $doc['sponsor'], 'Source subsystem' => $doc['source_system'], 'Legislative status' => $doc['source_status'] ?: $doc['status'], 'Records status' => $doc['records_status'], 'Received by' => $doc['owner_name']] as $label => $value): ?>
    <dt><?= htmlspecialchars($label) ?></dt><dd><?= htmlspecialchars($value ?: 'Not provided') ?></dd>
    <?php endforeach; ?>
  </dl>
  <p>This is a record summary, not a certified copy of the original document.</p>
</section>
<script>
(function () {
  const sheet = document.getElementById('repositoryPrintSheet');
  document.body.appendChild(sheet);
  function finishPrint() { document.body.classList.remove('printing-repository-record'); sheet.hidden = true; }
  window.addEventListener('afterprint', finishPrint);
  document.getElementById('printRepositoryRecord').addEventListener('click', function () {
    sheet.hidden = false;
    document.body.classList.add('printing-repository-record');
    try { window.print(); } finally { finishPrint(); }
  });
})();
</script>

<?php endif; ?>

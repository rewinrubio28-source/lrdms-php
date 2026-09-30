<?php
require_once __DIR__ . '/retrieval.php';
$retrievalFiles = retrieval_attachments($pdo, $doc);
$downloadAllowed = can_download_record(current_user(), $doc);
?>
<div class="verification-metadata mt-3">
  <h3>Retrieve document copy</h3>
  <?php if (!$retrievalFiles): ?><p class="small text-muted">No original attachment is available.</p>
  <?php elseif ($downloadAllowed): ?>
    <?php foreach ($retrievalFiles as $file): ?><p class="mb-2"><a class="btn btn-outline-primary btn-sm" href="download_document.php?id=<?= (int)$doc['id'] ?>&attachment=<?= (int)$file['id'] ?>"><i class="bi bi-download" aria-hidden="true"></i> <?= htmlspecialchars(basename(parse_url($file['file_path'], PHP_URL_PATH) ?: $file['file_path'])) ?></a></p><?php endforeach; ?>
    <p class="small text-muted">Downloaded files are copies of the stored attachments. Certification must be obtained from the authorized office.</p>
  <?php else: ?><p class="small text-muted">Request approval to download a copy of this record.</p><a class="btn btn-outline-primary btn-sm" href="copy_requests.php?document_id=<?= (int)$doc['id'] ?>&amp;from_document=<?= (int)$doc['id'] ?><?= !empty($searchReturn) ? '&amp;return=' . rawurlencode($searchReturn) : '' ?>">Request a copy</a><?php endif; ?>
  <a class="d-block small mt-2" href="copy_requests.php?from_document=<?= (int)$doc['id'] ?><?= !empty($searchReturn) ? '&amp;return=' . rawurlencode($searchReturn) : '' ?>">View copy requests</a>
</div>

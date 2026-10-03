<div class="verification-metadata">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h3>Record metadata</h3>
    <?php if ($canEditIncomingMetadata): ?><button type="button" id="editIncomingMetadata" class="btn btn-outline-primary btn-sm" aria-label="Edit record metadata" title="Edit record metadata" aria-controls="incomingMetadataForm" aria-expanded="<?= $incomingMetadataEditing ? 'true' : 'false' ?>"><i class="bi bi-pencil-square" aria-hidden="true"></i></button><?php endif; ?>
  </div>
  <?php foreach ($incomingMetadataErrors as $error): ?><div class="alert alert-danger small"><?= htmlspecialchars($error) ?></div><?php endforeach; ?>
  <form method="post" id="incomingMetadataForm">
    <?php csrf_field(); ?><input type="hidden" name="action" value="update_incoming_metadata">
    <div class="row g-2">
      <div class="col-sm-6"><label for="incoming-number" class="form-label small">Document number</label><input id="incoming-number" name="doc_number" class="form-control form-control-sm" required maxlength="60" <?= $incomingMetadataEditing ? '' : 'readonly' ?> value="<?= htmlspecialchars($incomingMetadataValues['doc_number'], ENT_QUOTES, 'UTF-8') ?>"></div>
      <div class="col-sm-6"><label for="incoming-type" class="form-label small">Document type</label><select id="incoming-type" name="doc_type" class="form-select form-select-sm" <?= $incomingMetadataEditing ? '' : 'disabled' ?>><?php foreach (['Ordinance', 'Resolution', 'Committee Report', 'Minutes', 'Other'] as $type): ?><option value="<?= $type ?>" <?= $incomingMetadataValues['doc_type'] === $type ? 'selected' : '' ?>><?= $type === 'Minutes' ? 'Session Minutes' : $type ?></option><?php endforeach; ?></select></div>
      <div class="col-12"><label for="incoming-title" class="form-label small">Title</label><textarea id="incoming-title" name="title" class="form-control form-control-sm" rows="3" required maxlength="500" <?= $incomingMetadataEditing ? '' : 'readonly' ?>><?= htmlspecialchars($incomingMetadataValues['title']) ?></textarea></div>
      <div class="col-12"><label for="incoming-sponsor" class="form-label small">Sponsor</label><input id="incoming-sponsor" name="sponsor" class="form-control form-control-sm" maxlength="150" <?= $incomingMetadataEditing ? '' : 'readonly' ?> value="<?= htmlspecialchars($incomingMetadataValues['sponsor'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Not provided"></div>
      <div class="col-sm-6"><label for="incoming-classification" class="form-label small">Classification</label><select id="incoming-classification" name="classification" class="form-select form-select-sm" <?= $incomingMetadataEditing ? '' : 'disabled' ?>><?php foreach (['PUBLIC','INTERNAL','RESTRICTED','CONFIDENTIAL'] as $value): ?><option <?= ($incomingMetadataValues['classification'] ?? '') === $value ? 'selected' : '' ?>><?= $value ?></option><?php endforeach; ?></select></div>
      <div class="col-sm-6"><label for="incoming-previous" class="form-label small">Previous version (optional)</label><select id="incoming-previous" name="previous_version_id" class="form-select form-select-sm" <?= $incomingMetadataEditing ? '' : 'disabled' ?>><option value="">New standalone record</option><?php foreach ($incomingPreviousOptions as $previous): ?><option value="<?= (int)$previous['id'] ?>" <?= (string)($incomingMetadataValues['previous_version_id'] ?? '') === (string)$previous['id'] ? 'selected' : '' ?>><?= htmlspecialchars($previous['doc_number'] . ' - ' . $previous['title']) ?></option><?php endforeach; ?></select></div>
      <div class="col-12"><h4 class="h6 mt-3">Source and responsibility</h4><p class="small text-muted mb-0">Enter details supplied with the document. Leave unknown details blank; do not infer its processing history.</p></div>
      <div class="col-sm-6"><label for="incoming-source-status" class="form-label small">Status reported by source</label><input id="incoming-source-status" name="source_status" class="form-control form-control-sm" maxlength="100" <?= $incomingMetadataEditing ? '' : 'readonly' ?> value="<?= htmlspecialchars($incomingMetadataValues['source_status'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. Final minutes, Approved report"><p class="small text-muted">Copy the supplied status. This does not approve the document or change its LRDMS receiving status.</p></div>
      <?php foreach (['source_record_id'=>'Source record ID / reference','originating_office'=>'Originating office','originating_division'=>'Originating division','submitter_position'=>'Submitted by (position)','responsible_custodian'=>'Responsible custodian','related_legislative_item'=>'Related legislative reference'] as $field=>$label): ?>
      <div class="col-sm-6"><label for="incoming-<?= $field ?>" class="form-label small"><?= $label ?></label><input id="incoming-<?= $field ?>" name="<?= $field ?>" class="form-control form-control-sm" maxlength="180" <?= $incomingMetadataEditing ? '' : 'readonly' ?> value="<?= htmlspecialchars($incomingMetadataValues[$field] ?? '', ENT_QUOTES, 'UTF-8') ?>"></div>
      <?php endforeach; ?>
    </div>
    <?php if ($canEditIncomingMetadata): ?><div id="incomingMetadataButtons" class="mt-3" <?= $incomingMetadataEditing ? '' : 'hidden' ?>><button type="submit" class="btn btn-primary btn-sm">Save changes</button> <button type="button" id="cancelIncomingMetadata" class="btn btn-outline-secondary btn-sm">Cancel</button></div><?php endif; ?>
  </form>
</div>
<script>
(function () {
  const form = document.getElementById('incomingMetadataForm');
  const edit = document.getElementById('editIncomingMetadata');
  let editing = <?= $incomingMetadataEditing ? 'true' : 'false' ?>;
  form.addEventListener('submit', function (e) { if (!editing) e.preventDefault(); });
  if (!edit) return;
  const original = <?= json_encode(array_intersect_key($doc, array_flip($incomingFields)), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  function toggle(value) {
    editing = value;
    form.querySelectorAll('input:not([type="hidden"]),textarea').forEach(field => field.readOnly = !value);
    form.querySelectorAll('select').forEach(field => field.disabled = !value);
    document.getElementById('incomingMetadataButtons').hidden = !value;
    edit.setAttribute('aria-expanded', String(value));
  }
  edit.addEventListener('click', function () { toggle(true); form.elements.doc_number.focus(); });
  document.getElementById('cancelIncomingMetadata').addEventListener('click', function () {
    Object.keys(original).forEach(name => form.elements[name].value = original[name] ?? '');
    toggle(false); edit.focus();
  });
})();
</script>

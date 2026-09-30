<div class="verification-metadata">
  <h3>Council term</h3>
  <p class="small mt-2"><?= !empty($doc['council_term']) ? htmlspecialchars(council_term_label((int)$doc['council_term'])) : 'Not assigned' ?></p>
  <?php if ($councilTermError): ?><div class="alert alert-danger small"><?= htmlspecialchars($councilTermError) ?></div><?php endif; ?>
  <?php if ($canAssignCouncilTerm): ?>
  <form method="post" class="mt-2">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="assign_council_term">
    <label for="document-council-term" class="form-label small">Council term number</label>
    <div class="d-flex gap-2">
      <input type="number" min="1" max="999" step="1" name="council_term" id="document-council-term" class="form-control form-control-sm" value="<?= (int)($doc['council_term'] ?? 0) ?: '' ?>" placeholder="e.g. 13" aria-describedby="council-term-help">
      <button type="submit" class="btn btn-outline-primary btn-sm">Save term</button>
    </div>
    <p id="council-term-help" class="small text-muted mt-2 mb-0">Use the council that issued the record. Leave blank if unknown.</p>
  </form>
  <?php endif; ?>
</div>

<?php
if (!organization_schema_available($pdo)) {
    echo '<div class="col-12"><p class="alert alert-warning">Database update required. Ask the administrator to run the deployment upgrade before saving organizational assignments.</p></div>';
    return;
}
$organizationLists = organization_lists($pdo);
?>
<div class="col-12 mb-3" data-organization-form>
  <h4 class="h6 mt-3">Organizational identity</h4>
  <p class="small text-muted">Assign the staff member's organizational unit, section, position, and committee memberships. These assignments do not automatically grant permissions.</p>
  <?php include __DIR__ . '/records_role_guide.php'; ?>
  <div class="row g-2">
    <?php foreach (['office_id' => ['Office / Parent unit', 'offices'], 'division_id' => ['Division / Section', 'divisions'], 'position_id' => ['Position / Designation', 'positions']] as $field => [$label, $table]): ?>
    <div class="col-md-6">
      <label class="form-label small" for="org-<?= $field ?>"><?= $label ?></label>
      <select class="form-select form-select-sm" name="<?= $field ?>" id="org-<?= $field ?>">
        <option value="">Not assigned</option>
        <?php foreach ($organizationLists[$table] as $item): ?>
        <option value="<?= (int)$item['id'] ?>" <?= $field === 'division_id' ? 'data-office="'.(int)$item['office_id'].'"' : '' ?> <?= (int)($organizationValues[$field] ?? 0) === (int)$item['id'] ? 'selected' : '' ?>><?= htmlspecialchars($item['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endforeach; ?>
  </div>
  <fieldset class="mt-3">
    <legend class="small">Committee memberships</legend>
    <?php foreach ($organizationLists['committees'] as $item): ?>
    <label class="d-block small mb-1"><input type="checkbox" name="committee_ids[]" value="<?= (int)$item['id'] ?>" <?= in_array((int)$item['id'], $organizationValues['committee_ids'] ?? [], true) ? 'checked' : '' ?>> <?= htmlspecialchars($item['name']) ?></label>
    <?php endforeach; ?>
    <p class="form-text">The primary committee is included automatically. Additional memberships grant document access only when the role has View Assigned Committees enabled.</p>
  </fieldset>
  <?php if (has_permission('access', 'manage_organization')): ?>
  <a href="organization.php" class="small">Manage offices, divisions, positions, and committees</a>
  <?php endif; ?>
</div>
<script>
(() => {
  const area = document.querySelector('[data-organization-form]');
  const office = area.querySelector('[name="office_id"]');
  const division = area.querySelector('[name="division_id"]');
  function updateDivisions() {
    for (const option of division.options) {
      if (!option.value) continue;
      option.hidden = option.disabled = !office.value || option.dataset.office !== office.value;
      if (option.disabled && option.selected) division.value = '';
    }
    division.disabled = !office.value;
  }
  office.addEventListener('change', updateDivisions);
  updateDivisions();
})();
</script>

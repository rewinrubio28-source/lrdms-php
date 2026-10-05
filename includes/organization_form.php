<?php
if (!organization_schema_available($pdo)) {
    echo '<div class="col-12"><p class="alert alert-warning">Database update required. Ask the administrator to run the deployment upgrade before saving organizational assignments.</p></div>';
    return;
}
$organizationLists = organization_lists($pdo);
$chartPositions = organization_chart_positions();
?>
<?php if (!$organizationLists['offices'] || !$organizationLists['positions']): ?>
<div class="col-12"><p class="alert alert-warning">Organizational choices have not been configured yet. An administrator needs to complete the system update or add the organizational entries before assigning staff.</p></div>
<?php endif; ?>
<div class="col-12 mb-3" data-organization-form>
  <h4 class="h6 mt-3">Position and Section</h4>
  <p class="small text-muted">Choose the employee's official position and section. Access Role controls their system permissions separately.</p>
  <?php include __DIR__ . '/records_role_guide.php'; ?>
  <div class="row g-2">
    <?php foreach (['office_id' => ['Office / Parent Division', 'offices'], 'division_id' => ['Section', 'divisions'], 'position_id' => ['Position', 'positions']] as $field => [$label, $table]): ?>
    <div class="col-md-6">
      <label class="form-label small" for="org-<?= $field ?>"><?= $label ?></label>
      <select class="form-select form-select-sm" name="<?= $field ?>" id="org-<?= $field ?>">
        <option value="">Not assigned</option>
        <?php foreach ($organizationLists[$table] as $item): ?>
        <?php $chart = $field === 'position_id' ? ($chartPositions[$item['name']] ?? null) : null; ?>
        <option value="<?= (int)$item['id'] ?>" data-name="<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>" data-sections="<?= $chart ? $chart[0] : '' ?>" <?= $field === 'division_id' ? 'data-office="'.(int)$item['office_id'].'"' : '' ?> <?= (int)($organizationValues[$field] ?? 0) === (int)$item['id'] ? 'selected' : '' ?>><?= htmlspecialchars($item['name']) ?><?= $chart && $chart[1] !== null ? ' (SG-' . $chart[1] . ')' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endforeach; ?>
  </div>
  <p class="form-text">For the supplied chart, select Information and Communication Division, then RMPS or ITTS. Division leadership may leave Section unassigned. Existing position assignments remain available for review.</p>
  <fieldset class="mt-3">
    <legend class="small">Committee memberships</legend>
    <?php foreach ($organizationLists['committees'] as $item): ?>
    <label class="d-block small mb-1"><input type="checkbox" name="committee_ids[]" value="<?= (int)$item['id'] ?>" <?= in_array((int)$item['id'], $organizationValues['committee_ids'] ?? [], true) ? 'checked' : '' ?>> <?= htmlspecialchars($item['name']) ?></label>
    <?php endforeach; ?>
    <p class="form-text">The primary committee is included automatically. Additional memberships grant document access only when the role has View Assigned Committees enabled.</p>
  </fieldset>
  <?php if (has_permission('access', 'manage_organization')): ?>
  <a href="organization.php" class="small action-link">Manage offices, divisions, positions, and committees</a>
  <?php endif; ?>
</div>
<script>
(() => {
  const area = document.querySelector('[data-organization-form]');
  const office = area.querySelector('[name="office_id"]');
  const division = area.querySelector('[name="division_id"]');
  const position = area.querySelector('[name="position_id"]');
  const savedPosition = position.value;
  function updatePositions() {
    const chartOffice = office.selectedOptions[0]?.dataset.name === 'Information and Communication Division';
    const sectionName = division.selectedOptions[0]?.dataset.name || '';
    const section = sectionName.endsWith('(RMPS)') ? 'RMPS' : sectionName.endsWith('(ITTS)') ? 'ITTS' : '';
    for (const option of position.options) {
      if (!option.value) continue;
      const groups = option.dataset.sections.split(' ');
      const matches = groups.includes('Leadership') || (section ? groups.includes(section) : !!option.dataset.sections);
      option.hidden = option.disabled = chartOffice && !matches && option.value !== savedPosition;
      if (option.disabled && option.selected) position.value = '';
    }
  }
  function updateDivisions() {
    for (const option of division.options) {
      if (!option.value) continue;
      option.hidden = option.disabled = !office.value || option.dataset.office !== office.value;
      if (option.disabled && option.selected) division.value = '';
    }
    division.disabled = !office.value;
    updatePositions();
  }
  division.addEventListener('change', updatePositions);
  office.addEventListener('change', updateDivisions);
  updateDivisions();
})();
</script>

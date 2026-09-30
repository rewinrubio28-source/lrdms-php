<?php
/**
 * Interactive permission checkbox matrix, grouped by module.
 * Rendered inside a role create/edit form; the form must submit a
 * `permission_ids[]` field. Expects `$permGroups` (module → permissions)
 * and `$editingPermIds` (list of already-checked permission ids).
 */
?>
<?php foreach ($permGroups as $module => $perms): ?>
  <div class="mb-3">
    <?php if ($module === 'repository'): ?>
      <p class="small text-muted">View All includes all verified records. Other selected scopes add together. View Committee uses the primary committee; View Assigned Committees includes all memberships. Office and division scopes match the document's originating office/division names. Position does not grant access. Editing permissions are selected separately.</p>
    <?php endif; ?>
    <div class="d-flex align-items-center gap-2 mb-1">
      <h4 class="perm-module m-0"><?= htmlspecialchars(perm_module_label($module)) ?></h4>
      <button type="button" class="btn btn-link btn-sm p-0 perm-toggle" data-module="<?= htmlspecialchars($module) ?>">select / clear</button>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <?php foreach ($perms as $p): ?>
        <span class="perm-chip" title="<?= htmlspecialchars($p['description'] ?? '') ?>">
          <label class="m-0">
            <input type="checkbox" name="permission_ids[]" value="<?= (int)$p['id'] ?>" data-module="<?= htmlspecialchars($module) ?>" <?= in_array((int)$p['id'], $editingPermIds, true) ? 'checked' : '' ?>>
            <?= htmlspecialchars(perm_action_label($p['action'])) ?>
          </label>
        </span>
      <?php endforeach; ?>
    </div>
  </div>
<?php endforeach; ?>

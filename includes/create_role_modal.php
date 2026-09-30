<?php if (has_permission('access', 'manage_roles')):
$permGroups = [];
foreach ($pdo->query('SELECT * FROM permissions ORDER BY module, action')->fetchAll() as $permission) $permGroups[$permission['module']][] = $permission;
$editingPermIds = [];
?>
<div class="modal fade" id="createRoleModal" tabindex="-1" aria-labelledby="createRoleTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <form class="modal-content" id="createRoleForm" method="post" action="api/create_role.php">
      <?php csrf_field(); ?>
      <div class="modal-header"><h5 class="modal-title" id="createRoleTitle">Create New Role</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <div class="alert alert-danger" role="alert" id="createRoleError" hidden></div>
        <label class="form-label" for="newRoleName">Role name</label><input id="newRoleName" class="form-control mb-3" name="name" maxlength="60" required>
        <label class="form-label" for="newRoleDescription">Description</label><textarea id="newRoleDescription" class="form-control mb-3" name="description" maxlength="255" rows="2"></textarea>
        <h6>Permissions</h6>
        <?php include __DIR__ . '/_perm_matrix.php'; ?>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary btn-sm">Create role</button></div>
    </form>
  </div>
</div>
<script src="assets/js/create-role.js?v=1" defer></script>
<?php endif; ?>

<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/config/database.php';

require_permission('access', 'manage_roles');
$me = current_user();
$pdo = get_db();

$errors = [];
$success = '';

// All permissions, ordered by module then action, for the matrix.
$permissions = $pdo->query(
    'SELECT * FROM permissions ORDER BY module, action'
)->fetchAll();

// Group them by module for rendering.
$permGroups = [];
foreach ($permissions as $p) {
    $permGroups[$p['module']][] = $p;
}

// All roles with user/permission counts.
$roles = $pdo->query(
    'SELECT r.*,
       (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count,
       (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS perm_count
     FROM roles r ORDER BY r.id'
)->fetchAll();

// Determine which role we're editing (if any).
$editingId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editing = null;
foreach ($roles as $r) {
    if ((int)$r['id'] === $editingId) $editing = $r;
}
$editingPermIds = [];
if ($editing) {
    $stmt = $pdo->prepare('SELECT permission_id FROM role_permissions WHERE role_id = ?');
    $stmt->execute([$editingId]);
    $editingPermIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // At the very start of the POST handling (right after checking REQUEST_METHOD === 'POST' or form_action):
    if (!validate_csrf()) {
        $errors[] = 'Security token expired. Please refresh the page and try again.';
    }
    if (validate_csrf()) {
    $formAction = $_POST['form_action'] ?? '';

    if ($formAction === 'create_role') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $perms = array_map('intval', (array)($_POST['permission_ids'] ?? []));

        if ($name === '') {
            $errors[] = 'Role name is required.';
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM roles WHERE name = ?');
            $stmt->execute([$name]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'A role with that name already exists.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO roles (name, description) VALUES (?,?)');
                $stmt->execute([$name, $description ?: null]);
                $newRoleId = (int)$pdo->lastInsertId();
                set_role_permissions($pdo, $newRoleId, $perms);
                log_action('access', 'created_role', $name . ' (permissions=' . count($perms) . ')');
                $success = 'Role "' . $name . '" created.';
            }
        }

    } elseif ($formAction === 'update_role') {
        $roleId = (int)($_POST['role_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $perms = array_map('intval', (array)($_POST['permission_ids'] ?? []));

        if ($name === '') {
            $errors[] = 'Role name is required.';
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM roles WHERE name = ? AND id <> ?');
            $stmt->execute([$name, $roleId]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'A role with that name already exists.';
            } elseif ((int)$me['role_id'] === $roleId && !in_array_permission($pdo, $perms, 'access', 'manage_users')) {
                $errors[] = 'You cannot remove user-management from your own role.';
            } else {
                $stmt = $pdo->prepare('UPDATE roles SET name = ?, description = ? WHERE id = ?');
                $stmt->execute([$name, $description ?: null, $roleId]);
                set_role_permissions($pdo, $roleId, $perms);
                log_action('access', 'updated_role', $name . ' (permissions=' . count($perms) . ')');
                $success = 'Role "' . $name . '" updated.';
            }
        }

    } elseif ($formAction === 'delete_role') {
        $roleId = (int)($_POST['role_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT name FROM roles WHERE id = ?');
        $stmt->execute([$roleId]);
        $name = $stmt->fetchColumn();

        if (!$name) {
            $errors[] = 'Role not found.';
        } elseif (in_array($name, ['Super Admin', 'Administrator'], true)) {
            $errors[] = 'The ' . htmlspecialchars($name) . ' role cannot be deleted.';
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role_id = ?');
            $stmt->execute([$roleId]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'This role still has users assigned — move them to another role first.';
            } else {
                $pdo->prepare('DELETE FROM roles WHERE id = ?')->execute([$roleId]);
                log_action('access', 'deleted_role', $name);
                $success = 'Role "' . $name . '" deleted.';
            }
        }
    }
    }
}

/**
 * Replace a role's permission set.
 */
function set_role_permissions($pdo, $roleId, array $permIds) {
    $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$roleId]);
    $stmt = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?,?)');
    foreach ($permIds as $pid) {
        if ($pid > 0) $stmt->execute([$roleId, $pid]);
    }
}

/**
 * Whether a list of permission ids (already resolved to ids) includes the
 * permission with the given module/action. Resolves ids on demand.
 */
function in_array_permission($pdo, array $permIds, $module, $action) {
    $stmt = $pdo->prepare('SELECT id FROM permissions WHERE module = ? AND action = ?');
    $stmt->execute([$module, $action]);
    $id = (int)$stmt->fetchColumn();
    return in_array($id, $permIds, true);
}

include __DIR__ . '/includes/layout_top.php';
?>
<div class="topbar">
  <div class="d-flex align-items-center gap-2">
    <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="Open menu">
      <i class="bi bi-list"></i>
    </button>
    <div>
      <a class="small text-muted text-decoration-none" href="users.php">← Back to users</a>
      <h1 class="topbar__title">Roles &amp; Permissions</h1>
    </div>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-12">
    <div class="card">
      <h3 style="font-size:16px;">Roles</h3>
      <ul class="list-unstyled mb-0">
        <?php foreach ($roles as $r): ?>
          <li class="d-flex justify-content-between align-items-center border-bottom py-2">
            <div>
              <strong><?= htmlspecialchars($r['name']) ?></strong>
              <?php if ($r['description']): ?><div class="text-muted small"><?= htmlspecialchars($r['description']) ?></div><?php endif; ?>
              <div class="small text-muted mt-1">
                <?= (int)$r['user_count'] ?> user<?= $r['user_count'] == 1 ? '' : 's' ?> ·
                <?= (int)$r['perm_count'] ?> permission<?= $r['perm_count'] == 1 ? '' : 's' ?>
                <?php if ((int)$me['role_id'] === (int)$r['id']): ?><span class="badge text-bg-info">you</span><?php endif; ?>
              </div>
            </div>
            <div class="text-nowrap ms-2">
              <a class="btn btn-outline-primary btn-sm" href="roles.php?edit=<?= (int)$r['id'] ?>">Edit</a>
              <?php if ((int)$r['user_count'] === 0 && !in_array($r['name'], ['Super Admin', 'Administrator'], true)): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="form_action" value="delete_role">
                <input type="hidden" name="role_id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-outline-danger btn-sm" onclick="return confirm('Delete this role permanently?')">Delete</button>
              </form>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div class="card">
      <?php if ($editing): ?>
        <h3 style="font-size:16px;">Edit role: <?= htmlspecialchars($editing['name']) ?></h3>
        <form method="post">
          <input type="hidden" name="form_action" value="update_role">
          <input type="hidden" name="role_id" value="<?= (int)$editing['id'] ?>">
          <div class="row g-2 mb-2">
            <div class="col-md-6"><label class="form-label small">Role name</label><input type="text" name="name" class="form-control form-control-sm" value="<?= htmlspecialchars($editing['name']) ?>" required></div>
            <div class="col-md-6"><label class="form-label small">Description</label><input type="text" name="description" class="form-control form-control-sm" value="<?= htmlspecialchars($editing['description'] ?? '') ?>"></div>
          </div>
          <?php include __DIR__ . '/includes/_perm_matrix.php'; ?>
          <button class="btn btn-primary btn-sm w-100">Save role</button>
          <a class="btn btn-outline-secondary btn-sm w-100 mt-2" href="roles.php">Cancel</a>
        </form>
      <?php else: ?>
        <h3 style="font-size:16px;">Create a role</h3>
        <form method="post">
          <input type="hidden" name="form_action" value="create_role">
          <div class="row g-2 mb-2">
            <div class="col-md-6"><label class="form-label small">Role name</label><input type="text" name="name" class="form-control form-control-sm" placeholder="e.g. Document Examiner" required></div>
            <div class="col-md-6"><label class="form-label small">Description</label><input type="text" name="description" class="form-control form-control-sm" placeholder="What this role is for"></div>
          </div>
          <?php include __DIR__ . '/includes/_perm_matrix.php'; ?>
          <button class="btn btn-primary btn-sm w-100">Create role</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-12">
    <div class="card">
      <h3 style="font-size:16px;">Permission descriptions</h3>
      <?php foreach ($permGroups as $module => $perms): ?>
        <div class="mb-3">
          <h4 class="perm-module"><?= htmlspecialchars(perm_module_label($module)) ?></h4>
          <ul class="list-unstyled mb-0">
            <?php foreach ($perms as $p): ?>
              <li class="small py-1 border-bottom">
                <code><?= htmlspecialchars(perm_action_label($p['action'])) ?></code>
                <span class="text-muted">— <?= htmlspecialchars($p['description'] ?? '') ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
  // "Select / clear" toggles all checkboxes within one module group.
  document.querySelectorAll('.perm-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var module = this.getAttribute('data-module');
      var boxes = document.querySelectorAll('input[type="checkbox"][data-module="' + module + '"]');
      var anyUnchecked = Array.prototype.some.call(boxes, function (b) { return !b.checked; });
      boxes.forEach(function (b) { b.checked = anyUnchecked; });
    });
  });
</script>

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>

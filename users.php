<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/organization.php';

require_permission('access', 'manage_users');
$user = current_user();
$pdo = get_db();

$roles = $pdo->query('SELECT * FROM roles ORDER BY id')->fetchAll();
$assignableRoles = assignable_roles($roles); // only roles ranking below the signed-in user's own
$committees = $pdo->query('SELECT * FROM committees ORDER BY name')->fetchAll();

$errors = [];
$success = '';
$oldInput = [];
$formSubmitted = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'create';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // At the very start of the POST handling (right after checking REQUEST_METHOD === 'POST' or form_action):
    if (!validate_csrf()) {
        $errors[] = 'Security token expired. Please refresh the page and try again.';
    } else {

    $formAction = $_POST['form_action'] ?? 'create';

    if ($formAction === 'create') {
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $roleId = (int)($_POST['role_id'] ?? 0);
        $committeeId = ($_POST['committee_id'] ?? '') !== '' ? (int)$_POST['committee_id'] : null;
        $organizationValues = organization_input($pdo, $_POST, $errors);
        $password = $_POST['password'] ?? '';
        $requireChange = !empty($_POST['must_change_password']);

        $oldInput = [
            'fullName' => $fullName,
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'roleId' => $roleId,
            'committeeId' => $committeeId,
            'requireChange' => $requireChange,
        ];

        if ($fullName === '' || $username === '' || $password === '' || !$roleId) {
            $errors[] = 'Full name, username, password, and role are required.';
        } elseif (strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters long.';
        } elseif (!can_assign_role_id($roleId, $roles)) {
            $errors[] = 'You are not allowed to create a user with that role.';
        } else {
            $check = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
            $check->execute([$username]);
            if ($check->fetchColumn() > 0) {
                $errors[] = 'That username is already taken.';
            } elseif ($email !== '') {
                $check = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
                $check->execute([$email]);
                if ($check->fetchColumn() > 0) {
                    $errors[] = 'That email is already in use.';
                }
            }
            if (!$errors) {
                $pdo->beginTransaction();
                try {
                $stmt = $pdo->prepare(
                    'INSERT INTO users (full_name, username, email, password_hash, role_id, committee_id, must_change_password)
                     VALUES (?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $fullName, $username, $email ?: null,
                    password_hash($password, PASSWORD_DEFAULT), $roleId, $committeeId,
                    $requireChange ? 1 : 0,
                ]);
                organization_save($pdo, (int)$pdo->lastInsertId(), $organizationValues);
                log_action('access', 'created_user', $username . ' (role_id=' . $roleId . '); organization=' . json_encode($organizationValues));
                $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }

                $success = 'User "' . $username . '" created.';
                $oldInput = [];
                $organizationValues = [];
                $formSubmitted = false;
            }
        }

    } elseif ($formAction === 'toggle_active') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $tStmt = $pdo->prepare('SELECT u.id, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
        $tStmt->execute([$targetId]);
        $targetRow = $tStmt->fetch();
        if ($targetId && $targetId !== (int)$user['id'] && (!$targetRow || !can_manage_user($targetRow))) {
            $errors[] = 'You do not have permission to change this account.';
        } elseif ($targetId && $targetId !== (int)$user['id']) {
            $stmt = $pdo->prepare('UPDATE users SET is_active = NOT is_active WHERE id = ?');
            $stmt->execute([$targetId]);
            log_action('access', 'toggled_user_active', 'user_id=' . $targetId);
            $success = 'User status updated.';
        } else {
            $errors[] = 'You cannot deactivate your own account.';
        }
    }
    } /* end CSRF guard */
}

// --- Filter / search state -------------------------------------------------
$q = trim($_GET['q'] ?? '');
$roleCounts = $pdo->query('SELECT role_id, COUNT(*) AS total FROM users GROUP BY role_id')->fetchAll(PDO::FETCH_KEY_PAIR);
$roleFilter = (int)($_GET['role_id'] ?? 0);
$selectedRoleName = 'All Users';
foreach ($roles as $roleOption) {
    if ((int)$roleOption['id'] === $roleFilter) $selectedRoleName = $roleOption['name'];
}
$committeeFilter = (int)($_GET['committee_id'] ?? 0);
$statusFilter = $_GET['status'] ?? 'all';
if (!in_array($statusFilter, ['all', 'active', 'disabled'], true)) $statusFilter = 'all';

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
if ($roleFilter) {
    $where[] = 'u.role_id = ?';
    $params[] = $roleFilter;
}
if ($committeeFilter) {
    $where[] = '(u.committee_id = ? OR EXISTS (SELECT 1 FROM user_committees uc WHERE uc.user_id = u.id AND uc.committee_id = ?))';
    $params[] = $committeeFilter;
    $params[] = $committeeFilter;
}
if ($statusFilter === 'active') {
    $where[] = 'u.is_active = 1';
} elseif ($statusFilter === 'disabled') {
    $where[] = 'u.is_active = 0';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

require_once __DIR__ . '/includes/profile_photos.php';
$photoExpression = profile_photos_available($pdo) ? 'EXISTS(SELECT 1 FROM user_profile_photos photo WHERE photo.user_id = u.id)' : '0';
$allUsers = $pdo->prepare(
    'SELECT u.*, r.name AS role_name, ' . $photoExpression . ' AS has_profile_photo
     FROM users u
     JOIN roles r ON r.id = u.role_id'
    . $whereSql . ' ORDER BY u.created_at DESC'
);
$allUsers->execute($params);
$allUsers = $allUsers->fetchAll();

include __DIR__ . '/includes/layout_top.php';
?>
<div class="topbar" data-banner-date="<?= date('M j, Y') ?>">
  <div class="d-flex align-items-center gap-2">
    <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="Open menu">
      <i class="bi bi-list"></i>
    </button>
    <div>
      <h1 class="topbar__title">Users &amp; Roles</h1>
      <p class="module-banner-description">Manage user accounts and access to the records system.</p>
    </div>
  </div>
  <div class="d-flex gap-2 align-items-center topbar__actions">
    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">
      <i class="bi bi-person-plus me-1"></i>Add User
    </button>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?></div><?php endif; ?>

<link rel="stylesheet" href="assets/css/users-roles.css?v=2">
<?php include __DIR__ . '/includes/users_roles_directory.php'; ?>
<script src="assets/js/users-roles.js?v=2" defer></script>

<!-- Add User modal -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <?php csrf_field(); ?>
        <input type="hidden" name="form_action" value="create">
        <div class="modal-header">
          <h5 class="modal-title" id="addUserModalLabel">Add a user</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label small">Full name</label>
              <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($oldInput['fullName'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small">Username</label>
              <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($oldInput['username'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small">Email</label>
              <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($oldInput['email'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small">Temporary password</label>
              <input type="text" name="password" class="form-control" value="<?= htmlspecialchars($oldInput['password'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small">System role</label>
              <select name="role_id" class="form-select" required>
                <option value="">— Select —</option>
                <?php foreach ($assignableRoles as $r): ?><option value="<?= $r['id'] ?>" <?= (isset($oldInput['roleId']) && (int)$oldInput['roleId'] === (int)$r['id']) ? 'selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small">Primary committee (if applicable)</label>
              <select name="committee_id" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($committees as $c): ?><option value="<?= $c['id'] ?>" <?= (isset($oldInput['committeeId']) && $oldInput['committeeId'] !== null && (int)$oldInput['committeeId'] === (int)$c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <?php $organizationValues = $organizationValues ?? []; include __DIR__ . '/includes/organization_form.php'; ?>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="must_change_password" id="mcp" <?= (!$formSubmitted || !empty($oldInput['requireChange'])) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="mcp">Require password change on first sign-in</label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <?php if (has_permission('access', 'manage_roles')): ?>
            <a class="btn btn-outline-primary btn-sm me-auto py-1 px-2" style="font-size:12px;" href="roles.php"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Roles &amp; Permissions</a>
          <?php endif; ?>
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm">Create user</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if (($errors && $formSubmitted) || ($_GET['open'] ?? '') === 'add-user'): ?>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    const url = new URL(window.location.href);
    const requestedOpen = url.searchParams.get('open') === 'add-user';
    if (requestedOpen) {
      url.searchParams.delete('open');
      history.replaceState(history.state, '', url.pathname + url.search + url.hash);
    }
    const navigation = performance.getEntriesByType('navigation')[0];
    if (navigation && navigation.type === 'reload') return;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('addUserModal')).show();
  });
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/create_role_modal.php'; ?>
<?php include __DIR__ . '/includes/layout_bottom.php'; ?>

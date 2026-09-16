<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/config/database.php';

require_permission('access', 'manage_users');
$user = current_user();
$pdo = get_db();

$roles = $pdo->query('SELECT * FROM roles ORDER BY id')->fetchAll();
$committees = $pdo->query('SELECT * FROM committees ORDER BY name')->fetchAll();

$errors = [];
$success = '';

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
        $password = $_POST['password'] ?? '';
        $requireChange = !empty($_POST['must_change_password']);
        $sendWelcome = !empty($_POST['send_welcome_email']);

        if ($fullName === '' || $username === '' || $password === '' || !$roleId) {
            $errors[] = 'Full name, username, password, and role are required.';
        } elseif (strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters long.';
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
                $stmt = $pdo->prepare(
                    'INSERT INTO users (full_name, username, email, password_hash, role_id, committee_id, must_change_password)
                     VALUES (?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $fullName, $username, $email ?: null,
                    password_hash($password, PASSWORD_DEFAULT), $roleId, $committeeId,
                    $requireChange ? 1 : 0,
                ]);
                log_action('access', 'created_user', $username . ' (role_id=' . $roleId . ')');

                if ($sendWelcome && $email !== '') {
                    require_once __DIR__ . '/config/email.php';
                    $welcomeBody = '
                    <html>
                    <head><style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: #37517e; color: white; padding: 20px; text-align: center; }
                        .content { padding: 20px; background: #f9f9f9; }
                        .creds { background: #fff; border: 1px solid #ddd; border-radius: 10px; padding: 16px 20px; margin: 16px 0; }
                        .creds b { color: #37517e; }
                        .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
                    </style></head>
                    <body>
                        <div class="container">
                            <div class="header"><h2>LRDMS Account Created</h2></div>
                            <div class="content">
                                <p>Hello ' . htmlspecialchars($fullName) . ',</p>
                                <p>An LRDMS account has been created for you. Use the credentials below to sign in:</p>
                                <div class="creds">
                                    <p>Username: <b>' . htmlspecialchars($username) . '</b></p>
                                    <p>Temporary password: <b>' . htmlspecialchars($password) . '</b></p>
                                </div>
                                ' . ($requireChange ? '<p>You will be required to set a new password on your first sign in.</p>' : '<p>Please change your password after signing in.</p>') . '
                                <p><a href="' . BASE_URL . '/public.php">Sign in to LRDMS →</a></p>
                            </div>
                            <div class="footer">
                                <p>This is an automated message from LRDMS. Please do not reply to this email.</p>
                            </div>
                        </div>
                    </body>
                    </html>';
                    $emailSent = send_email($email, 'Your LRDMS account', $welcomeBody);
                    log_action('access', 'sent_welcome_email', $username . ' (' . ($emailSent ? 'sent' : 'FAILED') . ')');
                    $success = 'User "' . $username . '" created.' . ($emailSent ? ' Welcome email sent.' : ' (Welcome email could not be sent — check SMTP config.)');
                } else {
                    $success = 'User "' . $username . '" created.';
                }
            }
        }

    } elseif ($formAction === 'toggle_active') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        if ($targetId && $targetId !== (int)$user['id']) {
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
$roleFilter = (int)($_GET['role_id'] ?? 0);
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
    $where[] = 'u.committee_id = ?';
    $params[] = $committeeFilter;
}
if ($statusFilter === 'active') {
    $where[] = 'u.is_active = 1';
} elseif ($statusFilter === 'disabled') {
    $where[] = 'u.is_active = 0';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$allUsers = $pdo->prepare(
    'SELECT u.*, r.name AS role_name, c.name AS committee_name
     FROM users u
     JOIN roles r ON r.id = u.role_id
     LEFT JOIN committees c ON c.id = u.committee_id'
    . $whereSql . ' ORDER BY u.created_at DESC'
);
$allUsers->execute($params);
$allUsers = $allUsers->fetchAll();

include __DIR__ . '/includes/layout_top.php';
?>
<div class="topbar">
  <div class="d-flex align-items-center gap-2">
    <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="Open menu">
      <i class="bi bi-list"></i>
    </button>
    <div>
      <h1 class="topbar__title">Users &amp; Roles</h1>
    </div>
  </div>
  <div class="d-flex gap-2 align-items-center topbar__actions">
    <?php if (has_permission('access', 'manage_roles')): ?>
      <a class="btn btn-outline-primary btn-sm" href="roles.php"><i class="bi bi-shield-lock me-1"></i>Roles &amp; Permissions</a>
    <?php endif; ?>
    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">
      <i class="bi bi-person-plus me-1"></i>Add User
    </button>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?></div><?php endif; ?>

<div class="card">
      <h3 style="font-size:16px;">All users <span class="text-muted small">(<?= count($allUsers) ?>)</span></h3>

      <form method="get" class="row g-2 mb-3">
        <div class="col-md-4">
          <input type="text" name="q" class="form-control form-control-sm" placeholder="Search name, username, email…" value="<?= htmlspecialchars($q) ?>">
        </div>
        <div class="col-md-3">
          <select name="role_id" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="0">All roles</option>
            <?php foreach ($roles as $r): ?>
              <option value="<?= $r['id'] ?>" <?= $roleFilter === (int)$r['id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <select name="committee_id" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="0">All committees</option>
            <?php foreach ($committees as $c): ?>
              <option value="<?= $c['id'] ?>" <?= $committeeFilter === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Any status</option>
            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="disabled" <?= $statusFilter === 'disabled' ? 'selected' : '' ?>>Disabled</option>
          </select>
        </div>
        <div class="col-12 d-flex gap-2">
          <button type="submit" class="visually-hidden">Search</button>
          <?php if ($q || $roleFilter || $committeeFilter || $statusFilter !== 'all'): ?>
            <a class="btn btn-outline-secondary btn-sm" href="users.php">Reset</a>
          <?php endif; ?>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Last login</th><th>2FA</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($allUsers as $u): ?>
            <tr>
              <td>
                <a href="user_view.php?id=<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?></a>
                <?php if ($u['must_change_password']): ?><span class="badge text-bg-warning" title="Must change password on next sign-in">pw</span><?php endif; ?>
              </td>
              <td class="font-monospace small"><?= htmlspecialchars($u['username']) ?></td>
              <td class="small text-muted"><?= $u['email'] ? htmlspecialchars($u['email']) : '—' ?></td>
              <td><?= htmlspecialchars($u['role_name']) ?><?= $u['committee_name'] ? ' <span class="text-muted small">/ ' . htmlspecialchars($u['committee_name']) . '</span>' : '' ?></td>
              <td><?= $u['is_active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Disabled</span>' ?></td>
              <td class="small text-muted"><?= $u['last_login_at'] ? date('M j, g:i A', strtotime($u['last_login_at'])) : 'Never' ?></td>
              <td><?= $u['totp_enabled'] ? '<span class="badge text-bg-primary">On</span>' : '<span class="text-muted">—</span>' ?></td>
              <td class="text-nowrap">
                <a class="btn btn-outline-primary btn-sm" href="user_view.php?id=<?= (int)$u['id'] ?>">Manage</a>
                <?php if ((int)$u['id'] !== (int)$user['id']): ?>
                <form method="post" class="d-inline">
                  <input type="hidden" name="form_action" value="toggle_active">
                  <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                  <button class="btn btn-outline-secondary btn-sm"><?= $u['is_active'] ? 'Disable' : 'Enable' ?></button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$allUsers): ?><tr><td colspan="8" class="text-muted small text-center py-4">No users match the current filters.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

<!-- Add User modal -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="form_action" value="create">
        <div class="modal-header">
          <h5 class="modal-title" id="addUserModalLabel">Add a user</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label small">Full name</label>
              <input type="text" name="full_name" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small">Username</label>
              <input type="text" name="username" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small">Email</label>
              <input type="email" name="email" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label small">Temporary password</label>
              <input type="text" name="password" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small">Role</label>
              <select name="role_id" class="form-select" required>
                <option value="">— Select —</option>
                <?php foreach ($roles as $r): ?><option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small">Committee (if applicable)</label>
              <select name="committee_id" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($committees as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="must_change_password" id="mcp" checked>
                <label class="form-check-label small" for="mcp">Require password change on first sign-in</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="send_welcome_email" id="swe">
                <label class="form-check-label small" for="swe">Send welcome email with credentials</label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm">Create user</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($errors): ?>
<script>
  // Re-open the modal after a failed create so the form stays on screen.
  new bootstrap.Modal(document.getElementById('addUserModal')).show();
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
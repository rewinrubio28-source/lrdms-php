<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/config/database.php';

require_permission('access', 'manage_users');
$me = current_user();
$pdo = get_db();

$roles = $pdo->query('SELECT * FROM roles ORDER BY id')->fetchAll();
$committees = $pdo->query('SELECT * FROM committees ORDER BY name')->fetchAll();

$errors = [];
$success = '';

// Resolve the target user id (from the URL, or from a submitted form).
$targetId = (int)($_GET['id'] ?? $_POST['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // At the very start of the POST handling (right after checking REQUEST_METHOD === 'POST' or form_action):
    if (!validate_csrf()) {
        $errors[] = 'Security token expired. Please refresh the page and try again.';
    }
    if (validate_csrf()) {
    $formAction = $_POST['form_action'] ?? '';
    $uid = (int)($_POST['user_id'] ?? $targetId);

    if ($formAction === 'update_details') {
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $roleId = (int)($_POST['role_id'] ?? 0);
        $committeeId = ($_POST['committee_id'] ?? '') !== '' ? (int)$_POST['committee_id'] : null;
        if ($uid === (int)$me['id']) {
            // The "Account is active" checkbox is disabled in the form when editing
            // your own account, so browsers never submit it — keep the current
            // stored value instead of treating the missing field as "inactive".
            $stmt = $pdo->prepare('SELECT is_active FROM users WHERE id = ?');
            $stmt->execute([$uid]);
            $isActive = (int)$stmt->fetchColumn();
        } else {
            $isActive = !empty($_POST['is_active']) ? 1 : 0;
        }
        $mustChange = !empty($_POST['must_change_password']) ? 1 : 0;

        if ($fullName === '' || $username === '' || !$roleId) {
            $errors[] = 'Full name, username, and role are required.';
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ? AND id <> ?');
            $stmt->execute([$username, $uid]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'That username is already taken.';
            } elseif ($email !== '') {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?');
                $stmt->execute([$email, $uid]);
                if ($stmt->fetchColumn() > 0) {
                    $errors[] = 'That email is already in use.';
                }
            }
            if (!$errors) {
                $stmt = $pdo->prepare(
                    'UPDATE users SET full_name = ?, username = ?, email = ?, role_id = ?, committee_id = ?, is_active = ?, must_change_password = ? WHERE id = ?'
                );
                $stmt->execute([$fullName, $username, $email ?: null, $roleId, $committeeId, $isActive, $mustChange, $uid]);
                log_action('access', 'updated_user', 'user_id=' . $uid);
                $success = 'User details updated.';
            }
        }
    } elseif ($formAction === 'reset_password') {
        $newPassword = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $requireChange = !empty($_POST['must_change_password']) ? 1 : 0;

        if (strlen($newPassword) < 6) {
            $errors[] = 'Password must be at least 6 characters long.';
        } elseif ($newPassword !== $confirm) {
            $errors[] = 'Passwords do not match.';
        } else {
            $stmt = $pdo->prepare(
                'UPDATE users SET password_hash = ?, must_change_password = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?'
            );
            $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $requireChange, $uid]);
            log_action('access', 'reset_password', 'user_id=' . $uid . ($requireChange ? ' (force change)' : ''));
            $success = 'Password reset.';
        }
    } elseif ($formAction === 'reset_2fa') {
        $pdo->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?')->execute([$uid]);
        log_action('access', 'reset_2fa', 'user_id=' . $uid);
        $success = 'Two-factor authentication cleared for this user.';
    } elseif ($formAction === 'clear_lockout') {
        $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?')->execute([$uid]);
        log_action('access', 'cleared_user_lockout', 'user_id=' . $uid);
        $success = 'Login lockout cleared.';
    } elseif ($formAction === 'revoke_session') {
        $sid = (int)($_POST['session_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT session_token FROM user_sessions WHERE id = ? AND user_id = ?');
        $stmt->execute([$sid, $uid]);
        $token = $stmt->fetchColumn();
        if ($token && $token !== ($_SESSION['session_token'] ?? '')) {
            $pdo->prepare('UPDATE user_sessions SET is_active = 0 WHERE id = ?')->execute([$sid]);
            log_action('access', 'revoked_session', 'user_id=' . $uid . ' session_id=' . $sid);
            $success = 'Session revoked.';
        } else {
            $errors[] = 'You cannot revoke your own current session here.';
        }
    } elseif ($formAction === 'revoke_all_sessions') {
        $stmt = $pdo->prepare(
            'UPDATE user_sessions SET is_active = 0 WHERE user_id = ? AND session_token <> ?'
        );
        $stmt->execute([$uid, $_SESSION['session_token'] ?? '']);
        log_action('access', 'revoked_all_sessions', 'user_id=' . $uid);
        $success = 'All other active sessions were signed out.';
    }
    }
}

// Load the target user fresh (after any POST handling).
$stmt = $pdo->prepare(
    'SELECT u.*, r.name AS role_name, c.name AS committee_name
     FROM users u
     JOIN roles r ON r.id = u.role_id
     LEFT JOIN committees c ON c.id = u.committee_id
     WHERE u.id = ?'
);
$stmt->execute([$targetId]);
$target = $stmt->fetch();

// Active sessions for this user.
$sessions = [];
if ($target) {
    $stmt = $pdo->prepare('SELECT * FROM user_sessions WHERE user_id = ? AND is_active = 1 ORDER BY created_at DESC');
    $stmt->execute([$targetId]);
    $sessions = $stmt->fetchAll();
}

// Activity drill-down (audit entries touching this user).
$activity = [];
$actTotal = 0;
$actPages = 1;
$actPage = max(1, (int)($_GET['actpage'] ?? 1));
$canViewActivity = has_permission('access', 'view_user_activity') || has_permission('audit', 'view');
if ($target && $canViewActivity) {
    $actPerPage = 25;
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM audit_log WHERE user_id = ? OR (user_id IS NULL AND username_snapshot = ?)'
    );
    $stmt->execute([$targetId, $target['username']]);
    $actTotal = (int)$stmt->fetchColumn();
    $actPages = max(1, (int)ceil($actTotal / $actPerPage));
    $actPage = min($actPage, $actPages);
    $actOffset = ($actPage - 1) * $actPerPage;
    $stmt = $pdo->prepare(
        'SELECT * FROM audit_log WHERE user_id = ? OR (user_id IS NULL AND username_snapshot = ?)
         ORDER BY created_at DESC LIMIT ' . $actPerPage . ' OFFSET ' . $actOffset
    );
    $stmt->execute([$targetId, $target['username']]);
    $activity = $stmt->fetchAll();
}

$isMe = $target && (int)$target['id'] === (int)$me['id'];
$isLocked = $target && !empty($target['locked_until']) && strtotime($target['locked_until']) > time();
$canResetPw = has_permission('access', 'reset_password');
$canForceLogout = has_permission('access', 'force_logout');

include __DIR__ . '/includes/layout_top.php';
?>
<div class="topbar">
  <div class="d-flex align-items-center gap-2">
    <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="Open menu">
      <i class="bi bi-list"></i>
    </button>
    <div>
      <a class="small text-muted text-decoration-none" href="users.php">← Back to users</a>
      <h1 class="topbar__title">
        <?= $target ? htmlspecialchars($target['full_name']) : 'User not found' ?>
        <?php if ($target): ?>
          <span class="badge text-bg-primary ms-1"><?= htmlspecialchars($target['role_name']) ?></span>
          <?= $target['is_active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Disabled</span>' ?>
          <?php if ($isMe): ?><span class="badge text-bg-info">This is you</span><?php endif; ?>
        <?php endif; ?>
      </h1>
    </div>
  </div>
</div>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?></div><?php endif; ?>

<?php if (!$target): ?>
  <div class="card"><p class="text-muted small mb-0">No user with that ID exists. <a href="users.php">Back to users →</a></p></div>
<?php else: ?>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card">
      <h3 style="font-size:16px;">Account details</h3>
      <form method="post">
        <input type="hidden" name="form_action" value="update_details">
        <input type="hidden" name="user_id" value="<?= (int)$target['id'] ?>">
        <div class="mb-2"><label class="form-label small">Full name</label><input type="text" name="full_name" class="form-control form-control-sm" value="<?= htmlspecialchars($target['full_name']) ?>" required></div>
        <div class="mb-2"><label class="form-label small">Username</label><input type="text" name="username" class="form-control form-control-sm" value="<?= htmlspecialchars($target['username']) ?>" required></div>
        <div class="mb-2"><label class="form-label small">Email</label><input type="email" name="email" class="form-control form-control-sm" value="<?= htmlspecialchars($target['email'] ?? '') ?>"></div>
        <div class="mb-2">
          <label class="form-label small">Role</label>
          <select name="role_id" class="form-select form-select-sm" required>
            <?php foreach ($roles as $r): ?>
              <option value="<?= $r['id'] ?>" <?= (int)$r['id'] === (int)$target['role_id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label small">Committee (if applicable)</label>
          <select name="committee_id" class="form-select form-select-sm">
            <option value="">— None —</option>
            <?php foreach ($committees as $c): ?>
              <option value="<?= $c['id'] ?>" <?= (int)$c['id'] === (int)$target['committee_id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?= $target['is_active'] ? 'checked' : '' ?> <?= $isMe ? 'disabled' : '' ?>>
          <label class="form-check-label small" for="is_active">Account is active</label>
        </div>
        <div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" name="must_change_password" id="mcp" <?= $target['must_change_password'] ? 'checked' : '' ?>>
          <label class="form-check-label small" for="mcp">Require password change on next sign-in</label>
        </div>
        <button class="btn btn-primary btn-sm w-100">Save details</button>
      </form>
    </div>

    <?php if ($canResetPw): ?>
    <div class="card">
      <h3 style="font-size:16px;">Reset password</h3>
      <p class="text-muted small">Sets a new password for this user<?= $isMe ? ' — you will be signed out' : '' ?>. Choosing "force change" makes them set their own password on next sign-in.</p>
      <form method="post">
        <input type="hidden" name="form_action" value="reset_password">
        <input type="hidden" name="user_id" value="<?= (int)$target['id'] ?>">
        <div class="mb-2">
          <label class="form-label small">New password</label>
          <input type="password" name="new_password" class="form-control form-control-sm" required minlength="6" id="pw-new">
          <div class="progress mt-1" style="height:4px;">
            <div class="progress-bar" id="pw-strength-bar" role="progressbar" style="width:0%"></div>
          </div>
          <div class="form-text" id="pw-strength-text"></div>
        </div>
        <div class="mb-2"><label class="form-label small">Confirm password</label><input type="password" name="confirm_password" class="form-control form-control-sm" required minlength="6"></div>
        <div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" name="must_change_password" id="rc_mcp">
          <label class="form-check-label small" for="rc_mcp">Force a password change on next sign-in</label>
        </div>
        <button class="btn btn-warning btn-sm w-100">Reset password</button>
      </form>
    </div>
    <?php endif; ?>

    <div class="card">
      <h3 style="font-size:16px;">Security</h3>
      <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="small">Two-factor authentication:
          <?= $target['totp_enabled'] ? '<span class="badge text-bg-success">Enabled</span>' : '<span class="badge text-bg-secondary">Off</span>' ?>
        </span>
        <?php if ($target['totp_enabled']): ?>
        <form method="post">
          <input type="hidden" name="form_action" value="reset_2fa">
          <input type="hidden" name="user_id" value="<?= (int)$target['id'] ?>">
          <button class="btn btn-outline-danger btn-sm" onclick="return confirm('Clear this user’s 2FA? They will be able to sign in with just a password.')">Reset 2FA</button>
        </form>
        <?php endif; ?>
      </div>
      <div class="d-flex justify-content-between align-items-center">
        <span class="small">Failed logins: <strong><?= (int)$target['failed_attempts'] ?></strong> / <?= LOGIN_MAX_ATTEMPTS ?></span>
        <?php if ($isLocked): ?>
          <form method="post">
            <input type="hidden" name="form_action" value="clear_lockout">
            <input type="hidden" name="user_id" value="<?= (int)$target['id'] ?>">
            <button class="btn btn-outline-primary btn-sm">Clear lockout</button>
          </form>
        <?php endif; ?>
      </div>
      <?php if ($isLocked): ?>
        <p class="text-danger small mb-0 mt-2">Account locked until <?= htmlspecialchars(date('M j, g:i A', strtotime($target['locked_until']))) ?>.</p>
      <?php endif; ?>
      <p class="text-muted small mt-3 mb-0">Last login: <?= $target['last_login_at'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($target['last_login_at']))) : 'never' ?></p>
    </div>
  </div>

  <div class="col-lg-6">
    <?php if ($canForceLogout): ?>
    <div class="card">
      <h3 style="font-size:16px;">Active sessions <span class="text-muted small">(<?= count($sessions) ?>)</span></h3>
      <?php if ($sessions): ?>
      <ul class="list-unstyled mb-3">
        <?php foreach ($sessions as $s): $isCurrent = ($s['session_token'] === ($_SESSION['session_token'] ?? '')); ?>
          <li class="d-flex justify-content-between align-items-center border-bottom py-2">
            <div class="small">
              <div><i class="bi bi-display"></i> <?= htmlspecialchars(user_agent_label($s['user_agent'])) ?><?= $isCurrent ? ' <span class="badge text-bg-info">this device</span>' : '' ?></div>
              <div class="text-muted"><?= htmlspecialchars($s['ip_address'] ?? '—') ?> · signed in <?= date('M j, g:i A', strtotime($s['created_at'])) ?> · last seen <?= date('M j, g:i A', strtotime($s['last_seen'])) ?></div>
            </div>
            <?php if (!$isCurrent): ?>
            <form method="post">
              <input type="hidden" name="form_action" value="revoke_session">
              <input type="hidden" name="user_id" value="<?= (int)$target['id'] ?>">
              <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
              <button class="btn btn-outline-danger btn-sm">Sign out</button>
            </form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <form method="post">
        <input type="hidden" name="form_action" value="revoke_all_sessions">
        <input type="hidden" name="user_id" value="<?= (int)$target['id'] ?>">
        <button class="btn btn-outline-secondary btn-sm w-100" onclick="return confirm('Sign out this user from all other devices?')">Sign out all other devices</button>
      </form>
      <?php else: ?>
        <p class="text-muted small mb-0">No active sessions.</p>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($canViewActivity): ?>
    <div class="card">
      <h3 style="font-size:16px;">Activity <span class="text-muted small">(<?= number_format($actTotal) ?>)</span></h3>
      <div class="audit-scroll">
        <ul class="audit-list">
          <?php foreach ($activity as $a):
            $detail = $a['detail'] ?? '';
            $detailShort = mb_strlen($detail) > 70 ? mb_substr($detail, 0, 70) . '…' : $detail;
          ?>
            <li>
              <time><?= htmlspecialchars(date('M j, Y g:i A', strtotime($a['created_at']))) ?></time>
              <span class="actor"><?= htmlspecialchars($a['username_snapshot'] ?? 'system') ?></span>
              <span><?= htmlspecialchars($a['module']) ?> — <?= htmlspecialchars($a['action']) ?><?= $detailShort ? ' — ' . htmlspecialchars($detailShort) : '' ?></span>
            </li>
          <?php endforeach; ?>
          <?php if (!$activity): ?><li class="text-muted small">No activity recorded for this user.</li><?php endif; ?>
        </ul>
      </div>
      <?php if ($actPages > 1): ?>
      <nav class="audit-pagination mt-2">
        <span class="text-muted small">page <?= $actPage ?> of <?= $actPages ?></span>
        <ul class="pagination pagination-sm mb-0">
          <li class="page-item <?= $actPage <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="user_view.php?id=<?= (int)$target['id'] ?>&actpage=<?= $actPage - 1 ?>">‹</a>
          </li>
          <?php for ($i = max(1, $actPage - 2); $i <= min($actPages, $actPage + 2); $i++): ?>
            <li class="page-item <?= $i === $actPage ? 'active' : '' ?>"><a class="page-link" href="user_view.php?id=<?= (int)$target['id'] ?>&actpage=<?= $i ?>"><?= $i ?></a></li>
          <?php endfor; ?>
          <li class="page-item <?= $actPage >= $actPages ? 'disabled' : '' ?>">
            <a class="page-link" href="user_view.php?id=<?= (int)$target['id'] ?>&actpage=<?= $actPage + 1 ?>">›</a>
          </li>
        </ul>
      </nav>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php endif; ?>
<script>
(function () {
  var input = document.getElementById('pw-new');
  var bar = document.getElementById('pw-strength-bar');
  var text = document.getElementById('pw-strength-text');
  if (!input || !bar || !text) return;
  input.addEventListener('input', function () {
    var v = this.value, score = 0;
    if (v.length >= 6) score++;
    if (v.length >= 10) score++;
    if (/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
    if (/[0-9]/.test(v)) score++;
    if (/[^A-Za-z0-9]/.test(v)) score++;
    var levels = [{w:'0%',c:'',t:''},{w:'20%',c:'bg-danger',t:'Very weak'},{w:'40%',c:'bg-warning',t:'Weak'},{w:'60%',c:'bg-info',t:'Fair'},{w:'80%',c:'bg-primary',t:'Strong'},{w:'100%',c:'bg-success',t:'Very strong'}];
    var l = levels[score] || levels[0];
    bar.style.width = l.w; bar.className = 'progress-bar ' + l.c;
    text.textContent = l.t;
  });
})();
</script>

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
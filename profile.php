<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/config/database.php';

require_login();
$user = current_user();
$pdo = get_db();

$errors = [];
$success = '';
$forceChange = !empty($user['must_change_password']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // At the very start of the POST handling (right after checking REQUEST_METHOD === 'POST' or form_action):
    if (!validate_csrf()) {
        $errors[] = 'Security token expired. Please refresh the page and try again.';
    }
    if (validate_csrf()) {
    $formAction = $_POST['form_action'] ?? '';

    if ($formAction === 'update_profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if ($fullName === '') {
            $errors[] = 'Full name is required.';
        } elseif ($email !== '') {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?');
            $stmt->execute([$email, $user['id']]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'That email is already in use.';
            }
        }
        if (!$errors) {
            $pdo->prepare('UPDATE users SET full_name = ?, email = ? WHERE id = ?')
                ->execute([$fullName, $email ?: null, $user['id']]);
            log_action('auth', 'updated_profile', $user['username']);
            global $__lrdms_current_user;
            $__lrdms_current_user = false; // re-resolve so the sidebar shows the new name
            $user = current_user();
            $success = 'Profile updated.';
        }

    } elseif ($formAction === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $hash = $stmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            $errors[] = 'Your current password is incorrect.';
        } elseif (strlen($new) < 6) {
            $errors[] = 'New password must be at least 6 characters long.';
        } elseif ($new !== $confirm) {
            $errors[] = 'New passwords do not match.';
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            log_action('auth', 'password_changed', $user['username']);
            global $__lrdms_current_user;
            $__lrdms_current_user = false; // drop the "must change" flag from the cached user
            $user = current_user();
            $forceChange = false;
            $success = 'Password changed.';
        }

    } elseif ($formAction === 'setup_2fa') {
        // Generate a secret and hold it in the session until a valid code confirms it.
        $_SESSION['2fa_pending_secret'] = totp_generate_secret();

    } elseif ($formAction === 'confirm_2fa') {
        $code = trim($_POST['totp_code'] ?? '');
        $pendingSecret = $_SESSION['2fa_pending_secret'] ?? '';
        if ($pendingSecret !== '' && verify_totp($pendingSecret, $code)) {
            $pdo->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?')
                ->execute([$pendingSecret, $user['id']]);
            unset($_SESSION['2fa_pending_secret']);
            log_action('auth', '2fa_enabled', $user['username']);
            global $__lrdms_current_user;
            $__lrdms_current_user = false;
            $user = current_user();
            $success = 'Two-factor authentication is now enabled.';
        } else {
            $errors[] = 'That code did not match. Check the time on your device and try again.';
        }

    } elseif ($formAction === 'disable_2fa') {
        $currentPassword = $_POST['current_password'] ?? '';
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $hash = $stmt->fetchColumn();

        if (!password_verify($currentPassword, $hash)) {
            $errors[] = 'Your current password is incorrect. 2FA was not disabled.';
        } else {
            $pdo->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?')
                ->execute([$user['id']]);
            log_action('auth', '2fa_disabled', $user['username']);
            global $__lrdms_current_user;
            $__lrdms_current_user = false;
            $user = current_user();
            $success = 'Two-factor authentication disabled.';
        }

    } elseif ($formAction === 'revoke_session') {
        $sid = (int)($_POST['session_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT session_token FROM user_sessions WHERE id = ? AND user_id = ?');
        $stmt->execute([$sid, $user['id']]);
        $token = $stmt->fetchColumn();
        if ($token && $token !== ($_SESSION['session_token'] ?? '')) {
            $pdo->prepare('UPDATE user_sessions SET is_active = 0 WHERE id = ?')->execute([$sid]);
            log_action('auth', 'revoked_session', 'user_id=' . $user['id'] . ' session_id=' . $sid);
            $success = 'Session signed out.';
        } else {
            $errors[] = 'You cannot sign out your current device here.';
        }

    } elseif ($formAction === 'revoke_all_sessions') {
        $pdo->prepare('UPDATE user_sessions SET is_active = 0 WHERE user_id = ? AND session_token <> ?')
            ->execute([$user['id'], $_SESSION['session_token'] ?? '']);
        log_action('auth', 'revoked_all_sessions', 'user_id=' . $user['id']);
        $success = 'All other devices were signed out.';
    }
    }
}

// Active sessions for the current user (fresh data after any POST).
$sessions = $pdo->prepare('SELECT * FROM user_sessions WHERE user_id = ? AND is_active = 1 ORDER BY created_at DESC');
$sessions->execute([$user['id']]);
$sessions = $sessions->fetchAll();

$pendingSecret = $_SESSION['2fa_pending_secret'] ?? '';

include __DIR__ . '/includes/layout_top.php';
?>
<div class="topbar">
  <div class="d-flex align-items-center gap-2">
    <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="Open menu">
      <i class="bi bi-list"></i>
    </button>
    <div>
      <h1 class="topbar__title">My Profile</h1>
    </div>
  </div>
</div>

<?php if ($forceChange): ?>
  <div class="alert alert-warning">
    <strong>You must set a new password to continue.</strong> Your password was set by an administrator — please choose your own before using the rest of the system.
  </div>
<?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card">
      <h3 style="font-size:16px;">Account details</h3>
      <form method="post">
        <?php csrf_field(); ?>
        <input type="hidden" name="form_action" value="update_profile">
        <div class="mb-2"><label class="form-label small">Full name</label><input type="text" name="full_name" class="form-control form-control-sm" value="<?= htmlspecialchars($user['full_name']) ?>" required></div>
        <div class="mb-2"><label class="form-label small">Email</label><input type="email" name="email" class="form-control form-control-sm" value="<?= htmlspecialchars($user['email'] ?? '') ?>"></div>
        <div class="mb-2">
          <label class="form-label small">Username</label>
          <input type="text" class="form-control form-control-sm" value="<?= htmlspecialchars($user['username']) ?>" disabled>
          <div class="form-text">Username cannot be changed. Contact an administrator if you need it changed.</div>
        </div>
        <div class="mb-2">
          <label class="form-label small">Role</label>
          <input type="text" class="form-control form-control-sm" value="<?= htmlspecialchars($user['role_name']) ?>" disabled>
        </div>
        <button class="btn btn-primary btn-sm w-100">Save profile</button>
      </form>
    </div>

    <div class="card">
      <h3 style="font-size:16px;">Change password</h3>
      <form method="post">
        <?php csrf_field(); ?>
        <input type="hidden" name="form_action" value="change_password">
        <div class="mb-2"><label class="form-label small">Current password</label><input type="password" name="current_password" class="form-control form-control-sm" required></div>
        <div class="mb-2">
          <label class="form-label small">New password</label>
          <input type="password" name="new_password" class="form-control form-control-sm" required minlength="6" id="pw-new">
          <div class="progress mt-1" style="height:4px;">
            <div class="progress-bar" id="pw-strength-bar" role="progressbar" style="width:0%"></div>
          </div>
          <div class="form-text" id="pw-strength-text"></div>
        </div>
        <div class="mb-3"><label class="form-label small">Confirm new password</label><input type="password" name="confirm_password" class="form-control form-control-sm" required minlength="6"></div>
        <button class="btn btn-warning btn-sm w-100">Change password</button>
      </form>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card">
      <h3 style="font-size:16px;">Two-factor authentication</h3>
      <?php if ($user['totp_enabled']): ?>
        <div class="d-flex justify-content-between align-items-center">
          <span class="small">Authenticator app: <span class="badge text-bg-success">Enabled</span></span>
        </div>
        <form method="post" onsubmit="return confirm('Disable two-factor authentication? Your account will only require a password.');">
          <?php csrf_field(); ?>
          <input type="hidden" name="form_action" value="disable_2fa">
          <div class="mb-2"><label class="form-label small">Confirm password to disable 2FA</label><input type="password" name="current_password" class="form-control form-control-sm" required></div>
          <button class="btn btn-outline-danger btn-sm w-100">Disable 2FA</button>
        </form>
        <p class="text-muted small mt-2 mb-0">On your next sign-in you will be asked for a 6-digit code from your authenticator app.</p>
      <?php elseif ($pendingSecret): ?>
        <?php $otpauth = totp_uri($user['username'], $pendingSecret); ?>
        <div class="text-center mb-3">
          <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?= rawurlencode($otpauth) ?>" alt="QR code" class="img-thumbnail" style="max-width:180px;">
          <p class="small text-muted mt-2 mb-0">Scan with Google Authenticator (or any TOTP app). If the QR won't load, enter the secret below manually:</p>
          <code class="d-inline-block mt-1 p-1 rounded bg-body-secondary"><?= htmlspecialchars($pendingSecret) ?></code>
        </div>
        <form method="post">
          <input type="hidden" name="form_action" value="confirm_2fa">
          <div class="mb-2"><label class="form-label small">Enter the 6-digit code from your app</label><input type="text" name="totp_code" class="form-control form-control-sm" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" required></div>
          <button class="btn btn-success btn-sm w-100">Verify &amp; enable</button>
          <?php csrf_field(); ?>
        </form>
      <?php else: ?>
        <form method="post">
          <?php csrf_field(); ?>
          <input type="hidden" name="form_action" value="setup_2fa">
          <p class="text-muted small">Add an extra layer of security with an authenticator app.</p>
          <button class="btn btn-primary btn-sm w-100">Set up 2FA</button>
        </form>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3 style="font-size:16px;">Active devices <span class="text-muted small">(<?= count($sessions) ?>)</span></h3>
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
              <?php csrf_field(); ?>
              <input type="hidden" name="form_action" value="revoke_session">
              <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
              <button class="btn btn-outline-danger btn-sm">Sign out</button>
            </form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <form method="post">
        <?php csrf_field(); ?>
        <input type="hidden" name="form_action" value="revoke_all_sessions">
        <button class="btn btn-outline-secondary btn-sm w-100" onclick="return confirm('Sign out from all other devices?')">Sign out all other devices</button>
      </form>
      <?php else: ?>
        <p class="text-muted small mb-0">No active sessions.</p>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3 style="font-size:16px;">Sign-in history</h3>
      <p class="small mb-1">Last login: <strong><?= $user['last_login_at'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($user['last_login_at']))) : 'never' ?></strong></p>
      <p class="text-muted small mb-0">Your activity is recorded in the system audit trail.</p>
    </div>
  </div>
</div>

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

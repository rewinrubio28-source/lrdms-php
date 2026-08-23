<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/config/database.php';

ensure_csrf_token();

// A password was already verified (attempt_login set this) — this page
// only completes the login after a valid TOTP code.
if (isset($_GET['cancel'])) {
    unset($_SESSION['2fa_user_id']);
    header('Location: public.php');
    exit;
}

$pendingUserId = $_SESSION['2fa_user_id'] ?? null;
$user = null;
if ($pendingUserId) {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
    $stmt->execute([$pendingUserId]);
    $user = $stmt->fetch();
}

$error = '';
$code = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $error = 'Security token expired. Please refresh the page and try again.';
    }
    if (validate_csrf()) {
    $code = trim($_POST['totp_code'] ?? '');

    if (!$user) {
        $error = 'No sign-in in progress. Please sign in again.';
    } elseif (verify_totp($user['totp_secret'], $code)) {
        complete_login($user);
        log_action('auth', 'login_2fa_complete', $user['username']);
        $redirect = !empty(current_user()['must_change_password']) ? 'profile.php?force=1' : 'dashboard.php';
        header('Location: ' . $redirect);
        exit;
    } else {
        log_action('auth', '2fa_failed', $user['username']);
        $error = 'That code did not match. Check the time on your device and try again.';
    }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <title>Two-Factor Verification - LRDMS</title>
  <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

  <!-- Favicons -->
  <link href="assets/img/favicon.png" rel="icon">
  <link href="assets/img/apple-touch-icon.png" rel="apple-touch-icon">

  <!-- Fonts -->
  <link href="https://fonts.googleapis.com" rel="preconnect">
  <link href="https://fonts.gstatic.com" rel="preconnect" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Open+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700;1,800&family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&family=Jost:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap" rel="stylesheet">

  <!-- Vendor CSS Files -->
  <link href="Arsha/assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="Arsha/assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">

  <!-- Main CSS File -->
  <link href="Arsha/assets/css/main.css" rel="stylesheet">

  <style>
    :root {
      --arsha-heading: #37517e;
      --arsha-accent: #47b2e4;
      --arsha-default: #444444;
      --lrdms-navy: #37517e;
      --lrdms-navy-dark: #2a3f63;
      --lrdms-gold: #c9a227;
      --lrdms-gold-light: #e0c15a;
    }
    body {
      background-image: url('Arsha/assets/img/bg.jpg') !important;
      background-size: cover !important;
      background-position: center !important;
      background-attachment: fixed !important;
      background-repeat: no-repeat !important;
      background-color: transparent !important;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .verify-card {
      background: #fff;
      border-radius: 20px;
      box-shadow: 0 20px 50px rgba(0, 0, 0, 0.25);
      max-width: 430px;
      width: 100%;
      margin: 20px;
      overflow: hidden;
    }
    .verify-card-header {
      background: linear-gradient(160deg, var(--lrdms-navy) 0%, var(--lrdms-navy-dark) 100%);
      padding: 28px;
      text-align: center;
      color: #fff;
    }
    .verify-card-header i { font-size: 42px; margin-bottom: 12px; display: block; color: var(--lrdms-gold-light); }
    .verify-card-header h4 { margin: 0; font-weight: 700; }
    .verify-card-body { padding: 30px; }
    .form-label { font-weight: 600; font-size: 13.5px; color: var(--lrdms-navy); }
    .form-control { border-radius: 10px; padding: 10px 15px; }
    .form-control:focus { border-color: var(--lrdms-navy); box-shadow: 0 0 0 0.2rem rgba(55, 81, 126, 0.15); }
    .btn-verify-submit {
      background: linear-gradient(135deg, var(--lrdms-gold) 0%, var(--lrdms-gold-light) 100%);
      border: none;
      color: #fff;
      font-weight: 600;
      border-radius: 10px;
      padding: 12px 0;
      letter-spacing: 0.3px;
      transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .btn-verify-submit:hover { transform: translateY(-1px); box-shadow: 0 8px 18px rgba(201, 162, 39, 0.35); color: #fff; }
    .code-input {
      text-align: center;
      font-size: 26px;
      letter-spacing: 10px;
      font-family: 'Poppins', monospace;
    }
  </style>
</head>

<body>

  <div class="verify-card">
    <div class="verify-card-header">
      <i class="bi bi-shield-lock"></i>
      <h4>Two-Factor Verification</h4>
    </div>
    <div class="verify-card-body">
      <?php if (!$user): ?>
        <p class="text-muted mb-4 small">No sign-in is in progress, or the account is no longer active. Please sign in again to continue.</p>
        <a href="public.php" class="btn btn-verify-submit w-100"><i class="bi bi-box-arrow-in-right me-1"></i> Go to Sign In</a>
      <?php else: ?>
        <p class="text-muted mb-3 small">
          Hello <strong><?= htmlspecialchars($user['username']) ?></strong>. Enter the 6-digit code from your authenticator app to finish signing in.
        </p>

        <?php if ($error): ?>
          <div class="alert alert-danger d-flex align-items-center gap-2 py-2 small mb-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div><?= htmlspecialchars($error) ?></div>
          </div>
        <?php endif; ?>

        <form method="post" id="verifyForm">
          <?php csrf_field(); ?>
          <div class="mb-3">
            <label for="totpCode" class="form-label">Verification Code</label>
            <input type="text" id="totpCode" name="totp_code" class="form-control code-input" placeholder="______" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" value="<?= htmlspecialchars($code) ?>" required autofocus>
          </div>
          <button type="submit" class="btn btn-verify-submit w-100">
            <i class="bi bi-check2-circle me-1"></i> Verify &amp; Sign In
          </button>
        </form>

        <div class="text-center mt-4">
          <small class="text-muted">
            Lost your app? Contact your administrator to reset 2FA, or
            <a href="verify_2fa.php?cancel=1" class="text-decoration-none">use a different account</a>.
          </small>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <script src="Arsha/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script>
    // Auto-submit once a full 6-digit code is entered (nice-to-have).
    var codeInput = document.getElementById('totpCode');
    var verifyForm = document.getElementById('verifyForm');
    if (codeInput && verifyForm) {
      codeInput.addEventListener('input', function () {
        var v = this.value.replace(/[^0-9]/g, '');
        this.value = v;
        if (v.length === 6) verifyForm.submit();
      });
    }
  </script>

</body>

</html>

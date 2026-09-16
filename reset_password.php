<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit.php';

ensure_csrf_token();

$token = $_GET['token'] ?? '';
$error = '';
$success = false;

// Validate token first to check if it's valid
$user = null;
if ($token) {
    $user = validate_password_reset_token($token);
    if (!$user) {
        $error = 'Invalid or expired password reset token. Please request a new password reset link.';
    }
}

// Process password reset form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_submitted']) && $user) {
    if (!validate_csrf()) {
        $error = 'Security token expired. Please refresh the page and try again.';
    }
    if (validate_csrf()) {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (strlen($new_password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        if (reset_password($token, $new_password)) {
            log_action('auth', 'password_reset_complete', $user['username']);
            $success = true;
        } else {
            $error = 'Failed to reset password. Please try again.';
        }
    }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <title>Reset Password - LRDMS</title>
  <meta name="description" content="">
  <meta name="keywords" content="">
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
    .reset-card {
      background: #fff;
      border-radius: 20px;
      box-shadow: 0 20px 50px rgba(0, 0, 0, 0.25);
      max-width: 450px;
      width: 100%;
      margin: 20px;
      overflow: hidden;
    }
    .reset-card-header {
      background: linear-gradient(160deg, var(--lrdms-navy) 0%, var(--lrdms-navy-dark) 100%);
      padding: 30px;
      text-align: center;
      color: #fff;
    }
    .reset-card-header i {
      font-size: 48px;
      margin-bottom: 15px;
      display: block;
    }
    .reset-card-header h4 {
      margin: 0;
      font-weight: 700;
    }
    .reset-card-body {
      padding: 35px;
    }
    .form-label {
      font-weight: 600;
      font-size: 13.5px;
      color: var(--lrdms-navy);
    }
    .form-control {
      border-radius: 10px;
      padding: 10px 15px;
    }
    .form-control:focus {
      border-color: var(--lrdms-navy);
      box-shadow: 0 0 0 0.2rem rgba(55, 81, 126, 0.15);
    }
    .btn-reset-submit {
      background: linear-gradient(135deg, var(--lrdms-gold) 0%, var(--lrdms-gold-light) 100%);
      border: none;
      color: #fff;
      font-weight: 600;
      border-radius: 10px;
      padding: 12px 0;
      letter-spacing: 0.3px;
      transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .btn-reset-submit:hover {
      transform: translateY(-1px);
      box-shadow: 0 8px 18px rgba(201, 162, 39, 0.35);
      color: #fff;
    }
    .password-toggle {
      cursor: pointer;
    }
    .reset-success-icon {
      width: 80px;
      height: 80px;
      border-radius: 50%;
      background: rgba(25, 135, 84, 0.1);
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 20px;
    }
    .reset-success-icon i {
      font-size: 40px;
      color: #198754;
    }
  </style>

</head>

<body>

  <div class="reset-card">
    <?php if ($success): ?>
      <!-- Success State -->
      <div class="reset-card-header" style="background: linear-gradient(135deg, #198754 0%, #157347 100%);">
        <i class="bi bi-check-circle"></i>
        <h4>Password Reset!</h4>
      </div>
      <div class="reset-card-body text-center">
        <div class="reset-success-icon">
          <i class="bi bi-check-lg"></i>
        </div>
        <h5 class="mb-3" style="color: var(--lrdms-navy);">Password Updated Successfully</h5>
        <p class="text-muted mb-4">Your password has been reset. You can now sign in with your new password.</p>
        <a href="public.php" class="btn btn-reset-submit w-100">
          <i class="bi bi-box-arrow-in-right me-1"></i> Go to Sign In
        </a>
      </div>

    <?php elseif (!$token || $error): ?>
      <!-- Invalid Token State -->
      <div class="reset-card-header" style="background: linear-gradient(160deg, #dc3545 0%, #b02a37 100%);">
        <i class="bi bi-exclamation-circle"></i>
        <h4>Invalid Link</h4>
      </div>
      <div class="reset-card-body text-center">
        <div class="mb-4">
          <i class="bi bi-key" style="font-size: 48px; color: var(--lrdms-navy);"></i>
        </div>
        <h5 class="mb-3" style="color: var(--lrdms-navy);">Password Reset Failed</h5>
        <p class="text-muted mb-4"><?php echo htmlspecialchars($error); ?></p>
        <a href="public.php" class="btn btn-reset-submit w-100">
          <i class="bi bi-arrow-left me-1"></i> Back to Sign In
        </a>
      </div>

    <?php else: ?>
      <!-- Reset Form -->
      <div class="reset-card-header">
        <i class="bi bi-key"></i>
        <h4>Reset Password</h4>
      </div>
      <div class="reset-card-body">
        <p class="text-muted mb-4 small">
          Hello <strong><?php echo htmlspecialchars($user['username']); ?></strong>, enter and confirm your new password below.
        </p>

        <?php if ($error): ?>
          <div class="alert alert-danger d-flex align-items-center gap-2 py-2 small mb-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div><?php echo htmlspecialchars($error); ?></div>
          </div>
        <?php endif; ?>

        <form id="resetPasswordForm" method="post">
          <?php csrf_field(); ?>
          <input type="hidden" name="reset_submitted" value="1">
          <div class="mb-3">
            <label for="newPassword" class="form-label">New Password</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-lock"></i></span>
              <input type="password" id="newPassword" name="new_password" class="form-control" placeholder="Enter new password" required minlength="6">
              <button class="btn btn-outline-secondary password-toggle" type="button" id="toggleNewPassword">
                <i class="bi bi-eye"></i>
              </button>
            </div>
          </div>
          <div class="mb-4">
            <label for="confirmPassword" class="form-label">Confirm Password</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
              <input type="password" id="confirmPassword" name="confirm_password" class="form-control" placeholder="Confirm new password" required minlength="6">
              <button class="btn btn-outline-secondary password-toggle" type="button" id="toggleConfirmPassword">
                <i class="bi bi-eye"></i>
              </button>
            </div>
          </div>
          <button type="submit" class="btn btn-reset-submit w-100">
            <i class="bi bi-check2-circle me-1"></i> Reset Password
          </button>
        </form>

        <div class="text-center mt-4">
          <small class="text-muted">
            <a href="public.php" class="text-decoration-none">Back to Sign In</a>
          </small>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <script src="Arsha/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script>
    // Toggle password visibility
    function setupPasswordToggle(toggleBtnId, inputId) {
      var toggleBtn = document.getElementById(toggleBtnId);
      var passwordInput = document.getElementById(inputId);
      if (toggleBtn && passwordInput) {
        toggleBtn.addEventListener('click', function() {
          var isPassword = passwordInput.type === 'password';
          passwordInput.type = isPassword ? 'text' : 'password';
          this.querySelector('i').classList.toggle('bi-eye', !isPassword);
          this.querySelector('i').classList.toggle('bi-eye-slash', isPassword);
        });
      }
    }
    setupPasswordToggle('toggleNewPassword', 'newPassword');
    setupPasswordToggle('toggleConfirmPassword', 'confirmPassword');
  </script>

</body>

</html>

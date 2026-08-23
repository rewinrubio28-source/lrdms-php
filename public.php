<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit.php';

ensure_csrf_token();

if (current_user()) {
    header('Location: dashboard.php');
    exit;
}

// Published (finalized + public) documents for the "Published Laws & Legislative Records" landing section.
// `?type=` filters the section to one document type (set from the filter pills or URL).
require_once __DIR__ . '/config/database.php';
// Types allowed in the public section. 'Other' is intentionally excluded —
// it is too vague a label for public visitors and may expose misc. documents.
$publicTypes = ['Ordinance', 'Resolution', 'Committee Report', 'Minutes'];

// Public-facing labels for each stored doc_type (DB values stay unchanged).
$typeDisplayLabels = [
    'Ordinance'        => 'Ordinance',
    'Resolution'       => 'Resolution',
    'Committee Report' => 'Committee Report',
    'Minutes'          => 'Session Minutes',
];
// Plural heading form shown when the section is filtered to one type.
$typeHeadingLabels = [
    'Ordinance'        => 'Ordinances',
    'Resolution'       => 'Resolutions',
    'Committee Report' => 'Committee Reports',
    'Minutes'          => 'Session Minutes',
];

$typeFilter = $_GET['type'] ?? 'All';
$typeFilter = in_array($typeFilter, $publicTypes, true) ? $typeFilter : 'All';
$typeLabel = $typeHeadingLabels[$typeFilter] ?? $typeFilter . 's';

// Load all public documents; client-side JS handles type filtering (no page refresh).
$publicDocs = get_db()->query(
    "SELECT d.id, d.doc_number, d.title, d.doc_type, d.sponsor, d.enactment_date
     FROM documents d
     WHERE d.is_public = 1 AND d.status IN ('Enacted','Amended','Superseded')
       AND d.doc_type <> 'Other'
     ORDER BY d.enactment_date DESC, d.created_at DESC"
)->fetchAll();

$loginError = isset($_GET['login_error']) && $_GET['login_error'] == 1;

// Forgot password flow state
$forgotStep = 'email';
$forgotEmail = '';
$forgotCodeSent = false;
$forgotCodeError = '';
$forgotResetError = '';
$forgotResetSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['forgot_submitted'])) {
        if (!validate_csrf()) {
            $forgotCodeError = 'Security token expired. Please refresh the page and try again.';
        }
        if (validate_csrf()) {
        $forgotEmail = trim($_POST['reset_email'] ?? '');
        if ($forgotEmail !== '' && filter_var($forgotEmail, FILTER_VALIDATE_EMAIL)) {
            require_once __DIR__ . '/config/email.php';

            $codeData = generate_password_reset_code($forgotEmail);
            $forgotCodeSent = (bool)$codeData;

            if ($codeData) {
                $emailSubject = 'LRDMS Password Reset Code';
                $emailBody = '
                <html>
                <head>
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: #37517e; color: white; padding: 20px; text-align: center; }
                        .content { padding: 20px; background: #f9f9f9; }
                        .code-box { display: inline-block; padding: 16px 32px; background: #fff; border: 2px dashed #c9a227; border-radius: 10px; font-size: 32px; font-weight: bold; letter-spacing: 8px; color: #37517e; margin: 20px 0; }
                        .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
                    </style>
                </head>
                <body>
                    <div class="container">
                        <div class="header">
                            <h2>LRDMS Password Reset</h2>
                        </div>
                        <div class="content">
                            <p>Hello ' . htmlspecialchars($codeData['username']) . ',</p>
                            <p>We received a request to reset your password. Use the code below to verify your identity:</p>
                            <p style="text-align: center;">
                                <span class="code-box">' . htmlspecialchars($codeData['code']) . '</span>
                            </p>
                            <p>This code will expire in 10 minutes.</p>
                            <p>If you did not request a password reset, please ignore this email.</p>
                        </div>
                        <div class="footer">
                            <p>This is an automated message from LRDMS. Please do not reply to this email.</p>
                        </div>
                    </div>
                </body>
                </html>
                ';

                send_email($codeData['email'], $emailSubject, $emailBody);
                $_SESSION['forgot_email'] = $forgotEmail;
                $forgotStep = 'code';
            }

            log_action('auth', 'password_reset_request', $forgotEmail);
        }
        }
    } elseif (isset($_POST['forgot_code_submitted'])) {
        if (!validate_csrf()) {
            $forgotCodeError = 'Security token expired. Please refresh the page and try again.';
        }
        if (validate_csrf()) {
        // Use session email if available, otherwise fall back to POST
        $forgotEmail = $_SESSION['forgot_email'] ?? trim($_POST['reset_email'] ?? '');
        $forgotCode = trim($_POST['reset_code'] ?? '');

        if ($forgotEmail !== '' && $forgotCode !== '') {
            $userData = validate_password_reset_code($forgotEmail, $forgotCode);
            if ($userData) {
                $_SESSION['forgot_email'] = $forgotEmail;
                $_SESSION['forgot_code'] = $forgotCode;
                $forgotStep = 'reset';
            } else {
                $forgotCodeError = 'Invalid or expired code. Please try again.';
                $forgotStep = 'code';
            }
        } else {
            $forgotCodeError = 'Email or code is empty.';
            $forgotStep = 'code';
        }
        }
    } elseif (isset($_POST['forgot_reset_submitted'])) {
        if (!validate_csrf()) {
            $forgotResetError = 'Security token expired. Please refresh the page and try again.';
        }
        if (validate_csrf()) {
        // Use session email if available
        $forgotEmail = $_SESSION['forgot_email'] ?? trim($_POST['reset_email'] ?? '');
        $forgotCode = $_SESSION['forgot_code'] ?? trim($_POST['reset_code'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (strlen($newPassword) < 6) {
            $forgotResetError = 'Password must be at least 6 characters long.';
            $forgotStep = 'reset';
        } elseif ($newPassword !== $confirmPassword) {
            $forgotResetError = 'Passwords do not match.';
            $forgotStep = 'reset';
        } else {
            $result = reset_password_with_code($forgotEmail, $forgotCode, $newPassword);
            if ($result) {
                $forgotResetSuccess = true;
                $forgotStep = 'success';
                unset($_SESSION['forgot_email'], $_SESSION['forgot_code']);
                log_action('auth', 'password_reset_complete', $forgotEmail);
            } else {
                $forgotResetError = 'Failed to reset password. Please try again.';
                $forgotStep = 'reset';
            }
        }
        }
    }
} else {
    // On GET requests, restore forgot password state from session if available
    if (isset($_SESSION['forgot_email']) && isset($_SESSION['forgot_code'])) {
        $forgotEmail = $_SESSION['forgot_email'];
        $forgotStep = 'reset';
    } elseif (isset($_SESSION['forgot_email'])) {
        $forgotEmail = $_SESSION['forgot_email'];
        $forgotStep = 'code';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submitted'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Handle AJAX login request
    if (isset($_POST['ajax_login'])) {
        header('Content-Type: application/json');
        if (!validate_csrf()) {
            echo json_encode(['success' => false, 'message' => 'Security token expired. Please refresh and try again.']);
            exit;
        }

        if ($username !== '' && $password !== '') {
            $result = attempt_login($username, $password);

            if ($result === 'success') {
                log_action('auth', 'login', $username);
                $redirect = !empty(current_user()['must_change_password']) ? 'profile.php?force=1' : 'dashboard.php';
                echo json_encode(['success' => true, 'redirect' => $redirect]);
                exit;
            }
            if ($result === '2fa') {
                log_action('auth', 'login_2fa_pending', $username);
                echo json_encode(['success' => true, 'redirect' => 'verify_2fa.php']);
                exit;
            }
            if ($result === 'locked') {
                log_action('auth', 'login_blocked_locked', $username);
                echo json_encode(['success' => false, 'message' => 'Account temporarily locked after too many failed attempts. Try again later.']);
                exit;
            }
        }

        log_action('auth', 'failed_login', $username);
        echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
        exit;
    }

    // Regular form submission
    if (validate_csrf()) {
    if ($username !== '' && $password !== '') {
        $result = attempt_login($username, $password);

        if ($result === 'success') {
            log_action('auth', 'login', $username);
            $redirect = !empty(current_user()['must_change_password']) ? 'profile.php?force=1' : 'dashboard.php';
            header('Location: ' . $redirect);
            exit;
        }
        if ($result === '2fa') {
            log_action('auth', 'login_2fa_pending', $username);
            header('Location: verify_2fa.php');
            exit;
        }
        if ($result === 'locked') {
            log_action('auth', 'login_blocked_locked', $username);
            $loginError = true;
            $loginLocked = true;
        } else {
            $loginError = true;
            log_action('auth', 'failed_login', $username);
        }
    } else {
        $loginError = true;
        log_action('auth', 'failed_login', $username);
    }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <title>LRDMS - Legislative Records & Document Management System</title>
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
  <link href="Arsha/assets/vendor/aos/aos.css" rel="stylesheet">
  <link href="Arsha/assets/vendor/glightbox/css/glightbox.min.css" rel="stylesheet">
  <link href="Arsha/assets/vendor/swiper/swiper-bundle.min.css" rel="stylesheet">

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
      background-image:
        linear-gradient(165deg, rgba(55, 81, 126, 0.86) 0%, rgba(17, 45, 78, 0.92) 100%),
        url('Arsha/assets/img/bg.jpg') !important;
      background-size: cover !important;
      background-position: center !important;
      background-attachment: fixed !important;
      background-repeat: no-repeat !important;
      background-color: transparent !important;
      color: var(--arsha-default) !important;
    }
    section, .section {
      background-color: transparent !important;
    }
    /* About/Features/Process/Legislation/Contact now use Arsha's own
       .dark-background preset (added on each <section> below) instead of
       being forced white — so the tinted hero photo keeps showing through
       behind them via the transparent .section rule above, matching the
       reference site's continuous colored background instead of a plain
       white block starting at "About Us". */
    .sitename {
      color: var(--arsha-heading) !important;
    }
    /* Header sits transparent over the tinted photo (Arsha's built-in
       .index-page behavior) until the page is scrolled, so its title
       needs to stay white/legible against the navy-tinted image instead
       of the navy text used once other sections are on a white card. */
    #header .sitename {
      color: #ffffff !important;
    }
    .btn-getstarted, .btn-get-started {
      background: var(--arsha-heading);
      border-color: var(--arsha-heading);
      color: #ffffff !important;
    }
    .btn-getstarted:hover, .btn-get-started:hover {
      background: var(--arsha-accent);
      border-color: var(--arsha-accent);
      color: #ffffff !important;
    }
    .hero h1 {
      color: #ffffff !important;
      text-shadow: 2px 2px 0 #000000, -1px -1px 0 #000000, 1px -1px 0 #000000, -1px 1px 0 #000000, 1px 1px 0 #000000;
    }
    .hero p {
      color: #ffffff !important;
      text-shadow: 1px 1px 0 #000000, -1px -1px 0 #000000, 1px -1px 0 #000000, -1px 1px 0 #000000, 1px 1px 0 #000000;
    }
    /* Section + info-item headings now follow Arsha's own heading-color
       cascade (white, via .dark-background on each section below) instead
       of being forced navy — navy text on the tinted photo was unreadable. */
    .read-more {
      color: var(--arsha-accent) !important;
    }

    /* Hero: no gradient overlay, show pure bg.jpg */
    #hero {
      background-image: url('Arsha/assets/img/bg.jpg') !important;
      background-size: cover !important;
      background-position: center !important;
      background-attachment: fixed !important;
      background-repeat: no-repeat !important;
      background-color: transparent !important;
    }

    /* About, Features, Process, Contact now share the same dark
       tinted-photo background as the Legislation section (via
       .dark-background on each <section> below) instead of a plain
       white card — Home/#hero keeps its own pure bg.jpg, untouched. */

    /* Features and Process cards are informational only (no links) —
       kill the template's hover lift/movement animation on both. */
    #features .service-item,
    #features .service-item:hover,
    #process .steps-item,
    #process .steps-item:hover {
      transform: none !important;
      transition: none !important;
      cursor: default !important;
    }

    /* Login Modal - branded split panel */
    #loginModal .modal-dialog {
      max-width: 820px;
    }
    #loginModal .modal-content {
      border: none;
      border-radius: 20px;
      overflow: hidden;
      box-shadow: 0 20px 50px rgba(0, 0, 0, 0.25);
    }
    #loginModal .modal-body {
      padding: 0;
    }
    #loginModal .login-split {
      display: flex;
      min-height: 480px;
    }
    #loginModal .login-brand-panel {
      flex: 0 0 42%;
      background: linear-gradient(160deg, var(--lrdms-navy) 0%, var(--lrdms-navy-dark) 100%);
      color: #fff;
      padding: 45px 35px;
      position: relative;
      display: flex;
      flex-direction: column;
      justify-content: center;
      overflow: hidden;
    }
    #loginModal .login-brand-panel::before {
      content: "";
      position: absolute;
      width: 260px;
      height: 260px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.06);
      top: -90px;
      right: -90px;
    }
    #loginModal .login-brand-panel::after {
      content: "";
      position: absolute;
      width: 180px;
      height: 180px;
      border-radius: 50%;
      background: rgba(201, 162, 39, 0.15);
      bottom: -60px;
      left: -60px;
    }
    #loginModal .login-brand-icon {
      width: 62px;
      height: 62px;
      border-radius: 16px;
      background: rgba(255, 255, 255, 0.12);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 28px;
      color: var(--lrdms-gold-light);
      margin-bottom: 22px;
      position: relative;
      z-index: 1;
    }
    #loginModal .login-brand-panel h4 {
      font-family: var(--heading-font, "Jost", sans-serif);
      font-weight: 700;
      font-size: 26px;
      margin-bottom: 12px;
      position: relative;
      z-index: 1;
    }
    #loginModal .login-brand-panel p {
      font-size: 14.5px;
      line-height: 1.7;
      color: rgba(255, 255, 255, 0.8);
      position: relative;
      z-index: 1;
      margin-bottom: 0;
    }
    #loginModal .login-brand-badge {
      margin-top: 30px;
      display: inline-flex;
      align-items: center;
      gap: 10px;
      background: rgba(255, 255, 255, 0.08);
      border: 1px solid rgba(255, 255, 255, 0.15);
      border-radius: 12px;
      padding: 10px 14px;
      position: relative;
      z-index: 1;
      width: fit-content;
    }
    #loginModal .login-brand-badge i {
      color: var(--lrdms-gold-light);
      font-size: 18px;
    }
    #loginModal .login-brand-badge span {
      font-size: 12.5px;
      color: rgba(255, 255, 255, 0.85);
    }
    #loginModal .login-form-panel {
      flex: 1;
      padding: 45px 40px;
      position: relative;
    }
    #loginModal .login-form-panel .btn-close {
      position: absolute;
      top: 20px;
      right: 20px;
      z-index: 2;
    }
    #loginModal .login-form-panel h5 {
      font-family: var(--heading-font, "Jost", sans-serif);
      font-weight: 700;
      color: var(--lrdms-navy);
      font-size: 24px;
      margin-bottom: 6px;
    }
    #loginModal .login-form-panel .login-subtext {
      color: #6c757d;
      font-size: 14px;
      margin-bottom: 28px;
    }
    #loginModal .form-label {
      font-weight: 600;
      font-size: 13.5px;
      color: var(--lrdms-navy);
    }
    #loginModal .form-icon-input {
      position: relative;
    }
    #loginModal .form-icon-input .form-control-icon-left {
      position: absolute;
      left: 14px;
      top: 50%;
      transform: translateY(-50%);
      color: #8a94a6;
      font-size: 15px;
      pointer-events: none;
    }
    #loginModal .form-icon-input input.form-control {
      border: 1px solid #dee2e6;
      background: #f5f6f8;
      border-radius: 10px;
      padding: 10px 14px 10px 40px;
    }
    #loginModal .form-icon-input.has-toggle input.form-control {
      padding-right: 42px;
    }
    #loginModal .form-icon-input input.form-control:focus {
      box-shadow: none;
      border-color: var(--lrdms-navy);
      background: #fff;
    }
    #loginModal .form-icon-input.has-error input.form-control {
      border-color: #dc3545;
      background: #fff;
    }
    #loginModal .form-icon-input.has-error .form-control-icon-left {
      color: #dc3545;
    }
    #loginModal .toggle-password-btn {
      position: absolute;
      right: 12px;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      padding: 0;
      line-height: 1;
      color: #8a94a6;
      cursor: pointer;
    }
    #loginModal .toggle-password-btn:hover {
      color: var(--lrdms-navy);
    }
    #loginModal .field-error {
      display: flex;
      align-items: center;
      gap: 6px;
      color: #dc3545;
      font-size: 12.5px;
      margin-top: 6px;
    }
    #loginModal .lrdms-link {
      color: var(--lrdms-navy);
      font-size: 13px;
      text-decoration: none;
      font-weight: 600;
    }
    #loginModal .lrdms-link:hover {
      color: var(--lrdms-gold);
      text-decoration: underline;
    }
    #loginModal .alert-success-soft {
      background: #eaf7ee;
      border: 1px solid #bfe6c9;
      color: #1e7a3d;
    }
     #loginModal .btn-login-submit {
      background: linear-gradient(135deg, var(--lrdms-gold) 0%, var(--lrdms-gold-light) 100%);
      border: none;
      color: #fff;
      font-weight: 600;
      border-radius: 10px;
      padding: 11px 0;
      letter-spacing: 0.3px;
      transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    #loginModal .btn-login-submit:hover {
      transform: translateY(-1px);
      box-shadow: 0 8px 18px rgba(201, 162, 39, 0.35);
      color: #fff;
    }
    @media (max-width: 767.98px) {
      #loginModal .login-brand-panel {
        display: none;
      }
    }

    /* Published laws section */
    .law-card {
      background: #f6f8fc;
      border: 1px solid rgba(55, 81, 126, 0.18);
      border-radius: 14px;
      padding: 22px;
      width: 100%;
      box-shadow: 0 6px 18px rgba(55, 81, 126, 0.09);
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .law-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 14px 32px rgba(55, 81, 126, 0.18);
    }
    .law-card__type {
      display: inline-block;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.6px;
      text-transform: uppercase;
      color: var(--lrdms-navy);
      background: rgba(55, 81, 126, 0.08);
      border: 1px solid rgba(55, 81, 126, 0.18);
      padding: 4px 10px;
      border-radius: 999px;
      margin-bottom: 12px;
    }
    .law-card__title {
      color: var(--lrdms-navy);
      font-size: 16px;
      font-weight: 700;
      line-height: 1.35;
      margin: 0 0 8px;
    }
    .law-card__meta { color: #6b7690; font-size: 13px; margin: 0 0 6px; }
    .law-card__sponsor { color: #8a94a6; font-size: 12.5px; margin: 0 0 14px; }
    .law-card__read { color: var(--lrdms-gold); font-weight: 600; font-size: 13px; text-decoration: none; }
    .law-card__read:hover { color: var(--lrdms-navy); }
    .legis-filters {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      justify-content: center;
      margin: 18px auto 36px;
    }
    .legis-filter {
      display: inline-block;
      font-size: 12px;
      font-weight: 600;
      letter-spacing: 0.4px;
      color: var(--lrdms-navy);
      background: #fff;
      border: 1px solid rgba(55, 81, 126, 0.22);
      padding: 7px 18px;
      border-radius: 999px;
      text-decoration: none;
      transition: background 0.2s ease, color 0.2s ease, border-color 0.2s ease;
    }
    .legis-filter:hover,
    .legis-filter.is-active {
      color: #fff;
      background: var(--lrdms-navy);
      border-color: var(--lrdms-navy);
    }
    .legis-empty {
      padding: 48px 0 32px;
    }
    .legis-empty i {
      font-size: 48px;
      color: var(--lrdms-gold);
      opacity: 0.45;
      display: block;
      margin-bottom: 14px;
    }
    .legis-empty-msg {
      color: rgba(255, 255, 255, 0.75);
      font-size: 15px;
      margin: 0;
    }
    #legislation .text-muted {
      color: rgba(255, 255, 255, 0.75) !important;
    }
  </style>

</head>

<body class="index-page">

  <header id="header" class="header d-flex align-items-center fixed-top">
    <div class="container-fluid container-xl position-relative d-flex align-items-center">

      <a href="public.php" class="logo d-flex align-items-center me-auto">
        <h1 class="sitename">MANILA CITY HALL</h1>
      </a>

      <nav id="navmenu" class="navmenu">
        <ul>
          <li><a href="#hero" class="active">Home</a></li>
          <li><a href="#about">About</a></li>
          <li><a href="#features">Features</a></li>
          <li><a href="#process">Process</a></li>
          <li><a href="#legislation">Legislative Documents</a></li>
          <li><a href="#contact">Contact</a></li>
        </ul>
        <i class="mobile-nav-toggle d-xl-none bi bi-list"></i>
      </nav>

      <a class="btn-getstarted" href="#" data-bs-toggle="modal" data-bs-target="#loginModal">Sign In</a>

    </div>
  </header>

  <main class="main">

    <section id="hero" class="hero section">

      <div class="container">
        <div class="row gy-4">
          <div class="col-lg-6 order-2 order-lg-1 d-flex flex-column justify-content-center" data-aos="zoom-out">
            <h1>Legislative Records & Document Management System</h1>
            <p>A centralized platform for managing ordinances, resolutions, and legislative documents of the Lungsod ng Maynila.</p>
            <div class="d-flex">
              <a href="#about" class="btn-get-started">Learn More</a>
              <a href="#" class="btn-get-started" style="background: var(--lrdms-gold); border-color: var(--lrdms-gold);" data-bs-toggle="modal" data-bs-target="#loginModal">Sign In</a>
            </div>
          </div>
          <div class="col-lg-6 order-1 order-lg-2 hero-img" data-aos="zoom-out" data-aos-delay="200">
            <img src="Arsha/assets/img/manila logo.png" class="img-fluid animated" alt="Lungsod ng Maynila Logo">
          </div>
        </div>
      </div>

    </section>

    <section id="about" class="about section dark-background">

      <div class="container section-title" data-aos="fade-up">
        <h2>About Us</h2>
      </div>

      <div class="container">
        <div class="row gy-4">
          <div class="col-lg-6 content" data-aos="fade-up" data-aos-delay="100">
            <p>
              The Legislative Records and Document Management System (LRDMS) is designed to streamline the management of legislative documents within the Lungsod ng Maynila.
            </p>
            <ul>
              <li><i class="bi bi-check2-circle"></i> <span>Centralized repository for all legislative documents</span></li>
              <li><i class="bi bi-check2-circle"></i> <span>Streamlined encoding and submission workflow</span></li>
              <li><i class="bi bi-check2-circle"></i> <span>Version control for document tracking</span></li>
              <li><i class="bi bi-check2-circle"></i> <span>Advanced search and retrieval capabilities</span></li>
            </ul>
          </div>
          <div class="col-lg-6" data-aos="fade-up" data-aos-delay="200">
            <p>Our system provides a secure and efficient way to manage ordinances, resolutions, committee reports, and other legislative documents. With role-based access control, we ensure that sensitive documents are only accessible to authorized personnel.</p>
            <a href="#features" class="read-more"><span>Explore Features</span><i class="bi bi-arrow-right"></i></a>
          </div>
        </div>
      </div>

    </section>

    <section id="features" class="services section dark-background">

      <div class="container section-title" data-aos="fade-up">
        <h2>Features</h2>
        <p>Comprehensive tools for legislative document management</p>
      </div>

      <div class="container">
        <div class="row gy-4">
          <div class="col-xl-3 col-md-6 d-flex" data-aos="fade-up" data-aos-delay="100">
            <div class="service-item position-relative">
              <div class="icon"><i class="bi bi-file-earmark-text icon"></i></div>
              <h4>Document Encoding</h4>
              <p>Easy-to-use form for creating and submitting new legislative documents</p>
            </div>
          </div>
          <div class="col-xl-3 col-md-6 d-flex" data-aos="fade-up" data-aos-delay="200">
            <div class="service-item position-relative">
              <div class="icon"><i class="bi bi-folder icon"></i></div>
              <h4>Repository</h4>
              <p>Centralized storage for all ordinances, resolutions, and committee reports</p>
            </div>
          </div>
          <div class="col-xl-3 col-md-6 d-flex" data-aos="fade-up" data-aos-delay="300">
            <div class="service-item position-relative">
              <div class="icon"><i class="bi bi-search icon"></i></div>
              <h4>Advanced Search</h4>
              <p>Find documents quickly with powerful search and filtering options</p>
            </div>
          </div>
          <div class="col-xl-3 col-md-6 d-flex" data-aos="fade-up" data-aos-delay="400">
            <div class="service-item position-relative">
              <div class="icon"><i class="bi bi-clock-history icon"></i></div>
              <h4>Version Control</h4>
              <p>Track all changes and revisions made to each document</p>
            </div>
          </div>
        </div>
      </div>

    </section>

    <section id="process" class="work-process section dark-background">

      <div class="container section-title" data-aos="fade-up">
        <h2>How It Works</h2>
        <p>Simple workflow for legislative document management</p>
      </div>

      <div class="container" data-aos="fade-up" data-aos-delay="100">
        <div class="row gy-5">
          <div class="col-lg-4" data-aos="fade-up" data-aos-delay="200">
            <div class="steps-item">
              <div class="steps-content">
                <div class="steps-number">01</div>
                <h3>Create Document</h3>
                <p>Authorized users can create new legislative documents using the encoding form with all necessary details.</p>
              </div>
            </div>
          </div>
          <div class="col-lg-4" data-aos="fade-up" data-aos-delay="300">
            <div class="steps-item">
              <div class="steps-content">
                <div class="steps-number">02</div>
                <h3>Review & Approval</h3>
                <p>Documents go through a review process where authorized personnel can approve, request changes, or reject.</p>
              </div>
            </div>
          </div>
          <div class="col-lg-4" data-aos="fade-up" data-aos-delay="400">
            <div class="steps-item">
              <div class="steps-content">
                <div class="steps-number">03</div>
                <h3>Publication</h3>
                <p>Once enacted, documents become part of the public repository and can be searched by authorized users.</p>
              </div>
            </div>
          </div>
        </div>
      </div>

    </section>

    <section id="legislation" class="legislation section dark-background">

      <div class="container section-title" data-aos="fade-up">
        <h2><?= $typeFilter !== 'All' ? htmlspecialchars($typeLabel) : 'Published Laws &amp; Legislative Records' ?></h2>
        <p>Read the city's enacted ordinances, resolutions, and legislative records</p>

        <div class="legis-filters">
          <a href="#" class="legis-filter<?= $typeFilter === 'All' ? ' is-active' : '' ?>" data-type="All">All</a>
          <?php foreach ($publicTypes as $t): ?>
            <a href="#" class="legis-filter<?= $typeFilter === $t ? ' is-active' : '' ?>" data-type="<?= $t ?>"><?= htmlspecialchars($typeDisplayLabels[$t] ?? $t) ?></a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="container">
        <div class="row gy-4">
          <?php if (!$publicDocs): ?>
            <div class="col-12 text-center" data-aos="fade-up">
              <p class="text-muted"><?= $typeFilter !== 'All' ? 'No published ' . htmlspecialchars(strtolower($typeDisplayLabels[$typeFilter] ?? $typeFilter)) . ' documents yet.' : 'Published laws will appear here once available.' ?></p>
            </div>
          <?php else: ?>
            <?php foreach ($publicDocs as $i => $doc): ?>
              <div class="col-lg-4 col-md-6 d-flex law-card-wrap" data-type="<?= htmlspecialchars($doc['doc_type']) ?>" data-aos="fade-up" data-aos-delay="<?= (($i % 3) + 1) * 100 ?>">
                <article class="law-card position-relative">
                  <span class="law-card__type"><?= htmlspecialchars($typeDisplayLabels[$doc['doc_type']] ?? $doc['doc_type']) ?></span>
                  <h4 class="law-card__title"><?= htmlspecialchars($doc['title']) ?></h4>
                  <p class="law-card__meta"><?= htmlspecialchars($doc['doc_number']) ?> · Enacted <?= $doc['enactment_date'] ? date('M j, Y', strtotime($doc['enactment_date'])) : '—' ?></p>
                  <?php if (!empty($doc['sponsor'])): ?><p class="law-card__sponsor">Sponsored by <?= htmlspecialchars($doc['sponsor']) ?></p><?php endif; ?>
                  <a href="public_view.php?id=<?= (int)$doc['id'] ?>" class="law-card__read stretched-link">Read document →</a>
                </article>
              </div>
            <?php endforeach; ?>
            <!-- empty state — JS shows this when no cards match the active filter -->
            <div class="col-12 text-center legis-empty" style="display:none;">
              <i class="bi bi-inbox"></i>
              <p class="legis-empty-msg"></p>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section id="contact" class="contact section dark-background">

      <div class="container section-title" data-aos="fade-up">
        <h2>Contact</h2>
        <p>For inquiries and support</p>
      </div>

      <div class="container" data-aos="fade-up" data-aos-delay="100">
        <div class="row gy-4 justify-content-center">
          <div class="col-lg-6">
            <div class="info-wrap">
              <div class="info-item d-flex" data-aos="fade-up" data-aos-delay="200">
                <i class="bi bi-geo-alt flex-shrink-0"></i>
                <div>
                  <h3>Location</h3>
                  <p>Manila City Hall, Padre Burgos Avenue<br>Ermita, Manila 1000, Metro Manila</p>
                </div>
              </div>
              <div class="info-item d-flex" data-aos="fade-up" data-aos-delay="300">
                <i class="bi bi-envelope flex-shrink-0"></i>
                <div>
                  <h3>Email</h3>
                  <p>info@manila.gov.ph</p>
                </div>
              </div>
              <div class="info-item d-flex" data-aos="fade-up" data-aos-delay="400">
                <i class="bi bi-phone flex-shrink-0"></i>
                <div>
                  <h3>Phone</h3>
                  <p>(02) 8527-5768</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

    </section>

  </main>

  <footer id="footer" class="footer">

    <div class="container footer-top">
      <div class="row gy-4">
        <div class="col-lg-4 col-md-6 footer-about">
          <a href="public.php" class="d-flex align-items-center">
            <span class="sitename">LRDMS</span>
          </a>
          <div class="footer-contact pt-3">
            <p>Manila City Hall</p>
            <p>Intramuros, Manila</p>
            <p class="mt-3"><strong>Email:</strong> <span>info@manila.gov.ph</span></p>
          </div>
        </div>
        <div class="col-lg-2 col-md-3 footer-links">
          <h4>Quick Links</h4>
          <ul>
            <li><i class="bi bi-chevron-right"></i> <a href="#hero">Home</a></li>
            <li><i class="bi bi-chevron-right"></i> <a href="#about">About</a></li>
            <li><i class="bi bi-chevron-right"></i> <a href="#features">Features</a></li>
            <li><i class="bi bi-chevron-right"></i> <a href="#legislation">Legislative Documents</a></li>
            <li><i class="bi bi-chevron-right"></i> <a href="#contact">Contact</a></li>
          </ul>
        </div>
        <div class="col-lg-2 col-md-3 footer-links">
          <h4>System Access</h4>
          <ul>
            <li><i class="bi bi-chevron-right"></i> <a href="#" data-bs-toggle="modal" data-bs-target="#loginModal">Sign In</a></li>
          </ul>
        </div>
      </div>
    </div>

    <div class="container copyright text-center mt-4">
      <p>© <span>Copyright</span> <strong class="px-1 sitename">LRDMS</strong> <span>All Rights Reserved</span></p>
    </div>

  </footer>

  <a href="#" id="scroll-top" class="scroll-top d-flex align-items-center justify-content-center"><i class="bi bi-arrow-up-short"></i></a>

  <div id="preloader"></div>

  <script src="Arsha/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="Arsha/assets/vendor/php-email-form/validate.js"></script>
  <script src="Arsha/assets/vendor/aos/aos.js"></script>
  <script src="Arsha/assets/vendor/glightbox/js/glightbox.min.js"></script>
  <script src="Arsha/assets/vendor/swiper/swiper-bundle.min.js"></script>
  <script src="Arsha/assets/vendor/waypoints/noframework.waypoints.js"></script>
  <script src="Arsha/assets/vendor/imagesloaded/imagesloaded.pkgd.min.js"></script>
  <script src="Arsha/assets/vendor/isotope-layout/isotope.pkgd.min.js"></script>
  <script src="Arsha/assets/js/main.js"></script>

  <!-- Legislation filter pills (client-side, no page refresh) -->
  <script>
  (function () {
    var headingLabels = <?= json_encode($typeHeadingLabels) ?>;
    var pills     = document.querySelectorAll('#legislation .legis-filter');
    var cards     = document.querySelectorAll('#legislation .law-card-wrap');
    var heading   = document.querySelector('#legislation .section-title h2');
    var emptyWrap = document.querySelector('#legislation .legis-empty');
    var emptyMsg  = document.querySelector('#legislation .legis-empty-msg');

    function applyFilter(type) {
      var anyVisible = false;

      // show / hide cards (need !important to override Bootstrap .d-flex)
      cards.forEach(function (card) {
        if (type === 'All' || card.getAttribute('data-type') === type) {
          card.style.removeProperty('display');
          anyVisible = true;
        } else {
          card.style.setProperty('display', 'none', 'important');
        }
      });

      // toggle active pill
      pills.forEach(function (p) {
        p.classList.toggle('is-active', p.getAttribute('data-type') === type);
      });

      // update heading
      heading.textContent = (type === 'All')
        ? 'Published Laws & Legislative Records'
        : (headingLabels[type] || type + 's');

      // empty state
      if (emptyWrap) {
        if (anyVisible) {
          emptyWrap.style.display = 'none';
        } else {
          var label = (type === 'All') ? 'documents' : (headingLabels[type] || type).toLowerCase();
          emptyMsg.textContent = 'No published ' + label + ' available yet.';
          emptyWrap.style.display = '';
        }
      }

      // sync URL without reload
      var url = new URL(window.location);
      if (type === 'All') { url.searchParams.delete('type'); }
      else                { url.searchParams.set('type', type); }
      history.replaceState(null, '', url);
    }

    // pill click handlers
    pills.forEach(function (pill) {
      pill.addEventListener('click', function (e) {
        e.preventDefault();
        applyFilter(this.getAttribute('data-type'));
      });
    });

    // apply initial filter on page load (handles ?type=… in URL)
    var initialType = new URLSearchParams(window.location.search).get('type') || 'All';
    applyFilter(initialType);
  })();
  </script>

  <div class="modal fade" id="loginModal" tabindex="-1" aria-labelledby="loginModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-body">
          <div class="login-split">
            <div class="login-brand-panel">
              <div class="login-brand-icon" style="background: transparent; padding: 0;">
                <img src="Arsha/assets/img/manila logo.png" alt="Manila City Hall" style="width: 50px; height: 50px; object-fit: contain;">
              </div>
              <h4>LRDMS</h4>
              <p>Legal Records &amp; Document Management System for Manila City Hall. Sign in to securely access case files and records.</p>
              <div class="login-brand-badge">
                <i class="bi bi-shield-lock-fill"></i>
                <span>Role-based, audited access</span>
              </div>
            </div>
            <div class="login-form-panel">
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>

              <div id="signInView">
                <h5 id="loginModalLabel">Welcome</h5>
                <div class="login-subtext">Sign in to continue to your dashboard.</div>

                <div id="loginErrorAlert" class="alert alert-danger d-flex align-items-center gap-2 py-2 small mb-3 d-none" role="alert">
                  <i class="bi bi-exclamation-triangle-fill"></i>
                  <div id="loginErrorMessage">Invalid username or password.</div>
                </div>

                <form id="loginForm" method="post" novalidate>
                  <?php csrf_field(); ?>
                  <input type="hidden" name="login_submitted" value="1">
                  <div class="mb-3">
                    <label for="modalUsername" class="form-label">Username</label>
                    <div class="form-icon-input" id="usernameFieldWrap">
                      <i class="bi bi-person form-control-icon-left"></i>
                      <input type="text" id="modalUsername" name="username" class="form-control" placeholder="Enter your username" required>
                    </div>
                    <div class="field-error d-none" id="usernameError"><i class="bi bi-exclamation-circle-fill"></i><span>Username is required.</span></div>
                  </div>
                  <div class="mb-3">
                    <label for="modalPassword" class="form-label">Password</label>
                    <div class="form-icon-input has-toggle" id="passwordFieldWrap">
                      <i class="bi bi-lock form-control-icon-left"></i>
                      <input type="password" id="modalPassword" name="password" class="form-control" placeholder="Enter your password" required>
                      <button class="toggle-password-btn" type="button" id="togglePassword" aria-label="Toggle password visibility">
                        <i class="bi bi-eye"></i>
                      </button>
                    </div>
                    <div class="field-error d-none" id="passwordError"><i class="bi bi-exclamation-circle-fill"></i><span>Password is required.</span></div>
                  </div>
                  <div class="d-flex align-items-center justify-content-between mb-3">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" id="modalRemember" name="remember">
                      <label class="form-check-label small text-muted" for="modalRemember">Remember me</label>
                    </div>
                    <a href="#" id="showForgotPassword" class="lrdms-link">Forgot password?</a>
                  </div>
                  <button type="submit" class="btn btn-login-submit w-100">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
                  </button>
                </form>

                <div class="text-center mt-3">
                  <small class="text-muted">Contact the administrator for account credentials.</small>
                </div>
              </div>

              <div id="forgotPasswordView" class="d-none">
                <h5>Reset password</h5>

                <div id="forgotStepEmail">
                  <div class="login-subtext">Enter your email and we'll send you a reset code.</div>

                  <div class="alert alert-success-soft d-flex align-items-center gap-2 py-2 small mb-3 d-none" id="resetSuccessAlert" role="alert">
                    <i class="bi bi-check-circle-fill"></i>
                    <div>If an account exists for that email, a reset code has been sent.</div>
                  </div>

                  <form id="forgotPasswordForm" method="post">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="forgot_submitted" value="1">
                    <div class="mb-3">
                      <label for="resetEmail" class="form-label">Email</label>
                      <div class="form-icon-input" id="resetEmailFieldWrap">
                        <i class="bi bi-envelope form-control-icon-left"></i>
                        <input type="email" id="resetEmail" name="reset_email" class="form-control" placeholder="name@example.com" required>
                      </div>
                      <div class="field-error d-none" id="resetEmailError"><i class="bi bi-exclamation-circle-fill"></i><span>Email is required.</span></div>
                    </div>
                    <button type="submit" class="btn btn-login-submit w-100">Send reset code</button>
                  </form>
                </div>

                <div id="forgotStepCode" class="d-none">
                  <div class="login-subtext">Enter the 6-digit code sent to your email.</div>

                  <div class="alert alert-danger d-flex align-items-center gap-2 py-2 small mb-3 d-none" id="resetCodeAlert" role="alert">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <div id="resetCodeAlertText">Invalid code.</div>
                  </div>

                  <form id="forgotCodeForm" method="post">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="forgot_code_submitted" value="1">
                    <input type="hidden" name="reset_email" id="forgotCodeEmail" value="<?php echo htmlspecialchars($forgotEmail); ?>">
                    <div class="mb-3">
                      <label for="resetCode" class="form-label">Reset Code</label>
                      <div class="form-icon-input" id="resetCodeFieldWrap">
                        <i class="bi bi-key form-control-icon-left"></i>
                        <input type="text" id="resetCode" name="reset_code" class="form-control" placeholder="123456" maxlength="6" required>
                      </div>
                      <div class="field-error d-none" id="resetCodeError"><i class="bi bi-exclamation-circle-fill"></i><span>Code is required.</span></div>
                    </div>
                    <button type="submit" class="btn btn-login-submit w-100">Verify code</button>
                  </form>
                </div>

                <div id="forgotStepReset" class="d-none">
                  <div class="login-subtext">Enter your new password below.</div>

                  <div class="alert alert-danger d-flex align-items-center gap-2 py-2 small mb-3 d-none" id="resetErrorAlert" role="alert">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <div id="resetErrorAlertText"></div>
                  </div>

                  <form id="forgotResetForm" method="post">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="forgot_reset_submitted" value="1">
                    <input type="hidden" name="reset_email" id="forgotResetEmail" value="<?php echo htmlspecialchars($forgotEmail); ?>">
                    <input type="hidden" name="reset_code" id="forgotResetCode" value="<?php echo htmlspecialchars($_SESSION['forgot_code'] ?? ''); ?>">
                    <div class="mb-3">
                      <label for="newPassword" class="form-label">New Password</label>
                      <div class="form-icon-input has-toggle" id="newPasswordFieldWrap">
                        <i class="bi bi-lock form-control-icon-left"></i>
                        <input type="password" id="newPassword" name="new_password" class="form-control" placeholder="Enter new password" required minlength="6">
                        <button class="toggle-password-btn" type="button" id="toggleNewPassword" aria-label="Toggle password visibility">
                          <i class="bi bi-eye"></i>
                        </button>
                      </div>
                      <div class="field-error d-none" id="newPasswordError"><i class="bi bi-exclamation-circle-fill"></i><span>Password is required.</span></div>
                    </div>
                    <div class="mb-3">
                      <label for="confirmPassword" class="form-label">Confirm Password</label>
                      <div class="form-icon-input has-toggle" id="confirmPasswordFieldWrap">
                        <i class="bi bi-lock-fill form-control-icon-left"></i>
                        <input type="password" id="confirmPassword" name="confirm_password" class="form-control" placeholder="Confirm new password" required minlength="6">
                        <button class="toggle-password-btn" type="button" id="toggleConfirmPassword" aria-label="Toggle password visibility">
                          <i class="bi bi-eye"></i>
                        </button>
                      </div>
                      <div class="field-error d-none" id="confirmPasswordError"><i class="bi bi-exclamation-circle-fill"></i><span>Please confirm your password.</span></div>
                    </div>
                    <button type="submit" class="btn btn-login-submit w-100">Reset Password</button>
                  </form>
                </div>

                <div id="forgotStepSuccess" class="d-none text-center">
                  <div class="reset-success-icon">
                    <i class="bi bi-check-lg"></i>
                  </div>
                  <h5 class="mb-3" style="color: var(--lrdms-navy);">Password Updated Successfully</h5>
                  <p class="text-muted mb-4">Your password has been reset. You can now sign in with your new password.</p>
                  <button type="button" class="btn btn-login-submit w-100" id="goToSignInAfterReset">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Go to Sign In
                  </button>
                </div>

                <div class="text-center mt-3" id="forgotBackLink">
                  <small class="text-muted">Remember your password? <a href="#" id="showSignIn" class="lrdms-link">Sign in</a></small>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

   <script>
     // Clear hash and login_error query param on page load so modal doesn't auto-open
     if (window.location.hash || window.location.search.includes('login_error')) {
       var url = new URL(window.location.href);
       url.searchParams.delete('login_error');
       url.searchParams.delete('ajax_login');
       history.replaceState({}, document.title, url.toString());
     }

     // Clear login error when modal is closed
     var loginModalEl = document.getElementById('loginModal');
     var loginErrorAlert = document.getElementById('loginErrorAlert');
     if (loginModalEl && loginErrorAlert) {
       loginModalEl.addEventListener('hidden.bs.modal', function() {
         loginErrorAlert.classList.add('d-none');
         // Clear form fields
         var usernameInput = document.getElementById('modalUsername');
         var passwordInput = document.getElementById('modalPassword');
         if (usernameInput) usernameInput.value = '';
         if (passwordInput) passwordInput.value = '';
         // Clear field errors
         setFieldError('usernameFieldWrap', 'usernameError', false);
         setFieldError('passwordFieldWrap', 'passwordError', false);
       });
     }

     // Toggle password visibility in login modal
     var toggleBtn = document.getElementById('togglePassword');
     var passwordInput = document.getElementById('modalPassword');
     if (toggleBtn && passwordInput) {
       toggleBtn.addEventListener('click', function() {
         var isPassword = passwordInput.type === 'password';
         passwordInput.type = isPassword ? 'text' : 'password';
         this.querySelector('i').classList.toggle('bi-eye', !isPassword);
         this.querySelector('i').classList.toggle('bi-eye-slash', isPassword);
       });
     }

     // Helper to show/hide a field error state
     function setFieldError(wrapId, errorId, show, message) {
       var wrap = document.getElementById(wrapId);
       var err = document.getElementById(errorId);
       if (!wrap || !err) return;
       if (show) {
         wrap.classList.add('has-error');
         if (message) { err.querySelector('span').textContent = message; }
         err.classList.remove('d-none');
       } else {
         wrap.classList.remove('has-error');
         err.classList.add('d-none');
       }
     }

      // Switch between Sign In and Forgot Password views
      var signInView = document.getElementById('signInView');
      var forgotView = document.getElementById('forgotPasswordView');
      var showForgotBtn = document.getElementById('showForgotPassword');
      var showSignInBtn = document.getElementById('showSignIn');

      function goToForgotView() {
        signInView.classList.add('d-none');
        forgotView.classList.remove('d-none');
        showForgotStep('email');
      }
      function goToSignInView() {
        forgotView.classList.add('d-none');
        signInView.classList.remove('d-none');
        showForgotStep('email');
      }
      if (showForgotBtn) {
        showForgotBtn.addEventListener('click', function(e) {
          e.preventDefault();
          goToForgotView();
        });
      }
      if (showSignInBtn) {
        showSignInBtn.addEventListener('click', function(e) {
          e.preventDefault();
          goToSignInView();
        });
      }
      var loginModalEl = document.getElementById('loginModal');
      if (loginModalEl) {
        loginModalEl.addEventListener('hidden.bs.modal', goToSignInView);
      }

     // Sign in form validation and AJAX submission
     var loginForm = document.getElementById('loginForm');
     if (loginForm) {
       loginForm.addEventListener('submit', function(e) {
         var username = document.getElementById('modalUsername');
         var password = document.getElementById('modalPassword');
         var valid = true;
         if (!username.value.trim()) {
           setFieldError('usernameFieldWrap', 'usernameError', true, 'Username is required.');
           valid = false;
         } else {
           setFieldError('usernameFieldWrap', 'usernameError', false);
         }
         if (!password.value) {
           setFieldError('passwordFieldWrap', 'passwordError', true, 'Password is required.');
           valid = false;
         } else {
           setFieldError('passwordFieldWrap', 'passwordError', false);
         }
         if (!valid) { e.preventDefault(); return; }

         // AJAX login submission
         e.preventDefault();
         var formData = new FormData(loginForm);
         formData.append('ajax_login', '1');
         var csrfMeta = document.querySelector('meta[name="csrf-token"]');
         if (csrfMeta) formData.append('csrf_token', csrfMeta.content);

         fetch(window.location.href, {
           method: 'POST',
           body: formData
         })
         .then(function(response) { return response.json(); })
         .then(function(data) {
           if (data.success) {
             sessionStorage.clear();
             window.location.href = data.redirect;
           } else {
             var loginErrorAlert = document.getElementById('loginErrorAlert');
             var loginErrorMessage = document.getElementById('loginErrorMessage');
             if (loginErrorAlert && loginErrorMessage) {
               loginErrorMessage.textContent = data.message || 'Invalid username or password.';
               loginErrorAlert.classList.remove('d-none');
             }
           }
         })
         .catch(function(error) {
           console.error('Login error:', error);
         });
       });
     }

      // Forgot password form validation and step switching
      var forgotEmail = document.getElementById('resetEmail');
      var forgotCode = document.getElementById('resetCode');
      var newPassword = document.getElementById('newPassword');
      var confirmPassword = document.getElementById('confirmPassword');

      function showForgotStep(step) {
        document.getElementById('forgotStepEmail').classList.add('d-none');
        document.getElementById('forgotStepCode').classList.add('d-none');
        document.getElementById('forgotStepReset').classList.add('d-none');
        document.getElementById('forgotStepSuccess').classList.add('d-none');
        document.getElementById('forgotBackLink').classList.add('d-none');

        if (step === 'email') {
          document.getElementById('forgotStepEmail').classList.remove('d-none');
          document.getElementById('forgotBackLink').classList.remove('d-none');
        } else if (step === 'code') {
          document.getElementById('forgotStepCode').classList.remove('d-none');
          document.getElementById('forgotBackLink').classList.remove('d-none');
        } else if (step === 'reset') {
          document.getElementById('forgotStepReset').classList.remove('d-none');
          document.getElementById('forgotBackLink').classList.remove('d-none');
        } else if (step === 'success') {
          document.getElementById('forgotStepSuccess').classList.remove('d-none');
        }
      }

      function setFieldError(wrapId, errorId, show, message) {
        var wrap = document.getElementById(wrapId);
        var err = document.getElementById(errorId);
        if (!wrap || !err) return;
        if (show) {
          wrap.classList.add('has-error');
          if (message) { err.querySelector('span').textContent = message; }
          err.classList.remove('d-none');
        } else {
          wrap.classList.remove('has-error');
          err.classList.add('d-none');
        }
      }

      if (forgotEmail) {
        forgotEmail.addEventListener('input', function() {
          setFieldError('resetEmailFieldWrap', 'resetEmailError', false);
        });
      }

      // Email form validation
      var forgotForm = document.getElementById('forgotPasswordForm');
      if (forgotForm) {
        forgotForm.addEventListener('submit', function(e) {
          var emailVal = forgotEmail ? forgotEmail.value.trim() : '';
          var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
          var valid = true;

          if (!emailVal) {
            setFieldError('resetEmailFieldWrap', 'resetEmailError', true, 'Email is required.');
            valid = false;
          } else if (!emailRegex.test(emailVal)) {
            setFieldError('resetEmailFieldWrap', 'resetEmailError', true, 'Enter a valid email address.');
            valid = false;
          } else {
            setFieldError('resetEmailFieldWrap', 'resetEmailError', false);
          }

          if (!valid) { e.preventDefault(); }
        });
      }

      // Code form validation
      var forgotCodeForm = document.getElementById('forgotCodeForm');
      if (forgotCodeForm) {
        forgotCodeForm.addEventListener('submit', function(e) {
          // Sync hidden fields before submit
          syncForgotHiddenFields();
          var codeVal = forgotCode ? forgotCode.value.trim() : '';
          var valid = true;

          if (!codeVal) {
            setFieldError('resetCodeFieldWrap', 'resetCodeError', true, 'Code is required.');
            valid = false;
          } else if (!/^\d{6}$/.test(codeVal)) {
            setFieldError('resetCodeFieldWrap', 'resetCodeError', true, 'Enter a valid 6-digit code.');
            valid = false;
          } else {
            setFieldError('resetCodeFieldWrap', 'resetCodeError', false);
          }

          if (!valid) {
            e.preventDefault();
            setFieldError('resetCodeFieldWrap', 'resetCodeError', true, 'Please enter a valid 6-digit code.');
          }
        });
      }

      // Reset form validation
      var forgotResetForm = document.getElementById('forgotResetForm');
      if (forgotResetForm) {
        forgotResetForm.addEventListener('submit', function(e) {
          var pwVal = newPassword ? newPassword.value : '';
          var cpwVal = confirmPassword ? confirmPassword.value : '';
          var valid = true;

          if (!pwVal || pwVal.length < 6) {
            setFieldError('newPasswordFieldWrap', 'newPasswordError', true, 'Password must be at least 6 characters.');
            valid = false;
          } else {
            setFieldError('newPasswordFieldWrap', 'newPasswordError', false);
          }

          if (!cpwVal || cpwVal !== pwVal) {
            setFieldError('confirmPasswordFieldWrap', 'confirmPasswordError', true, 'Passwords do not match.');
            valid = false;
          } else {
            setFieldError('confirmPasswordFieldWrap', 'confirmPasswordError', false);
          }

          if (!valid) { e.preventDefault(); }
        });
      }

      // Go to Sign In after successful reset
      var goToSignInAfterReset = document.getElementById('goToSignInAfterReset');
      if (goToSignInAfterReset) {
        goToSignInAfterReset.addEventListener('click', function() {
          goToSignInView();
        });
      }

      // Toggle password visibility for forgot reset step
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

      // Populate hidden fields when moving between forgot steps
      function syncForgotHiddenFields() {
        var emailVal = forgotEmail ? forgotEmail.value.trim() : '';
        var codeVal = forgotCode ? forgotCode.value.trim() : '';
        var forgotCodeEmail = document.getElementById('forgotCodeEmail');
        var forgotResetEmail = document.getElementById('forgotResetEmail');
        var forgotResetCode = document.getElementById('forgotResetCode');
        if (forgotCodeEmail) forgotCodeEmail.value = emailVal;
        if (forgotResetEmail) forgotResetEmail.value = emailVal;
        if (forgotResetCode) forgotResetCode.value = codeVal;
      }

      if (forgotEmail) {
        forgotEmail.addEventListener('input', syncForgotHiddenFields);
      }
      if (forgotCode) {
        forgotCode.addEventListener('input', syncForgotHiddenFields);
      }

      <?php if ($loginError): ?>
      // This no longer triggers because AJAX login exits early before setting $loginError
      // Kept for backward compatibility with non-AJAX fallback
      <?php endif; ?>

      <?php if (!empty($loginLocked)): ?>
      document.addEventListener('DOMContentLoaded', function() {
        var loginModal = new bootstrap.Modal(document.getElementById('loginModal'));
        loginModal.show();
        var alertEl = document.getElementById('loginErrorAlert');
        var msgEl = document.getElementById('loginErrorMessage');
        if (alertEl && msgEl) {
          msgEl.textContent = 'Account temporarily locked after too many failed attempts. Try again later.';
          alertEl.classList.remove('d-none');
        }
      });
      <?php endif; ?>

      <?php if ($forgotResetSuccess): ?>
      document.addEventListener('DOMContentLoaded', function() {
        var loginModal = new bootstrap.Modal(document.getElementById('loginModal'));
        loginModal.show();
        var signInView = document.getElementById('signInView');
        var forgotView = document.getElementById('forgotPasswordView');
        if (signInView) signInView.classList.add('d-none');
        if (forgotView) forgotView.classList.remove('d-none');
        showForgotStep('success');
      });
      <?php elseif ($forgotStep === 'code' || $forgotStep === 'reset'): ?>
      document.addEventListener('DOMContentLoaded', function() {
        var loginModal = new bootstrap.Modal(document.getElementById('loginModal'));
        loginModal.show();
        var signInView = document.getElementById('signInView');
        var forgotView = document.getElementById('forgotPasswordView');
        if (signInView) signInView.classList.add('d-none');
        if (forgotView) forgotView.classList.remove('d-none');
        showForgotStep('<?php echo $forgotStep; ?>');
        syncForgotHiddenFields();
      });
      <?php endif; ?>

      <?php if ($forgotCodeError): ?>
      document.addEventListener('DOMContentLoaded', function() {
        var alertEl = document.getElementById('resetCodeAlert');
        var alertText = document.getElementById('resetCodeAlertText');
        if (alertEl && alertText) {
          alertText.textContent = <?php echo json_encode($forgotCodeError); ?>;
          alertEl.classList.remove('d-none');
        }
      });
      <?php endif; ?>

      <?php if ($forgotResetError): ?>
      document.addEventListener('DOMContentLoaded', function() {
        var alertEl = document.getElementById('resetErrorAlert');
        var alertText = document.getElementById('resetErrorAlertText');
        if (alertEl && alertText) {
          alertText.textContent = <?php echo json_encode($forgotResetError); ?>;
          alertEl.classList.remove('d-none');
        }
      });
      <?php endif; ?>
   </script>

</body>

</html>
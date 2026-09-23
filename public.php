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
// Same rule as includes/rbac.php's document_visibility_clause() for anonymous
// visitors — is_public is the persistent "was this authorized to be public"
// flag; a document keeps showing here after being Amended/Withdrawn/Superseded
// (that's the point of a records archive), just never at the pre-filing stage
// (Draft/Submitted/Under Review), which isn't LRDMS's to show.
$publicDocs = get_db()->query(
    "SELECT d.id, d.doc_number, d.title, d.doc_type, d.sponsor, d.enactment_date
     FROM documents d
     WHERE d.is_public = 1 AND d.status NOT IN ('Draft','Submitted','Under Review')
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
    /* About/Features/Process/Legislation/Contact still carry Arsha's
       .dark-background preset in the markup below (so their CSS variables
       default to the navy/white scheme), but the override block further
       down (search "About Us pababa") forces a plain white background on
       those five sections and flips their text/heading color to navy blue
       instead — the tinted hero photo now only shows behind Home/#hero. */
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
    /* The "MANILA CITY HALL" brand text is sized for desktop (30px) — on
       phones it wraps onto 2 lines and squeezes the Sign In pill in the
       header until its own text wraps too ("Sign" / "In"), turning it
       into a small circle instead of a pill. Keep the pill on one line
       always, and shrink the brand text on narrow screens so it stops
       eating the button's space. */
    .header .btn-getstarted {
      white-space: nowrap;
      flex-shrink: 0;
    }
    @media (max-width: 991px) {
      .header .logo h1 { font-size: 20px; letter-spacing: 1px; }
    }
    @media (max-width: 576px) {
      .header .logo h1 { font-size: 31px; letter-spacing: 0.5px; }
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
    /* Section + info-item headings follow Arsha's own heading-color
       cascade, which the override below repoints to navy blue on all
       five white sections (About Us pababa). */
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

    /* --- About Us pababa: white background, blue instead of white --- */
    /* From "About Us" on down (Features, Process, Legislation, Contact),
       flip the tinted-photo/dark-background look to a plain white section
       background, and swap what used to be white text/headings to navy
       blue so it's still visible against the new white background.
       Home/#hero above keeps its own tinted bg.jpg, untouched. */
    #about, #features, #process, #legislation, #contact {
      background-color: #ffffff !important;
      --background-color: #ffffff;
      --default-color: var(--lrdms-navy);
      --heading-color: var(--lrdms-navy);
      --surface-color: #ffffff;
      --contrast-color: #ffffff;
      background-image: radial-gradient(1600px 1100px at 50% 30%, rgba(58, 150, 220, 0.12), transparent 75%) !important;
      background-repeat: no-repeat !important;
      background-attachment: fixed !important;
    }

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

    /* Login screen - full-bleed split layout (reference: Manila CMAS login) */
    #loginModal.login-fullscreen {
      position: fixed;
      inset: 0;
      z-index: 1055;
      display: flex;
      align-items: stretch;
      justify-content: stretch;
      background: #0b1730;
      overflow-y: auto;
    }
    #loginModal .login-split {
      display: flex;
      flex-wrap: wrap;
      min-height: 100vh;
      width: 100%;
    }
    #loginModal .login-brand-panel {
      flex: 1 1 48%;
      min-height: 42vh;
      position: relative;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: 56px 40px;
      color: #fff;
      background:
        linear-gradient(165deg, rgba(9, 23, 48, 0.80) 0%, rgba(5, 13, 28, 0.90) 100%),
        url('Arsha/assets/img/bg.jpg') center/cover no-repeat;
    }
    #loginModal .login-brand-seal {
      width: 168px;
      height: 168px;
      border-radius: 50%;
      background: #fff;
      padding: 10px;
      box-shadow: 0 14px 34px rgba(0, 0, 0, 0.35);
      margin-bottom: 26px;
      flex-shrink: 0;
    }
    #loginModal .login-brand-seal img {
      width: 100%;
      height: 100%;
      object-fit: contain;
      display: block;
    }
    #loginModal .login-brand-panel h4 {
      font-family: var(--heading-font, "Jost", sans-serif);
      font-weight: 800;
      font-size: 32px;
      letter-spacing: 0.5px;
      text-transform: uppercase;
      color: #fff;
      margin-bottom: 10px;
    }
    #loginModal .login-brand-panel p {
      font-size: 15px;
      font-weight: 500;
      color: #a9c6ec;
      max-width: 360px;
      margin: 0 auto;
    }
    #loginModal .login-form-panel {
      flex: 1 1 52%;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 40px 32px;
      background: #fff;
    }
    #loginModal .login-form-inner {
      width: 100%;
      max-width: 380px;
    }
    #loginModal .login-form-panel h5 {
      font-family: var(--heading-font, "Jost", sans-serif);
      font-weight: 800;
      color: #0b1f3a;
      font-size: 30px;
      text-align: center;
      margin-bottom: 6px;
    }
    #loginModal .login-form-panel .login-subtext {
      color: #6c757d;
      font-size: 14.5px;
      text-align: center;
      margin-bottom: 26px;
    }
    #loginModal .form-label {
      font-weight: 600;
      font-size: 13.5px;
      color: #0b1f3a;
      margin-bottom: 6px;
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
      border: 1.5px solid #e1e4ea;
      background: #fff;
      border-radius: 10px;
      padding: 11px 14px 11px 40px;
      font-size: 14.5px;
    }
    #loginModal .form-icon-input.has-toggle input.form-control {
      padding-right: 42px;
    }
    #loginModal .form-icon-input input.form-control:focus {
      box-shadow: 0 0 0 3px rgba(11, 31, 58, 0.12);
      border-color: #0b1f3a;
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
      color: #0b1f3a;
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
      color: #0b1f3a;
      font-size: 12.5px;
      text-decoration: none;
      font-weight: 600;
    }
    #loginModal .lrdms-link:hover {
      color: #c9a227;
      text-decoration: underline;
    }
    #loginModal .alert-success-soft {
      background: #eaf7ee;
      border: 1px solid #bfe6c9;
      color: #1e7a3d;
    }
    #loginModal .btn-login-submit {
      background: linear-gradient(135deg, #123a66 0%, #081a34 100%);
      border: none;
      color: #fff;
      font-weight: 700;
      font-size: 15px;
      border-radius: 10px;
      padding: 13px 0;
      letter-spacing: 0.3px;
      box-shadow: 0 10px 24px rgba(8, 26, 52, 0.28);
      transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    #loginModal .btn-login-submit:hover {
      transform: translateY(-1px);
      box-shadow: 0 12px 26px rgba(8, 26, 52, 0.38);
      color: #fff;
    }
    #loginModal .login-footnote {
      text-align: center;
      font-size: 12.5px;
      line-height: 1.6;
      color: #8a94a6;
      margin-top: 20px;
      padding-top: 18px;
      border-top: 1px solid #eef0f3;
    }
    #loginModal .reset-success-icon {
      width: 76px;
      height: 76px;
      border-radius: 50%;
      background: rgba(25, 135, 84, 0.1);
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 18px;
    }
    #loginModal .reset-success-icon i {
      font-size: 36px;
      color: #198754;
    }
    @media (max-width: 767.98px) {
      #loginModal .login-brand-panel {
        flex: 1 1 100%;
        min-height: 32vh;
        padding: 36px 24px;
      }
      #loginModal .login-brand-seal {
        width: 108px;
        height: 108px;
        margin-bottom: 16px;
      }
      #loginModal .login-brand-panel h4 {
        font-size: 22px;
      }
      #loginModal .login-brand-panel p {
        font-size: 13px;
      }
      #loginModal .login-form-panel {
        flex: 1 1 100%;
        padding: 32px 24px;
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
      color: rgba(55, 81, 126, 0.65);
      font-size: 15px;
      margin: 0;
    }
    #legislation .text-muted {
      color: rgba(55, 81, 126, 0.65) !important;
    }
  </style>

</head>

<body class="index-page">

  <!-- Landing/marketing UI removed: this subsystem now goes straight to login.
       A separate shared landing page (built by the integration team) links
       directly into this login screen for the LRDMS subsystem. -->

  <script src="Arsha/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>


  <div id="loginModal" class="login-fullscreen">
          <div class="login-split">
            <div class="login-brand-panel">
              <div class="login-brand-seal">
                <img src="Arsha/assets/img/manila logo.png" alt="Lungsod ng Maynila Seal">
              </div>
              <h4>Lungsod ng Maynila</h4>
              <p>Legal Records &amp; Document Management System</p>
            </div>
            <div class="login-form-panel">
              <div class="login-form-inner">

              <div id="signInView">
                <h5 id="loginModalLabel">Welcome back!</h5>
                <div class="login-subtext">Sign in to continue to your account.</div>

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
                    <div class="d-flex align-items-center justify-content-between">
                      <label for="modalPassword" class="form-label mb-0">Password</label>
                      <a href="#" id="showForgotPassword" class="lrdms-link">Forgot password?</a>
                    </div>
                    <div class="form-icon-input has-toggle mt-2" id="passwordFieldWrap">
                      <i class="bi bi-lock form-control-icon-left"></i>
                      <input type="password" id="modalPassword" name="password" class="form-control" placeholder="Enter your password" required>
                      <button class="toggle-password-btn" type="button" id="togglePassword" aria-label="Toggle password visibility">
                        <i class="bi bi-eye"></i>
                      </button>
                    </div>
                    <div class="field-error d-none" id="passwordError"><i class="bi bi-exclamation-circle-fill"></i><span>Password is required.</span></div>
                  </div>
                  <button type="submit" class="btn btn-login-submit w-100 mt-2">Sign In</button>
                </form>

                <div class="login-footnote">
                  Contact the administrator for account credentials.<br>
                  &copy; <?php echo date('Y'); ?> Legal Records &amp; Document Management System
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
                  <h5 class="mb-3">Password Updated Successfully</h5>
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

      // Ang login screen ay full-page na ngayon (hindi na popup modal), kaya
      // laging naka-display na ito nang default sa CSS -- wala nang
      // show()/hide() na kailangan.

      <?php if ($loginError): ?>
      // This no longer triggers because AJAX login exits early before setting $loginError
      // Kept for backward compatibility with non-AJAX fallback
      <?php endif; ?>

      <?php if (!empty($loginLocked)): ?>
      document.addEventListener('DOMContentLoaded', function() {
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
        var signInView = document.getElementById('signInView');
        var forgotView = document.getElementById('forgotPasswordView');
        if (signInView) signInView.classList.add('d-none');
        if (forgotView) forgotView.classList.remove('d-none');
        showForgotStep('success');
      });
      <?php elseif ($forgotStep === 'code' || $forgotStep === 'reset'): ?>
      document.addEventListener('DOMContentLoaded', function() {
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

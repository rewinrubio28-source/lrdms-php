<?php
/**
 * Session-based authentication + account security.
 *
 * Uses PHP's built-in password_hash()/password_verify() (bcrypt) and
 * RFC 6238 TOTP (Google Authenticator-compatible) — no external auth
 * library needed.
 *
 * Security features layered on top of the original login:
 *   • failed-attempt lockout  — users.failed_attempts / locked_until
 *   • last login tracking     — users.last_login_at
 *   • forced password change  — users.must_change_password
 *   • TOTP two-factor auth    — users.totp_secret / totp_enabled
 *   • per-login sessions      — user_sessions table (revoke / sign out all)
 *
 * A session token is minted at login, stored in user_sessions, and copied
 * into $_SESSION. current_user() verifies the token on every request, so
 * revoking a session row (or disabling the account) takes effect
 * immediately instead of waiting for the PHP session to expire.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/audit.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('LOGIN_MAX_ATTEMPTS')) define('LOGIN_MAX_ATTEMPTS', 5);
if (!defined('LOGIN_LOCK_MINUTES'))  define('LOGIN_LOCK_MINUTES', 15);

/* ============================================================
   Login / logout
   ============================================================ */

/**
 * Find a user row by username (password_hash included — for login use only).
 */
function get_user_by_username($username) {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    return $stmt->fetch();
}

/**
 * Whether the user row is currently inside a failed-login lockout window.
 */
function is_account_locked($user) {
    return !empty($user['locked_until']) && strtotime($user['locked_until']) > time();
}

/**
 * Attempts to log a user in. Returns:
 *   'success' — fully logged in (session created)
 *   '2fa'     — password valid, account has 2FA enabled; verify_2fa.php
 *               must complete the login
 *   'locked'  — account is inside the failed-login lockout window
 *   false     — invalid username/password (or account disabled)
 */
function attempt_login($username, $password) {
    $pdo = get_db();
    $user = get_user_by_username($username);

    if (!$user || !$user['is_active']) {
        return false;
    }

    if (is_account_locked($user)) {
        return 'locked';
    }

    if (!password_verify($password, $user['password_hash'])) {
        $attempts = (int)$user['failed_attempts'] + 1;
        $lockedUntil = null;
        if ($attempts >= LOGIN_MAX_ATTEMPTS) {
            $lockedUntil = date('Y-m-d H:i:s', time() + LOGIN_LOCK_MINUTES * 60);
            log_action('auth', 'login_locked', $username);
        }
        $stmt = $pdo->prepare('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?');
        $stmt->execute([$attempts, $lockedUntil, $user['id']]);
        return false;
    }

    // Valid password — reset the lockout counters.
    $stmt = $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?');
    $stmt->execute([$user['id']]);

    $stmt = $pdo->prepare('SELECT name FROM roles WHERE id = ?');
    $stmt->execute([$user['role_id']]);
    $user['role_name'] = $stmt->fetchColumn();

    // 2FA gate: hold the login until a valid TOTP code is entered.
    if (!empty($user['totp_enabled']) && $user['totp_secret'] !== null && $user['totp_secret'] !== '') {
        $_SESSION['2fa_user_id'] = (int)$user['id'];
        return '2fa';
    }

    complete_login($user);
    return 'success';
}

/**
 * Finish a login: mint a session token, record the session row, and stamp
 * the user session. Called after the password check (attempt_login) or
 * after a valid 2FA code (verify_2fa.php).
 */
function complete_login($user) {
    $pdo = get_db();
    if (empty($user['role_name'])) {
        $stmt = $pdo->prepare('SELECT name FROM roles WHERE id = ?');
        $stmt->execute([$user['role_id']]);
        $user['role_name'] = $stmt->fetchColumn();
    }

    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare(
        'INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent)
         VALUES (?,?,?,?)'
    );
    $stmt->execute([
        $user['id'], $token,
        $_SERVER['REMOTE_ADDR'] ?? null,
        isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
    ]);

    // Regenerate the session ID on privilege change to guard against
    // session fixation attacks.
    session_regenerate_id(true);
    $_SESSION['user_id']       = (int)$user['id'];
    $_SESSION['username']      = $user['username'];
    $_SESSION['full_name']     = $user['full_name'];
    $_SESSION['role_id']       = (int)$user['role_id'];
    $_SESSION['role_name']     = $user['role_name'];
    $_SESSION['committee_id']  = $user['committee_id'];
    $_SESSION['session_token'] = $token;
    unset($_SESSION['2fa_user_id']);

    $stmt = $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
    $stmt->execute([$user['id']]);

    // Invalidate the per-request cache so the next current_user() re-resolves.
    global $__lrdms_current_user;
    $__lrdms_current_user = false;
}

/**
 * Returns the logged-in user as an associative array, or null.
 *
 * The session token is verified against the user_sessions table on every
 * request, so revoked sessions / disabled accounts are rejected here
 * rather than being trusted from the PHP session alone.
 */
function current_user() {
    global $__lrdms_current_user;
    if (!isset($__lrdms_current_user)) {
        $__lrdms_current_user = false; // false = not resolved yet
    }
    if ($__lrdms_current_user !== false) {
        return $__lrdms_current_user;
    }
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['session_token'])) {
        $__lrdms_current_user = null;
        return null;
    }

    $pdo = get_db();
    $stmt = $pdo->prepare(
        'SELECT u.id, u.username, u.full_name, u.email, u.role_id, r.name AS role_name,
                u.committee_id, u.is_active, u.must_change_password, u.totp_enabled, u.totp_secret,
                u.last_login_at, us.id AS session_id, us.session_token,
                us.ip_address AS session_ip, us.created_at AS session_created_at,
                us.last_seen AS session_last_seen
         FROM user_sessions us
         JOIN users u ON u.id = us.user_id
         JOIN roles r ON r.id = u.role_id
         WHERE us.session_token = ? AND us.is_active = 1 AND u.is_active = 1'
    );
    $stmt->execute([$_SESSION['session_token']]);
    $user = $stmt->fetch();

    if (!$user) {
        // Session revoked or account disabled — drop the local state.
        unset($_SESSION['user_id'], $_SESSION['session_token']);
        $__lrdms_current_user = null;
        return null;
    }

    // Refresh last_seen once per request.
    if (empty($GLOBALS['__lrdms_seen_touched'])) {
        $GLOBALS['__lrdms_seen_touched'] = true;
        $stmt = $pdo->prepare('UPDATE user_sessions SET last_seen = NOW() WHERE id = ?');
        $stmt->execute([$user['session_id']]);
    }

    $__lrdms_current_user = $user;
    return $user;
}

/**
 * Redirects to the login page if nobody is logged in, and forces users who
 * must change their password onto profile.php until they do.
 */
function require_login() {
    $user = current_user();
    if (!$user) {
        header('Location: public.php');
        exit;
    }
    if (!empty($user['must_change_password']) && basename($_SERVER['PHP_SELF'], '.php') !== 'profile') {
        header('Location: profile.php?force=1');
        exit;
    }
}

function do_logout() {
    $pdo = get_db();
    if (isset($_SESSION['session_token'])) {
        $stmt = $pdo->prepare('UPDATE user_sessions SET is_active = 0 WHERE session_token = ?');
        $stmt->execute([$_SESSION['session_token']]);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
    global $__lrdms_current_user;
    $__lrdms_current_user = null;
}

/* ============================================================
   TOTP two-factor authentication (RFC 6238, SHA-1, 6 digits, 30 s)
   ============================================================ */

/**
 * Generate a new random base32 TOTP secret (160 bits).
 */
function totp_generate_secret() {
    return _base32_encode(random_bytes(20));
}

/**
 * Compute a TOTP code for a secret at the current (optionally offset) time step.
 */
function totp_generate_code($secret, $timeStep = 30, $digits = 6, $window = 0) {
    $counter = (int)(time() / $timeStep) + $window;
    $counterBin = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
    $key = _base32_decode($secret);
    if ($key === false) return false;
    $hash = hash_hmac('sha1', $counterBin, $key, true);
    $offset = ord($hash[19]) & 0x0f;
    $code = (
        ((ord($hash[$offset]) & 0x7f) << 24) |
        ((ord($hash[$offset + 1]) & 0xff) << 16) |
        ((ord($hash[$offset + 2]) & 0xff) << 8) |
        (ord($hash[$offset + 3]) & 0xff)
    ) % pow(10, $digits);
    return str_pad((string)$code, $digits, '0', STR_PAD_LEFT);
}

/**
 * Verify a user-supplied TOTP code, allowing ±1 time step of clock skew.
 */
function verify_totp($secret, $code, $digits = 6, $window = 1) {
    $code = trim((string)$code);
    if ($code === '' || !ctype_digit($code) || strlen($code) !== $digits) {
        return false;
    }
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_generate_code($secret, 30, $digits, $i), $code)) {
            return true;
        }
    }
    return false;
}

/**
 * otpauth:// URI for the authenticator app (used for QR display).
 */
function totp_uri($username, $secret) {
    return 'otpauth://totp/' . rawurlencode('LRDMS:' . $username)
        . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode('LRDMS')
        . '&digits=6&period=30';
}

function _base32_encode($data) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $binary = '';
    foreach (str_split($data) as $char) {
        $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }
    $result = '';
    for ($i = 0, $len = strlen($binary); $i < $len; $i += 5) {
        $result .= $alphabet[bindec(str_pad(substr($binary, $i, 5), 5, '0', STR_PAD_RIGHT))];
    }
    return $result;
}

function _base32_decode($base32) {
    $base32 = strtoupper(rtrim(trim($base32), '='));
    if ($base32 === '') return false;
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $binary = '';
    for ($i = 0, $len = strlen($base32); $i < $len; $i++) {
        $pos = strpos($alphabet, $base32[$i]);
        if ($pos === false) return false;
        $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $result = '';
    for ($i = 0, $blen = strlen($binary); $i + 8 <= $blen; $i += 8) {
        $result .= chr(bindec(substr($binary, $i, 8)));
    }
    return $result;
}

/**
 * Short human-readable device label from a User-Agent string.
 */
function user_agent_label($userAgent) {
    $ua = $userAgent ?? '';
    if ($ua === '') return 'Unknown device';
    $browser = 'Browser';
    if      (stripos($ua, 'Edg/')   !== false) $browser = 'Microsoft Edge';
    elseif  (stripos($ua, 'Chrome/') !== false) $browser = 'Chrome';
    elseif  (stripos($ua, 'Firefox/') !== false) $browser = 'Firefox';
    elseif  (stripos($ua, 'Safari/')  !== false) $browser = 'Safari';
    elseif  (stripos($ua, 'Trident/') !== false || stripos($ua, 'MSIE') !== false) $browser = 'Internet Explorer';
    $os = 'Unknown OS';
    if      (stripos($ua, 'Windows') !== false) $os = 'Windows';
    elseif  (stripos($ua, 'Mac OS') !== false || stripos($ua, 'Macintosh') !== false) $os = 'macOS';
    elseif  (stripos($ua, 'Android') !== false) $os = 'Android';
    elseif  (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) $os = 'iOS';
    elseif  (stripos($ua, 'Linux')  !== false) $os = 'Linux';
    return $browser . ' · ' . $os;
}

/* ============================================================
   Password reset (original flows, plus the security flags)
   ============================================================ */

/**
 * Generate a password reset code for a user.
 * Returns the code data on success, or null if user not found/inactive/no email.
 */
function generate_password_reset_code($email) {
    $pdo = get_db();

    $stmt = $pdo->prepare(
        'SELECT id, email, username FROM users WHERE email = ? AND is_active = 1'
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        return null;
    }

    $stmt = $pdo->prepare('DELETE FROM password_reset_codes WHERE user_id = ?');
    $stmt->execute([$user['id']]);

    $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    $stmt = $pdo->prepare(
        'INSERT INTO password_reset_codes (user_id, code, expires_at) VALUES (?, ?, ?)'
    );
    $stmt->execute([$user['id'], $code, $expiresAt]);

    return [
        'code' => $code,
        'email' => $user['email'],
        'username' => $user['username'],
        'expires_at' => $expiresAt
    ];
}

/**
 * Validate a password reset code.
 * Returns user data if valid, or null if invalid/expired.
 */
function validate_password_reset_code($email, $code) {
    $pdo = get_db();

    $stmt = $pdo->prepare(
        'SELECT prc.id, prc.user_id, prc.expires_at, prc.used_at, u.username, u.email
         FROM password_reset_codes prc
         JOIN users u ON u.id = prc.user_id
         WHERE u.email = ? AND prc.code = ? AND prc.used_at IS NULL'
    );
    $stmt->execute([$email, $code]);
    $record = $stmt->fetch();

    if (!$record) {
        return null;
    }

    if (strtotime($record['expires_at']) < time()) {
        return null;
    }

    return [
        'user_id' => $record['user_id'],
        'username' => $record['username'],
        'email' => $record['email']
    ];
}

/**
 * Reset user password using a verification code.
 * Returns true on success, or false on failure.
 * Clearing the reset clears any forced-change flag and the lockout counters.
 */
function reset_password_with_code($email, $code, $new_password) {
    $pdo = get_db();

    $user = validate_password_reset_code($email, $code);
    if (!$user) {
        return false;
    }

    $password_hash = password_hash($new_password, PASSWORD_BCRYPT);

    $stmt = $pdo->prepare(
        'UPDATE users SET password_hash = ?, must_change_password = 0,
                failed_attempts = 0, locked_until = NULL WHERE id = ?'
    );
    $stmt->execute([$password_hash, $user['user_id']]);

    $stmt = $pdo->prepare(
        'UPDATE password_reset_codes SET used_at = NOW() WHERE code = ? AND user_id = ?'
    );
    $stmt->execute([$code, $user['user_id']]);

    return true;
}

/**
 * Reset user password using token.
 * Returns true on success, or false on failure.
 */
function reset_password($token, $new_password) {
    $pdo = get_db();

    $user = validate_password_reset_token($token);
    if (!$user) {
        return false;
    }

    $password_hash = password_hash($new_password, PASSWORD_BCRYPT);

    $stmt = $pdo->prepare(
        'UPDATE users SET password_hash = ?, must_change_password = 0,
                failed_attempts = 0, locked_until = NULL WHERE id = ?'
    );
    $stmt->execute([$password_hash, $user['user_id']]);

    $stmt = $pdo->prepare(
        'UPDATE password_reset_tokens SET used_at = NOW() WHERE token = ?'
    );
    $stmt->execute([$token]);

    return true;
}

/* ============================================================
   CSRF protection
   ============================================================ */

/**
 * Ensure a CSRF token exists in the session. Call this on every page
 * that renders a form or handles a POST.
 */
function ensure_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

/**
 * Render a hidden <input> with the CSRF token. Place inside every
 * <form method="post">.
 */
function csrf_field() {
    ensure_csrf_token();
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token']) . '">';
}

/**
 * Validate the CSRF token from a POST request. Returns true if valid.
 * Reads from POST body or X-CSRF-Token header (for AJAX).
 */
function validate_csrf() {
    ensure_csrf_token();
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return hash_equals($_SESSION['csrf_token'], $token);
}

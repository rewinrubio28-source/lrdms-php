<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/config/database.php';

require_permission('audit', 'view');
$pdo = get_db();

$q = trim($_GET['q'] ?? '');
$eventFilter = $_GET['event'] ?? 'All';
$roleFilter = $_GET['role'] ?? 'All';
// Kept for backwards-compat (?module= links / old bookmarks). No UI control.
$moduleFilter = $_GET['module'] ?? 'All';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$view = $_GET['view'] ?? 'list';
if (!in_array($view, ['list', 'grid', 'table'], true)) $view = 'list';

// ---- Build shared WHERE clause -------------------------------------------
$where = [];
$params = [];

if ($moduleFilter !== 'All') {
    $where[] = 'a.module = ?';
    $params[] = $moduleFilter;
}
if ($eventFilter !== 'All') {
    $where[] = 'a.action = ?';
    $params[] = $eventFilter;
}
if ($roleFilter !== 'All') {
    if ($roleFilter === 'System') {
        $where[] = 'r.name IS NULL';
    } else {
        $where[] = 'r.name = ?';
        $params[] = $roleFilter;
    }
}
if ($q !== '') {
    $where[] = '(a.action LIKE ? OR a.username_snapshot LIKE ? OR u.full_name LIKE ? OR a.detail LIKE ? OR a.ip_address LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
require __DIR__ . '/includes/audit_filters.php';
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// ---- Total matching records (drives pagination) ---------------------------
$countSql = 'SELECT COUNT(*) FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             LEFT JOIN roles r ON r.id = u.role_id' . $whereSql;
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// ---- Current page of logs ($perPage/$offset are PHP ints → safe to inline) -
$sql = 'SELECT a.*, u.full_name AS actor_full_name, r.name AS actor_role, (SELECT name FROM offices WHERE id = u.office_id) AS actor_office, (SELECT name FROM divisions WHERE id = u.division_id) AS actor_division
        FROM audit_log a
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN roles r ON r.id = u.role_id'
        . $whereSql .
        ' ORDER BY a.created_at DESC, a.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$events = $pdo->query('SELECT DISTINCT action FROM audit_log ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$roles = $pdo->query('SELECT name FROM roles ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

// ---- Query suffix shared by pagination links (keeps all filters) ----------
$qsParts = [];
if ($q !== '') $qsParts['q'] = $q;
if ($eventFilter !== 'All') $qsParts['event'] = $eventFilter;
if ($roleFilter !== 'All') $qsParts['role'] = $roleFilter;
if ($moduleFilter !== 'All') $qsParts['module'] = $moduleFilter;
if ($view !== 'list') $qsParts['view'] = $view;
$qsParts = array_merge($qsParts, array_filter($auditExtra, static function ($v) { return $v !== ''; }));
$qs = $qsParts ? '&' . http_build_query($qsParts) : '';
$exportBase = array_filter(['q' => $q !== '' ? $q : null, 'event' => $eventFilter !== 'All' ? $eventFilter : null, 'role' => $roleFilter !== 'All' ? $roleFilter : null, 'module' => $moduleFilter !== 'All' ? $moduleFilter : null]);
$exportBase = array_merge($exportBase, array_filter($auditExtra, static function ($v) { return $v !== ''; }));
$exportQs = $exportBase ? '?' . http_build_query($exportBase) : '';
$viewBase = $exportBase ? '?' . http_build_query($exportBase) : '?';
$viewBaseSep = $exportBase ? '&' : '';

// ---- Presentation helpers --------------------------------------------------
function audit_stamp($datetime) {
    $ts = strtotime($datetime);
    return $ts ? date('M j, Y \a\t g:i A', $ts) : '—';
}

// Turn log_action() keys like UPDATED_PROFILE into readable labels
// ("Updated Profile") without leaking the raw snake_case to the UI.
function audit_action_label($action) {
    return ucwords(str_replace(['_', '-'], ' ', strtolower((string)$action)));
}

function audit_is_system($row) {
    // user_id becomes NULL for both true system events AND accounts
    // since deleted (FK ON DELETE SET NULL), so distinguish by snapshot:
    // log_action() writes username_snapshot='system' only when no user.
    $snap = strtolower((string)($row['username_snapshot'] ?? ''));
    if (in_array($snap, ['system', 'admin_system'], true)) return true;
    return stripos((string)$row['action'], 'auto') !== false;
}

function audit_badge_class($action) {
    $a = strtolower($action);
    if (str_contains($a, 'auto') || str_contains($a, 'amend') || str_contains($a, 'rollback') || str_contains($a, 'version')) return 'badge-amber';
    if (str_contains($a, 'fail') || str_contains($a, 'reject') || str_contains($a, 'delete') || str_contains($a, 'block') || str_contains($a, 'lock')) return 'badge-red';
    if (str_contains($a, 'create') || str_contains($a, 'complete') || str_contains($a, 'enable') || str_contains($a, 'verif') || str_contains($a, 'release')) return 'badge-blue';
    if (str_contains($a, 'login') || str_contains($a, 'logout') || str_contains($a, 'search') || str_contains($a, 'view') || str_contains($a, 'ingest') || str_contains($a, 'document')) return 'badge-blue';
    if (str_contains($a, 'update') || str_contains($a, 'reset') || str_contains($a, 'revok') || str_contains($a, 'password') || str_contains($a, '2fa')) return 'badge-slate';
    return 'badge-slate';
}

function audit_avatar_class($row) {
    if (audit_is_system($row)) return 'avatar-amber';
    $m = strtolower((string)($row['module'] ?? ''));
    $a = strtolower((string)($row['action'] ?? ''));
    if (str_contains($a, 'fail') || str_contains($a, 'reject') || str_contains($a, 'delete') || str_contains($a, 'block')) return 'avatar-red';
    if (str_contains($a, 'login') || str_contains($a, 'logout') || $m === 'auth') return 'avatar-green';
    if ($m === 'search') return 'avatar-slate';
    return 'avatar-blue';
}

function audit_icon($row) {
    $m = strtolower((string)($row['module'] ?? ''));
    $a = strtolower((string)($row['action'] ?? ''));
    if (str_contains($a, 'auto') || str_contains($a, 'system')) return 'bi-diagram-2';
    if (str_contains($a, 'verif') || str_contains($a, 'release') || str_contains($a, 'document')) return 'bi-file-earmark-check';
    if (str_contains($a, 'login') || str_contains($a, 'logout') || str_contains($a, 'password') || str_contains($a, '2fa')) return 'bi-shield-lock';
    if (str_contains($a, 'search')) return 'bi-search';
    if (str_contains($a, 'delete') || str_contains($a, 'reject')) return 'bi-x-circle';
    if ($m === 'access') return 'bi-person-gear';
    if ($m === 'version') return 'bi-stack';
    if ($m === 'encoding') return 'bi-pencil-square';
    return 'bi-activity';
}

function audit_display_name($row) {
    if (!empty($row['actor_full_name'])) return $row['actor_full_name'];
    $snap = (string)($row['username_snapshot'] ?? '');
    if ($snap !== '') {
        if (strtolower($snap) === 'system') return 'System';
        return str_replace('_', ' ', $snap);
    }
    return 'System';
}

function audit_role_label($row) {
    if (!empty($row['actor_role'])) return ucwords(strtolower($row['actor_role']));
    if (audit_is_system($row)) return 'System';
    return '';
}

function audit_doc_id($row) {
    if (preg_match('/#(\d+)/', (string)($row['detail'] ?? ''), $m)) return $m[1];
    return null;
}

// Bold key objects (doc numbers, IDs, quoted names) inside an escaped string.
function audit_bold($text) {
    $e = htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
    $e = preg_replace(
        '/(Ordinance No\.\s*[A-Za-z0-9\-]+|Resolution No\.\s*[A-Za-z0-9\-]+|Document #\d+|#\d+|user_id=\d+|session_id=[A-Za-z0-9]+|role_id=\d+)/',
        '<strong>$1</strong>',
        $e
    );
    $e = preg_replace('/&quot;(.+?)&quot;/', '&quot;<strong>$1</strong>&quot;', $e);
    return $e;
}

// First chunk of a detail line (usually the document / user being acted on).
function audit_doc_label($detail) {
    $detail = trim((string)$detail);
    if ($detail === '') return '';
    $parts = preg_split('/\s+[—–-]\s+|\s+→\s+|\s+\(/u', $detail, 2);
    return audit_bold($parts[0]);
}

// Resolve account references without exposing storage fields in the activity feed.
function audit_account_label($detail) {
    if (preg_match('/(?:^|\s)user_id=(\d+)/', $detail, $match)) {
        static $names = [];
        $id = (int)$match[1];
        if (!array_key_exists($id, $names)) {
            $stmt = get_db()->prepare('SELECT full_name, username FROM users WHERE id = ?');
            $stmt->execute([$id]);
            $account = $stmt->fetch();
            $names[$id] = $account ? $account['full_name'] . ' (' . $account['username'] . ')' : null;
        }
        return $names[$id] ? audit_bold($names[$id]) : 'the selected user';
    }
    $name = trim(preg_split('/\s*\(role_id=|;\s*organization=/', $detail, 2)[0]);
    return $name !== '' ? audit_bold($name) : 'the selected user';
}

// Plain-English "what did they do" line. Returns safe HTML (<strong> allowed).
function audit_summary_html($row) {
    $action = (string)($row['action'] ?? '');
    $detail = trim((string)($row['detail'] ?? ''));
    $rich = audit_bold($detail);
    $doc = audit_doc_label($detail);
    $sameAsActor = $detail !== '' && $detail === (string)($row['username_snapshot'] ?? '');

    switch ($action) {
        case 'verified_incoming_document':
            if (stripos($detail, 'released to public') !== false) return "Verified {$doc} and <strong>released it to the public repository</strong>.";
            if (stripos($detail, 'kept private') !== false) return "Verified {$doc} but <strong>kept it private</strong> (not visible to the public).";
            if (stripos($detail, 'released after verification') !== false) return "Verified and <strong>released {$doc}</strong> after checking it.";
            return $detail !== '' ? "Verified an incoming document: {$rich}." : 'Verified an incoming document.';
        case 'rejected_incoming_document':
            $split = preg_split('/\s+[—–-]\s*rejected:\s*/i', $detail, 2);
            if (count($split) === 2) return 'Rejected ' . audit_bold($split[0]) . '. Reason: <strong>' . htmlspecialchars($split[1], ENT_QUOTES, 'UTF-8') . '</strong>.';
            return "Rejected {$doc}. {$rich}";
        case 'ran_ocr_incoming_document':
            return "Ran text recognition (OCR) on {$doc} to make the scanned files searchable.";
        case 'api_ingest':
            $p = explode(' → ', $detail, 2);
            if (count($p) === 2) return 'Accepted new document ' . audit_bold($p[1]) . ' from ' . audit_bold($p[0]) . ' into Encoding.';
            return "Accepted a new document from another system: {$rich}.";
        case 'api_ingest_rejected_duplicate':
            $p = explode(' → ', $detail, 2);
            if (count($p) === 2) return 'Skipped duplicate ' . audit_bold($p[1]) . ' from ' . audit_bold($p[0]) . ' — it already exists.';
            return "Skipped a duplicate document: {$rich}.";
        case 'amended_document': return "Created an amended copy. {$rich}";
        case 'added_change_note': return "Added a version note to {$doc}.";
        case 'rolled_back': return "Restored an older version as a new document. {$rich}";
        case 'related_document': return "Linked two documents together. {$rich}";
        case 'unrelated_document': return "Removed a link between documents. {$rich}";
        case 'updated_visibility':
            if (stripos($detail, 'public') !== false && stripos($detail, 'private') === false) return "Made {$doc} <strong>public</strong>.";
            if (stripos($detail, 'private') !== false) return "Made {$doc} <strong>private</strong>.";
            return "Changed document visibility. {$rich}";
        case 'login': return $sameAsActor ? 'Signed in.' : "Signed in as {$rich}.";
        case 'login_2fa_complete': return 'Signed in (completed two-factor verification).';
        case 'logout': return $sameAsActor ? 'Signed out.' : "Signed out ({$rich}).";
        case 'failed_login': return "Failed sign-in attempt for user {$rich}.";
        case 'login_locked':
        case 'login_blocked_locked': return "Sign-in blocked — account {$rich} is locked.";
        case 'login_2fa_pending': return "Entered the correct password for {$rich} — waiting for the two-factor code.";
        case 'login_2fa_otp_emailed': return "Emailed a two-factor code to {$rich}.";
        case '2fa_failed': return "Failed two-factor attempt for {$rich}.";
        case '2fa_enabled': return 'Turned <strong>on</strong> two-factor authentication.';
        case '2fa_disabled': return 'Turned <strong>off</strong> two-factor authentication.';
        case 'password_changed': return 'Changed the account password.';
        case 'password_reset_complete': return $sameAsActor ? 'Reset the password.' : "Reset the password for {$rich}.";
        case 'password_reset_request': return "Requested a password reset for {$rich}.";
        case 'updated_profile': return ($detail !== '' && !$sameAsActor) ? "Updated the profile of {$rich}." : 'Updated their profile information.';
        case 'created_user': return 'Created a new user account for ' . audit_account_label($detail) . '.';
        case 'downloaded_document': return "Downloaded a document attachment: {$rich}.";
        case 'requested_document_copy': return "Requested a copy of {$rich}.";
        case 'reviewed_document_copy': return "Reviewed a document copy request: {$rich}.";
        case 'updated_user': return 'Updated the account details and assignments of ' . audit_account_label($detail) . '.';
        case 'toggled_user_active': return 'Changed the account access status of ' . audit_account_label($detail) . '.';
        case 'updated_profile_photo': return 'Updated their profile photo.';
        case 'removed_profile_photo': return 'Removed their profile photo.';
        case 'reset_password': return "Reset the password for {$rich}.";
        case 'reset_2fa': return "Reset two-factor authentication for {$rich}.";
        case 'cleared_user_lockout': return "Unlocked account {$rich} after too many failed sign-ins.";
        case 'revoked_session': return "Signed out one session for {$rich}.";
        case 'revoked_all_sessions': return "Signed out all sessions for {$rich}.";
        case 'sent_welcome_email':
            if (stripos($detail, 'FAILED') !== false) return 'Tried to send a welcome email to ' . $doc . ' but it <strong>failed</strong>.';
            return 'Sent a welcome email to ' . $doc . '.';
        case 'created_role': return 'Created the role ' . audit_bold(trim(preg_split('/\s*\(permissions=|;\s*permissions=/', $detail, 2)[0])) . '.';
        case 'updated_role': return 'Updated the role and permissions for ' . audit_bold(trim(preg_split('/\s*\(permissions=|;\s*permissions=/', $detail, 2)[0])) . '.';
        case 'deleted_role': return "Deleted role: {$rich}.";
        case 'ran_search':
        case 'api_query': return "Ran a search: {$rich}.";
        case 'saved_search_created': return "Saved a search called {$rich}.";
        case 'saved_search_renamed': return "Renamed a saved search: {$rich}.";
        case 'saved_search_deleted': return "Deleted a saved search: {$rich}.";
        case 'post_received': return "Received pushed files for intake: {$rich}.";
        case 'push_result': return "Finished pushing documents to storage: {$rich}.";
        default:
            $human = ucwords(str_replace(['_', '-'], ' ', $action));
            return $detail !== '' ? htmlspecialchars($human, ENT_QUOTES, 'UTF-8') . ': ' . $rich . '.' : htmlspecialchars($human, ENT_QUOTES, 'UTF-8') . '.';
    }
}

$offices = $pdo->query('SELECT id, name FROM offices ORDER BY name')->fetchAll();
$stats = $pdo->query("SELECT COUNT(*) AS total,
 SUM(user_id IS NOT NULL) AS users,
 SUM(module IN ('encoding','version','repository','document')) AS records,
 SUM(LOWER(action) REGEXP 'fail|denied|blocked|locked|reject') AS security
 FROM audit_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch();
$selectedEvent = $logs[0] ?? null;
foreach ($logs as $log) if ((int)$log['id'] === (int)($_GET['selected'] ?? 0)) $selectedEvent = $log;
function audit_outcome($row) { return preg_match('/fail|denied|blocked|locked|reject/i', $row['action']) ? 'Attention' : 'Recorded'; }
$escape = static function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
include __DIR__ . '/includes/layout_top.php';
include __DIR__ . '/includes/audit_workspace_view.php';
include __DIR__ . '/includes/layout_bottom.php';

<?php
/**
 * RBAC has two layers in this system, deliberately kept separate:
 *
 *  1. PERMISSION-BASED  — "can this role touch this module/action at all?"
 *     Backed by the permissions / role_permissions tables and checked
 *     with has_permission() / require_permission().
 *
 *  2. STATUS & OWNERSHIP-BASED — "of the records in a module this role
 *     CAN access, which specific rows can it see or edit?"
 *     A Legislative Staff account has repository access, but that does
 *     not mean it can see another councilor's still-pending draft.
 *     Handled by document_visibility_clause() (for lists/SQL) and
 *     can_view_document() (for a single row already fetched by ID).
 *
 * Layer 2 is ALSO permission-driven. Four repository permissions express
 * the scope of the rows a role may see, checked highest-first:
 *   view_all       → every document        (Administrator, Records Officer)
 *   view_committee → own committee's pending + own + public
 *                    (Committee Secretary, for the review workflow)
 *   view_own       → own documents + public (Legislative Staff)
 *   view_public    → enacted + public only  (Public Viewer, and logged-out)
 *
 * The `d`-prefixed SQL below references the documents table through the
 * alias `d` — any query using the clause must alias documents as `d` so
 * the columns stay unambiguous when the query also joins users/committees
 * (both of which have a committee_id column).
 *
 * NOTE: document_visibility_clause() and can_view_document() encode the
 * same rules twice — once as SQL, once as a PHP predicate — because one
 * filters lists and the other checks a row already loaded by ID. Keep
 * them in sync when the rules change.
 */
require_once __DIR__ . '/auth.php';

/**
 * Human-readable label for a permission module slug, used by the Roles &
 * Permissions UI so it never shows raw internal names ("access",
 * "repository", …) to end users. Falls back to the slug capitalized.
 */
function perm_module_label($module) {
    static $labels = [
        'access'     => 'User & Role Management',
        'audit'      => 'Audit Trail',
        'encoding'   => 'Encoding & OCR',
        'repository' => 'Document Repository',
        'search'     => 'Search',
        'version'    => 'Document Versions',
    ];
    return $labels[$module] ?? ucfirst($module);
}

/**
 * Human-readable label for a permission action slug ("force_logout" →
 * "Force Logout"). Falls back to replacing underscores with spaces and
 * title-casing, so unknown actions stay readable.
 */
function perm_action_label($action) {
    static $labels = [
        'create'            => 'Create',
        'edit_metadata'     => 'Edit Metadata',
        'register_record'   => 'Validate / Register Record',
        'manage_organization' => 'Manage Organizational Lists',
        'manage_visibility' => 'Manage Public Visibility',
        'print_record'      => 'Print Record Summary',
        'download'          => 'Download Document Copy',
        'review_copy_requests' => 'Review Copy Requests',
        'export'            => 'Export Report',
        'view_all'          => 'View All',
        'view_committee'    => 'View Committee',
        'view_memberships'  => 'View Assigned Committees',
        'view_office'       => 'View Originating Office',
        'view_division'     => 'View Originating Division',
        'view_own'          => 'View Own',
        'view_public'       => 'View Public',
        'amend'             => 'Amend',
        'rollback'          => 'Rollback',
        'run'               => 'Run',
        'manage_users'      => 'Manage Users',
        'manage_roles'      => 'Manage Roles',
        'reset_password'    => 'Reset Password',
        'force_logout'      => 'Force Logout',
        'view_user_activity' => 'View User Activity',
        'view'              => 'View',
    ];
    return $labels[$action] ?? ucwords(str_replace('_', ' ', $action));
}

/**
 * Whether a specific role holds the (module, action) permission.
 * Used by has_permission() (session user) and by the visibility helpers
 * (the explicitly passed user) so both stay in sync.
 */
function _role_has_permission($roleId, $module, $action) {
    $pdo = get_db();
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM role_permissions rp
         JOIN permissions p ON p.id = rp.permission_id
         WHERE rp.role_id = ? AND p.module = ? AND p.action = ?'
    );
    $stmt->execute([$roleId, $module, $action]);
    return $stmt->fetchColumn() > 0;
}

function has_permission($module, $action) {
    $user = current_user();
    if (!$user) return false;
    return _role_has_permission($user['role_id'], $module, $action);
}

/**
 * ROLE HIERARCHY for user management.
 *
 *   Super Admin (3)  >  Administrator (2)  >  every other role (1)
 *
 * Rule: a person may only create, assign, or manage roles/accounts that rank
 * STRICTLY BELOW their own. So:
 *   - Super Admin  → can create/manage Administrator and everyone below
 *                    (nobody can create another Super Admin here).
 *   - Administrator → can only create/manage the roles below Administrator
 *                    (never another Administrator, never a Super Admin).
 * Any role not listed (including future custom roles) counts as rank 1.
 */
function role_rank($roleName) {
    static $ranks = ['Super Admin' => 3, 'Administrator' => 2];
    return $ranks[$roleName] ?? 1;
}

function my_role_rank() {
    $u = current_user();
    return $u ? role_rank($u['role_name']) : 0;
}

/** Directory visibility only. Never apply this predicate to audit/history queries. */
function user_directory_clause() {
    return "u.role_id NOT IN (SELECT id FROM roles WHERE name = 'Super Admin')";
}

/** Hidden accounts can still open their own profile. */
function can_view_user_account(array $target, array $viewer) {
    return $target['role_name'] !== 'Super Admin'
        || (int)$target['id'] === (int)$viewer['id'];
}

/** The roles (from a full roles list) the signed-in user is allowed to assign to someone else. */
function assignable_roles(array $roles) {
    $mine = my_role_rank();
    return array_values(array_filter($roles, function ($r) use ($mine) {
        return role_rank($r['name']) < $mine;
    }));
}

/** Server-side check: may the signed-in user assign this role id to someone? */
function can_assign_role_id($roleId, array $roles) {
    foreach (assignable_roles($roles) as $r) {
        if ((int)$r['id'] === (int)$roleId) return true;
    }
    return false;
}

/**
 * May the signed-in user manage (edit / reset password / disable / etc.) this
 * account? $target needs 'id' and 'role_name'. Your own account is always
 * allowed here; changing your own role and disabling yourself are blocked
 * separately where they happen.
 */
function can_manage_user(array $target) {
    $me = current_user();
    if (!$me) return false;
    if ((int)$target['id'] === (int)$me['id']) return true;
    return role_rank($target['role_name']) < role_rank($me['role_name']);
}

/**
 * Gate a whole page on a permission. Renders a 403 message and stops
 * execution if the current role doesn't have it.
 */
function require_permission($module, $action) {
    require_login();
    if (!has_permission($module, $action)) {
        http_response_code(403);
        include __DIR__ . '/layout_top.php';
        echo '<div class="alert alert-danger">Access denied — your role does not have permission to <strong>'
            . htmlspecialchars($action) . '</strong> in <strong>' . htmlspecialchars($module) . '</strong>.</div>';
        include __DIR__ . '/layout_bottom.php';
        exit;
    }
}

/**
 * Returns [whereClauseSql, boundParams] implementing the visibility layer
 * for use in a list query, e.g.:
 *   list($clause, $params) = document_visibility_clause(current_user());
 *   $pdo->prepare("SELECT * FROM documents d WHERE $clause")->execute($params);
 *
 * The clause qualifies every column with the alias `d`, so any query that
 * uses it must alias the documents table as `d`. This keeps the clause
 * unambiguous when the query also joins users/committees — both of which
 * have a committee_id column.
 */
function legacy_document_visibility_clause($user) {
    // "Public" visibility keys off is_public alone, not status = 'Enacted'.
    // is_public is never touched by amend/rollback/withdraw (see document.php
    // and version.php's status-change action) — it only ever changes via an
    // explicit "Publicly visible" toggle. So it's the true, persistent record
    // of "this was authorized to be shown publicly," independent of whatever
    // its current lifecycle status is. A document that was Enacted-and-public
    // and later got Amended or Withdrawn doesn't stop having existed — a
    // records archive should keep showing it (with its current status badge
    // making the Amended/Withdrawn/Superseded state obvious), not make it
    // vanish the moment a newer version or a withdrawal happens. Only
    // Draft/Submitted/Under Review stay excluded even if somehow is_public=1
    // on legacy data, since those were never meant to be public in the first
    // place — they're the pre-filing stage owned by System 1, not LRDMS.
    $publicClause = "d.is_public = 1 AND d.status NOT IN ('Draft','Submitted','Under Review')";

    if (!$user) {
        return [$publicClause, []];
    }

    // Check the broadest scope first; the grants are mutually exclusive per
    // role (see seed data), so the first match is the whole answer.
    if (_role_has_permission($user['role_id'], 'repository', 'view_all')) {
        // "view_all" means every reviewed record — not the intake tray.
        // A document that hasn't been verified yet isn't really "in the
        // repository" yet; it belongs in encoding.php's Awaiting
        // Verification queue, not in Repository/Search/Version Control
        // listings, even for a role with full visibility. This must NOT
        // be applied to can_view_document() below — document.php relies
        // on that check staying true for an unverified doc so it can
        // redirect to document_review.php instead of 404-ing.
        return ['d.verified_at IS NOT NULL', []];
    }
    if (_role_has_permission($user['role_id'], 'repository', 'view_committee')) {
        // Originally scoped to documents in ('Submitted','Under Review') for the
        // user's committee — but that stage of the legislative process is owned
        // by System 1, not LRDMS (see includes/workflow.php), and nothing here
        // can ever put a document in those states anymore. Rebuilt around what
        // "view_committee" actually means now: any already-reviewed document
        // belonging to the secretary's own committee (verified_at IS NOT NULL —
        // this must stay in lockstep with document.php's redirect-to-review-page
        // guard, or a secretary could pass can_view_document() for a document
        // that then immediately 403s them out of document_review.php).
        return [
            "(d.committee_id = ? AND d.verified_at IS NOT NULL)
             OR d.owner_id = ?
             OR ($publicClause)",
            [$user['committee_id'], $user['id']],
        ];
    }
    if (_role_has_permission($user['role_id'], 'repository', 'view_own')) {
        return [
            "d.owner_id = ? OR ($publicClause)",
            [$user['id']],
        ];
    }
    // view_public, and any role with no repository grant, sees public docs only.
    return [$publicClause, []];
}

/**
 * Same rules as document_visibility_clause(), but as a PHP predicate for
 * a single row you've already fetched by ID (e.g. document.php?id=).
 */
function legacy_can_view_document($user, $doc) {
    $isPublic = (int)$doc['is_public'] === 1
        && !in_array($doc['status'], ['Draft', 'Submitted', 'Under Review'], true);

    if (!$user) {
        return $isPublic;
    }
    if (_role_has_permission($user['role_id'], 'repository', 'view_all')) {
        return true;
    }
    if (_role_has_permission($user['role_id'], 'repository', 'view_committee')) {
        return ($doc['committee_id'] !== null
                && (int)$doc['committee_id'] === (int)$user['committee_id']
                && $doc['verified_at'] !== null)
            || (int)$doc['owner_id'] === (int)$user['id']
            || $isPublic;
    }
    if (_role_has_permission($user['role_id'], 'repository', 'view_own')) {
        return (int)$doc['owner_id'] === (int)$user['id']
            || $isPublic;
    }
    return $isPublic;
}

/** Optional organization scopes, enabled explicitly through role permissions.
 * Office/division refer to document origin, not the document owner's current assignment.
 * Null/unmatched origin metadata never grants access. Intake remains excluded.
 */
function organization_visibility_clause($user) {
    if (!$user) return ['1=0', []];
    $clauses = [];
    $params = [];
    if (_role_has_permission($user['role_id'], 'repository', 'view_memberships')) {
        $clauses[] = 'EXISTS (SELECT 1 FROM users scope_user WHERE scope_user.id = ? AND (scope_user.committee_id = d.committee_id OR EXISTS (SELECT 1 FROM user_committees scope_member WHERE scope_member.user_id = scope_user.id AND scope_member.committee_id = d.committee_id)))';
        $params[] = $user['id'];
    }
    if (_role_has_permission($user['role_id'], 'repository', 'view_office')) {
        $clauses[] = 'EXISTS (SELECT 1 FROM users scope_user JOIN offices scope_office ON scope_office.id = scope_user.office_id WHERE scope_user.id = ? AND scope_office.name = d.originating_office)';
        $params[] = $user['id'];
    }
    if (_role_has_permission($user['role_id'], 'repository', 'view_division')) {
        $clauses[] = 'EXISTS (SELECT 1 FROM users scope_user JOIN offices scope_office ON scope_office.id = scope_user.office_id JOIN divisions scope_division ON scope_division.id = scope_user.division_id AND scope_division.office_id = scope_office.id WHERE scope_user.id = ? AND scope_office.name = d.originating_office AND scope_division.name = d.originating_division)';
        $params[] = $user['id'];
    }
    return $clauses ? ['(d.verified_at IS NOT NULL AND (' . implode(' OR ', $clauses) . '))', $params] : ['1=0', []];
}

function document_visibility_clause($user) {
    [$legacy, $params] = legacy_document_visibility_clause($user);
    [$organization, $organizationParams] = organization_visibility_clause($user);
    return ['d.verified_at IS NOT NULL AND ((' . $legacy . ') OR (' . $organization . '))', array_merge($params, $organizationParams)];
}

function can_view_document($user, $doc) {
    if (legacy_can_view_document($user, $doc)) return true;
    if (!$user || empty($doc['id'])) return false;
    [$clause, $params] = organization_visibility_clause($user);
    if (!$params) return false;
    $stmt = get_db()->prepare('SELECT 1 FROM documents d WHERE d.id = ? AND (' . $clause . ')');
    $stmt->execute(array_merge([(int)$doc['id']], $params));
    return (bool)$stmt->fetchColumn();
}

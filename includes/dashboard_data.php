<?php
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/dashboard_filters.php';
function _dash_pct($n, $total) { return $total > 0 ? round($n / $total * 100) : 0; }
function dashboard_snapshot(PDO $pdo, array $user, array $filters): array {
    $ownsTransaction=!$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
    }
    try {
        $data=dashboard_data($pdo,$user,$filters);
        $data['filters']=$filters;
        $data['generated_at']=date('c');
        if ($ownsTransaction) $pdo->commit();
        return $data;
    } catch (Throwable $e) { if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function dashboard_data(PDO $pdo, array $user, array $filters): array {
// ------------------------------------------------------------
// KPIs + status counts (scoped by the role visibility rules)
// ------------------------------------------------------------
list($clause, $params) = dashboard_record_scope($user, $filters);

$stmt = $pdo->prepare("SELECT status, COUNT(*) AS n FROM documents d WHERE $clause GROUP BY status");
$stmt->execute($params);
$statusCounts = [];
foreach ($stmt->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int)$row['n'];
}
$totalDocs     = array_sum($statusCounts);
$enactedCount  = $statusCounts['Enacted'] ?? 0;
$rejectedCount = $statusCounts['Rejected'] ?? 0;

// Replaces the old "In Pipeline" (Draft+Submitted+Under Review) tile — that
// pipeline is owned by System 1, not LRDMS (see includes/workflow.php), and
// nothing in this app can create a document in those states anymore, so the
// count was permanently zero. "Awaiting Verification" is the real, live
// queue records officers actually work from.
$canEncode = _role_has_permission($user['role_id'], 'encoding', 'create');
$awaitingVerificationCount = 0;
if ($canEncode) {
    [$incomingClause,$incomingParams]=dashboard_record_filter_scope($filters);
    $incomingQuery=$pdo->prepare("SELECT d.* FROM documents d WHERE verified_at IS NULL AND source_system <> 'Manual Encoding' AND ($incomingClause)");
    $incomingQuery->execute($incomingParams);
    $incoming=$incomingQuery->fetchAll();
    $awaitingVerificationCount = count(array_filter($incoming, static function ($record) use ($user) { return can_view_document($user, $record); }));
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM documents d WHERE $clause AND status = 'Enacted' AND is_public = 1");
$stmt->execute($params);
$publicCount = (int)$stmt->fetchColumn();

$canAccess = _role_has_permission($user['role_id'], 'access', 'manage_users');
$canSearch = _role_has_permission($user['role_id'], 'search', 'run');
$canAudit  = _role_has_permission($user['role_id'], 'audit', 'view');

$activeUsers = 0;
if ($canAccess) {
    $activeUsers = (int)$pdo->query('SELECT COUNT(*) FROM users u WHERE u.is_active = 1 AND ' . user_directory_clause())->fetchColumn();
}

// ------------------------------------------------------------
// Encoding & Submission
// ------------------------------------------------------------
$recentDocs = [];
$pendingDigit = 0;
$myRecentDocs = [];
$monthData = [];

// Administrators without encoding permission should not load or see
// encoding-specific dashboard data.
if ($canEncode) {
    $stmt = $pdo->prepare("SELECT d.*, u.full_name AS owner_name FROM documents d
                           JOIN users u ON u.id = d.owner_id
                           WHERE $clause ORDER BY d.created_at DESC, d.id DESC LIMIT 5");
    $stmt->execute($params);
    $recentDocs = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM documents d
                           WHERE $clause AND (file_path IS NULL OR ocr_text IS NULL OR ocr_text = '')");
    $stmt->execute($params);
    $pendingDigit = (int)$stmt->fetchColumn();

    // My Recent Encodings — scoped to current user
    // Encoding Activity Trend — monthly counts (last 12 months)
    // My Recent Encodings — scoped to current user
    $myStmt = $pdo->prepare("SELECT d.id, d.doc_number, d.title, d.doc_type, d.status, d.created_at
                             FROM documents d
                             WHERE d.owner_id = ? AND ($clause)
                             ORDER BY d.created_at DESC LIMIT 5");
    $myStmt->execute(array_merge([$user['id']], $params));
    $myRecentDocs = $myStmt->fetchAll();

    // Encoding Activity Trend — monthly counts (last 12 months)
}

    $monthStmt = $pdo->prepare("SELECT DATE_FORMAT(d.created_at, '%Y-%m') AS month, COUNT(*) AS n
                                FROM documents d
                                WHERE ($clause)
                                GROUP BY month
                                ORDER BY month ASC
                                LIMIT 60");
    $monthStmt->execute($params);
    $monthData = dashboard_months($filters,$monthStmt->fetchAll());

// ------------------------------------------------------------
// Repository — by document type
// ------------------------------------------------------------
$stmt = $pdo->prepare("SELECT doc_type, COUNT(*) AS n FROM documents d
                       WHERE $clause GROUP BY doc_type ORDER BY n DESC");
$stmt->execute($params);
$typeCounts = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT SUM(is_public = 1) AS pub, COUNT(*) AS total FROM documents d WHERE $clause");
$stmt->execute($params);
$pubRow = $stmt->fetch();
$pubCount = (int)($pubRow['pub'] ?? 0);
$restrictedCount = $totalDocs - $pubCount;

// ------------------------------------------------------------
// Version Control — chains and revision counts
// ------------------------------------------------------------
$versionChains = 0;
$totalRevisions = 0;
$versionedHeads = [];
if ($totalDocs > 0) {
    $stmt = $pdo->prepare("SELECT id, previous_version_id FROM documents d WHERE $clause");
    $stmt->execute($params);
    $links = [];
    foreach ($stmt->fetchAll() as $r) {
        $links[(int)$r['id']] = $r['previous_version_id'] ? (int)$r['previous_version_id'] : null;
    }
    foreach ($links as $prev) {
        if ($prev !== null) $totalRevisions++;
    }

    $stmt = $pdo->prepare("SELECT id, doc_number, title, status, updated_at FROM documents d
                           WHERE $clause AND next_version_id IS NULL AND previous_version_id IS NOT NULL
                           ORDER BY updated_at DESC LIMIT 5");
    $stmt->execute($params);
    $versionedHeads = $stmt->fetchAll();
    foreach ($versionedHeads as &$vh) {
        $n = 1;
        $id = (int)$vh['id'];
        $seen = [];
        while (isset($links[$id]) && $links[$id] !== null && !isset($seen[$id]) && array_key_exists($links[$id], $links)) {
            $seen[$id] = true;
            $n++;
            $id = $links[$id];
        }
        $vh['versions'] = $n;
    }
    unset($vh);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM documents d
                           WHERE $clause AND next_version_id IS NULL AND previous_version_id IS NOT NULL");
    $stmt->execute($params);
    $versionChains = (int)$stmt->fetchColumn();
}

// ------------------------------------------------------------
// Retrieval & Search (search_log)
// ------------------------------------------------------------
$totalSearches = 0;
$keywordSearches = 0;
$semanticSearches = 0;
$recentSearches = [];
if ($canSearch) {
    [$searchClause,$searchParams]=dashboard_date_scope($filters,'created_at');
    if (!$canAudit) { $searchClause.=' AND user_id=?'; $searchParams[]=$user['id']; }
    $searchQuery=$pdo->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(search_type='keyword'),0) AS keyword_count, COALESCE(SUM(search_type='semantic'),0) AS semantic_count FROM search_log WHERE $searchClause");
    $searchQuery->execute($searchParams); $counts=$searchQuery->fetch();
    $totalSearches=(int)$counts['total']; $keywordSearches=(int)$counts['keyword_count']; $semanticSearches=(int)$counts['semantic_count'];
    $searchQuery=$pdo->prepare("SELECT * FROM search_log WHERE $searchClause ORDER BY created_at DESC, id DESC LIMIT 4");
    $searchQuery->execute($searchParams); $recentSearches=$searchQuery->fetchAll();
}

// ------------------------------------------------------------
// Access Control & Security (users by role)
// ------------------------------------------------------------
$usersByRole = [];
$totalUsers = 0;
$inactiveUsers = 0;
if ($canAccess) {
    $usersByRole = $pdo->query(
        'SELECT r.name, COUNT(u.id) AS n FROM roles r
         LEFT JOIN users u ON u.role_id = r.id
         WHERE r.name <> \'Super Admin\'
         GROUP BY r.id, r.name ORDER BY n DESC'
    )->fetchAll();
    $totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users u WHERE ' . user_directory_clause())->fetchColumn();
    $inactiveUsers = (int)$pdo->query('SELECT COUNT(*) FROM users u WHERE u.is_active = 0 AND ' . user_directory_clause())->fetchColumn();
}

// ------------------------------------------------------------
// Recent activity (audit trail)
// ------------------------------------------------------------
$recent = [];
if ($canAudit) {
    [$auditClause,$auditParams]=dashboard_date_scope($filters,'created_at');
    $auditQuery=$pdo->prepare("SELECT * FROM audit_log WHERE $auditClause ORDER BY created_at DESC, id DESC LIMIT 6");
    $auditQuery->execute($auditParams); $recent=$auditQuery->fetchAll();
}

return compact('statusCounts','totalDocs','enactedCount','rejectedCount','canEncode','awaitingVerificationCount','publicCount','canAccess','canSearch','canAudit','activeUsers','recentDocs','pendingDigit','myRecentDocs','monthData','typeCounts','pubCount','restrictedCount','versionChains','totalRevisions','versionedHeads','totalSearches','keywordSearches','semanticSearches','recentSearches','usersByRole','totalUsers','inactiveUsers','recent');
}

<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/config/database.php';

require_login();
$user = current_user();
$pdo = get_db();

// ------------------------------------------------------------
// Helper (dashboard scope only — does not touch shared modules)
// ------------------------------------------------------------
function _dash_pct($n, $total) {
    return $total > 0 ? round($n / $total * 100) : 0;
}

// ------------------------------------------------------------
// KPIs + status counts (scoped by the role visibility rules)
// ------------------------------------------------------------
list($clause, $params) = document_visibility_clause($user);

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
$canEncode = has_permission('encoding', 'create');
$awaitingVerificationCount = 0;
if ($canEncode) {
    $incoming = $pdo->query("SELECT * FROM documents WHERE verified_at IS NULL AND source_system <> 'Manual Encoding'")->fetchAll();
    $awaitingVerificationCount = count(array_filter($incoming, static function ($record) use ($user) { return can_view_document($user, $record); }));
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM documents d WHERE $clause AND status = 'Enacted' AND is_public = 1");
$stmt->execute($params);
$publicCount = (int)$stmt->fetchColumn();

$canAccess = has_permission('access', 'manage_users');
$canSearch = has_permission('search', 'run');
$canAudit  = has_permission('audit', 'view');

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
    $monthStmt = $pdo->prepare("SELECT DATE_FORMAT(d.created_at, '%Y-%m') AS month, COUNT(*) AS n
                                FROM documents d
                                WHERE ($clause) AND d.created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), '%Y-%m-01') AND d.created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
                                GROUP BY month
                                ORDER BY month ASC
                                LIMIT 12");
    $monthStmt->execute($params);
    $monthData = $monthStmt->fetchAll();
}

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
    $totalSearches = (int)$pdo->query('SELECT COUNT(*) FROM search_log')->fetchColumn();
    $keywordSearches = (int)$pdo->query("SELECT COUNT(*) FROM search_log WHERE search_type = 'keyword'")->fetchColumn();
    $semanticSearches = $totalSearches - $keywordSearches;
    $recentSearches = $pdo->query('SELECT * FROM search_log ORDER BY created_at DESC LIMIT 4')->fetchAll();
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
    $recent = $pdo->query('SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 6')->fetchAll();
}

// ------------------------------------------------------------
// View
// ------------------------------------------------------------
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$firstName = trim($user['full_name'] ?: $user['username']);
$firstName = explode(' ', $firstName)[0];
$dateStr = date('l, F j, Y');
$timeStr = date('g:i A');

include __DIR__ . '/includes/layout_top.php';
?>
<div class="dash-header" data-banner-date="">
  <div class="d-flex align-items-start gap-2">
    <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="Open menu">
      <i class="bi bi-list"></i>
    </button>
    <div>
      <div class="module-banner-eyebrow">DASHBOARD</div>
      <h1 class="dash-header__title"><span id="greeting"><?= htmlspecialchars($greeting) ?></span>, <?= htmlspecialchars($firstName) ?> 👋</h1>
      <p class="dash-header__date"><?= htmlspecialchars($dateStr) ?> · <span id="clock" class="dash-header__clock"><?= htmlspecialchars($timeStr) ?></span></p>
    </div>
  </div>
  <div class="dash-header__actions">
  </div>
</div>

<?php if ($canEncode): ?>
<nav class="d-flex flex-wrap gap-2 mb-4" aria-label="Dashboard shortcuts">
  <a class="btn btn-primary btn-sm" href="encoding.php">Incoming Records <span class="badge text-bg-light ms-1"><?= $awaitingVerificationCount ?></span></a>
  <a class="btn btn-outline-primary btn-sm" href="encoding.php?tab=followups">Pending &amp; Follow-up</a>
</nav>
<?php endif; ?>

<div class="kpi-row">
  <div class="stat-tile kpi-primary">
    <div class="stat-tile__icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
    </div>
    <div class="stat-tile__label">Total Records</div>
    <div class="stat-tile__value"><?= number_format($totalDocs) ?></div>
    <div class="stat-tile__sub">Visible to your role</div>
  </div>

  <div class="stat-tile kpi-success">
    <div class="stat-tile__icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    </div>
    <div class="stat-tile__label">Enacted Records</div>
    <div class="stat-tile__value"><?= number_format($enactedCount) ?></div>
    <div class="stat-tile__sub">Finalized legislation on file</div>
  </div>

  <div class="stat-tile kpi-accent">
    <div class="stat-tile__icon">
      <?php if ($canEncode): ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><path d="M10 12h4"/></svg>
      <?php else: ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
      <?php endif; ?>
    </div>
    <?php if ($canEncode): ?>
      <div class="stat-tile__label">Awaiting Verification</div>
      <div class="stat-tile__value"><?= number_format($awaitingVerificationCount) ?></div>
      <div class="stat-tile__sub">Incoming, not yet reviewed</div>
    <?php else: ?>
      <div class="stat-tile__label">Rejected Records</div>
      <div class="stat-tile__value"><?= number_format($rejectedCount) ?></div>
      <div class="stat-tile__sub">Records rejected by the source</div>
    <?php endif; ?>
  </div>

  <?php if ($canAccess): ?>
    <div class="stat-tile kpi-warning">
      <div class="stat-tile__icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      </div>
      <div class="stat-tile__label">Active Users</div>
      <div class="stat-tile__value"><?= number_format($activeUsers) ?></div>
      <div class="stat-tile__sub">Enabled accounts</div>
    </div>
  <?php else: ?>
    <div class="stat-tile kpi-warning">
      <div class="stat-tile__icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
      </div>
      <div class="stat-tile__label">Public Records</div>
      <div class="stat-tile__value"><?= number_format($publicCount) ?></div>
      <div class="stat-tile__sub">Enacted &amp; publicly visible</div>
    </div>
  <?php endif; ?>
</div>

<!-- ── Row 1: Role-aware intake and repository overview ─────────── -->
<div class="dash-grid">
  <?php if ($canEncode): ?>
  <section class="module-card span-2">
    <header class="module-card__header">
      <div>
        <h3>Encoding &amp; Submission</h3>
        <p class="module-card__subtitle">Document intake &amp; status pipeline</p>
      </div>
      <a class="module-card__link" href="encoding.php">Open module →</a>
    </header>
    <div class="module-card__body">
      <div class="status-chips">
        <?php foreach (['Enacted', 'Amended', 'Rejected'] as $i => $s): ?>
          <div class="status-chip <?= $s === 'Enacted' ? 'is-emphasis' : '' ?>">
            <span class="status-chip__num"><?= $statusCounts[$s] ?? 0 ?></span>
            <span class="status-chip__label"><?= htmlspecialchars($s) ?></span>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($recentDocs): ?>
        <ul class="mini-list">
          <?php foreach ($recentDocs as $d): ?>
            <li>
              <div class="mini-list__main">
                <a class="mini-list__title" href="document.php?id=<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['title']) ?></a>
                <span class="mini-list__meta"><?= htmlspecialchars($d['doc_number']) ?> · <?= htmlspecialchars($d['doc_type']) ?></span>
              </div>
              <span class="stamp stamp--<?= strtolower(str_replace(' ', '-', $d['status'])) ?>"><?= htmlspecialchars($d['status']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="module-empty">No recent records available.</p>
      <?php endif; ?>

      <div class="module-note <?= $pendingDigit > 0 ? 'is-warning' : '' ?>">
        <span>
          <?= $pendingDigit > 0
              ? '<strong>' . number_format($pendingDigit) . '</strong> record(s) still need digitization (no source file or OCR text).'
              : 'All visible records have source files and OCR text on file.' ?>
        </span>
      </div>
    </div>
  </section>

  <!-- ── My Recent Encodings ─────────── -->
  <section class="module-card">
    <header class="module-card__header">
      <div>
        <h3>My Recent Records</h3>
        <p class="module-card__subtitle">Recently filed records assigned to you</p>
      </div>
    </header>
    <div class="module-card__body">
      <?php if ($myRecentDocs): ?>
        <ul class="mini-list">
          <?php foreach ($myRecentDocs as $d): ?>
            <li>
              <div class="mini-list__main">
                <a class="mini-list__title" href="document.php?id=<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['title']) ?></a>
                <span class="mini-list__meta"><?= htmlspecialchars($d['doc_number']) ?> · <?= htmlspecialchars($d['doc_type']) ?></span>
              </div>
              <span class="stamp stamp--<?= strtolower(str_replace(' ', '-', $d['status'])) ?>"><?= htmlspecialchars($d['status']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="module-empty">No records are assigned to you yet.</p>
      <?php endif; ?>
    </div>
  </section>

  <!-- ── Encoding Activity Trend ─────────── -->
  <section class="module-card span-2">
    <header class="module-card__header">
      <div>
        <h3>Encoding Activity</h3>
        <p class="module-card__subtitle">Monthly document intake (last 12 months)</p>
      </div>
    </header>
    <div class="module-card__body">
      <?php if ($monthData): ?>
        <?php
          $maxMonth = max(array_column($monthData, 'n'));
          $chartH = 120;
        ?>
        <div style="display:flex;align-items:flex-end;gap:6px;height:<?= $chartH ?>px;padding:8px 0;">
          <?php foreach ($monthData as $m): ?>
            <?php $h = $maxMonth > 0 ? round((int)$m['n'] / $maxMonth * $chartH) : 0; ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;">
              <span style="font-size:11px;font-weight:600;color:var(--lrdms-navy);"><?= (int)$m['n'] ?></span>
              <div style="width:100%;max-width:48px;height:<?= $h ?>px;background:linear-gradient(180deg,var(--lrdms-navy),var(--lrdms-navy-dark));border-radius:4px 4px 0 0;transition:height .3s;"></div>
              <span style="font-size:10px;color:#6b7690;white-space:nowrap;"><?= date('M', strtotime($m['month'] . '-01')) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="module-empty">No encoding activity yet.</p>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="module-card <?= $canEncode ? '' : 'span-3' ?>">
    <header class="module-card__header">
      <div>
        <h3>Legislative Repository</h3>
        <p class="module-card__subtitle">Collection by document type</p>
      </div>
      <a class="module-card__link" href="repository.php">Open module →</a>
    </header>
    <div class="module-card__body">
      <?php foreach ($typeCounts as $t): ?>
        <div class="bar-row">
          <span class="bar-label"><?= htmlspecialchars($t['doc_type']) ?></span>
          <div class="bar-track"><div class="bar-fill bar-fill--primary" style="width: <?= _dash_pct((int)$t['n'], $totalDocs) ?>%"></div></div>
          <span class="bar-value"><?= (int)$t['n'] ?></span>
        </div>
      <?php endforeach; ?>

      <div class="split-stats">
        <div class="split-stat">
          <div class="split-stat__num"><?= number_format($pubCount) ?></div>
          <div class="split-stat__label">Public</div>
        </div>
        <div class="split-stat">
          <div class="split-stat__num"><?= number_format($restrictedCount) ?></div>
          <div class="split-stat__label">Not public</div>
        </div>
      </div>
    </div>
  </section>
</div>

<!-- ── Row 2: Version Control + Retrieval & Search ───────── -->
<div class="dash-grid">
  <section class="module-card <?= $canSearch ? 'span-2' : 'span-3' ?>">
    <header class="module-card__header">
      <div>
        <h3>Version Control</h3>
        <p class="module-card__subtitle">Revision chains &amp; amendment history</p>
      </div>
      <a class="module-card__link" href="version.php">Open module →</a>
    </header>
    <div class="module-card__body">
      <div class="split-stats" style="grid-template-columns: repeat(3, 1fr); margin-top:0; margin-bottom:14px;">
        <div class="split-stat">
          <div class="split-stat__num"><?= number_format($versionChains) ?></div>
          <div class="split-stat__label">Versioned chains</div>
        </div>
        <div class="split-stat">
          <div class="split-stat__num"><?= number_format($totalRevisions) ?></div>
          <div class="split-stat__label">Total revisions</div>
        </div>
        <div class="split-stat">
          <div class="split-stat__num"><?= number_format($totalDocs - $totalRevisions) ?></div>
          <div class="split-stat__label">Single-version</div>
        </div>
      </div>

      <?php if ($versionedHeads): ?>
        <ul class="mini-list">
          <?php foreach ($versionedHeads as $vh): ?>
            <li>
              <div class="mini-list__main">
                <a class="mini-list__title" href="document.php?id=<?= (int)$vh['id'] ?>"><?= htmlspecialchars($vh['title']) ?></a>
                <span class="mini-list__meta"><?= htmlspecialchars($vh['doc_number']) ?> · <?= (int)$vh['versions'] ?> version(s)</span>
              </div>
              <span class="stamp stamp--<?= strtolower(str_replace(' ', '-', $vh['status'])) ?>"><?= htmlspecialchars($vh['status']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="module-empty">No amended / versioned records yet.</p>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($canSearch): ?>
  <section class="module-card">
    <header class="module-card__header">
      <div>
        <h3>Search and Document Retrieval</h3>
        <p class="module-card__subtitle">Search activity across the repository</p>
      </div>
      <a class="module-card__link" href="search.php">Open module →</a>
    </header>
    <div class="module-card__body">
      <div class="bar-row">
        <span class="bar-label">Keyword</span>
        <div class="bar-track"><div class="bar-fill bar-fill--accent" style="width: <?= _dash_pct($keywordSearches, $totalSearches) ?>%"></div></div>
        <span class="bar-value"><?= number_format($keywordSearches) ?></span>
      </div>
      <div class="bar-row">
        <span class="bar-label">Semantic</span>
        <div class="bar-track"><div class="bar-fill bar-fill--success" style="width: <?= _dash_pct($semanticSearches, $totalSearches) ?>%"></div></div>
        <span class="bar-value"><?= number_format($semanticSearches) ?></span>
      </div>
      <p class="module-empty" style="margin-top:10px;"><?= number_format($totalSearches) ?> total search<?= $totalSearches === 1 ? '' : 'es' ?> recorded.</p>

      <?php if ($recentSearches): ?>
        <ul class="mini-list" style="margin-top:6px;">
          <?php foreach ($recentSearches as $s): ?>
            <li>
              <div class="mini-list__main">
                <span class="mini-list__title" style="font-weight:400;">"<?= htmlspecialchars($s['query']) ?>"</span>
                <span class="mini-list__meta"><?= htmlspecialchars(ucfirst($s['search_type'])) ?> · <?= (int)$s['results_count'] ?> result(s)</span>
              </div>
              <span class="mini-list__time"><?= htmlspecialchars(date('M j, g:i A', strtotime($s['created_at']))) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>
</div>

<?php if ($canAccess || $canAudit): ?>
<!-- ── Row 3: Access Control & Security + Recent Activity ── -->
<div class="dash-grid">
  <?php if ($canAccess): ?>
  <section class="module-card <?= $canAudit ? 'span-2' : 'span-3' ?>">
    <header class="module-card__header">
      <div>
        <h3>Access Control &amp; Security</h3>
        <p class="module-card__subtitle">Users &amp; roles in the system</p>
      </div>
      <a class="module-card__link" href="users.php">Open module →</a>
    </header>
    <div class="module-card__body">
      <?php foreach ($usersByRole as $ur): ?>
        <div class="bar-row">
          <span class="bar-label"><?= htmlspecialchars($ur['name']) ?></span>
          <div class="bar-track"><div class="bar-fill bar-fill--success" style="width: <?= _dash_pct((int)$ur['n'], $totalUsers) ?>%"></div></div>
          <span class="bar-value"><?= (int)$ur['n'] ?></span>
        </div>
      <?php endforeach; ?>

      <div class="split-stats">
        <div class="split-stat">
          <div class="split-stat__num"><?= number_format($totalUsers - $inactiveUsers) ?></div>
          <div class="split-stat__label">Active</div>
        </div>
        <div class="split-stat">
          <div class="split-stat__num"><?= number_format($inactiveUsers) ?></div>
          <div class="split-stat__label">Disabled</div>
        </div>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($canAudit): ?>
  <section class="module-card <?= $canAccess ? '' : 'span-3' ?>">
    <header class="module-card__header">
      <div>
        <h3>Recent Activity</h3>
        <p class="module-card__subtitle">Latest audit trail events</p>
      </div>
      <a class="module-card__link" href="audit_trail.php">View trail →</a>
    </header>
    <div class="module-card__body">
      <?php if ($recent): ?>
        <ul class="activity-list">
          <?php foreach ($recent as $a): ?>
            <li>
              <span class="activity-dot"></span>
              <span class="activity-body">
                <span class="activity-actor"><?= htmlspecialchars($a['username_snapshot'] ?? 'system') ?></span>
                <?= htmlspecialchars($a['action']) ?><?= $a['detail'] ? ' — ' . htmlspecialchars($a['detail']) : '' ?>
              </span>
              <time><?= htmlspecialchars(date('M j, g:i A', strtotime($a['created_at']))) ?></time>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="module-empty">No activity logged yet.</p>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Notification Bell Dropdown Panel -->
<div class="notif-dropdown" id="notif-dropdown">
  <div class="notif-dropdown__header">
    <span class="notif-dropdown__title">Notifications</span>
    <button type="button" class="notif-dropdown__mark-all" id="notif-mark-all" aria-label="Mark all as read">
      <i class="bi bi-check2-all"></i> Mark all read
    </button>
  </div>
  <ul class="notif-list" id="notif-list">
    <li class="notif-list__empty" id="notif-empty">
      <i class="bi bi-bell-slash"></i>
      <span>No new notifications</span>
    </li>
  </ul>
  <div class="notif-dropdown__footer" id="notif-footer" style="display:none;">
    <a class="notif-dropdown__view-all" href="audit_trail.php">View all activity →</a>
  </div>
</div>

<script>
  // Live dashboard clock + greeting — ticks every 15s so the greeting
  // and time stay correct even if the page is left open all day.
  (function () {
    var clock = document.getElementById('clock');
    var greeting = document.getElementById('greeting');
    if (!clock && !greeting) return;
    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function tick() {
      var now = new Date();
      var h24 = now.getHours();
      var h12 = h24 % 12 || 12;
      var ampm = h24 < 12 ? 'AM' : 'PM';
      if (clock) clock.textContent = h12 + ':' + pad(now.getMinutes()) + ' ' + ampm;
      if (greeting) {
        var g = h24 < 12 ? 'Good morning' : (h24 < 17 ? 'Good afternoon' : 'Good evening');
        if (greeting.textContent !== g) greeting.textContent = g;
      }
    }
    tick();
    setInterval(tick, 15000);
  })();
</script>
<?php include __DIR__ . '/includes/layout_bottom.php'; ?>

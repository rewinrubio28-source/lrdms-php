<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/config/database.php';

require_permission('audit', 'view');
$pdo = get_db();

$moduleFilter = $_GET['module'] ?? 'All';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

// Total matching records (drives the pagination)
$countSql = 'SELECT COUNT(*) FROM audit_log';
$params = [];
if ($moduleFilter !== 'All') {
    $countSql .= ' WHERE module = ?';
    $params[] = $moduleFilter;
}
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Current page of logs ($perPage/$offset are PHP ints → safe to inline)
<<<<<<< HEAD
$sql = 'SELECT a.*, u.full_name AS actor_full_name, r.name AS actor_role
        FROM audit_log a
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN roles r ON r.id = u.role_id';
if ($moduleFilter !== 'All') {
    $sql .= ' WHERE a.module = ?';
}
$sql .= ' ORDER BY a.created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
=======
$sql = 'SELECT * FROM audit_log';
if ($moduleFilter !== 'All') {
    $sql .= ' WHERE module = ?';
}
$sql .= ' ORDER BY created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
>>>>>>> origin/main
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$modules = $pdo->query('SELECT DISTINCT module FROM audit_log ORDER BY module')->fetchAll(PDO::FETCH_COLUMN);

// Query suffix shared by pagination links (keeps the module filter)
$qs = $moduleFilter !== 'All' ? '&module=' . urlencode($moduleFilter) : '';

include __DIR__ . '/includes/layout_top.php';
?>
<div class="topbar">
  <div class="d-flex align-items-center gap-2">
    <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="Open menu">
      <i class="bi bi-list"></i>
    </button>
    <div>
      <h1 class="topbar__title">Audit Trail</h1>
    </div>
  </div>
</div>

<div class="card">
  <form method="get" class="row g-2 mb-3">
    <div class="col-md-4">
      <select name="module" class="form-select">
        <option value="All">All modules</option>
        <?php foreach ($modules as $m): ?>
          <option value="<?= htmlspecialchars($m) ?>" <?= $moduleFilter === $m ? 'selected' : '' ?>><?= htmlspecialchars($m) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2"><button class="btn btn-outline-primary w-100">Filter</button></div>
    <div class="col-md-3"><a class="btn btn-outline-secondary w-100" href="api/export_audit.php?module=<?= urlencode($moduleFilter) ?>">Export CSV</a></div>
  </form>

  <div class="audit-scroll">
<<<<<<< HEAD
  <table class="table table-sm audit-table">
    <thead>
      <tr>
        <th>Timestamp</th>
        <th>User</th>
        <th>Role</th>
        <th>Module</th>
        <th>Action</th>
        <th>Detail</th>
        <th>IP Address</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($logs as $a): ?>
      <tr>
        <td class="text-nowrap"><?= htmlspecialchars(date('M j, Y \a\t g:i A', strtotime($a['created_at']))) ?></td>
        <td>
          <?php if (!empty($a['actor_full_name'])): ?>
            <?= htmlspecialchars($a['actor_full_name']) ?>
            <span class="text-muted small d-block">@<?= htmlspecialchars($a['username_snapshot'] ?? '') ?></span>
          <?php else: ?>
            <?= htmlspecialchars($a['username_snapshot'] ?? 'system') ?>
            <?php if (!empty($a['user_id'])): ?><span class="text-muted small d-block">account since deleted</span><?php endif; ?>
          <?php endif; ?>
        </td>
        <td><?= htmlspecialchars($a['actor_role'] ?? '—') ?></td>
        <td><?= htmlspecialchars($a['module']) ?></td>
        <td><?= htmlspecialchars($a['action']) ?></td>
        <td style="white-space:normal;word-break:break-word;"><?= htmlspecialchars($a['detail'] ?? '') ?></td>
        <td class="text-nowrap"><?= htmlspecialchars($a['ip_address'] ?? '—') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$logs): ?><tr><td colspan="7" class="text-muted small">No activity logged yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
=======
  <ul class="audit-list">
    <?php foreach ($logs as $a):
      $detail = $a['detail'] ?? '';
      $detailShort = mb_strlen($detail) > 80 ? mb_substr($detail, 0, 80) . '...' : $detail;
    ?>
      <li>
        <time><?= htmlspecialchars(date('M j, Y \a\t g:i A', strtotime($a['created_at']))) ?></time>
        <span class="actor"><?= htmlspecialchars($a['username_snapshot'] ?? 'system') ?></span>
        <span><?= htmlspecialchars($a['module']) ?> — <?= htmlspecialchars($a['action']) ?><?= $detailShort ? ' — ' . htmlspecialchars($detailShort) : '' ?></span>
      </li>
    <?php endforeach; ?>
    <?php if (!$logs): ?><li class="text-muted small">No activity logged yet.</li><?php endif; ?>
  </ul>
>>>>>>> origin/main
  </div>

  <?php if ($total > 0): ?>
  <nav class="audit-pagination">
    <span class="text-muted small"><?= number_format($total) ?> entries — page <?= $page ?> of <?= $totalPages ?></span>
    <ul class="pagination pagination-sm mb-0">
      <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
        <a class="page-link" href="audit_trail.php?page=<?= $page - 1 ?><?= $qs ?>">‹</a>
      </li>
      <?php
      $window = 2;
      $pages = [];
      for ($i = max(1, $page - $window); $i <= min($totalPages, $page + $window); $i++) $pages[] = $i;
      if (!in_array(1, $pages)): ?>
        <li class="page-item <?= $page == 1 ? 'active' : '' ?>"><a class="page-link" href="audit_trail.php?page=1<?= $qs ?>">1</a></li>
        <?php if (($pages[0] ?? 0) > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
      <?php endif;
      foreach ($pages as $p): ?>
        <li class="page-item <?= $p == $page ? 'active' : '' ?>"><a class="page-link" href="audit_trail.php?page=<?= $p ?><?= $qs ?>"><?= $p ?></a></li>
      <?php endforeach;
      if (!in_array($totalPages, $pages)):
        if (($pages[count($pages) - 1] ?? 0) < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
        <li class="page-item <?= $page == $totalPages ? 'active' : '' ?>"><a class="page-link" href="audit_trail.php?page=<?= $totalPages ?><?= $qs ?>"><?= $totalPages ?></a></li>
      <?php endif; ?>
      <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
        <a class="page-link" href="audit_trail.php?page=<?= $page + 1 ?><?= $qs ?>">›</a>
      </li>
    </ul>
  </nav>
  <?php endif; ?>
</div>

<<<<<<< HEAD
<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
=======
<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
>>>>>>> origin/main

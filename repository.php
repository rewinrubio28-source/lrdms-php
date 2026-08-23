<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/config/database.php';

require_login();
$user = current_user();
$pdo = get_db();

$statusFilter = $_GET['status'] ?? 'All';
$typeFilter = $_GET['type'] ?? 'All';
$q = trim($_GET['q'] ?? '');

list($visClause, $visParams) = document_visibility_clause($user);
$where = [$visClause];
$params = $visParams;

if ($statusFilter !== 'All') {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
}
if ($typeFilter !== 'All') {
    $where[] = 'doc_type = ?';
    $params[] = $typeFilter;
}
if ($q !== '') {
    $where[] = '(title LIKE ? OR doc_number LIKE ? OR sponsor LIKE ?)';
    $like = "%$q%";
    array_push($params, $like, $like, $like);
}

$sql = 'SELECT d.*, u.full_name AS owner_name FROM documents d
        JOIN users u ON u.id = d.owner_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY d.enactment_date DESC, d.created_at DESC
        LIMIT 200';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll();

include __DIR__ . '/includes/layout_top.php';
?>
<div class="topbar">
  <div class="d-flex align-items-center gap-2">
    <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="Open menu">
      <i class="bi bi-list"></i>
    </button>
    <div>
      <h1 class="topbar__title">Repository</h1>
    </div>
  </div>
</div>

<div class="card">
  <form method="get" class="row g-2 mb-3">
    <div class="col-md-4">
      <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" class="form-control" placeholder="Filter by title, number, or sponsor…">
    </div>
    <div class="col-md-3">
      <select name="status" class="form-select">
        <option value="All">All statuses</option>
        <?php foreach (['Draft', 'Submitted', 'Under Review', 'Enacted', 'Amended', 'Superseded', 'Withdrawn'] as $s): ?>
          <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <select name="type" class="form-select">
        <option value="All">All types</option>
        <?php foreach (['Ordinance', 'Resolution', 'Committee Report', 'Minutes', 'Other'] as $t): ?>
          <option value="<?= $t ?>" <?= $typeFilter === $t ? 'selected' : '' ?>><?= $t ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <button class="btn btn-outline-primary w-100">Filter</button>
    </div>
  </form>

  <?php if (!$documents): ?>
    <p class="text-muted">No documents match these filters (or your role's visibility rules don't allow seeing more).</p>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead><tr><th>Title</th><th>Type</th><th>Status</th><th>Enacted</th><th>Owner</th></tr></thead>
        <tbody>
        <?php foreach ($documents as $d): ?>
          <tr>
            <td>
              <a href="document.php?id=<?= $d['id'] ?>" class="doc-title"><?= htmlspecialchars($d['title']) ?></a>
              <div class="doc-number"><?= htmlspecialchars($d['doc_number']) ?></div>
            </td>
            <td><?= htmlspecialchars($d['doc_type']) ?></td>
            <td><span class="stamp stamp--<?= strtolower(str_replace(' ', '-', $d['status'])) ?>"><?= htmlspecialchars($d['status']) ?></span></td>
            <td><?= $d['enactment_date'] ? htmlspecialchars(date('M j, Y', strtotime($d['enactment_date']))) : '—' ?></td>
            <td class="small text-muted"><?= htmlspecialchars($d['owner_name']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>

<?php
/**
 * System Integrations — per-subsystem record containers.
 *
 * A single page (selected via ?sys=N) that shows the records each
 * subsystem exchanges with #6 (Legislative Records & Document
 * Management). Records arriving from a subsystem are Documents, so
 * they are filtered out of the shared documents table by doc_type
 * and/or the source_system tag the subsystem pushed them under.
 *
 * Subsystems with no records yet render an empty state — their
 * container is ready and will fill up once the integration pushes.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/config/database.php';

require_login();
$user = current_user();
$pdo = get_db();

$subsystems = [
    1 => [
        'name'         => 'Ordinance & Resolution Lifecycle',
        'filter'       => "doc_type IN ('Ordinance', 'Resolution')",
        'fns'          => ['Encoding', 'Repository', 'Version Control', 'Search', 'Audit Trail'],
    ],
    2 => [
        'name'         => 'Session & Legislative Meeting',
        'filter'       => "doc_type = 'Minutes'",
        'fns'          => ['Encoding', 'Repository', 'Retrieval', 'Audit Trail'],
    ],
    3 => [
        'name'         => 'Legislative Agenda & Calendar',
        'filter'       => "(doc_type = 'Agenda' OR source_system LIKE '%Agenda%')",
        'fns'          => ['Repository', 'Document Linking', 'Retrieval', 'Search'],
    ],
    4 => [
        'name'         => 'Committee Management & Assignment',
        'filter'       => "doc_type = 'Committee Report'",
        'fns'          => ['Repository', 'Version Control', 'Retrieval', 'Audit Trail'],
    ],
    5 => [
        'name'         => 'Voting, Quorum & Decision Support',
        'filter'       => "(doc_type = 'Decision Record' OR source_system LIKE '%Decision%' OR source_system LIKE '%Voting%')",
        'fns'          => ['Repository', 'Decision Record', 'Retrieval', 'Audit Trail'],
    ],
    7 => [
        'name'         => 'Public Hearing & Consultation',
        'filter'       => "(doc_type = 'Hearing Record' OR source_system LIKE '%Hearing%')",
        'fns'          => ['Storage', 'Search', 'Retrieval', 'Audit Trail'],
    ],
    8 => [
        'name'         => 'Legislative Archives & Historical Repository',
        'filter'       => "status = 'Enacted' AND next_version_id IS NULL",
        'fns'          => ['Archival Processing', 'Retention'],
    ],
    9 => [
        'name'         => 'Legislative Research, Policy Analysis & Impact Evaluation',
        'filter'       => "(doc_type = 'Research Report' OR source_system LIKE '%Research%')",
        'fns'          => ['Search', 'Retrieval', 'Repository'],
    ],
    10 => [
        'name'         => 'Citizen Engagement & Public Feedback',
        'filter'       => "(doc_type = 'Public Feedback' OR source_system LIKE '%Feedback%' OR source_system LIKE '%Citizen%')",
        'fns'          => ['Record Association', 'Storage', 'Retrieval', 'Audit Trail'],
    ],
];

$sys = (int)($_GET['sys'] ?? 1);
if (!isset($subsystems[$sys])) {
    $sys = 1;
}
$sub = $subsystems[$sys];


list($clause, $params) = document_visibility_clause($user);
$stmt = $pdo->prepare("SELECT d.*, u.full_name AS owner_name, c.name AS committee_name
                       FROM documents d
                       JOIN users u ON u.id = d.owner_id
                       LEFT JOIN committees c ON c.id = d.committee_id
                       WHERE $clause AND ({$sub['filter']})
                       ORDER BY d.enactment_date DESC, d.created_at DESC, d.id DESC
                       LIMIT 200");
$stmt->execute($params);
$records = $stmt->fetchAll();
$count = count($records);

include __DIR__ . '/includes/layout_top.php';
?>
<div class="topbar">
  <div class="d-flex align-items-center gap-2">
    <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="Open menu">
      <i class="bi bi-list"></i>
    </button>
    <div>
      <div class="topbar__eyebrow">Repository</div>
      <h1 class="topbar__title"><?= htmlspecialchars($sub['name']) ?></h1>
    </div>
  </div>
</div>

<div class="card">
  <form method="get" class="row g-2 mb-3 align-items-center">
    <div class="col-md-5">
      <select name="sys" class="form-select" onchange="this.form.submit()">
        <?php foreach ($subsystems as $num => $s): ?>
          <option value="<?= $num ?>" <?= $num === $sys ? 'selected' : '' ?>>
            <?= htmlspecialchars($s['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-7">
      <a class="btn btn-outline-primary btn-sm" href="repository.php">Browse full repository</a>
    </div>
  </form>

  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
      <h3 class="mt-2 mb-1"><?= htmlspecialchars($sub['name']) ?></h3>
    </div>
    <div class="split-stat" style="min-width: 120px;">
      <div class="split-stat__num"><?= number_format($count) ?></div>
      <div class="split-stat__label">Records in container</div>
    </div>
  </div>

  <div class="int-fns" style="padding-top: 0; margin-bottom: 16px;">
    <?php foreach ($sub['fns'] as $fn): ?>
      <span class="int-fn"><?= htmlspecialchars($fn) ?></span>
    <?php endforeach; ?>
  </div>

  <?php if ($records): ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th>Number</th>
            <th>Title</th>
            <th>Sponsor</th>
            <th>Committee</th>
            <th>Status</th>
            <th>Source</th>
            <th>Enacted</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($records as $d): ?>
            <tr>
              <td class="doc-number"><?= htmlspecialchars($d['doc_number']) ?></td>
              <td><a class="doc-title" href="document.php?id=<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['title']) ?></a></td>
              <td><?= htmlspecialchars($d['sponsor'] ?: '—') ?></td>
              <td><?= htmlspecialchars($d['committee_name'] ?: '—') ?></td>
              <td><span class="stamp stamp--<?= strtolower(str_replace(' ', '-', $d['status'])) ?>"><?= htmlspecialchars($d['status']) ?></span></td>
              <td class="text-muted"><?= htmlspecialchars($d['source_system']) ?></td>
              <td class="text-muted"><?= $d['enactment_date'] ? date('M j, Y', strtotime($d['enactment_date'])) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="module-note">
      <span>Coming soon</span>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>

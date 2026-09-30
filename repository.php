<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/config/database.php';

require_once __DIR__ . '/includes/council_terms.php';
require_once __DIR__ . '/includes/storage.php';
require_login();
$user = current_user();
$pdo = get_db();

$statusFilter = $_GET['status'] ?? 'All';
$typeFilter = $_GET['type'] ?? 'All';
$repositorySections = ['ordinances' => 'Ordinance', 'resolutions' => 'Resolution'];
$repositorySection = is_string($_GET['section'] ?? null) && isset($repositorySections[$_GET['section']]) ? $_GET['section'] : '';
if ($repositorySection !== '') $typeFilter = $repositorySections[$repositorySection];
$repositoryTitle = $repositorySection !== '' ? ucfirst($repositorySection) : 'Repository';
$classificationFilter = $_GET['classification'] ?? 'All';
$termFilter = is_string($_GET['council_term'] ?? null) ? $_GET['council_term'] : 'All';
if ($repositorySection === 'resolutions') $termFilter = 'All';
$q = trim($_GET['q'] ?? '');
$committeeFilter = $_GET['committee'] ?? 'All';
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

list($visClause, $visParams) = document_visibility_clause($user);
$where = ['(' . $visClause . ')'];
$params = $visParams;

if ($termFilter === 'unassigned') {
    $where[] = 'd.council_term IS NULL';
} elseif ($termFilter !== 'All') {
    $where[] = 'd.council_term = ?';
    $params[] = ctype_digit($termFilter) ? (int)$termFilter : -1;
}
if ($statusFilter !== 'All') {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
}
if ($typeFilter !== 'All') {
    $where[] = 'doc_type = ?';
    $params[] = $typeFilter;
}
if ($classificationFilter !== 'All') {
    $where[] = 'd.classification = ?';
    $params[] = $classificationFilter;
}
if ($committeeFilter !== 'All') {
    $where[] = 'd.committee_id = ?';
    $params[] = $committeeFilter;
}
if ($dateFrom !== '') {
    $where[] = 'd.enactment_date >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'd.enactment_date <= ?';
    $params[] = $dateTo;
}
if ($q !== '') {
    $where[] = '(title LIKE ? OR doc_number LIKE ? OR sponsor LIKE ?)';
    $like = "%$q%";
    array_push($params, $like, $like, $like);
}

$committees = $pdo->query('SELECT id, name FROM committees ORDER BY name')->fetchAll();

$termStmt = $pdo->prepare('SELECT DISTINCT d.council_term FROM documents d WHERE (' . $visClause . ') AND d.council_term IS NOT NULL ORDER BY d.council_term DESC');
$termStmt->execute($visParams);
$councilTerms = $termStmt->fetchAll(PDO::FETCH_COLUMN);
// Include the council groups requested in the reference, plus later assigned terms.
$councilTerms = array_unique(array_merge(range(1, 13), array_map('intval', $councilTerms)));
rsort($councilTerms, SORT_NUMERIC);
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM documents d JOIN users u ON u.id=d.owner_id WHERE ' . implode(' AND ', $where));
$countStmt->execute($params);
$totalDocuments = (int)$countStmt->fetchColumn();
$pageSize = 20;
$totalPages = max(1, (int)ceil($totalDocuments / $pageSize));
$page = min($totalPages, max(1, (int)($_GET['page'] ?? 1)));
$offset = ($page - 1) * $pageSize;

$sql = 'SELECT d.*, u.full_name AS owner_name, c.name AS committee_name FROM documents d
        JOIN users u ON u.id = d.owner_id
        LEFT JOIN committees c ON c.id = d.committee_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY d.enactment_date DESC, d.created_at DESC, d.id DESC
        LIMIT ' . $pageSize . ' OFFSET ' . $offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll();

// Load all attachments for the current page in one query to avoid N+1 queries.
$attachmentsByDoc = [];
if ($documents) {
    $ids = array_column($documents, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $attStmt = $pdo->prepare(
        "SELECT document_id, file_path FROM document_attachments
         WHERE document_id IN ($placeholders) ORDER BY document_id, sort_order"
    );
    $attStmt->execute($ids);
    foreach ($attStmt->fetchAll() as $row) {
        $attachmentsByDoc[$row['document_id']][] = $row['file_path'];
    }
}

// AJAX live-filter: when the request comes from fetch() (repository.js),
// render only the results table and skip the full page layout.
$isAjax = (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_GET['ajax']) && $_GET['ajax'] === '1')
);

if (!$isAjax) {
    include __DIR__ . '/includes/layout_top.php';
}
?>
<?php
// Renders just the results (used for both the initial full page load and
// every AJAX refresh triggered by repository.js), so the two never drift apart.
function render_repository_results(array $documents, array $attachmentsByDoc = [], int $totalDocuments = 0, int $page = 1, int $totalPages = 1): void {
    ?><div class="repo-results-heading"><div><i class="bi bi-collection" aria-hidden="true"></i> <strong><?= $totalDocuments ?></strong> records &middot; Page <?= $page ?> of <?= $totalPages ?></div><span>Newest enactment first</span></div><?php
    if (!$documents): ?>
        <p class="text-muted" id="repo-empty-msg">No documents match these filters (or your role's visibility rules don't allow seeing more).</p>
    <?php else: ?>
        <?php foreach ($documents as $d): ?>
          <div class="card doc-card">
            <div class="doc-card__header flex-wrap">
              <span><i class="bi bi-file-earmark-text" aria-hidden="true"></i> <?= htmlspecialchars($d['doc_number']) ?></span>
              <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="repo-type-tag"><?= htmlspecialchars($d['doc_type']) ?></span>
                <?php if (trim((string)($d['classification'] ?? '')) !== ''): ?>
                <span class="classification-tag classification-tag--<?= htmlspecialchars(strtolower($d['classification']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($d['classification']) ?></span>
                <?php endif; ?>
              </div>
            </div>
            <div class="doc-card__body">
              <div class="doc-card__row">
                <div class="doc-card__label">Title:</div>
              <div class="doc-card__value doc-card__value--title"><a href="document.php?id=<?= $d['id'] ?>"><?= htmlspecialchars($d['title']) ?></a></div>
              </div>
              <div class="doc-card__row">
                <div class="doc-card__label">Type:</div>
                <div class="doc-card__value"><?= htmlspecialchars($d['doc_type']) ?></div>
              </div>
              <div class="doc-card__row">
                <div class="doc-card__label">Council term:</div>
                <div class="doc-card__value"><?= !empty($d['council_term']) ? htmlspecialchars(council_term_label((int)$d['council_term'])) : 'Not assigned' ?></div>
              </div>
              <?php if (!empty($d['sponsor'])): ?>
              <div class="doc-card__row">
                <div class="doc-card__label">Sponsor:</div>
                <div class="doc-card__value"><?= htmlspecialchars($d['sponsor']) ?></div>
              </div>
              <?php endif; ?>
              <?php if (!empty($d['committee_name'])): ?>
              <div class="doc-card__row">
                <div class="doc-card__label">Primary Referral:</div>
                <div class="doc-card__value"><?= htmlspecialchars($d['committee_name']) ?></div>
              </div>
              <?php endif; ?>
              <div class="doc-card__row">
                <div class="doc-card__label">Enactment Date:</div>
                <div class="doc-card__value"><?= $d['enactment_date'] ? htmlspecialchars(date('M j, Y', strtotime($d['enactment_date']))) : '—' ?></div>
              </div>
              <div class="doc-card__row">
                <div class="doc-card__label">Source System:</div>
                <div class="doc-card__value"><?= htmlspecialchars($d['source_system']) ?><?= $d['verified_at'] ? ' · verified ' . htmlspecialchars(date('M j, Y', strtotime($d['verified_at']))) : '' ?></div>
              </div>
              <div class="doc-card__row">
                <div class="doc-card__label">Status:</div>
                <div class="doc-card__value"><span class="stamp stamp--<?= strtolower(str_replace(' ', '-', $d['status'])) ?>"><?= htmlspecialchars($d['status']) ?></span></div>
              </div>
            </div>
            <div class="doc-card__actions">
              <a href="document.php?id=<?= (int)$d['id'] ?>" class="btn btn-primary btn-sm">Open Document <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
              <a href="#" data-doc-id="<?= $d['id'] ?>" data-bs-toggle="modal" data-bs-target="#historyModal" class="btn btn-outline-secondary btn-sm open-history-modal"><i class="bi bi-clock-history"></i> History</a>
              <?php
                $files = $attachmentsByDoc[$d['id']] ?? [];
                if (!$files && !empty($d['file_path'])) {
                    $files = [$d['file_path']];
                }
              ?>
              <?php $files = array_map('record_file_url', $files); ?>
              <?php if ($files): ?>
                <a href="#"
                   data-files='<?= htmlspecialchars(json_encode(array_values($files)), ENT_QUOTES, 'UTF-8') ?>'
                   data-file="<?= htmlspecialchars($files[0], ENT_QUOTES, 'UTF-8') ?>"
                   data-bs-toggle="modal"
                   data-bs-target="#filePreviewModal"
                   class="btn btn-outline-secondary btn-sm open-file-modal">
                  <i class="bi bi-file-earmark-text"></i> Text As Filed<?= count($files) > 1 ? ' (' . count($files) . ')' : '' ?>
                </a>
              <?php elseif (!empty($d['ocr_text'])): ?>
                <a href="document_text.php?id=<?= (int)$d['id'] ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-text"></i> Text As Filed</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
    <?php endif;
    if ($totalPages > 1): ?>
      <nav class="d-flex justify-content-between align-items-center my-3" aria-label="Repository pages">
        <?php foreach (['Previous' => $page - 1, 'Next' => $page + 1] as $label => $target): ?>
          <?php if ($target >= 1 && $target <= $totalPages):
            $pageParams = $_GET; unset($pageParams['ajax']); $pageParams['page'] = $target; ?>
            <a class="btn btn-outline-secondary btn-sm" data-repo-page="<?= $target ?>" href="repository.php?<?= htmlspecialchars(http_build_query($pageParams), ENT_QUOTES, 'UTF-8') ?>"><?= $label ?></a>
          <?php else: ?><span class="btn btn-outline-secondary btn-sm disabled" aria-disabled="true"><?= $label ?></span><?php endif; ?>
        <?php endforeach; ?>
      </nav>
    <?php endif;
}

// AJAX refresh: output only the results markup and stop — no layout, no form.
if ($isAjax) {
    render_repository_results($documents, $attachmentsByDoc, $totalDocuments, $page, $totalPages);
    exit;
}
?>
<div class="topbar" data-banner-date="<?= date('M j, Y') ?>">
  <div class="d-flex align-items-center gap-2">
    <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="Open menu">
      <i class="bi bi-list"></i>
    </button>
    <div>
      <h1 class="topbar__title"><?= htmlspecialchars($repositoryTitle) ?></h1>
      <p class="module-banner-description">Browse and manage the legislative records collection.</p>
      <p class="repo-page-subtitle">Browse legislative records, view attachments, and trace document history.</p>
    </div>
  </div>
</div>

<link rel="stylesheet" href="assets/css/repository-workspace.css?v=1">
<div class="repo-workspace">
  <form method="get" class="row g-2 mb-3" id="repo-filter-form">
    <?php if ($repositorySection !== ''): ?><input type="hidden" name="section" id="repo-section" value="<?= htmlspecialchars($repositorySection) ?>"><?php endif; ?>
    <div class="col-md-3">
      <label class="form-label small text-muted mb-0" for="repo-q">Search</label>
      <input type="text" name="q" id="repo-q" value="<?= htmlspecialchars($q) ?>" class="form-control" placeholder="Filter by title, number, or sponsor…" autocomplete="off">
    </div>
    <div class="col-md-2">
      <label class="form-label small text-muted mb-0" for="repo-status">Status</label>
      <select name="status" id="repo-status" class="form-select">
        <option value="All">All statuses</option>
        <?php foreach (['Enacted', 'Amended', 'Rejected'] as $s): ?>
          <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small text-muted mb-0" for="repo-type">Type</label>
      <select name="type" id="repo-type" class="form-select" <?= $repositorySection !== '' ? 'disabled' : '' ?>>
        <option value="All">All types</option>
        <?php foreach (['Ordinance', 'Resolution', 'Committee Report', 'Minutes', 'Other'] as $t): ?>
          <option value="<?= $t ?>" <?= $typeFilter === $t ? 'selected' : '' ?>><?= $t ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label small text-muted mb-0" for="repo-classification">Classification</label>
      <select name="classification" id="repo-classification" class="form-select">
        <option value="All">All</option>
        <?php foreach (['PUBLIC' => 'Public', 'INTERNAL' => 'Internal', 'RESTRICTED' => 'Restricted', 'CONFIDENTIAL' => 'Confidential'] as $value => $label): ?>
          <option value="<?= $value ?>" <?= $classificationFilter === $value ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2 d-flex align-items-end">
      <a href="repository.php<?= $repositorySection !== '' ? '?section=' . rawurlencode($repositorySection) : '' ?>" class="btn btn-outline-secondary w-100" id="repo-reset">Reset</a>
    </div>
    <?php if ($repositorySection !== 'resolutions'): ?>
    <div class="col-md-3">
      <label class="form-label small text-muted mb-0" for="repo-council-term">Council term</label>
      <select name="council_term" id="repo-council-term" class="form-select">
        <option value="All">All council terms</option>
        <option value="unassigned" <?= $termFilter === 'unassigned' ? 'selected' : '' ?>>Not assigned</option>
        <?php foreach ($councilTerms as $term): ?>
          <option value="<?= (int)$term ?>" <?= $termFilter === (string)$term ? 'selected' : '' ?>><?= htmlspecialchars(council_term_label((int)$term)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div class="col-md-3">
      <label class="form-label small text-muted mb-0" for="repo-committee">Committee</label>
      <select name="committee" id="repo-committee" class="form-select">
        <option value="All">All committees</option>
        <?php foreach ($committees as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= (string)$committeeFilter === (string)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small text-muted mb-0" for="repo-date-from">Enacted from</label>
      <input type="date" name="date_from" id="repo-date-from" value="<?= htmlspecialchars($dateFrom) ?>" class="form-control">
    </div>
    <div class="col-md-2">
      <label class="form-label small text-muted mb-0" for="repo-date-to">Enacted to</label>
      <input type="date" name="date_to" id="repo-date-to" value="<?= htmlspecialchars($dateTo) ?>" class="form-control">
    </div>
    <div class="col-md-1 d-flex flex-column">
      <label class="form-label small text-muted mb-0">&nbsp;</label>
      <button type="submit" class="btn btn-primary btn-sm flex-fill" id="repo-filter-btn">Filter</button>
    </div>
  </form>

  <div id="repo-results">
    <?php render_repository_results($documents, $attachmentsByDoc, $totalDocuments, $page, $totalPages); ?>
  </div>
</div>

<script src="assets/js/repository.js"></script>

<div class="modal fade" id="filePreviewModal" tabindex="-1" aria-labelledby="filePreviewModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-md-down">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="filePreviewModalLabel">Document file</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-0 d-flex justify-content-center align-items-center" style="min-height:70vh;">
        <iframe id="filePreviewIframe" style="width:100%; height:70vh; border:none; display:none;" title="Document file preview"></iframe>
        <img id="filePreviewImg" style="max-width:100%; max-height:70vh; object-fit:contain; display:none;" alt="Document image preview">
      </div>
      <div class="modal-footer">
        <a id="filePreviewFullLink" href="#" target="_blank" class="btn btn-outline-primary btn-sm">Open in new tab</a>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  // Shared file-preview modal for the whole Repository. Supports one or many
  // attachments and remains compatible with AJAX re-renders.
  var modalEl = document.getElementById('filePreviewModal');
  if (!modalEl) return;

  var files = [];
  var currentIndex = 0;

  function isImage(fp) {
    var ext = fp.split('.').pop().toLowerCase();
    return ['jpg','jpeg','png','gif','webp','bmp','svg'].indexOf(ext) !== -1;
  }

  function showFile(index) {
    if (!files.length) return;

    currentIndex = Math.max(0, Math.min(index, files.length - 1));
    var filePath = files[currentIndex];

    var iframe = document.getElementById('filePreviewIframe');
    var img = document.getElementById('filePreviewImg');
    var fullLink = document.getElementById('filePreviewFullLink');
    var nav = document.getElementById('filePreviewNav');
    var prev = document.getElementById('filePreviewPrev');
    var next = document.getElementById('filePreviewNext');
    var counter = document.getElementById('filePreviewCounter');

    if (isImage(filePath)) {
      img.src = filePath;
      img.style.display = 'block';
      iframe.style.display = 'none';
      iframe.src = 'about:blank';
    } else {
      iframe.src = filePath;
      iframe.style.display = 'block';
      img.style.display = 'none';
      img.src = '';
    }

    fullLink.href = filePath;

    if (files.length > 1) {
      nav.style.display = 'flex';
      counter.textContent = (currentIndex + 1) + ' of ' + files.length;
      prev.disabled = currentIndex === 0;
      next.disabled = currentIndex === files.length - 1;
    } else {
      nav.style.display = 'none';
    }
  }

  modalEl.addEventListener('show.bs.modal', function (event) {
    var trigger = event.relatedTarget;
    var dataFiles = trigger.getAttribute('data-files');

    try {
      files = dataFiles ? JSON.parse(dataFiles) : [];
    } catch (e) {
      files = [];
    }

    if (!files.length) {
      var single = trigger.getAttribute('data-file');
      if (single) files = [single];
    }

    showFile(0);
  });

  document.getElementById('filePreviewPrev').addEventListener('click', function () {
    showFile(currentIndex - 1);
  });

  document.getElementById('filePreviewNext').addEventListener('click', function () {
    showFile(currentIndex + 1);
  });

  modalEl.addEventListener('hidden.bs.modal', function () {
    files = [];
    currentIndex = 0;
    document.getElementById('filePreviewIframe').src = 'about:blank';
    document.getElementById('filePreviewImg').src = '';
    document.getElementById('filePreviewNav').style.display = 'none';
  });
})();
</script>

<div class="modal fade" id="historyModal" tabindex="-1" aria-labelledby="historyModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="historyModalLabel">Document history</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-0">
        <iframe id="historyIframe" style="width:100%; height:420px; border:none;" title="Document version history"></iframe>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  // History modal — embeds document_history.php: a small, read-only
  // page showing just this document's version chain and change notes.
  // Not the Version Control module (no compare/rollback here).
  var modalEl = document.getElementById('historyModal');
  if (!modalEl) return;
  modalEl.addEventListener('show.bs.modal', function (event) {
    var trigger = event.relatedTarget;
    var docId = trigger.getAttribute('data-doc-id');
    var url = 'document_history.php?id=' + encodeURIComponent(docId);
    document.getElementById('historyIframe').src = url;
  });
  modalEl.addEventListener('hidden.bs.modal', function () {
    document.getElementById('historyIframe').src = 'about:blank';
  });
})();
</script>

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>

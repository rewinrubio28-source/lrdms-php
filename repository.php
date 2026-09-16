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
$committeeFilter = $_GET['committee'] ?? 'All';
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

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

$sql = 'SELECT d.*, u.full_name AS owner_name, c.name AS committee_name FROM documents d
        JOIN users u ON u.id = d.owner_id
        LEFT JOIN committees c ON c.id = d.committee_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY d.enactment_date DESC, d.created_at DESC
        LIMIT 200';
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
function render_repository_results(array $documents, array $attachmentsByDoc = []): void {
    if (!$documents): ?>
        <p class="text-muted" id="repo-empty-msg">No documents match these filters (or your role's visibility rules don't allow seeing more).</p>
    <?php else: ?>
        <?php foreach ($documents as $d): ?>
          <div class="card doc-card">
            <div class="doc-card__header"><?= htmlspecialchars($d['doc_number']) ?></div>
            <div class="doc-card__body">
              <div class="doc-card__row">
                <div class="doc-card__label">Title:</div>
                <div class="doc-card__value doc-card__value--title"><a href="document.php?id=<?= $d['id'] ?>"><?= htmlspecialchars($d['title']) ?></a></div>
              </div>
              <div class="doc-card__row">
                <div class="doc-card__label">Type:</div>
                <div class="doc-card__value"><?= htmlspecialchars($d['doc_type']) ?></div>
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
              <a href="#" data-doc-id="<?= $d['id'] ?>" data-bs-toggle="modal" data-bs-target="#historyModal" class="btn btn-outline-secondary btn-sm open-history-modal"><i class="bi bi-clock-history"></i> History</a>
              <?php
                $files = $attachmentsByDoc[$d['id']] ?? [];
                if (!$files && !empty($d['file_path'])) {
                    $files = [$d['file_path']];
                }
              ?>
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
}

// AJAX refresh: output only the results markup and stop — no layout, no form.
if ($isAjax) {
    render_repository_results($documents, $attachmentsByDoc);
    exit;
}
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
  <form method="get" class="row g-2 mb-3" id="repo-filter-form">
    <div class="col-md-4">
      <label class="form-label small text-muted mb-0" for="repo-q">Search</label>
      <input type="text" name="q" id="repo-q" value="<?= htmlspecialchars($q) ?>" class="form-control" placeholder="Filter by title, number, or sponsor…" autocomplete="off">
    </div>
    <div class="col-md-3">
      <label class="form-label small text-muted mb-0" for="repo-status">Status</label>
      <select name="status" id="repo-status" class="form-select">
        <option value="All">All statuses</option>
        <?php foreach (['Enacted', 'Amended', 'Superseded', 'Withdrawn', 'Rejected'] as $s): ?>
          <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label small text-muted mb-0" for="repo-type">Type</label>
      <select name="type" id="repo-type" class="form-select">
        <option value="All">All types</option>
        <?php foreach (['Ordinance', 'Resolution', 'Committee Report', 'Minutes', 'Other'] as $t): ?>
          <option value="<?= $t ?>" <?= $typeFilter === $t ? 'selected' : '' ?>><?= $t ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2 d-flex align-items-end">
      <a href="repository.php" class="btn btn-outline-secondary w-100" id="repo-reset">Reset</a>
    </div>
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
    <?php render_repository_results($documents); ?>
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
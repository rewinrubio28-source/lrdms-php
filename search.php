<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/semantic_search.php';
require_once __DIR__ . '/includes/saved_searches.php';
require_once __DIR__ . '/config/database.php';

require_permission('search', 'run');
$user = current_user();
$pdo = get_db();
list($visClause, $visParams) = document_visibility_clause($user);

$query = trim($_GET['q'] ?? '');
$mode = ($_GET['mode'] ?? 'keyword') === 'semantic' ? 'semantic' : 'keyword';
$typeFilter = $_GET['doc_type'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$results = [];

// The query string the user currently has (q/mode/doc_type/status/
// date_from/date_to) — used as the action URL for the Save/Rename/Delete
// saved-search forms below, so submitting one of them (a POST) doesn't
// lose whatever search is currently on screen (GET params in a URL are
// preserved on a POST request; only the POST body is separate).
$currentQs = http_build_query([
    'q' => $query, 'mode' => $mode, 'doc_type' => $typeFilter,
    'status' => $statusFilter, 'date_from' => $dateFrom, 'date_to' => $dateTo,
]);

$savedSearchErrors = [];
$savedSearchSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $savedSearchErrors[] = 'Security token expired. Please refresh the page and try again.';
    } else {
        $formAction = $_POST['form_action'] ?? '';

        if ($formAction === 'save_search') {
            $name = trim($_POST['name'] ?? '');
            if ($name === '') {
                $savedSearchErrors[] = 'Please enter a name for the saved search.';
            } elseif ($query === '') {
                // Mirrors the existing page's own rule (see the "Enter a
                // query above" empty state below) — there's no search
                // configuration to save until a query has actually been run.
                $savedSearchErrors[] = 'Run a search before saving it.';
            } else {
                $criteria = [
                    'q' => $query, 'mode' => $mode, 'doc_type' => $typeFilter,
                    'status' => $statusFilter, 'date_from' => $dateFrom, 'date_to' => $dateTo,
                ];
                create_saved_search($pdo, $user['id'], $name, $criteria);
                log_action('search', 'saved_search_created', $name);
                $savedSearchSuccess = 'Saved search "' . $name . '" created.';
            }
        } elseif ($formAction === 'rename_saved_search') {
            $savedSearchId = (int)($_POST['saved_search_id'] ?? 0);
            $newName = trim($_POST['name'] ?? '');
            if ($newName === '') {
                $savedSearchErrors[] = 'Please enter a name.';
            } elseif (rename_saved_search($pdo, $user['id'], $savedSearchId, $newName)) {
                log_action('search', 'saved_search_renamed', 'id=' . $savedSearchId . ' -> "' . $newName . '"');
                $savedSearchSuccess = 'Saved search renamed.';
            } else {
                $savedSearchErrors[] = 'Saved search not found.';
            }
        } elseif ($formAction === 'delete_saved_search') {
            $savedSearchId = (int)($_POST['saved_search_id'] ?? 0);
            if (delete_saved_search($pdo, $user['id'], $savedSearchId)) {
                log_action('search', 'saved_search_deleted', 'id=' . $savedSearchId);
                $savedSearchSuccess = 'Saved search deleted.';
            } else {
                $savedSearchErrors[] = 'Saved search not found.';
            }
        }
    }
}

$savedSearches = list_saved_searches($pdo, $user['id']);

if ($query !== '') {
    $results = $mode === 'semantic'
        ? semantic_search($pdo, $query, $visClause, $visParams)
        : keyword_search($pdo, $query, $visClause, $visParams);

    // Apply client-side filters (no changes to search functions)
    if ($typeFilter !== '') {
        $results = array_filter($results, function ($d) use ($typeFilter) {
            return $d['doc_type'] === $typeFilter;
        });
    }
    if ($statusFilter !== '') {
        $results = array_filter($results, function ($d) use ($statusFilter) {
            return $d['status'] === $statusFilter;
        });
    }
    if ($dateFrom !== '') {
        $results = array_filter($results, function ($d) use ($dateFrom) {
            return ($d['enactment_date'] ?? '') >= $dateFrom;
        });
    }
    if ($dateTo !== '') {
        $results = array_filter($results, function ($d) use ($dateTo) {
            return ($d['enactment_date'] ?? '') <= $dateTo;
        });
    }
    $results = array_values($results);

    $stmt = $pdo->prepare('INSERT INTO search_log (user_id, query, search_type, results_count) VALUES (?,?,?,?)');
    $stmt->execute([$user['id'], $query, $mode, count($results)]);
    log_action('search', 'ran_search', "($mode) \"$query\" — " . count($results) . ' results');
}

/**
 * Highlights search terms in text using <mark> tags.
 */
function highlight_terms($text, $query) {
    if ($query === '' || $text === '') return htmlspecialchars($text);
    $escaped = preg_quote($query, '/');
    $safe = htmlspecialchars($text);
    return preg_replace("/($escaped)/i", '<mark style="background:#fef08a;padding:1px 2px;border-radius:2px;">$1</mark>', $safe);
}

include __DIR__ . '/includes/layout_top.php';
?>
<div class="topbar">
  <div class="d-flex align-items-center gap-2">
    <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="Open menu">
      <i class="bi bi-list"></i>
    </button>
    <div>
      <h1 class="topbar__title">Retrieval &amp; Search</h1>
    </div>
  </div>
</div>

<?php if ($savedSearchSuccess): ?><div class="alert alert-success"><?= htmlspecialchars($savedSearchSuccess) ?></div><?php endif; ?>
<?php if ($savedSearchErrors): ?><div class="alert alert-danger"><?php foreach ($savedSearchErrors as $e) echo htmlspecialchars($e) . '<br>'; ?></div><?php endif; ?>

<div class="card">
  <form method="get" class="row g-2">
    <div class="col-md-4">
      <input type="text" name="q" value="<?= htmlspecialchars($query) ?>" class="form-control" placeholder="Search title, document number, or extracted text…">
    </div>
    <div class="col-md-2">
      <select name="mode" class="form-select form-select-sm" title="Keyword matches exact words. Semantic matches by meaning via the BERT service, and falls back to keyword automatically if that service is down.">
        <option value="keyword" <?= $mode === 'keyword' ? 'selected' : '' ?>>Keyword</option>
        <option value="semantic" <?= $mode === 'semantic' ? 'selected' : '' ?>>Semantic (BERT)</option>
      </select>
    </div>
    <div class="col-md-2">
      <select name="doc_type" class="form-select form-select-sm">
        <option value="">All Types</option>
        <?php foreach (['Ordinance','Resolution','Committee Report','Minutes'] as $t): ?>
          <option value="<?= $t ?>" <?= $typeFilter === $t ? 'selected' : '' ?>><?= $t ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <select name="status" class="form-select form-select-sm">
        <option value="">All Status</option>
        <?php foreach (['Draft','Submitted','Under Review','Enacted','Amended','Superseded','Withdrawn'] as $s): ?>
          <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2"><button class="btn btn-primary w-100">Search</button></div>
    <div class="col-12 mt-1">
      <details <?= ($dateFrom || $dateTo) ? 'open' : '' ?>>
        <summary class="text-muted small" style="cursor:pointer;">Date range filter</summary>
        <div class="row g-2 mt-1">
          <div class="col-md-3">
            <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="form-control form-control-sm" placeholder="From">
          </div>
          <div class="col-md-3">
            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="form-control form-control-sm" placeholder="To">
          </div>
        </div>
      </details>
    </div>
    <?php if ($query !== ''): ?>
    <div class="col-12 mt-1">
      <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#saveSearchModal">
        <i class="bi bi-star me-1"></i>Save Search
      </button>
    </div>
    <?php endif; ?>
  </form>
</div>

<div class="card">
  <h3 style="font-size:16px;">My Saved Searches</h3>
  <?php if (!$savedSearches): ?>
    <p class="text-muted small mb-0">No saved searches yet. Save a search to quickly reuse your frequently used legislative queries.</p>
  <?php else: ?>
    <ul class="list-unstyled mb-0">
      <?php foreach ($savedSearches as $s): ?>
        <li class="d-flex justify-content-between align-items-center border-bottom py-2">
          <div>
            <a href="search.php?<?= htmlspecialchars(saved_search_query_string($s['criteria'])) ?>" class="text-decoration-none">
              <i class="bi bi-star-fill text-warning me-1"></i><strong><?= htmlspecialchars($s['name']) ?></strong>
            </a>
            <div class="text-muted small mt-1">
              "<?= htmlspecialchars($s['criteria']['q'] ?? '') ?>"
              <?= ($s['criteria']['mode'] ?? 'keyword') === 'semantic' ? '· Semantic' : '· Keyword' ?>
              <?php if (!empty($s['criteria']['doc_type'])): ?>· <?= htmlspecialchars($s['criteria']['doc_type']) ?><?php endif; ?>
              <?php if (!empty($s['criteria']['status'])): ?>· <?= htmlspecialchars($s['criteria']['status']) ?><?php endif; ?>
            </div>
          </div>
          <div class="text-nowrap ms-2">
            <a class="btn btn-outline-primary btn-sm" href="search.php?<?= htmlspecialchars(saved_search_query_string($s['criteria'])) ?>">Run</a>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#renameSavedSearchModal-<?= (int)$s['id'] ?>">Rename</button>
            <form method="post" class="d-inline" action="search.php?<?= htmlspecialchars($currentQs) ?>">
              <input type="hidden" name="form_action" value="delete_saved_search">
              <input type="hidden" name="saved_search_id" value="<?= (int)$s['id'] ?>">
              <button class="btn btn-outline-danger btn-sm" onclick="return confirm('Delete saved search &quot;<?= htmlspecialchars($s['name'], ENT_QUOTES) ?>&quot;?')">Delete</button>
            </form>
          </div>
        </li>

        <!-- Rename modal for this saved search -->
        <div class="modal fade" id="renameSavedSearchModal-<?= (int)$s['id'] ?>" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <form method="post" action="search.php?<?= htmlspecialchars($currentQs) ?>">
                <input type="hidden" name="form_action" value="rename_saved_search">
                <input type="hidden" name="saved_search_id" value="<?= (int)$s['id'] ?>">
                <div class="modal-header">
                  <h5 class="modal-title">Rename saved search</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                  <label class="form-label small">Name</label>
                  <input type="text" name="name" class="form-control form-control-sm" value="<?= htmlspecialchars($s['name']) ?>" required autofocus>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                  <button class="btn btn-primary btn-sm">Save</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<!-- Save Search modal -->
<div class="modal fade" id="saveSearchModal" tabindex="-1" aria-labelledby="saveSearchModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" action="search.php?<?= htmlspecialchars($currentQs) ?>">
        <input type="hidden" name="form_action" value="save_search">
        <div class="modal-header">
          <h5 class="modal-title" id="saveSearchModalLabel">Save Search</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <label class="form-label small">Name</label>
          <input type="text" name="name" class="form-control form-control-sm" placeholder="e.g. 2026 Traffic Ordinances" required autofocus>
          <p class="text-muted small mt-2 mb-0">This saves your current search terms and filters — not today's results. Running it later will search the database as it is at that time.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary btn-sm">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="card">
  <?php if ($query === ''): ?>
    <p class="text-muted">Enter a query above to search the repository.</p>
  <?php elseif (!$results): ?>
    <p class="text-muted">No matching documents (within what your role can see).</p>
  <?php else: ?>
    <p class="text-muted small mb-3"><?= count($results) ?> result<?= count($results) !== 1 ? 's' : '' ?> found</p>
    <?php foreach ($results as $d): ?>
      <div class="result-item" style="border-bottom:1px solid #f1f5f9;padding:14px 0;">
        <a href="document.php?id=<?= $d['id'] ?>" class="doc-title" style="font-weight:600;color:var(--lrdms-navy);text-decoration:none;font-size:15px;"><?= highlight_terms($d['title'], $query) ?></a>
        <div class="doc-number" style="font-size:13px;color:#6b7690;margin:4px 0;">
          <?= highlight_terms($d['doc_number'], $query) ?> · <?= htmlspecialchars($d['doc_type']) ?>
          · <?= $d['enactment_date'] ? date('M j, Y', strtotime($d['enactment_date'])) : '' ?>
        </div>
        <span class="stamp stamp--<?= strtolower(str_replace(' ', '-', $d['status'])) ?>"><?= htmlspecialchars($d['status']) ?></span>
        <p class="snippet" style="font-size:13px;color:#4a5568;margin:6px 0 0;"><?= highlight_terms(mb_substr(strip_tags((string)$d['ocr_text']), 0, 200), $query) ?>…</p>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
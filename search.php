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
$documentSearchReturn = rawurlencode('search.php' . ($_GET ? '?' . http_build_query($_GET) : ''));
list($visClause, $visParams) = document_visibility_clause($user);

$query = trim($_GET['q'] ?? '');
$mode = ($_GET['mode'] ?? 'keyword') === 'semantic' ? 'semantic' : 'keyword';
$typeFilter = $_GET['doc_type'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$sortBy = in_array($_GET['sort'] ?? '', ['relevance', 'newest', 'oldest', 'title_asc'], true)
    ? $_GET['sort'] : 'relevance';
$view = ($_GET['view'] ?? 'list') === 'grid' ? 'grid' : 'list';
$results = [];
$yearFilter = trim($_GET['year'] ?? '');
$committeeFilter = (int)($_GET['committee_id'] ?? 0);
$officeFilter = trim($_GET['office'] ?? '');
$classificationFilter = trim($_GET['classification'] ?? '');
$searchPage = max(1, (int)($_GET['page'] ?? 1));
$searchTotal = 0;
$searchPages = 1;
$hasCriteria = $query !== '' || $typeFilter !== '' || $statusFilter !== '' || $dateFrom !== '' || $dateTo !== '' || $yearFilter !== '' || $committeeFilter || $officeFilter !== '' || $classificationFilter !== '';
$extraCriteria = ['year' => $yearFilter, 'committee_id' => $committeeFilter, 'office' => $officeFilter, 'classification' => $classificationFilter];
$searchCommittees = $pdo->query('SELECT id, name FROM committees ORDER BY name')->fetchAll();
$officeStmt = $pdo->prepare("SELECT DISTINCT d.originating_office FROM documents d WHERE ($visClause) AND d.verified_at IS NOT NULL AND d.originating_office IS NOT NULL AND d.originating_office <> '' ORDER BY d.originating_office");
$officeStmt->execute($visParams);
$searchOffices = $officeStmt->fetchAll(PDO::FETCH_COLUMN);
// Apply all filters to eligible rows before ranking or limiting results.
$visClause = '(' . $visClause . ') AND d.verified_at IS NOT NULL';
foreach (['doc_type' => $typeFilter, 'status' => $statusFilter, 'originating_office' => $officeFilter, 'classification' => $classificationFilter] as $column => $value) {
    if ($value !== '') { $visClause .= " AND d.$column = ?"; $visParams[] = $value; }
}
if ($committeeFilter) { $visClause .= ' AND d.committee_id = ?'; $visParams[] = $committeeFilter; }
if ($yearFilter !== '') { $visClause .= ' AND YEAR(d.enactment_date) = ?'; $visParams[] = (int)$yearFilter; }
if ($dateFrom !== '') { $visClause .= ' AND d.enactment_date >= ?'; $visParams[] = $dateFrom; }
if ($dateTo !== '') { $visClause .= ' AND d.enactment_date <= ?'; $visParams[] = $dateTo; }

// The query string the user currently has (q/mode/doc_type/status/
// date_from/date_to/sort) — used as the action URL for the Save/Rename/
// Delete saved-search forms below, so submitting one of them (a POST)
// doesn't lose whatever search is currently on screen (GET params in a
// URL are preserved on a POST request; only the POST body is separate).
$currentQs = http_build_query([
    'q' => $query, 'mode' => $mode, 'doc_type' => $typeFilter,
    'status' => $statusFilter, 'date_from' => $dateFrom, 'date_to' => $dateTo,
    'sort' => $sortBy,
]);
$currentQs .= '&' . http_build_query($extraCriteria);

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
            } elseif (!$hasCriteria) {
                // Mirrors the existing page's own rule (see the "Enter a
                // query above" empty state below) — there's no search
                // configuration to save until a query has actually been run.
                $savedSearchErrors[] = 'Run a search before saving it.';
            } else {
                $criteria = [
                    'q' => $query, 'mode' => $mode, 'doc_type' => $typeFilter,
                    'status' => $statusFilter, 'date_from' => $dateFrom, 'date_to' => $dateTo,
                    'sort' => $sortBy,
                ];
                create_saved_search($pdo, $user['id'], $name, array_merge($criteria, $extraCriteria));
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

if ($hasCriteria) {
    $searchExecution = ['effective_mode' => 'keyword', 'fallback' => false];
    $results = $mode === 'semantic' && $query !== ''
        ? semantic_search($pdo, $query, $visClause, $visParams, null, $searchExecution)
        : keyword_search($pdo, $query, $visClause, $visParams, null);

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

    // Sort — "Relevance" leaves the ranking that keyword_search()/
    // semantic_search() already produced untouched.
    if ($sortBy === 'newest') {
        usort($results, fn($a, $b) => strcmp($b['enactment_date'] ?? '', $a['enactment_date'] ?? ''));
    } elseif ($sortBy === 'oldest') {
        usort($results, fn($a, $b) => strcmp($a['enactment_date'] ?? '', $b['enactment_date'] ?? ''));
    } elseif ($sortBy === 'title_asc') {
        usort($results, fn($a, $b) => strcasecmp($a['title'], $b['title']));
    }

    $stmt = $pdo->prepare('INSERT INTO search_log (user_id, query, search_type, results_count) VALUES (?,?,?,?)');
    $stmt->execute([$user['id'], $query, $searchExecution['effective_mode'], count($results)]);
    log_action('search', 'ran_search', 'requested=' . $mode . ' effective=' . $searchExecution['effective_mode'] . ' query=' . $query . ' results=' . count($results));
    $searchTotal = count($results);
    $searchPages = max(1, (int)ceil($searchTotal / 20));
    $searchPage = min($searchPage, $searchPages);
    $results = array_slice($results, ($searchPage - 1) * 20, 20);
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

// How many attachments each result has — one grouped query for every
// result on the page rather than one query per row.
$attachmentCounts = [];
if ($results) {
    $ids = array_column($results, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $attStmt = $pdo->prepare("SELECT document_id, COUNT(*) c FROM document_attachments WHERE document_id IN ($placeholders) GROUP BY document_id");
    $attStmt->execute($ids);
    foreach ($attStmt->fetchAll() as $row) {
        $attachmentCounts[$row['document_id']] = (int)$row['c'];
    }
}

/**
 * Counts how many versions precede this one by walking previous_version_id
 * back to the root. Capped so a data error can't loop forever.
 */
function count_previous_versions($pdo, $previousId) {
    $count = 0;
    $steps = 0;
    while ($previousId && $steps < 25) {
        $count++;
        $steps++;
        $stmt = $pdo->prepare('SELECT previous_version_id FROM documents WHERE id = ?');
        $stmt->execute([$previousId]);
        $previousId = $stmt->fetchColumn();
    }
    return $count;
}

$docTypeIcons = [
    'Ordinance' => 'bi-journal-richtext',
    'Resolution' => 'bi-clipboard-check',
    'Committee Report' => 'bi-people',
    'Minutes' => 'bi-clock-history',
    'Other' => 'bi-file-earmark-text',
];

include __DIR__ . '/includes/layout_top.php';
?>
<style>
  .search-hero__title { color: var(--text-primary); font-size: 21px; font-weight: 700; margin: 0; }
  .search-hero__subtitle { color: var(--text-muted); font-size: 13.5px; margin: 3px 0 0; }

  .filters-card { padding: 20px; }
  .filters-card label.field-label {
    display: block; font-size: 11px; font-weight: 700; letter-spacing: .04em;
    color: var(--text-subtle); text-transform: uppercase; margin-bottom: 6px;
  }
  .filters-card .search-input-wrap { position: relative; }
  .filters-card .search-input-wrap i.bi-search {
    position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-subtle);
  }
  .filters-card .search-input-wrap input.form-control { padding-left: 34px; }
  .filters-row { display: flex; flex-wrap: wrap; gap: 16px; margin-top: 16px; }
  .filters-row .filter-group { flex: 1 1 160px; min-width: 140px; }
  .filters-row .filter-group--years { flex: 1 1 200px; }
  .filters-row .filter-group--years .years-inputs { display: flex; align-items: center; gap: 6px; }
  .filters-row .filter-group--submit { flex: 0 0 auto; align-self: flex-end; }
  .filters-card .semantic-toggle {
    display: flex; align-items: center; gap: 8px; margin-top: 12px; font-size: 12.5px; color: var(--text-muted);
  }
  .btn-apply-filters {
    background: var(--primary); border: 1px solid var(--primary); color: #fff;
    font-weight: 600; padding: 8px 22px; border-radius: 8px; white-space: nowrap;
  }
  .btn-apply-filters:hover { background: var(--primary-dark); border-color: var(--primary-dark); color: #fff; }

  .results-toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
  .results-toolbar__count { color: var(--text-muted); font-size: 13px; }
  .results-toolbar__count strong { color: var(--text-primary); }
  .view-toggle { display: flex; gap: 4px; }
  .view-toggle a {
    display: inline-flex; align-items: center; justify-content: center;
    width: 30px; height: 30px; border-radius: 6px; color: var(--text-subtle);
    border: 1px solid transparent; text-decoration: none;
  }
  .view-toggle a.is-active { background: var(--primary-soft-bg); color: var(--primary-soft-text); border-color: var(--border); }
  .view-toggle a:hover { color: var(--text-primary); }

  .results-wrap.is-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 14px; }
  .results-wrap.is-grid .result-card { height: 100%; }

  .result-card {
    display: flex; gap: 14px; padding: 16px; border: 1px solid var(--border);
    border-radius: 12px; background: var(--surface2); margin-bottom: 12px;
  }
  .results-wrap.is-grid .result-card { flex-direction: column; margin-bottom: 0; }
  .result-card__icon {
    flex: 0 0 40px; width: 40px; height: 40px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center; font-size: 17px;
  }
  .doctype-badge--ordinance         { background: var(--primary-soft-bg); color: var(--primary-soft-text); }
  .doctype-badge--resolution        { background: var(--info-soft-bg); color: var(--info-soft-text); }
  .doctype-badge--committee-report  { background: var(--accent-soft-bg); color: var(--accent-soft-text); }
  .doctype-badge--minutes           { background: var(--warning-soft-bg); color: var(--warning-soft-text); }
  .doctype-badge--other             { background: var(--neutral-soft-bg); color: var(--neutral-soft-text); }

  .result-card__body { flex: 1; min-width: 0; }
  .result-card__top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
  .result-card__title { font-weight: 700; color: var(--text-primary); text-decoration: none; font-size: 15px; }
  .result-card__title:hover { text-decoration: underline; }
  .result-card__meta { font-size: 12px; color: var(--text-subtle); text-transform: uppercase; letter-spacing: .02em; margin: 4px 0 8px; }
  .result-card__snippet { font-size: 13px; color: var(--text-muted); margin: 0 0 12px; line-height: 1.5; }
  .result-card__footer { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; border-top: 1px solid var(--border); padding-top: 10px; }
  .result-card__facts { display: flex; gap: 16px; font-size: 12.5px; color: var(--text-subtle); }
  .result-card__facts span { display: inline-flex; align-items: center; gap: 5px; }
  .result-card__actions { display: flex; gap: 8px; }
  .result-card__actions .btn { padding: 5px 14px; font-size: 12.5px; }
</style>

<div class="topbar" data-banner-date="<?= date('M j, Y') ?>">
  <div class="d-flex align-items-center gap-2">
    <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="Open menu">
      <i class="bi bi-list"></i>
    </button>
    <div>
      <h1 class="search-hero__title">Search and Document Retrieval</h1>
      <p class="search-hero__subtitle">Find legislative records, view document details, and request copies.</p>
    </div>
  </div>
</div>

<?php if ($savedSearchSuccess): ?><div class="alert alert-success"><?= htmlspecialchars($savedSearchSuccess) ?></div><?php endif; ?>
<?php if ($savedSearchErrors): ?><div class="alert alert-danger"><?php foreach ($savedSearchErrors as $e) echo htmlspecialchars($e) . '<br>'; ?></div><?php endif; ?>

<div class="card filters-card">
  <div class="d-flex justify-content-end mb-3"><a href="copy_requests.php?return=<?= $documentSearchReturn ?>" class="btn btn-outline-primary btn-sm">Document Copy Requests</a></div>
  <form method="get" id="advanced-search-form">
    <div class="row g-3 align-items-end">
      <div class="col-md-9">
        <label class="field-label">Global Keyword Search</label>
        <div class="search-input-wrap">
          <i class="bi bi-search"></i>
          <input type="text" name="q" value="<?= htmlspecialchars($query) ?>" class="form-control" placeholder="Search by Title, Subject, Author, or Keyword…">
        </div>
      </div>
      <div class="col-md-3">
        <label class="field-label">Document Type</label>
        <select name="doc_type" class="form-select">
          <option value="">All Records</option>
          <?php foreach (['Ordinance','Resolution','Committee Report','Minutes'] as $t): ?>
            <option value="<?= $t ?>" <?= $typeFilter === $t ? 'selected' : '' ?>><?= $t ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <?php include __DIR__ . '/includes/search_extra_filters.php'; ?>
    <label class="semantic-toggle">
      <input class="form-check-input" type="checkbox" name="mode" value="semantic" id="semanticToggle" <?= $mode === 'semantic' ? 'checked' : '' ?>>
      Use semantic search (matches by meaning, via the BERT service — falls back to keyword automatically)
    </label>

    <div class="filters-row">
      <div class="filter-group filter-group--years">
        <label class="field-label">Enactment Date Range</label>
        <div class="years-inputs">
          <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="form-control" placeholder="From">
          <span class="text-muted">–</span>
          <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="form-control" placeholder="To">
        </div>
      </div>
      <div class="filter-group">
        <label class="field-label">Status</label>
        <select name="status" class="form-select">
          <option value="">Any Status</option>
          <?php foreach (['Enacted','Amended','Rejected'] as $s): ?>
            <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-group">
        <label class="field-label">Sort By</label>
        <select name="sort" class="form-select">
          <option value="relevance" <?= $sortBy === 'relevance' ? 'selected' : '' ?>>Relevance</option>
          <option value="newest" <?= $sortBy === 'newest' ? 'selected' : '' ?>>Newest First</option>
          <option value="oldest" <?= $sortBy === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
          <option value="title_asc" <?= $sortBy === 'title_asc' ? 'selected' : '' ?>>Title A–Z</option>
        </select>
      </div>
      <div class="filter-group filter-group--submit">
        <button class="btn btn-apply-filters">Apply Filters</button>
      </div>
    </div>

    <?php if ($hasCriteria): ?>
    <div class="mt-3">
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
            <a href="search.php?<?= htmlspecialchars(saved_search_query_string($s['criteria'])) ?>" class="text-decoration-none action-link">
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
  <?php if (!empty($searchExecution['fallback'])): ?>
    <div class="alert alert-warning" role="status">Semantic search is temporarily unavailable. Showing keyword matches instead.</div>
  <?php endif; ?>
  <?php if (!$hasCriteria): ?>
    <p class="text-muted mb-0">Enter a keyword or choose filters to find a registered record.</p>
  <?php elseif (!$results): ?>
    <p class="text-muted mb-0">No matching documents (within what your role can see).</p>
  <?php else: ?>
    <div class="results-toolbar">
      <p class="results-toolbar__count mb-0"><strong><?= $searchTotal ?></strong> matching records · Page <?= $searchPage ?> of <?= $searchPages ?></p>
      <div class="view-toggle">
        <a href="search.php?<?= htmlspecialchars($currentQs) ?>&view=list" class="<?= $view === 'list' ? 'is-active' : '' ?>" title="List view"><i class="bi bi-list-ul"></i></a>
        <a href="search.php?<?= htmlspecialchars($currentQs) ?>&view=grid" class="<?= $view === 'grid' ? 'is-active' : '' ?>" title="Grid view"><i class="bi bi-grid-3x3-gap"></i></a>
      </div>
    </div>

    <div class="results-wrap <?= $view === 'grid' ? 'is-grid' : '' ?>">
      <?php foreach ($results as $d):
        $typeClass = strtolower(str_replace(' ', '-', $d['doc_type']));
        $icon = $docTypeIcons[$d['doc_type']] ?? 'bi-file-earmark-text';
        $attCount = $attachmentCounts[$d['id']] ?? 0;
        $prevCount = $d['previous_version_id'] ? count_previous_versions($pdo, $d['previous_version_id']) : 0;
        $firstFile = null;
        if ($attCount) {
            $fileStmt = $pdo->prepare('SELECT file_path FROM document_attachments WHERE document_id = ? ORDER BY sort_order, id LIMIT 1');
            $fileStmt->execute([$d['id']]);
            $firstFile = $fileStmt->fetchColumn() ?: null;
        }
        if (!$firstFile && $d['file_path']) $firstFile = $d['file_path'];
      ?>
        <div class="result-card">
          <div class="result-card__icon doctype-badge doctype-badge--<?= $typeClass ?>"><i class="bi <?= $icon ?>"></i></div>
          <div class="result-card__body">
            <div class="result-card__top">
              <a href="document.php?id=<?= $d['id'] ?>&amp;return=<?= $documentSearchReturn ?>" class="result-card__title action-link"><?= highlight_terms($d['title'], $query) ?></a>
              <span class="stamp stamp--<?= strtolower(str_replace(' ', '-', $d['status'])) ?>"><?= htmlspecialchars($d['status']) ?></span>
            </div>
            <div class="result-card__meta">
              <?= highlight_terms($d['doc_number'], $query) ?>
              <?php if ($d['enactment_date']): ?> · <?= strtoupper($d['status']) ?>: <?= strtoupper(date('M j, Y', strtotime($d['enactment_date']))) ?><?php endif; ?>
            </div>
            <p class="result-card__snippet"><?= highlight_terms(mb_substr(strip_tags((string)$d['ocr_text']), 0, 200), $query) ?>…</p>
            <div class="result-card__footer">
              <div class="result-card__facts">
                <?php if ($attCount): ?><span><i class="bi bi-paperclip"></i> <?= $attCount ?> Attachment<?= $attCount !== 1 ? 's' : '' ?></span><?php endif; ?>
                <?php if ($prevCount): ?><span><i class="bi bi-clock-history"></i> <?= $prevCount ?> Previous Version<?= $prevCount !== 1 ? 's' : '' ?></span><?php endif; ?>
              </div>
              <div class="result-card__actions">
                <a href="document.php?id=<?= $d['id'] ?>&amp;return=<?= $documentSearchReturn ?>" class="btn btn-outline-secondary btn-sm">Open Document</a>
                <?php if ($firstFile && has_permission('repository', 'download')): ?>
                  <a href="download_document.php?id=<?= (int)$d['id'] ?>" class="btn btn-primary btn-sm">Download File</a>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if ($searchPages > 1): ?><nav class="d-flex justify-content-between mt-3" aria-label="Search result pages"><span><?php if ($searchPage > 1): ?><a class="btn btn-outline-primary btn-sm" href="search.php?<?= htmlspecialchars($currentQs) ?>&view=<?= $view ?>&page=<?= $searchPage-1 ?>">Previous</a><?php endif; ?></span><?php if ($searchPage < $searchPages): ?><a class="btn btn-outline-primary btn-sm" href="search.php?<?= htmlspecialchars($currentQs) ?>&view=<?= $view ?>&page=<?= $searchPage+1 ?>">Next</a><?php endif; ?></nav><?php endif; ?>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>

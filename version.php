<?php
/**
 * Version Control — Module 02.
 *
 * Previously, versioning only existed *inside* document.php (one document
 * at a time): the amend flow, and a horizontal chain view. This page adds
 * the missing pieces:
 *   - A first-class module you can navigate to directly, browsing any
 *     document's full revision chain without opening it first.
 *   - Version Comparison — pick any two versions in a chain and see what
 *     changed between them.
 *   - Rollback / Restore — the 'version','rollback' permission has existed
 *     in role_permissions since sql/schema.sql, granted to Records Officer,
 *     but nothing implemented it until now.
 *
 * Rollback never deletes or overwrites history — consistent with the amend
 * flow in document.php, it creates a new current version carrying the
 * restored content forward, and closes out the old current version as
 * 'Amended'. The chain only ever grows.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/legislative.php';
require_once __DIR__ . '/config/database.php';

require_login();
$user = current_user();
$pdo = get_db();

function fetch_doc_row($pdo, $id) {
    $stmt = $pdo->prepare('SELECT d.*, u.full_name AS owner_name FROM documents d JOIN users u ON u.id = d.owner_id WHERE d.id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/** Walks previous_version_id back to the root, then next_version_id forward. Returns oldest -> newest. */
function version_chain($pdo, $doc) {
    $head = $doc;
    while ($head['previous_version_id']) {
        $head = fetch_doc_row($pdo, $head['previous_version_id']);
    }
    $chain = [];
    $walker = $head;
    while ($walker) {
        $chain[] = $walker;
        $walker = $walker['next_version_id'] ? fetch_doc_row($pdo, $walker['next_version_id']) : null;
    }
    return $chain;
}

function latest_note($pdo, $documentId) {
    $stmt = $pdo->prepare('SELECT n.*, u.full_name FROM document_change_notes n JOIN users u ON u.id = n.created_by WHERE document_id = ? ORDER BY n.created_at DESC LIMIT 1');
    $stmt->execute([$documentId]);
    return $stmt->fetch();
}

/**
 * Computes a simple line-by-line diff between two texts.
 * Returns an array of ['status' => 'same'|'added'|'removed', 'text' => ...].
 */
function _compute_diff($oldText, $newText) {
    $oldLines = preg_split('/\r?\n/', $oldText ?: '');
    $newLines = preg_split('/\r?\n/', $newText ?: '');

    // Simple LCS-based diff
    $m = count($oldLines);
    $n = count($newLines);
    $dp = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));
    for ($i = 1; $i <= $m; $i++) {
        for ($j = 1; $j <= $n; $j++) {
            if ($oldLines[$i - 1] === $newLines[$j - 1]) {
                $dp[$i][$j] = $dp[$i - 1][$j - 1] + 1;
            } else {
                $dp[$i][$j] = max($dp[$i - 1][$j], $dp[$i][$j - 1]);
            }
        }
    }

    // Backtrack to build diff
    $diff = [];
    $i = $m; $j = $n;
    while ($i > 0 || $j > 0) {
        if ($i > 0 && $j > 0 && $oldLines[$i - 1] === $newLines[$j - 1]) {
            array_unshift($diff, ['status' => 'same', 'text' => $oldLines[$i - 1]]);
            $i--; $j--;
        } elseif ($j > 0 && ($i === 0 || $dp[$i][$j - 1] >= $dp[$i - 1][$j])) {
            array_unshift($diff, ['status' => 'added', 'text' => $newLines[$j - 1]]);
            $j--;
        } else {
            array_unshift($diff, ['status' => 'removed', 'text' => $oldLines[$i - 1]]);
            $i--;
        }
    }
    return $diff;
}

/**
 * Renders a diff array as HTML with colored highlighting.
 */
function _render_diff($diff) {
    if (empty($diff)) return '<p class="text-muted small">Both versions are identical.</p>';
    $html = '<div class="diff-block">';
    foreach ($diff as $line) {
        $cls = $line['status'] === 'added' ? 'diff-add' : ($line['status'] === 'removed' ? 'diff-del' : 'diff-same');
        $prefix = $line['status'] === 'added' ? '+' : ($line['status'] === 'removed' ? '−' : ' ');
        $html .= '<div class="' . $cls . '"><span class="diff-prefix">' . $prefix . '</span>' . htmlspecialchars($line['text']) . '</div>';
    }
    $html .= '</div>';
    return $html;
}

$message = '';
$errors = [];

if (isset($_GET['restored'])) $message = 'Restored an earlier version as the new current version.';

// ------------------------------------------------------------
// ROLLBACK / RESTORE
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rollback') {
    if (!validate_csrf()) {
        $errors[] = 'Security token expired. Please refresh the page and try again.';
    }
    if (validate_csrf()) {
    if (!has_permission('version', 'rollback')) {
        $errors[] = 'Your role cannot roll back versions.';
    } else {
        $targetId = (int)($_POST['target_id'] ?? 0);
        $target = fetch_doc_row($pdo, $targetId);
        if (!$target) {
            $errors[] = 'Version not found.';
        } else {
            $chain = version_chain($pdo, $target);
            $head = end($chain);
            if ((int)$head['id'] === (int)$target['id']) {
                $errors[] = 'That is already the current version.';
            } elseif (in_array($head['status'], ['Superseded', 'Withdrawn'], true)) {
                $errors[] = 'This document is closed and cannot be restored.';
            } else {
                $newId = null;
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare(
                        'INSERT INTO documents
                           (doc_number, title, doc_type, sponsor, committee_id, owner_id, status, is_public,
                            source_system, enactment_date, file_path, ocr_text, previous_version_id)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
                    );
                    $stmt->execute([
                        $target['doc_number'], $target['title'], $target['doc_type'], $target['sponsor'],
                        $target['committee_id'], $user['id'], 'Enacted', $head['is_public'],
                        $head['source_system'], $target['enactment_date'], $target['file_path'], $target['ocr_text'],
                        $head['id'],
                    ]);
                    $newId = $pdo->lastInsertId();

                    $pdo->prepare('UPDATE documents SET status = ?, next_version_id = ? WHERE id = ?')
                        ->execute(['Amended', $newId, $head['id']]);

                    $userNote = trim($_POST['rollback_note'] ?? '');
                    $noteText = $userNote !== ''
                        ? $userNote
                        : 'Restored content from the version enacted '
                            . ($target['enactment_date'] ? date('M j, Y', strtotime($target['enactment_date'])) : 'on an earlier, undated version') . '.';
                    $pdo->prepare('INSERT INTO document_change_notes (document_id, note, created_by) VALUES (?,?,?)')
                        ->execute([$newId, $noteText, $user['id']]);

                    $pdo->commit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $errors[] = 'Rollback failed: ' . $e->getMessage();
                    $newId = null;
                }

                if (!empty($newId)) {
                    log_action('version', 'rolled_back', $target['doc_number'] . ' — restored version #' . $targetId . ' as new current version #' . $newId);
                    header('Location: version.php?doc=' . $newId . '&tab=rollback&restored=1#version-tabs');
                    exit;
                }
            }
        }
    }
    }
}

// ------------------------------------------------------------
// RELATED LEGISLATION — add / remove relationship
// Gated by the same 'version','amend' permission that already governs
// creating a new version, per the "reuse existing permissions" rule.
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_relationship') {
    if (!validate_csrf()) {
        $errors[] = 'Security token expired. Please refresh the page and try again.';
    } elseif (!has_permission('version', 'amend')) {
        $errors[] = 'Your role cannot link related legislation.';
    } else {
        $fromId = (int)($_POST['from_id'] ?? 0);
        $relatedId = (int)($_POST['related_id'] ?? 0);
        $type = $_POST['relationship_type'] ?? '';
        if (!$fromId || !$relatedId) {
            $errors[] = 'Select a document to relate this record to.';
        } else {
            $res = add_relationship($pdo, $fromId, $relatedId, $type, $user['id']);
            if (!$res['ok']) {
                $errors[] = $res['error'];
            } else {
                log_action('version', 'related_document', "#$fromId related to #$relatedId ($type)");
                header('Location: version.php?doc=' . $fromId . '&tab=related&related=1#version-tabs');
                exit;
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_relationship') {
    if (!validate_csrf()) {
        $errors[] = 'Security token expired. Please refresh the page and try again.';
    } elseif (!has_permission('version', 'amend')) {
        $errors[] = 'Your role cannot remove related legislation.';
    } else {
        $relId = (int)($_POST['rel_id'] ?? 0);
        $backTo = (int)($_POST['from_id'] ?? 0);
        if ($relId) {
            remove_relationship($pdo, $relId);
            log_action('version', 'unrelated_document', "relationship #$relId removed");
        }
        header('Location: version.php?doc=' . $backTo . '&tab=related#version-tabs');
        exit;
    }
}

if (isset($_GET['related'])) $message = 'Related legislation linked.';

// ------------------------------------------------------------
// DOCUMENT PICKER (scoped by the same visibility rules as Repository)
// ------------------------------------------------------------
list($visClause, $visParams) = document_visibility_clause($user);
$sql = "SELECT d.*, u.full_name AS owner_name FROM documents d JOIN users u ON u.id = d.owner_id
        WHERE d.next_version_id IS NULL AND ($visClause)
        ORDER BY d.title";
$stmt = $pdo->prepare($sql);
$stmt->execute($visParams);
$heads = $stmt->fetchAll();

$selectedId = (int)($_GET['doc'] ?? 0);
$selected = null;
foreach ($heads as $h) { if ((int)$h['id'] === $selectedId) { $selected = $h; break; } }
if (!$selected && $heads) $selected = $heads[0];

$committeeName = null;
if ($selected && $selected['committee_id']) {
    $cStmt = $pdo->prepare('SELECT name FROM committees WHERE id = ?');
    $cStmt->execute([$selected['committee_id']]);
    $committeeName = $cStmt->fetchColumn() ?: null;
}

$tab = $_GET['tab'] ?? 'overview';
if (!in_array($tab, ['overview', 'history', 'compare', 'related', 'rollback'], true)) $tab = 'overview';

$chain = $selected ? version_chain($pdo, $selected) : [];
$chainDesc = array_reverse($chain); // newest -> oldest, matches the reference timeline layout
$chainTotal = count($chainDesc);
$chainIds = array_map(function ($n) { return (int)$n['id']; }, $chainDesc);
$relationships = $selected ? get_document_relationships($pdo, $chainIds) : [];
$relationshipCount = array_sum(array_map('count', $relationships));

// comparison selections
$aId = (int)($_GET['a'] ?? ($chainDesc[0]['id'] ?? 0));
$bId = (int)($_GET['b'] ?? ($chainDesc[1]['id'] ?? ($chainDesc[0]['id'] ?? 0)));
$docA = null; $docB = null;
foreach ($chainDesc as $idx => $n) {
    if ((int)$n['id'] === $aId) $docA = ['row' => $n, 'v' => $chainTotal - $idx];
    if ((int)$n['id'] === $bId) $docB = ['row' => $n, 'v' => $chainTotal - $idx];
}

include __DIR__ . '/includes/layout_top.php';
?>
<div class="topbar">
  <div class="d-flex align-items-center gap-2">
    <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="Open menu">
      <i class="bi bi-list"></i>
    </button>
    <div>
      <p class="topbar__eyebrow mb-0">Legislative Records &amp; Retrieval</p>
      <h1 class="topbar__title">Version Control</h1>
    </div>
  </div>
</div>

<style>
  /* ── Diff block styles ── */
  .diff-section { margin-top:20px; }
  .diff-header { font-size:14px; font-weight:600; color:var(--lrdms-navy); margin-bottom:10px; display:flex; align-items:center; gap:6px; }
  .diff-block { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; font-family:'Courier New',monospace; font-size:13px; line-height:1.6; max-height:400px; overflow-y:auto; }
  .diff-block > div { padding:2px 12px; border-bottom:1px solid #f1f5f9; white-space:pre-wrap; word-wrap:break-word; }
  .diff-add { background:#dcfce7; color:#166534; }
  .diff-del { background:#fee2e2; color:#991b1b; }
  .diff-same { color:#64748b; }
  .diff-prefix { display:inline-block; width:16px; font-weight:700; color:#94a3b8; }

  /* ── Timeline icon badges ── */
  .vtimeline__icon { width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:14px; color:#fff; flex-shrink:0; }
  .vtimeline__icon--original { background:#6366f1; }
  .vtimeline__icon--amend { background:var(--lrdms-gold); }
  .vtimeline__icon--rollback { background:#10b981; }
</style>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?></div><?php endif; ?>

<!-- ============================================================
     LEGISLATIVE DOCUMENT SEARCH
     AJAX-driven, hits api/version_lookup.php (session-authenticated,
     RBAC-scoped, head-documents-only, capped result count) instead of
     loading every document into a dropdown.
     ============================================================ -->
<div class="card lrdms-search-card">
  <h2 class="lrdms-search-title"><i class="bi bi-search"></i> Legislative Document Search</h2>
  <div class="lrdms-search-row">
    <input type="text" id="lrdms-search-input" class="form-control" autocomplete="off"
           placeholder="Search ordinance/resolution number, title, subject, sponsor, keyword…">
  </div>
  <div class="lrdms-quick-filters">
    <select id="lrdms-filter-type" class="form-select form-select-sm">
      <option value="">All Types</option>
      <?php foreach (['Ordinance','Resolution','Committee Report','Minutes','Other'] as $t): ?>
        <option value="<?= $t ?>"><?= $t ?></option>
      <?php endforeach; ?>
    </select>
    <select id="lrdms-filter-status" class="form-select form-select-sm">
      <option value="">All Status</option>
      <?php foreach (['Draft','Submitted','Under Review','Enacted','Amended','Superseded','Withdrawn'] as $s): ?>
        <option value="<?= $s ?>"><?= $s ?></option>
      <?php endforeach; ?>
    </select>
    <span id="lrdms-search-count" class="text-muted small"></span>
  </div>
  <div id="lrdms-search-results" class="lrdms-search-results"></div>

  <details class="lrdms-browse-all" <?= (!$heads || $selected) ? '' : 'open' ?>>
    <summary>Browse all documents (secondary — use search above for large repositories)</summary>
    <form method="get" class="row g-2 align-items-end mt-2" id="doc-picker-form">
      <div class="col-md-12" id="doc-picker-select-col">
        <select name="doc" class="form-select" id="doc-picker-select" onchange="this.form.submit()">
          <option value="">— Select a document —</option>
          <?php foreach ($heads as $h):
            $len = count(version_chain($pdo, $h)); ?>
            <option value="<?= $h['id'] ?>" <?= $selected && (int)$selected['id'] === (int)$h['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($h['doc_number'] . ' — ' . $h['title']) ?><?= $len > 1 ? ' (' . $len . ' versions)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
      </div>
    </form>
  </details>
  <?php if (!$heads): ?>
    <p class="text-muted small mt-3 mb-0">No documents on file yet — or your role's visibility rules don't allow seeing any.</p>
  <?php endif; ?>
</div>

<script>
(function () {
  var input = document.getElementById('lrdms-search-input');
  var typeSel = document.getElementById('lrdms-filter-type');
  var statusSel = document.getElementById('lrdms-filter-status');
  var results = document.getElementById('lrdms-search-results');
  var count = document.getElementById('lrdms-search-count');
  var timer = null;

  var STATUS_CLASS = function (s) { return 'stamp--' + s.toLowerCase().replace(/ /g, '-'); };

  function render(list) {
    if (!list.length) {
      results.innerHTML = input.value.trim() ? '<p class="text-muted small mb-0">No matching legislative records.</p>' : '';
      return;
    }
    var html = '';
    list.forEach(function (d) {
      var date = d.enactment_date ? d.enactment_date : '—';
      var committee = d.committee_name ? d.committee_name : 'Unassigned';
      var sponsor = d.sponsor ? d.sponsor : '—';
      html += '<div class="lrdms-result-card">'
        + '<div class="lrdms-result-main">'
        + '<div class="lrdms-result-num">' + d.doc_number + '</div>'
        + '<div class="lrdms-result-title">' + d.title + '</div>'
        + '<div class="lrdms-result-meta">'
        + '<span class="stamp ' + STATUS_CLASS(d.status) + '">' + d.status + '</span>'
        + '<span>' + d.doc_type + '</span>'
        + '<span>' + date + '</span>'
        + '<span>' + d.version_count + ' version' + (d.version_count === 1 ? '' : 's') + '</span>'
        + '</div>'
        + '<div class="lrdms-result-sub">Committee: ' + committee + ' &nbsp;·&nbsp; Sponsor: ' + sponsor + '</div>'
        + '</div>'
        + '<div class="lrdms-result-actions">'
        + '<a class="btn btn-sm btn-outline-primary" href="version.php?doc=' + d.id + '&tab=overview">View Record</a>'
        + '<a class="btn btn-sm btn-outline-secondary" href="version.php?doc=' + d.id + '&tab=history">History</a>'
        + '<a class="btn btn-sm btn-outline-secondary" href="version.php?doc=' + d.id + '&tab=compare">Compare</a>'
        + '</div></div>';
    });
    results.innerHTML = html;
  }

  function esc(s) {
    var d = document.createElement('div');
    d.innerText = s == null ? '' : s;
    return d.innerHTML;
  }

  function runSearch() {
    var q = input.value.trim();
    var type = typeSel.value;
    var status = statusSel.value;
    if (!q && !type && !status) { results.innerHTML = ''; count.textContent = ''; return; }
    var url = 'api/version_lookup.php?q=' + encodeURIComponent(q) + '&type=' + encodeURIComponent(type) + '&status=' + encodeURIComponent(status);
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        // escape text fields before render() concatenates them into HTML
        var safe = data.results.map(function (d) {
          var c = {};
          for (var k in d) c[k] = typeof d[k] === 'string' ? esc(d[k]) : d[k];
          return c;
        });
        count.textContent = data.count + ' result' + (data.count === 1 ? '' : 's') + ' found';
        render(safe);
      })
      .catch(function () { results.innerHTML = '<p class="text-danger small mb-0">Search failed. Please try again.</p>'; });
  }

  input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(runSearch, 300); });
  typeSel.addEventListener('change', runSearch);
  statusSel.addEventListener('change', runSearch);
})();
</script>

  <?php if ($selected): ?>
    <!-- ============================================================
         LEGISLATIVE RECORD PROFILE
         ============================================================ -->
    <div class="card lrdms-profile-card">
      <div class="lrdms-profile-head">
        <div>
          <div class="lrdms-profile-eyebrow"><?= htmlspecialchars($selected['doc_type']) ?> No. <?= htmlspecialchars($selected['doc_number']) ?></div>
          <h2 class="lrdms-profile-title"><?= htmlspecialchars($selected['title']) ?></h2>
        </div>
        <div class="lrdms-profile-status">
          <span class="stamp stamp--<?= strtolower(str_replace(' ', '-', $selected['status'])) ?> lrdms-stamp-lg">● <?= htmlspecialchars($selected['status']) ?></span>
          <?php $stage = legislative_stage_caption($selected['status']); if ($stage): ?>
            <div class="lrdms-profile-stage"><?= htmlspecialchars($stage) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <div class="lrdms-meta-grid">
        <div><span class="lrdms-meta-label">Document Type</span><span class="lrdms-meta-value"><?= htmlspecialchars($selected['doc_type']) ?></span></div>
        <div><span class="lrdms-meta-label">Principal Sponsor</span><span class="lrdms-meta-value"><?= htmlspecialchars($selected['sponsor'] ?: 'Not recorded') ?></span></div>
        <div><span class="lrdms-meta-label">Committee</span><span class="lrdms-meta-value"><?= htmlspecialchars($committeeName ?: 'Unassigned') ?></span></div>
        <div><span class="lrdms-meta-label">Source</span><span class="lrdms-meta-value"><?= htmlspecialchars($selected['source_system']) ?></span></div>
        <div><span class="lrdms-meta-label">Date Enacted / Effective</span><span class="lrdms-meta-value"><?= $selected['enactment_date'] ? htmlspecialchars(date('F j, Y', strtotime($selected['enactment_date']))) : 'Not yet set' ?></span></div>
        <div><span class="lrdms-meta-label">Visibility</span><span class="lrdms-meta-value"><?= $selected['is_public'] ? 'Public' : 'Internal only' ?></span></div>
        <div><span class="lrdms-meta-label">Encoded / Owned By</span><span class="lrdms-meta-value"><?= htmlspecialchars($selected['owner_name']) ?></span></div>
        <div><span class="lrdms-meta-label">Internal Record ID</span><span class="lrdms-meta-value" style="font-family:var(--font-mono);">#<?= (int)$selected['id'] ?></span></div>
      </div>

      <p class="text-muted small mt-3 mb-0" style="font-family:var(--font-mono);">
        <?= $chainTotal ?> version<?= $chainTotal === 1 ? '' : 's' ?> on record
        <?= $chainTotal > 1 ? '· ' . ($chainTotal - 1) . ' amendment' . ($chainTotal - 1 === 1 ? '' : 's') : '' ?>
        <?= $relationshipCount ? '· ' . $relationshipCount . ' related record' . ($relationshipCount === 1 ? '' : 's') : '' ?>
      </p>
    </div>

    <div class="card" id="version-tabs">
    <ul class="nav nav-pills my-1 lrdms-tabs">
      <li class="nav-item"><a class="nav-link <?= $tab === 'overview' ? 'active' : '' ?>" href="version.php?doc=<?= $selected['id'] ?>&tab=overview#version-tabs">Overview</a></li>
      <li class="nav-item"><a class="nav-link <?= $tab === 'history' ? 'active' : '' ?>" href="version.php?doc=<?= $selected['id'] ?>&tab=history#version-tabs">Legislative History</a></li>
      <li class="nav-item"><a class="nav-link <?= $tab === 'compare' ? 'active' : '' ?>" href="version.php?doc=<?= $selected['id'] ?>&tab=compare#version-tabs">Version Comparison</a></li>
      <li class="nav-item"><a class="nav-link <?= $tab === 'related' ? 'active' : '' ?>" href="version.php?doc=<?= $selected['id'] ?>&tab=related#version-tabs">Related Legislation<?= $relationshipCount ? ' (' . $relationshipCount . ')' : '' ?></a></li>
      <li class="nav-item"><a class="nav-link <?= $tab === 'rollback' ? 'active' : '' ?>" href="version.php?doc=<?= $selected['id'] ?>&tab=rollback#version-tabs">Rollback / Restore</a></li>
    </ul>

    <?php if ($tab === 'overview'): ?>
      <?php $latestNote = latest_note($pdo, $selected['id']); ?>
      <div class="lrdms-overview-grid">
        <div>
          <h4 class="lrdms-subhead">Most recent change</h4>
          <?php if ($latestNote): ?>
            <p class="vtimeline__desc mb-1"><?= htmlspecialchars($latestNote['note']) ?></p>
            <p class="text-muted small">by <?= htmlspecialchars($latestNote['full_name']) ?> · <?= htmlspecialchars(date('M j, Y g:i A', strtotime($latestNote['created_at']))) ?></p>
          <?php else: ?>
            <p class="text-muted small">No change notes recorded yet.</p>
          <?php endif; ?>

          <h4 class="lrdms-subhead mt-3">Document file</h4>
          <?php if ($selected['file_path']): ?>
            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#filePreviewModal" data-file="<?= htmlspecialchars($selected['file_path']) ?>">
              <i class="bi bi-file-earmark-text"></i> Preview current version
            </button>
            <a href="<?= htmlspecialchars($selected['file_path']) ?>" class="btn btn-sm btn-outline-secondary" target="_blank">Download</a>
          <?php else: ?>
            <p class="text-muted small">No file attached to the current version.</p>
          <?php endif; ?>
        </div>
        <div>
          <h4 class="lrdms-subhead">Related legislation</h4>
          <?php if (!$relationships): ?>
            <p class="text-muted small">No recorded relationships to other legislation.</p>
          <?php else: ?>
            <?php foreach ($relationships as $label => $items): foreach ($items as $it): ?>
              <div class="lrdms-related-row">
                <span class="lrdms-related-tag"><?= htmlspecialchars($label) ?></span>
                <a href="version.php?doc=<?= $it['doc']['id'] ?>&tab=overview"><?= htmlspecialchars($it['doc']['doc_number']) ?></a>
              </div>
            <?php endforeach; endforeach; ?>
          <?php endif; ?>
          <a href="version.php?doc=<?= $selected['id'] ?>&tab=related" class="small">View all related legislation →</a>

          <h4 class="lrdms-subhead mt-3">Full document</h4>
          <p><a href="document.php?id=<?= $selected['id'] ?>">Open full document record →</a></p>
        </div>
      </div>

    <?php elseif ($tab === 'history'): ?>
      <div class="vtimeline">
        <?php foreach ($chainDesc as $i => $node):
          $vNum = $chainTotal - $i;
          $isCurrent = $i === 0;
          $isFirst = $vNum === 1;
          $note = latest_note($pdo, $node['id']);
          // Determine icon type
          $isRollback = $note && stripos($note['note'], 'Restored') !== false;
          $iconCls = $isFirst ? 'original' : ($isRollback ? 'rollback' : 'amend');
          $icon = $isFirst ? 'bi-file-earmark-plus' : ($isRollback ? 'bi-arrow-counterclockwise' : 'bi-pencil');
          $badge = version_badge($isCurrent, $isFirst, $isRollback); ?>
          <div class="vtimeline__item <?= $isCurrent ? 'is-current' : '' ?>">
            <div class="vtimeline__icon vtimeline__icon--<?= $iconCls ?>"><i class="bi <?= $icon ?>"></i></div>
            <div class="vtimeline__row">
              <div>
                <span class="vtimeline__title">Version <?= $vNum ?>.0<?= $isFirst ? ' — Original Filing' : '' ?></span>
                <span class="lrdms-vbadge lrdms-vbadge--<?= $badge['class'] ?>"><?= $badge['label'] ?></span>
              </div>
              <span class="vtimeline__time"><?= htmlspecialchars(date('M j, Y · g:i A', strtotime($node['created_at']))) ?></span>
            </div>
            <div class="vtimeline__desc">
              <?php if ($note): ?>
                <?= htmlspecialchars($note['note']) ?>
              <?php elseif ($isFirst): ?>
                Original filing — initial document submitted and encoded into the repository.
              <?php else: ?>
                <em class="text-muted">No change note recorded for this revision.</em>
              <?php endif; ?>
            </div>
            <div class="vtimeline__meta">
              <?= $isFirst ? 'Filed' : 'Edited' ?> by <?= htmlspecialchars($node['owner_name']) ?>
              · <span class="stamp stamp--<?= strtolower(str_replace(' ', '-', $node['status'])) ?>"><?= htmlspecialchars($node['status']) ?></span>
              · <a href="document.php?id=<?= $node['id'] ?>">View full document →</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

    <?php elseif ($tab === 'compare'): ?>
      <?php if ($chainTotal < 2): ?>
        <p class="text-muted small">This document has only one version on file — nothing to compare yet.</p>
      <?php else: ?>
        <form method="get" class="row g-2 align-items-end mb-3">
          <input type="hidden" name="doc" value="<?= $selected['id'] ?>">
          <input type="hidden" name="tab" value="compare">
          <div class="col-md-5">
            <label class="form-label small fw-semibold">Compare</label>
            <select name="a" class="form-select form-select-sm">
              <?php foreach ($chainDesc as $i => $n): ?>
                <option value="<?= $n['id'] ?>" <?= $aId === (int)$n['id'] ? 'selected' : '' ?>>v<?= $chainTotal - $i ?>.0 — <?= $n['enactment_date'] ? htmlspecialchars(date('M j, Y', strtotime($n['enactment_date']))) : '—' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-5">
            <label class="form-label small fw-semibold">Against</label>
            <select name="b" class="form-select form-select-sm">
              <?php foreach ($chainDesc as $i => $n): ?>
                <option value="<?= $n['id'] ?>" <?= $bId === (int)$n['id'] ? 'selected' : '' ?>>v<?= $chainTotal - $i ?>.0 — <?= $n['enactment_date'] ? htmlspecialchars(date('M j, Y', strtotime($n['enactment_date']))) : '—' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2"><button class="btn btn-outline-primary btn-sm w-100">Compare</button></div>
        </form>

        <?php if ($docA && $docB): ?>
          <div class="compare-grid">
            <?php foreach ([$docA, $docB] as $pair): $row = $pair['row']; $v = $pair['v']; $isCur = $row['next_version_id'] === null;
              $n = latest_note($pdo, $row['id']); ?>
              <div class="compare-col <?= $isCur ? 'is-current' : '' ?>">
                <h4>v<?= $v ?>.0<?= $isCur ? ' (Current)' : '' ?></h4>
                <div class="vtimeline__meta"><?= htmlspecialchars(date('M j, Y', strtotime($row['created_at']))) ?> · <?= htmlspecialchars($row['owner_name']) ?></div>
                <div class="vtimeline__desc"><?= $n ? htmlspecialchars($n['note']) : '<em class="text-muted">No change note recorded.</em>' ?></div>
                <span class="stamp stamp--<?= strtolower(str_replace(' ', '-', $row['status'])) ?>"><?= htmlspecialchars($row['status']) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
          <?php if ($docA['row']['id'] === $docB['row']['id']): ?>
            <div class="compare-diff">Select two different versions to see what changed between them.</div>
          <?php else:
            $older = $docA['v'] < $docB['v'] ? $docA : $docB;
            $newer = $docA['v'] < $docB['v'] ? $docB : $docA;
            $oldBody = $older['row']['body'] ?? $older['row']['ocr_text'] ?? '';
            $newBody = $newer['row']['body'] ?? $newer['row']['ocr_text'] ?? '';
            $diff = _compute_diff($oldBody, $newBody); ?>
            <div class="compare-diff">
              <strong>Changed:</strong> status moved from "<?= htmlspecialchars($older['row']['status']) ?>" to "<?= htmlspecialchars($newer['row']['status']) ?>"
              <?php if ($older['row']['enactment_date'] !== $newer['row']['enactment_date']): ?>
                , enactment date updated to <?= htmlspecialchars(date('M j, Y', strtotime($newer['row']['enactment_date']))) ?>
              <?php endif; ?>
            </div>
            <div class="diff-section">
              <h5 class="diff-header"><i class="bi bi-file-diff"></i> Content Diff — v<?= $older['v'] ?>.0 → v<?= $newer['v'] ?>.0</h5>
              <?= _render_diff($diff) ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>

    <?php elseif ($tab === 'related'): ?>
      <?php if (!$relationships): ?>
        <p class="text-muted small">No recorded relationships to other legislation yet.</p>
      <?php else: ?>
        <?php foreach ($relationships as $label => $items): ?>
          <h4 class="lrdms-subhead"><?= htmlspecialchars($label) ?></h4>
          <?php foreach ($items as $it): $rd = $it['doc']; ?>
            <div class="lrdms-related-card">
              <div>
                <a href="version.php?doc=<?= $rd['id'] ?>&tab=overview" class="doc-title" style="text-decoration:none;font-weight:600;"><?= htmlspecialchars($rd['doc_number']) ?></a>
                <span class="text-muted small">— <?= htmlspecialchars($rd['title']) ?></span>
                <span class="stamp stamp--<?= strtolower(str_replace(' ', '-', $rd['status'])) ?> ms-2"><?= htmlspecialchars($rd['status']) ?></span>
              </div>
              <?php if (has_permission('version', 'amend')): ?>
                <form method="post" onsubmit="return confirm('Remove this relationship?');">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="action" value="remove_relationship">
                  <input type="hidden" name="rel_id" value="<?= $it['rel_id'] ?>">
                  <input type="hidden" name="from_id" value="<?= $selected['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger">Remove</button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if (has_permission('version', 'amend')): ?>
        <h4 class="lrdms-subhead mt-4">Link related legislation</h4>
        <form method="post" class="row g-2 align-items-end">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="add_relationship">
          <input type="hidden" name="from_id" value="<?= $selected['id'] ?>">
          <div class="col-md-4">
            <label class="form-label small fw-semibold">Relationship type</label>
            <select name="relationship_type" class="form-select form-select-sm">
              <?php foreach (relationship_type_labels() as $key => $lbl): ?>
                <option value="<?= $key ?>"><?= htmlspecialchars($lbl['forward']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Target document</label>
            <select name="related_id" class="form-select form-select-sm">
              <option value="">— Select a document —</option>
              <?php foreach ($heads as $h): if ((int)$h['id'] === (int)$selected['id']) continue; ?>
                <option value="<?= $h['id'] ?>"><?= htmlspecialchars($h['doc_number'] . ' — ' . $h['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Link</button></div>
        </form>
      <?php endif; ?>

    <?php elseif ($tab === 'rollback'): ?>
      <?php if (!has_permission('version', 'rollback')): ?>
        <div class="alert alert-warning mb-0">🔒 Your role cannot restore prior versions. This action is limited to Records Officers.</div>
      <?php else: ?>
        <p class="text-muted small">Restoring a version does not delete history.</p>
        <?php foreach ($chainDesc as $i => $node): $vNum = $chainTotal - $i; $isCurrent = $i === 0; ?>
          <div class="rollback-row <?= $isCurrent ? 'is-current' : '' ?>">
            <div>
              <strong>v<?= $vNum ?>.0</strong> — <?= $node['enactment_date'] ? htmlspecialchars(date('M j, Y', strtotime($node['enactment_date']))) : '—' ?>
              · <span class="stamp stamp--<?= strtolower(str_replace(' ', '-', $node['status'])) ?>"><?= htmlspecialchars($node['status']) ?></span>
            </div>
            <?php if ($isCurrent): ?>
              <span class="text-muted small">This is the current version.</span>
            <?php else: ?>
              <form method="post" onsubmit="return confirm('Restore v<?= $vNum ?>.0 as the new current version? This will be recorded as a new version in the history.');">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="rollback">
                <input type="hidden" name="target_id" value="<?= $node['id'] ?>">
                <div class="mt-2 mb-2">
                  <textarea name="rollback_note" class="form-control form-control-sm" rows="2" placeholder="Reason for restoring this version (optional)" style="resize:vertical;"></textarea>
                </div>
                <button class="btn btn-outline-primary btn-sm">Restore this version</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    <?php endif; ?>
    </div>
  <?php endif; ?>

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

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
<script>
(function () {
  var modalEl = document.getElementById('filePreviewModal');
  if (!modalEl) return;
  function isImage(fp) {
    var ext = fp.split('.').pop().toLowerCase();
    return ['jpg','jpeg','png','gif','webp','bmp','svg'].indexOf(ext) !== -1;
  }
  modalEl.addEventListener('show.bs.modal', function (event) {
    var trigger = event.relatedTarget;
    var filePath = trigger.getAttribute('data-file');
    var iframe = document.getElementById('filePreviewIframe');
    var img = document.getElementById('filePreviewImg');
    var fullLink = document.getElementById('filePreviewFullLink');

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
  });
  modalEl.addEventListener('hidden.bs.modal', function () {
    var iframe = document.getElementById('filePreviewIframe');
    var img = document.getElementById('filePreviewImg');
    iframe.src = 'about:blank';
    img.src = '';
  });
})();
</script>

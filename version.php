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

require_once __DIR__ . '/includes/storage.php';
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
    $seen = [(int)$head['id'] => true];
    while (!empty($head['previous_version_id'])) {
        $prev = fetch_doc_row($pdo, $head['previous_version_id']);
        if (!$prev || isset($seen[(int)$prev['id']]) || !can_view_document(current_user(), $prev)) break; // broken link (points to a row that no longer exists) — stop walking back from here
        $seen[(int)$prev['id']] = true;
        $head = $prev;
    }
    $chain = [];
    $walker = $head;
    $seen = [];
    while ($walker && !isset($seen[(int)$walker['id']]) && can_view_document(current_user(), $walker)) {
        $seen[(int)$walker['id']] = true;
        $chain[] = $walker;
        $walker = !empty($walker['next_version_id']) ? fetch_doc_row($pdo, $walker['next_version_id']) : null;
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
    // Bound LCS memory for long OCR documents; retain a full-text comparison.
    if ($m * $n > 250000) {
        return array_merge(array_map(static function ($line) { return ['status' => 'removed', 'text' => $line]; }, $oldLines), array_map(static function ($line) { return ['status' => 'added', 'text' => $line]; }, $newLines));
    }
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

if (isset($_GET['restored'])) $message = 'Restored earlier content as a new, separately-numbered document.';

// ------------------------------------------------------------
// ROLLBACK / RESTORE
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rollback') {
    if (!validate_csrf()) {
        $errors[] = 'Security token expired. Please refresh the page and try again.';
    }
    if (validate_csrf()) {
    if (!has_permission('version', 'rollback') || !has_permission('encoding', 'create')) {
        $errors[] = 'Your role cannot roll back versions.';
    } else {
        $targetId = (int)($_POST['target_id'] ?? 0);
        $target = fetch_doc_row($pdo, $targetId);
        if (!$target || !can_view_document($user, $target)) {
            $errors[] = 'Version not found.';
        } else {
            $chain = version_chain($pdo, $target);
            $head = end($chain);
            if (!$head || !empty($head['next_version_id']) || empty($head['verified_at']) || empty($target['verified_at'])) {
                $errors[] = 'Restore requires access to the complete registered version chain.';
            } elseif ((int)$head['id'] === (int)$target['id']) {
                $errors[] = 'That is already the current version.';
            } elseif (in_array($head['status'], ['Superseded', 'Withdrawn'], true)) {
                $errors[] = 'This document is closed and cannot be restored.';
            } else {
                // A restore still files a new legislative instrument (it just happens to
                // carry forward old content), so it needs its own doc_number too — same
                // rule as document.php's amend action, never a copy of an existing number.
                $newDocNumber = trim($_POST['new_doc_number'] ?? '');
                if ($newDocNumber === '' || mb_strlen($newDocNumber) > 60) {
                    $errors[] = 'New document number is required to restore this version as a new instrument.';
                } else {
                    $dupStmt = $pdo->prepare('SELECT id FROM documents WHERE doc_number = ?');
                    $dupStmt->execute([$newDocNumber]);
                    if ($dupStmt->fetch()) {
                        $errors[] = 'A document with doc number "' . $newDocNumber . '" already exists. Use an unused document number.';
                    }
                }

                if (trim((string)($_POST['rollback_note'] ?? '')) === '') $errors[] = 'Enter the reason and authorization reference for restoring this copy.';
                if (!$errors) {
                $newId = null;
                $pdo->beginTransaction();
                try {
                    $lock = $pdo->prepare('SELECT * FROM documents WHERE id=? FOR UPDATE');
                    $lock->execute([$head['id']]);
                    $lockedHead = $lock->fetch();
                    if (!$lockedHead || $lockedHead['next_version_id'] !== null || in_array($lockedHead['status'], ['Superseded', 'Withdrawn'], true) || !can_view_document($user, $lockedHead)) throw new RuntimeException('The current version changed. Refresh before restoring.');
                    $head = $lockedHead;
                    $lock->execute([$target['id']]);
                    $target = $lock->fetch();
                    if (!$target || !can_view_document($user, $target)) throw new RuntimeException('The earlier record is no longer accessible.');
                    $classificationRanks = ['PUBLIC' => 0, 'INTERNAL' => 1, 'RESTRICTED' => 2, 'CONFIDENTIAL' => 3];
                    $restoreClassification = ($classificationRanks[$target['classification']] ?? 3) > ($classificationRanks[$head['classification']] ?? 3) ? $target['classification'] : $head['classification'];
                    $stmt = $pdo->prepare(
                        'INSERT INTO documents
                           (doc_number, title, doc_type, sponsor, committee_id, owner_id, status, is_public, verified_at,
                            source_system, enactment_date, file_path, ocr_text, previous_version_id)
                         VALUES (?,?,?,?,?,?,?,?,NULL,?,?,?,?,?)'
                    );
                    // New copies remain pending until the shared registration service links them.
                    $stmt->execute([
                        $newDocNumber, $target['title'], $target['doc_type'], $target['sponsor'],
                        $target['committee_id'], $user['id'], $head['status'], 0,
                        'Records restoration', $target['enactment_date'], $target['file_path'], $target['ocr_text'],
                        $head['id'],
                    ]);
                    $newId = $pdo->lastInsertId();
                    $pdo->prepare('UPDATE documents SET body=?, council_term=? WHERE id=?')->execute([$target['body'] ?? null, $target['council_term'] ?? null, $newId]);
                    $pdo->prepare('INSERT INTO document_attachments (document_id, file_path, display_name, sort_order) SELECT ?, file_path, display_name, sort_order FROM document_attachments WHERE document_id=?')->execute([$newId, $target['id']]);


                    $pdo->prepare('UPDATE documents SET records_status=?, classification=?, originating_office=?, originating_division=?, submitter_position=?, responsible_custodian=?, related_legislative_item=?, source_record_id=?, source_status=?, source_status_date=?, status_last_synced=NOW(), received_at=NOW(), pending_since=NOW(), registered_at=NULL WHERE id=?')
                        ->execute(['Pending Validation', $restoreClassification, $target['originating_office'], $target['originating_division'], $target['submitter_position'], $target['responsible_custodian'], $head['related_legislative_item'], $head['source_record_id'], $head['source_status'], $head['source_status_date'], $newId]);

                    $userNote = trim($_POST['rollback_note'] ?? '');
                    $noteText = $userNote !== ''
                        ? $userNote
                        : 'Restored content from ' . $target['doc_number'] . ', enacted '
                            . ($target['enactment_date'] ? date('M j, Y', strtotime($target['enactment_date'])) : 'on an earlier, undated version') . '.';
                    $pdo->prepare('INSERT INTO document_change_notes (document_id, note, created_by) VALUES (?,?,?)')
                        ->execute([$newId, $noteText, $user['id']]);

                    log_action('version', 'rolled_back', 'Record #' . $target['id'] . ' restored as #' . $newId . '; previous current #' . $head['id']);
                    $pdo->commit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log('Version restore: ' . $e->getMessage());
                    $errors[] = 'Restore failed. Refresh the page and check the document number before trying again.';
                    $newId = null;
                }

                if (!empty($newId)) {
                    $_SESSION['flash_success'] = 'Restored copy submitted for validation. The current version stays unchanged until registration.';
                    header('Location: document.php?id=' . $newId);
                    exit;
                }
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
        if ($relId && remove_relationship($pdo, $relId)) {
            log_action('version', 'unrelated_document', "relationship #$relId removed");
            header('Location: version.php?doc=' . $backTo . '&tab=related#version-tabs');
            exit;
        } else {
            $errors[] = 'Relationship unavailable or access denied.';
        }
    }
}

if (isset($_GET['related'])) $message = 'Related legislation linked.';

// ------------------------------------------------------------
// DOCUMENT PICKER (scoped by the same visibility rules as Repository)
// ------------------------------------------------------------
list($visClause, $visParams) = document_visibility_clause($user);
$sql = "SELECT d.*, u.full_name AS owner_name FROM documents d JOIN users u ON u.id = d.owner_id
        WHERE d.verified_at IS NOT NULL AND d.next_version_id IS NULL AND ($visClause)
        ORDER BY d.title";
$stmt = $pdo->prepare($sql);
$stmt->execute($visParams);
$heads = $stmt->fetchAll();

// Load the visible history once so older versions can be opened directly.
$stmt = $pdo->prepare("SELECT d.*, u.full_name AS owner_name FROM documents d JOIN users u ON u.id = d.owner_id WHERE d.verified_at IS NOT NULL AND ($visClause) ORDER BY d.created_at DESC, d.id DESC");
$stmt->execute($visParams);
$visibleVersions = $stmt->fetchAll();
$visibleById = array_column($visibleVersions, null, 'id');
$versionInfo = [];
foreach ($visibleVersions as $record) {
    if (isset($versionInfo[$record['id']])) continue;
    $root = $record;
    $seen = [];
    while (!empty($root['previous_version_id']) && isset($visibleById[$root['previous_version_id']]) && !isset($seen[$root['id']])) {
        $seen[$root['id']] = true;
        $root = $visibleById[$root['previous_version_id']];
    }
    $ids = []; $node = $root;
    while ($node && !in_array((int)$node['id'], $ids, true)) {
        $ids[] = (int)$node['id'];
        $node = $visibleById[$node['next_version_id'] ?? 0] ?? null;
    }
    foreach ($ids as $index => $id) $versionInfo[$id] = ['number' => $index + 1, 'total' => count($ids), 'ids' => $ids];
}
$selectedId = (int)($_GET['doc'] ?? 0);
$selected = $visibleById[$selectedId] ?? null;

// Load all attachment files for the selected (current) version. Fall back to
// the legacy file_path column so older/single-file records still work —
// same pattern as document.php, so Version Control shows every file a
// version actually has instead of just the first one.
$selectedFiles = [];
if ($selected) {
    $attStmt = $pdo->prepare('SELECT file_path FROM document_attachments WHERE document_id = ? ORDER BY sort_order');
    $attStmt->execute([$selected['id']]);
    $selectedFiles = array_column($attStmt->fetchAll(), 'file_path');
    if (!$selectedFiles && $selected['file_path']) $selectedFiles = [$selected['file_path']];
}

$committeeName = null;
if ($selected && $selected['committee_id']) {
    $cStmt = $pdo->prepare('SELECT name FROM committees WHERE id = ?');
    $cStmt->execute([$selected['committee_id']]);
    $committeeName = $cStmt->fetchColumn() ?: null;
}

$tab = $_GET['tab'] ?? 'overview';
if (!in_array($tab, ['overview', 'history', 'compare', 'activity', 'related', 'rollback'], true)) $tab = 'overview';

$chain = $selected ? array_map(static function ($id) use ($visibleById) { return $visibleById[$id]; }, $versionInfo[$selectedId]['ids']) : [];
$selectedVersion = $selected ? $versionInfo[$selectedId]['number'] : 0;
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
<div class="topbar" data-banner-date="<?= date('M j, Y') ?>">
  <div class="d-flex align-items-center gap-2">
    <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="Open menu">
      <i class="bi bi-list"></i>
    </button>
    <div>
      <p class="topbar__eyebrow mb-0">Legislative Records &amp; Retrieval</p>
      <h1 class="topbar__title">Version Control</h1>
      <p class="module-banner-description">Review record history and compare changes across versions.</p>
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

  /* ── AJAX tab switch feedback ── */
  #version-tabs.lrdms-tab-loading { opacity:.55; transition:opacity .12s ease; pointer-events:none; }
</style>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?></div><?php endif; ?>

<link rel="stylesheet" href="assets/css/version-workspace.css?v=4">
<div class="version-page">
<?php if (!$selected): ?>
<?php if ($selectedId): ?><div class="alert alert-warning">This version is unavailable or you do not have access to it.</div><?php endif; ?>
<?php include __DIR__ . '/includes/version_history_workspace.php'; ?>
<?php else: ?><a class="version-back action-link" href="version.php">&larr; Back to Version History</a><?php endif; ?>
  <?php if ($selected): ?>
    <!-- ============================================================
         LEGISLATIVE RECORD PROFILE
         ============================================================ -->
    <div class="card lrdms-profile-card">
      <div class="lrdms-profile-head">
        <div>
          <div class="lrdms-profile-eyebrow"><?= htmlspecialchars($selected['doc_type']) ?> No. <?= htmlspecialchars($selected['doc_number']) ?> &middot; Viewing v<?= $selectedVersion ?></div>
          <h2 class="lrdms-profile-title"><?= htmlspecialchars($selected['title']) ?></h2>
        </div>
        <div class="lrdms-profile-status">
          <div class="text-muted small mb-1">Version Status</div>
          <span class="version-chip <?= empty($selected['next_version_id']) ? 'is-current' : '' ?>"><?= empty($selected['next_version_id']) ? 'CURRENT' : 'EARLIER VERSION' ?> &middot; v<?= $selectedVersion ?></span>
          <div class="text-muted small mt-3 mb-1">Legislative Status (from source)</div>
          <?php if ($selectedVersion < $chainTotal): ?><a class="small mb-2 action-link" href="version.php?doc=<?= (int)$chainDesc[0]['id'] ?>">Go to current v<?= $chainTotal ?> &rarr;</a><?php endif; ?>

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
        <?= $chainTotal > 1 ? '· ' . ($chainTotal - 1) . ' revision' . ($chainTotal - 1 === 1 ? '' : 's') : '' ?>
        <?= $relationshipCount ? '· ' . $relationshipCount . ' related record' . ($relationshipCount === 1 ? '' : 's') : '' ?>
      </p>
    </div>

    <div class="card" id="version-tabs">
    <ul class="nav nav-pills my-1 lrdms-tabs">
      <li class="nav-item"><a class="nav-link <?= $tab === 'overview' ? 'active' : '' ?>" href="version.php?doc=<?= $selected['id'] ?>&tab=overview#version-tabs">Overview</a></li>
      <li class="nav-item"><a class="nav-link <?= $tab === 'history' ? 'active' : '' ?>" href="version.php?doc=<?= $selected['id'] ?>&tab=history#version-tabs">Version History</a></li>
      <li class="nav-item"><a class="nav-link <?= $tab === 'compare' ? 'active' : '' ?>" href="version.php?doc=<?= $selected['id'] ?>&tab=compare#version-tabs">Compare Versions</a></li>
      <li class="nav-item"><a class="nav-link <?= $tab === 'activity' ? 'active' : '' ?>" href="version.php?doc=<?= $selected['id'] ?>&tab=activity#version-tabs">Activity</a></li>
      <li class="nav-item"><a class="nav-link <?= $tab === 'related' ? 'active' : '' ?>" href="version.php?doc=<?= $selected['id'] ?>&tab=related#version-tabs">Related Legislation<?= $relationshipCount ? ' (' . $relationshipCount . ')' : '' ?></a></li>
      <li class="nav-item"><a class="nav-link <?= $tab === 'rollback' ? 'active' : '' ?>" href="version.php?doc=<?= $selected['id'] ?>&tab=rollback#version-tabs">Restore Earlier Copy</a></li>
    </ul>

    <p class="version-readonly"><i class="bi bi-lock"></i> Received versions are preserved. Review the record history and compare changes below.</p>
    <?php if ($tab === 'overview'): ?>
      <?php $latestNote = latest_note($pdo, $selected['id']); ?>
      <div class="lrdms-overview-grid">
        <div>
          <h4 class="lrdms-subhead">What changed in v<?= $selectedVersion ?></h4>
          <?php if ($latestNote): ?>
            <p class="vtimeline__desc mb-1"><?= htmlspecialchars($latestNote['note']) ?></p>
            <p class="text-muted small">by <?= htmlspecialchars($latestNote['full_name']) ?> · <?= htmlspecialchars(date('M j, Y g:i A', strtotime($latestNote['created_at']))) ?></p>
          <?php else: ?>
            <p class="text-muted small">No change notes recorded yet.</p>
          <?php endif; ?>

          <h4 class="lrdms-subhead mt-3">Document file</h4>
          <?php if ($selectedFiles): ?>
            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#filePreviewModal" data-files="<?= htmlspecialchars(json_encode(array_map('record_file_url', $selectedFiles)), ENT_QUOTES, 'UTF-8') ?>">
              <i class="bi bi-file-earmark-text"></i> Preview version<?= count($selectedFiles) > 1 ? ' (' . count($selectedFiles) . ' files)' : '' ?>
            </button>
          <?php else: ?>
            <p class="text-muted small">No file attached to this version.</p>
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
          <a href="version.php?doc=<?= $selected['id'] ?>&tab=related" class="small action-link">View all related legislation →</a>

          <h4 class="lrdms-subhead mt-3">Full document</h4>
          <p><a class="action-link" href="document.php?id=<?= (int)$selected['id'] ?>&amp;return=<?= rawurlencode('version.php?doc=' . (int)$selected['id'] . '&tab=' . $tab) ?>">Open full document record →</a></p>
        </div>
      </div>

      <?php if (empty($selected['next_version_id']) && !in_array($selected['status'], ['Superseded','Withdrawn'], true) && has_permission('version','amend') && has_permission('encoding','create')): ?>
      <details class="document-supporting-details mt-3">
        <summary>Submit a received revision</summary>
        <form method="post" enctype="multipart/form-data" action="document.php?id=<?= (int)$selected['id'] ?>" class="mt-3">
          <?php csrf_field(); ?><input type="hidden" name="action" value="amend">
          <p class="small text-muted">Upload the received revision for validation. The current version remains unchanged until registration.</p>
          <label for="revision-number" class="form-label small">New document number</label><input id="revision-number" name="new_doc_number" class="form-control mb-2" maxlength="60" required>
          <label for="revision-file" class="form-label small">Received document file</label><input id="revision-file" name="new_file" type="file" accept=".pdf,.png,.jpg,.jpeg,.doc,.docx,.txt" class="form-control mb-2" required>
          <label for="revision-note" class="form-label small">Source reference / change note</label><textarea id="revision-note" name="amend_note" class="form-control mb-2" maxlength="4000" required></textarea>
          <button class="btn btn-primary btn-sm">Submit for validation</button>
        </form>
      </details>
      <?php endif; ?>

    <?php elseif ($tab === 'activity'): ?>
      <div class="vtimeline">
      <?php foreach ($chainDesc as $i => $node): $activityNote = latest_note($pdo, $node['id']); ?>
        <div class="vtimeline__item"><div class="vtimeline__row"><strong>v<?= $chainTotal - $i ?> received</strong><time class="vtimeline__time"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($node['created_at']))) ?></time></div><p class="vtimeline__desc"><?= htmlspecialchars($activityNote['note'] ?? 'Document received and registered.') ?></p><div class="vtimeline__meta"><?= htmlspecialchars($node['owner_name']) ?> &middot; <?= htmlspecialchars($node['source_system']) ?></div></div>
      <?php endforeach; ?>
      </div>
    <?php elseif ($tab === 'history'): ?>
      <div class="vtimeline">
        <?php foreach ($chainDesc as $i => $node):
          $vNum = $chainTotal - $i;
          $isCurrent = empty($node['next_version_id']);
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
                <span class="vtimeline__title">Version <?= $vNum ?><?= $isFirst ? ' — Original Filing' : '' ?></span>
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
            <div class="d-flex gap-2 my-2"><a class="btn btn-sm btn-outline-primary" href="version.php?doc=<?= (int)$node['id'] ?>">View Document</a><?php if ($chainTotal > 1): ?><a class="btn btn-sm btn-outline-secondary" href="version.php?doc=<?= (int)$selected['id'] ?>&tab=compare&a=<?= (int)$node['id'] ?>&b=<?= (int)($chainDesc[$i === 0 ? 1 : 0]['id']) ?>">Compare</a><?php endif; ?></div>
            <div class="vtimeline__meta">
              <?= $isFirst ? 'Filed' : 'Edited' ?> by <?= htmlspecialchars($node['owner_name']) ?>
              · <span class="stamp stamp--<?= strtolower(str_replace(' ', '-', $node['status'])) ?>"><?= htmlspecialchars($node['status']) ?></span>
              · <a class="action-link" href="document.php?id=<?= (int)$node['id'] ?>&amp;return=<?= rawurlencode('version.php?doc=' . (int)$selected['id'] . '&tab=history') ?>">View full document →</a>
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
                <option value="<?= $n['id'] ?>" <?= $aId === (int)$n['id'] ? 'selected' : '' ?>>v<?= $chainTotal - $i ?> — <?= $n['enactment_date'] ? htmlspecialchars(date('M j, Y', strtotime($n['enactment_date']))) : '—' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-5">
            <label class="form-label small fw-semibold">Against</label>
            <select name="b" class="form-select form-select-sm">
              <?php foreach ($chainDesc as $i => $n): ?>
                <option value="<?= $n['id'] ?>" <?= $bId === (int)$n['id'] ? 'selected' : '' ?>>v<?= $chainTotal - $i ?> — <?= $n['enactment_date'] ? htmlspecialchars(date('M j, Y', strtotime($n['enactment_date']))) : '—' ?></option>
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
                <h4>v<?= $v ?><?= $isCur ? ' (Current)' : '' ?></h4>
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
            $oldBody = trim((string)($older['row']['body'] ?? '')) !== '' ? $older['row']['body'] : ($older['row']['ocr_text'] ?? '');
            $newBody = trim((string)($newer['row']['body'] ?? '')) !== '' ? $newer['row']['body'] : ($newer['row']['ocr_text'] ?? '');
            $diff = _compute_diff($oldBody, $newBody);
            $removedCount = count(array_filter($diff, static function ($line) { return $line['status'] === 'removed'; }));
            $addedCount = count(array_filter($diff, static function ($line) { return $line['status'] === 'added'; }));
            ?>
            <?php if (count(preg_split('/\r?\n/', $oldBody)) * count(preg_split('/\r?\n/', $newBody)) > 250000): ?><p class="text-muted small">Large document: showing full older and newer text instead of line-by-line matching.</p><?php endif; ?>
            <div class="compare-diff">
              <strong>Changes:</strong>
              <?php if ($older['row']['status'] !== $newer['row']['status']): ?>
                Status changed from &ldquo;<?= htmlspecialchars($older['row']['status']) ?>&rdquo; to &ldquo;<?= htmlspecialchars($newer['row']['status']) ?>&rdquo;.
              <?php else: ?>
                Status unchanged (<?= htmlspecialchars($newer['row']['status']) ?>).
              <?php endif; ?>
              <?php if ($older['row']['enactment_date'] !== $newer['row']['enactment_date']): ?>
                Enactment date: <?= $newer['row']['enactment_date'] ? htmlspecialchars(date('M j, Y', strtotime($newer['row']['enactment_date']))) : 'Not set' ?>.
              <?php endif; ?>
            </div>
            <div class="diff-section">
              <h5 class="diff-header"><i class="bi bi-file-diff"></i> Content Diff — v<?= $older['v'] ?> → v<?= $newer['v'] ?></h5>
              <?php if (trim($oldBody) === '' && trim($newBody) === ''): ?>
                <p class="text-muted small">No extracted document text is available for these versions.</p>
              <?php else: ?>
              <?php if ($oldBody === $newBody): ?><p class="text-muted small">Document text is identical in these versions.</p><?php endif; ?>
              <div class="version-diff-legend"><span>&minus; Removed from v<?= $older['v'] ?> (<?= $removedCount ?> lines)</span><span>+ Added in v<?= $newer['v'] ?> (<?= $addedCount ?> lines)</span><span>Unchanged</span></div>
              <div class="compare-grid version-diff-grid">
                <section><h6>v<?= $older['v'] ?> &mdash; Older</h6><?= _render_diff(array_filter($diff, static function ($line) { return $line['status'] !== 'added'; })) ?></section>
                <section><h6>v<?= $newer['v'] ?> &mdash; Newer</h6><?= _render_diff(array_filter($diff, static function ($line) { return $line['status'] !== 'removed'; })) ?></section>
              </div>
              <?php endif; ?>
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
        <div class="alert alert-warning mb-0">🔒 Your role cannot restore prior versions. Your role needs the Restore permission.</div>
      <?php else: ?>
        <p class="text-muted small">Restore records an authorized earlier copy under a new document number; it does not approve or amend legislation — it does not delete history.</p>
        <?php foreach ($chainDesc as $i => $node): $vNum = $chainTotal - $i; $isCurrent = empty($node['next_version_id']); ?>
          <div class="rollback-row <?= $isCurrent ? 'is-current' : '' ?>">
            <div>
              <strong>v<?= $vNum ?></strong> — <?= htmlspecialchars($node['doc_number']) ?> · <?= $node['enactment_date'] ? htmlspecialchars(date('M j, Y', strtotime($node['enactment_date']))) : '—' ?>
              · <span class="stamp stamp--<?= strtolower(str_replace(' ', '-', $node['status'])) ?>"><?= htmlspecialchars($node['status']) ?></span>
            </div>
            <?php if ($isCurrent): ?>
              <span class="text-muted small">This is the current version.</span>
            <?php elseif (in_array($chainDesc[0]['status'], ['Superseded', 'Withdrawn'], true) || !empty($chainDesc[0]['next_version_id'])): ?>
              <span class="text-muted small">Restore unavailable: the current record is closed or inaccessible.</span>
            <?php else: ?>
              <form method="post" onsubmit="return confirm('Restore v<?= $vNum ?> (<?= htmlspecialchars($node['doc_number']) ?>) as a new document? This will be filed as a new instrument in the history.');">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="rollback">
                <input type="hidden" name="target_id" value="<?= $node['id'] ?>">
                <div class="mt-2 mb-2">
                  <input type="text" name="new_doc_number" class="form-control form-control-sm mb-2" placeholder="New document number (e.g. 2026-045)" required>
                  <textarea name="rollback_note" class="form-control form-control-sm" rows="2" required placeholder="Reason and authorization reference for restoring this copy" style="resize:vertical;"></textarea>
                </div>
                <button class="btn btn-outline-primary btn-sm">Submit restored copy for review</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    <?php endif; ?>
    </div>
  <?php endif; ?>

</div>
<div class="modal fade" id="filePreviewModal" tabindex="-1" aria-labelledby="filePreviewModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-md-down">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="filePreviewModalLabel">Document file</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div id="filePreviewNav" class="justify-content-between align-items-center px-3 py-2 border-bottom" style="display:none;">
        <button type="button" id="filePreviewPrev" class="btn btn-outline-secondary btn-sm">&larr; Prev</button>
        <span id="filePreviewCounter" class="small text-muted"></span>
        <button type="button" id="filePreviewNext" class="btn btn-outline-secondary btn-sm">Next &rarr;</button>
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

    currentIndex = 0;
    showFile(0);
  });

  document.getElementById('filePreviewPrev').addEventListener('click', function () {
    showFile(currentIndex - 1);
  });

  document.getElementById('filePreviewNext').addEventListener('click', function () {
    showFile(currentIndex + 1);
  });

  modalEl.addEventListener('hidden.bs.modal', function () {
    var iframe = document.getElementById('filePreviewIframe');
    var img = document.getElementById('filePreviewImg');
    iframe.src = 'about:blank';
    img.src = '';
    files = [];
    currentIndex = 0;
  });
})();
</script>
<script>
// AJAX tab switching for Overview / Legislative History / Version Comparison /
// Related Legislation / Rollback & Restore — only the #version-tabs card content
// is fetched and swapped in; the rest of the page (profile header, sidebar) stays put.
(function () {
  if (!document.getElementById('version-tabs')) return;

  function cleanUrl(href) {
    var u = new URL(href, window.location.href);
    u.hash = '';
    return u.pathname + u.search;
  }

  function sameDocDifferentTab(href) {
    try {
      var u = new URL(href, window.location.href);
      if (u.pathname !== window.location.pathname) return false;
      if (!u.searchParams.has('tab')) return false;
      var curDoc = new URLSearchParams(window.location.search).get('doc');
      var linkDoc = u.searchParams.get('doc');
      return !!curDoc && !!linkDoc && curDoc === linkDoc;
    } catch (e) { return false; }
  }

  var tabRequest = 0;
  function loadTab(url, push) {
    var card = document.getElementById('version-tabs');
    if (card) card.classList.add('lrdms-tab-loading');
    var request = ++tabRequest;
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) throw new Error('Request failed');
        return r.text();
      })
      .then(function (html) {
        if (request !== tabRequest) return;
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var fresh = doc.getElementById('version-tabs');
        var current = document.getElementById('version-tabs');
        if (!fresh || !current) { window.location.href = url; return; }
        current.replaceWith(fresh);
        bindWithin(fresh);
        if (push) history.pushState({ lrdmsVersionTab: true }, '', url);
      })
      .catch(function () { if (request === tabRequest) window.location.href = url; });
  }

  function bindWithin(scope) {
    scope.querySelectorAll('a[href]').forEach(function (a) {
      if (a.target === '_blank') return;
      var href = a.getAttribute('href');
      if (!href || !sameDocDifferentTab(href)) return;
      a.addEventListener('click', function (e) {
        if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
        e.preventDefault();
        loadTab(cleanUrl(href), true);
      });
    });

    var compareForm = scope.querySelector('form[method="get"]');
    if (compareForm) {
      compareForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var params = new URLSearchParams(new FormData(compareForm));
        loadTab(window.location.pathname + '?' + params.toString(), true);
      });
    }
  }

  bindWithin(document.getElementById('version-tabs'));

  window.addEventListener('popstate', function () {
    if (document.getElementById('version-tabs')) loadTab(window.location.href, false);
  });
})();
</script>

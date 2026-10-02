<?php
// The caller supplies only records visible to the signed-in user.
$vh = static function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
$q = trim($_GET['q'] ?? '');
$typeFilter = $_GET['type'] ?? '';
$ownerFilter = $_GET['owner'] ?? '';
$days = in_array((string)($_GET['days'] ?? ''), ['7', '30', '90'], true) ? (int)$_GET['days'] : 0;
$owners = [];
foreach ($visibleVersions as $record) $owners[$record['owner_id']] = $record['owner_name'];
$filtered = array_values(array_filter($visibleVersions, static function ($r) use ($q, $typeFilter, $ownerFilter, $days) {
    return ($q === '' || stripos($r['title'] . ' ' . $r['doc_number'], $q) !== false)
        && ($typeFilter === '' || $r['doc_type'] === $typeFilter)
        && ($ownerFilter === '' || (string)$r['owner_id'] === $ownerFilter)
        && (!$days || strtotime($r['created_at']) >= strtotime('-' . $days . ' days'));
}));
$total = count($filtered);
$page = max(1, min((int)($_GET['page'] ?? 1), max(1, (int)ceil($total / 10))));
$rows = array_slice($filtered, ($page - 1) * 10, 10);
$focus = $rows[0] ?? null;
foreach ($rows as $r) if ((int)$r['id'] === (int)($_GET['focus'] ?? 0)) $focus = $r;
$link = static function ($params) { return 'version.php?' . http_build_query(array_merge($_GET, $params)); };
?>
<div class="version-workspace" id="version-list-workspace">
 <div class="version-main">
  <section class="card version-history">
   <h2 class="version-section-title"><i class="bi bi-clock-history"></i> Version History</h2>
   <form method="get" class="version-filters">
    <input class="form-control" name="q" value="<?= $vh($q) ?>" placeholder="Search records, titles, or reference numbers" aria-label="Search versions">
    <select class="form-select" name="type" aria-label="Record type"><option value="">All Record Types</option><?php foreach (array_unique(array_column($visibleVersions, 'doc_type')) as $t): ?><option <?= $t === $typeFilter ? 'selected' : '' ?>><?= $vh($t) ?></option><?php endforeach; ?></select>
    <select class="form-select" name="owner" aria-label="Record owner"><option value="">All Users</option><?php foreach ($owners as $id => $name): ?><option value="<?= (int)$id ?>" <?= (string)$id === $ownerFilter ? 'selected' : '' ?>><?= $vh($name) ?></option><?php endforeach; ?></select>
    <select class="form-select" name="days" aria-label="Time period"><option value="">All Time</option><?php foreach ([7,30,90] as $d): ?><option value="<?= $d ?>" <?= $days === $d ? 'selected' : '' ?>>Last <?= $d ?> Days</option><?php endforeach; ?></select>
    <a class="btn btn-outline-secondary" href="version.php">Reset</a><button class="btn btn-outline-primary">Filter</button><button class="btn btn-primary" type="button" id="version-export"><i class="bi bi-download"></i> Export</button>
   </form>
   <div class="table-responsive"><table class="version-table"><thead><tr><th>Version</th><th>Record ID</th><th>Title / Description</th><th>Record Type</th><th>Record Owner</th><th>Filed Date</th><th>Actions</th></tr></thead><tbody>
   <?php foreach ($rows as $r): $info = $versionInfo[$r['id']]; ?>
    <tr class="<?= $focus && $focus['id'] == $r['id'] ? 'is-selected' : '' ?>" data-version-row>
     <td><span class="version-chip <?= empty($r['next_version_id']) ? 'is-current' : '' ?>">v<?= $info['number'] ?></span><?php if (empty($r['next_version_id'])): ?><small class="version-current">Current</small><?php endif; ?></td>
     <td class="version-number"><?= $vh($r['doc_number']) ?></td>
     <td><a class="version-record-title" href="<?= $vh($link(['focus' => $r['id']])) ?>"><?= $vh($r['title']) ?></a><div class="version-row-meta"><?= $info['total'] === 1 ? 'Single version' : $info['total'] . ' versions in chain' ?> &middot; <span class="stamp stamp--<?= $vh(strtolower(str_replace(' ', '-', $r['status']))) ?>"><?= $vh($r['status']) ?></span></div></td>
     <td><span class="version-type"><?= $vh($r['doc_type']) ?></span></td><td><?= $vh($r['owner_name']) ?></td><td class="version-date"><?= $vh(date('M j, Y g:i A', strtotime($r['created_at']))) ?></td><td><a class="btn btn-sm btn-outline-primary" href="version.php?doc=<?= (int)$r['id'] ?>">View</a></td>
    </tr>
   <?php endforeach; ?>
   <?php if (!$rows): ?><tr><td colspan="7" class="version-empty">No versions found. Try a different search or reset the filters.</td></tr><?php endif; ?>
   </tbody></table></div>
   <div class="version-table-footer"><span>Showing <?= $total ? ($page - 1) * 10 + 1 : 0 ?>&ndash;<?= min($page * 10, $total) ?> of <?= $total ?> versions</span><nav aria-label="Version history pages"><?php if ($page > 1): ?><a class="btn btn-sm btn-outline-primary" href="<?= $vh($link(['page' => $page - 1, 'focus' => null])) ?>" aria-label="Previous page">&lsaquo;</a><?php endif; ?><span class="btn btn-sm btn-primary" aria-current="page"><?= $page ?></span><?php if ($page * 10 < $total): ?><a class="btn btn-sm btn-outline-primary" href="<?= $vh($link(['page' => $page + 1, 'focus' => null])) ?>" aria-label="Next page">&rsaquo;</a><?php endif; ?></nav></div>
  </section>
  <section class="card"><h2 class="version-section-title"><i class="bi bi-lightning-charge"></i> Recent Version Activity</h2><div class="version-recent">
   <?php foreach (array_slice($visibleVersions, 0, 4) as $r): ?><a href="version.php?doc=<?= (int)$r['id'] ?>&tab=activity"><span class="version-chip">v<?= $versionInfo[$r['id']]['number'] ?></span><strong><?= $vh($r['title']) ?></strong><span>Received from <?= $vh($r['owner_name']) ?></span><time><?= $vh(date('M j, Y g:i A', strtotime($r['created_at']))) ?></time></a><?php endforeach; ?>
   <?php if (!$visibleVersions): ?><p class="text-muted">No version activity yet.</p><?php endif; ?>
  </div></section>
 </div>
 <aside class="version-side">
  <section class="card"><h2 class="version-section-title"><i class="bi bi-file-earmark-text"></i> Record Version Details</h2>
  <?php if ($focus): $info = $versionInfo[$focus['id']]; $note = latest_note($pdo, $focus['id']); ?>
   <h3 class="version-detail-title"><?= $vh($focus['title']) ?></h3><span class="version-chip <?= empty($focus['next_version_id']) ? 'is-current' : '' ?>">v<?= $info['number'] ?> &middot; <?= empty($focus['next_version_id']) ? 'Current' : 'Earlier version' ?></span>
   <dl class="version-details"><?php foreach (['Record ID' => $focus['doc_number'], 'Record Type' => $focus['doc_type'], 'Title' => $focus['title'], 'Record Owner' => $focus['owner_name'], 'Filed Date' => date('M j, Y g:i A', strtotime($focus['created_at'])), 'Previous Version' => $info['number'] > 1 ? 'v' . ($info['number'] - 1) : 'Original filing', 'Change Summary' => $note['note'] ?? 'No change note recorded.'] as $label => $value): ?><dt><?= $label ?></dt><dd><?= $vh($value) ?></dd><?php endforeach; ?></dl>
   <a class="btn btn-outline-primary w-100" href="version.php?doc=<?= (int)$focus['id'] ?>&tab=history"><i class="bi bi-clock-history"></i> View Full History</a>
  <?php else: ?><p class="text-muted">Select a record to see its version details.</p><?php endif; ?></section>
  <section class="card"><h2 class="version-section-title"><i class="bi bi-bezier2"></i> Version Comparison</h2><p class="text-muted small">Compare two versions of the same record.</p>
   <?php if ($focus && $info['total'] > 1): ?><form method="get"><input type="hidden" name="doc" value="<?= (int)$focus['id'] ?>"><input type="hidden" name="tab" value="compare"><div class="version-compare-pickers"><?php foreach (['a','b'] as $index => $field): ?><select name="<?= $field ?>" class="form-select" aria-label="<?= $field === 'a' ? 'Compare version' : 'Against version' ?>"><?php foreach (array_reverse($info['ids']) as $i => $id): ?><option value="<?= $id ?>" <?= $i === $index ? 'selected' : '' ?>>Version <?= $info['total'] - $i ?> &mdash; <?= $vh($visibleById[$id]['doc_number']) ?></option><?php endforeach; ?></select><?php endforeach; ?></div><button class="btn btn-primary w-100 mt-2">Compare</button></form><?php else: ?><p class="text-muted small mb-0">A record needs at least two visible versions to compare.</p><?php endif; ?>
  </section>
 </aside>
<script type="application/json" id="version-export-data"><?= json_encode(array_merge([['Version', 'Record ID', 'Title', 'Record Type', 'Record Owner', 'Filed Date']], array_map(static function ($r) use ($versionInfo) { return ['v' . $versionInfo[$r['id']]['number'], $r['doc_number'], $r['title'], $r['doc_type'], $r['owner_name'], $r['created_at']]; }, $filtered)), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</div>
<script src="assets/js/version-list.js?v=1" defer></script>

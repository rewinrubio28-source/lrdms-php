<?php
/**
 * Document History (read-only) — a small, purpose-built page for the
 * "History" button in repository.php's card view.
 *
 * Layout is modeled after a House Bill/Resolution history reference the
 * client shared (header bar + flat key facts + collapsible sections) —
 * style only. The content stays LRDMS's own: this is version history for
 * a document already inside LRDMS (v1 → v2 → ... , change notes), not a
 * pre-enactment legislative journey — that's System 1's job, not ours.
 *
 * Deliberately NOT the Version Control module (version.php): no compare,
 * no rollback — just a read-only look, meant for a modal iframe.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

require_login();
$pdo = get_db();
$id = (int)($_GET['id'] ?? 0);

function fetch_history_doc($pdo, $id) {
    $stmt = $pdo->prepare('SELECT d.*, u.full_name AS owner_name FROM documents d JOIN users u ON u.id = d.owner_id WHERE d.id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

$doc = fetch_history_doc($pdo, $id);
if (!$doc) {
    http_response_code(404);
    echo '<p class="text-muted small p-3">Document not found.</p>';
    exit;
}

// Walk the chain oldest -> newest, same logic as document.php / version.php.
$head = $doc;
while (!empty($head['previous_version_id'])) {
    $prev = fetch_history_doc($pdo, $head['previous_version_id']);
    if (!$prev) break;
    $head = $prev;
}
$chain = [];
$walker = $head;
while ($walker) {
    $chain[] = $walker;
    $walker = !empty($walker['next_version_id']) ? fetch_history_doc($pdo, $walker['next_version_id']) : null;
}

$notesStmt = $pdo->prepare('SELECT n.*, u.full_name FROM document_change_notes n JOIN users u ON u.id = n.created_by WHERE document_id = ? ORDER BY n.created_at DESC');
$notesStmt->execute([$doc['id']]);
$notes = $notesStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/orbit.css?v=9">
<style>
  body { margin: 0; }
  .hist-header {
    background: linear-gradient(90deg, var(--surface2) 0%, var(--surface) 100%);
    border-bottom: 1px solid var(--border);
    padding: 12px 18px;
    font-weight: 700;
    font-size: 12.5px;
    letter-spacing: .04em;
    color: var(--primary-dark);
    text-transform: uppercase;
  }
  .hist-facts { padding: 16px 18px 4px; }
  .hist-fact { margin-bottom: 10px; font-size: 13.5px; }
  .hist-fact strong { color: var(--text-primary); }
  .hist-fact span { color: var(--text-muted); }
  .hist-body { padding: 4px 18px 18px; }
  .accordion-button { font-size: 13.5px; font-weight: 600; }
  .accordion-item { border-color: var(--border) !important; }
</style>
</head>
<body>

<div class="hist-header">Document History</div>

<div class="hist-facts">
  <div class="hist-fact"><strong>Doc Number:</strong> <span><?= htmlspecialchars($doc['doc_number']) ?></span></div>
  <div class="hist-fact"><strong>Title:</strong> <span><?= htmlspecialchars($doc['title']) ?></span></div>
  <div class="hist-fact"><strong>Current Status:</strong> <span class="stamp stamp--<?= strtolower(str_replace(' ', '-', $doc['status'])) ?>"><?= htmlspecialchars($doc['status']) ?></span></div>
  <div class="hist-fact"><strong>Source System:</strong> <span><?= htmlspecialchars($doc['source_system']) ?></span></div>
  <div class="hist-fact"><strong>Enactment Date:</strong> <span><?= $doc['enactment_date'] ? htmlspecialchars(date('M j, Y', strtotime($doc['enactment_date']))) : '—' ?></span></div>
</div>

<div class="hist-body">
  <div class="accordion" id="historyAccordion">

    <div class="accordion-item">
      <h2 class="accordion-header">
        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#accVersions">
          Versions <?= count($chain) > 1 ? '(' . count($chain) . ')' : '' ?>
        </button>
      </h2>
      <div id="accVersions" class="accordion-collapse collapse show" data-bs-parent="#historyAccordion">
        <div class="accordion-body">
          <?php if (count($chain) <= 1): ?>
            <p class="text-muted small mb-0">Only one version on file — no amendments yet.</p>
          <?php else: ?>
            <?php foreach ($chain as $i => $node): ?>
              <div class="doc-card__row" style="align-items:flex-start;border-bottom:1px solid var(--border);padding:10px 0;">
                <div class="doc-card__label" style="flex:0 0 50px;">v<?= $i + 1 ?></div>
                <div class="doc-card__value" style="flex:1;">
                  <?= $node['id'] == $doc['id'] ? '<strong>' . htmlspecialchars($node['title']) . ' (this version)</strong>' : htmlspecialchars($node['title']) ?>
                  <div class="text-muted small">
                    <span class="stamp stamp--<?= strtolower(str_replace(' ', '-', $node['status'])) ?>"><?= htmlspecialchars($node['status']) ?></span>
                    · <?= $node['enactment_date'] ? htmlspecialchars(date('M j, Y', strtotime($node['enactment_date']))) : '—' ?>
                    · by <?= htmlspecialchars($node['owner_name']) ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="accordion-item">
      <h2 class="accordion-header">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accNotes">
          Notes <?= $notes ? '(' . count($notes) . ')' : '' ?>
        </button>
      </h2>
      <div id="accNotes" class="accordion-collapse collapse" data-bs-parent="#historyAccordion">
        <div class="accordion-body">
          <?php if (!$notes): ?>
            <p class="text-muted small mb-0">No notes yet.</p>
          <?php else: ?>
            <?php foreach ($notes as $n): ?>
              <div class="note-item" style="margin-bottom:10px;">
                <strong><?= htmlspecialchars($n['full_name']) ?></strong> — <?= htmlspecialchars($n['note']) ?>
                <div class="doc-number small text-muted"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($n['created_at']))) ?></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
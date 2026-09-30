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
require_once __DIR__ . '/includes/rbac.php';
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
if (!$doc || !can_view_document(current_user(), $doc)) {
    http_response_code(404);
    echo '<p class="text-muted small p-3">Document not found.</p>';
    exit;
}

// Walk the chain oldest -> newest, same logic as document.php / version.php.
$head = $doc;
$visited = [(int)$head['id'] => true];
while (!empty($head['previous_version_id'])) {
    $prev = fetch_history_doc($pdo, $head['previous_version_id']);
    if (!$prev || isset($visited[(int)$prev['id']]) || !can_view_document(current_user(), $prev)) break;
    $visited[(int)$prev['id']] = true;
    $head = $prev;
}
$chain = [];
$walker = $head;
$visited = [];
while ($walker && !isset($visited[(int)$walker['id']]) && can_view_document(current_user(), $walker)) {
    $visited[(int)$walker['id']] = true;
    $chain[] = $walker;
    $walker = !empty($walker['next_version_id']) ? fetch_history_doc($pdo, $walker['next_version_id']) : null;
}

$notesStmt = $pdo->prepare('SELECT n.*, u.full_name FROM document_change_notes n JOIN users u ON u.id = n.created_by WHERE document_id = ? ORDER BY n.created_at DESC');
$notesStmt->execute([$doc['id']]);
$notes = $notesStmt->fetchAll();
$eventsStmt = $pdo->prepare('SELECT h.*, u.full_name FROM record_validation_history h LEFT JOIN users u ON u.id=h.actor_id WHERE h.document_id=? ORDER BY h.created_at DESC,h.id DESC');
$eventsStmt->execute([$doc['id']]);
$recordEvents = $eventsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Record history</title>
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
  body{background:#f4f7fc;color:#263d5b;padding:16px}
  .hist-header{border:0;background:transparent;padding:0 0 12px;color:#416a9e}
  .hist-facts{background:#fff;border:1px solid #dce6f3;border-radius:12px;padding:18px;margin-bottom:16px;box-shadow:0 3px 12px #243b5307}
  .hist-body{padding:0}.hist-fact{font-size:12px;line-height:1.6}.accordion{--bs-accordion-border-color:#dce6f3;--bs-accordion-active-bg:#eef4ff;--bs-accordion-active-color:#254f88}.history-version{padding:14px;border:1px solid #e1e9f4;border-radius:10px;margin-bottom:12px;background:#fafcff}.history-version h3{font-size:13px;line-height:1.5}.history-version p{font-size:12px;margin:5px 0;color:#61758f}.history-event{border-left:2px solid #a9c9f1;padding:0 0 14px 14px;margin-left:3px;font-size:12px}.history-event small{display:block;color:#6d819b;margin:4px 0}
</style>
</head>
<body>

<div class="hist-header">Document History</div>

<div class="hist-facts">
  <div class="hist-fact"><strong>Doc Number:</strong> <span><?= htmlspecialchars($doc['doc_number']) ?></span></div>
  <div class="hist-fact"><strong>Title:</strong> <span><?= htmlspecialchars($doc['title']) ?></span></div>
  <div class="hist-fact"><strong>Legislative Status (source):</strong> <span><?= htmlspecialchars($doc['source_status'] ?: $doc['status']) ?></span></div>
  <div class="hist-fact"><strong>Records Status:</strong> <span><?= htmlspecialchars($doc['records_status'] ?? 'Not recorded') ?></span></div>
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
            <p class="text-muted small">One stored record is available in this history.</p>
          <?php endif; ?>
          <p class="small text-muted">Linked records are shown as stored. A legislative amendment is a separate instrument, not necessarily a file revision.</p>
            <?php foreach ($chain as $i => $node): ?>
              <div class="history-version">
                <h3><?= htmlspecialchars($node['doc_number']) ?><?= (int)$node['id'] === (int)$doc['id'] ? ' · Selected record' : '' ?></h3>
                <p>Stored: <?= htmlspecialchars(date('M j, Y g:i A', strtotime($node['created_at']))) ?></p>
                <p>Recorded owner: <?= htmlspecialchars($node['owner_name']) ?></p>
                <a class="btn btn-outline-primary btn-sm" href="document.php?id=<?= (int)$node['id'] ?>" target="_top">Open record and attachments</a>
              </div>
              <div class="doc-card__row" style="align-items:flex-start;border-bottom:1px solid var(--border);padding:10px 0;">
                <div class="doc-card__label" style="flex:0 0 50px;">Record</div>
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
        </div>
      </div>
    </div>

    <div class="accordion-item">
      <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accProcessing" aria-expanded="false" aria-controls="accProcessing">Records processing history (<?= count($recordEvents) ?>)</button></h2>
      <div id="accProcessing" class="accordion-collapse collapse" data-bs-parent="#historyAccordion"><div class="accordion-body">
        <?php if (!$recordEvents): ?><p class="small text-muted mb-0">No processing events have been recorded for this document.</p><?php endif; ?>
        <?php foreach ($recordEvents as $event): ?><div class="history-event"><strong><?= htmlspecialchars($event['action']) ?></strong><small><?= htmlspecialchars($event['created_at']) ?> · <?= htmlspecialchars($event['full_name'] ?: 'System') ?></small><?php if ($event['note']): ?><p><?= nl2br(htmlspecialchars($event['note'])) ?></p><?php endif; ?></div><?php endforeach; ?>
      </div></div>
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

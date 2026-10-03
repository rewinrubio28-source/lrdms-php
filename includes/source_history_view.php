<?php
require_once __DIR__.'/source_history.php';
$sourceEvents=[];
$sourceHistoryAvailable=source_history_available($pdo);
if ($sourceHistoryAvailable) {
    $sourceStmt=$pdo->prepare('SELECT h.*,u.full_name AS recorder_name FROM document_source_history h LEFT JOIN users u ON u.id=h.recorded_by WHERE h.document_id=? ORDER BY h.event_date DESC,h.id DESC');
    $sourceStmt->execute([(int)$doc['id']]); $sourceEvents=$sourceStmt->fetchAll();
}
$sourceEscape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
?>
<section class="card record-monitor p-3" aria-labelledby="source-history-heading">
  <div class="record-monitor-heading"><h3 id="source-history-heading">Process history from source</h3><?php if ($sourceHistoryAvailable && has_permission('repository','edit_metadata')): ?><a class="btn btn-outline-primary btn-sm" href="source_history.php?id=<?= (int)$doc['id'] ?>">Add source event</a><?php endif; ?></div>
  <p class="small text-muted">Manually recorded prior events are separate from actions performed in LRDMS. Demo entries are simulated, not evidence of an integrated transaction.</p>
  <?php if (isset($_GET['source_history_saved'])): ?><div class="alert alert-success" role="status">Source event recorded.</div><?php endif; ?>
  <?php if (!$sourceEvents): ?><p class="small mb-0"><?= $sourceHistoryAvailable?'No source history recorded. Do not infer prior approvals or routing from the current status.':'Source history is not available yet.' ?></p><?php endif; ?>
  <ol class="list-unstyled mb-0">
  <?php foreach ($sourceEvents as $event): ?>
    <li class="vtimeline__item text-break">
      <span class="badge <?= $event['evidence_type']==='Simulated demo'?'bg-warning text-dark':'bg-secondary' ?>"><?= $sourceEscape($event['evidence_type']) ?></span>
      <h4 class="h6 mt-2">#<?= (int)$event['id'] ?> &middot; <?= $sourceEscape($event['event_title']) ?></h4>
      <p class="small mb-1"><time><?= $sourceEscape($event['event_date']) ?></time> &middot; <?= $sourceEscape($event['source_office']) ?><?php if ($event['destination_office']): ?> &rarr; <?= $sourceEscape($event['destination_office']) ?><?php endif; ?></p>
      <?php if ($event['actor_name']): ?><p class="small mb-1">Person / role at source: <?= $sourceEscape($event['actor_name']) ?></p><?php endif; ?>
      <p class="small mb-1"><?= nl2br($sourceEscape($event['remarks'])) ?></p>
      <?php if ($event['reference']): ?><p class="small mb-1">Reference: <?= $sourceEscape($event['reference']) ?></p><?php endif; ?>
      <p class="small text-muted">Recorded in LRDMS by <?= $sourceEscape($event['recorder_name'] ?: 'Unavailable account') ?> on <?= $sourceEscape($event['created_at']) ?>.</p>
    </li>
  <?php endforeach; ?></ol>
</section>

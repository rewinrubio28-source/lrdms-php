<?php
// Read-only tracking for the document already authorized by document.php.
$trackingStatus = trim((string)($doc['records_status'] ?? '')) ?: 'Not recorded';
$trackingDates = [
    'Received' => $doc['received_at'] ?? null,
    'Registered' => $doc['registered_at'] ?? null,
    'Verified' => $doc['verified_at'] ?? null,
];
?>
<?php include __DIR__ . '/session_tracking_view.php'; ?>
<div class="record-tracking-layout">
  <div class="record-tracking-main">
    <?php include __DIR__ . '/source_history_view.php'; ?>
    <section class="card record-monitor" aria-labelledby="record-monitor-heading">
      <div class="record-monitor-heading">
        <h3 id="record-monitor-heading">Document monitor</h3>
        <span class="stamp stamp--submitted">Current document</span>
      </div>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead><tr><th scope="col">Document</th><th scope="col">Current process</th><th scope="col">Source status</th></tr></thead>
          <tbody><tr>
            <td><b><?= htmlspecialchars($doc['doc_number']) ?></b><p class="small text-muted mt-1 mb-0 text-break"><?= htmlspecialchars($doc['title']) ?></p></td>
            <td><span class="stamp stamp--submitted"><?= htmlspecialchars($trackingStatus) ?></span></td>
            <td><?= htmlspecialchars(trim((string)($doc['source_status'] ?? '')) ?: 'Not recorded') ?></td>
          </tr></tbody>
        </table>
      </div>
    </section>
    <section class="card record-monitor-context" aria-labelledby="record-monitor-context-heading">
      <h3 id="record-monitor-context-heading">Records processing</h3>
      <div class="row g-3 mt-1">
        <div class="col-sm-6 d-flex gap-3">
          <i class="bi bi-shield-check" aria-hidden="true"></i>
          <div><b>Verification</b><p><?= !empty($doc['verified_at']) ? 'Verified on ' . htmlspecialchars(date('M j, Y, g:i A', strtotime($doc['verified_at']))) : 'No verification timestamp recorded.' ?></p></div>
        </div>
        <div class="col-sm-6 d-flex gap-3">
          <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i>
          <div><b>Source system</b><p><?= htmlspecialchars(trim((string)($doc['source_system'] ?? '')) ?: 'Not recorded') ?></p>
          <?php if (trim((string)($doc['source_record_id'] ?? '')) !== ''): ?><p>Reference: <?= htmlspecialchars($doc['source_record_id']) ?></p><?php endif; ?></div>
        </div>
      </div>
    </section>
    <?php if ($trackingEvents): $latestTrackingEvent = $trackingEvents[0]; ?>
    <section class="card record-monitor" aria-labelledby="latest-record-activity">
      <div class="record-monitor-heading"><h3 id="latest-record-activity">Latest LRDMS processing activity</h3></div>
      <div class="p-3">
        <b><?= htmlspecialchars($latestTrackingEvent['action']) ?></b>
        <p class="small text-muted mt-1 mb-0"><?= htmlspecialchars(date('M j, Y, g:i A', strtotime($latestTrackingEvent['created_at']))) ?> &middot; <?= htmlspecialchars($latestTrackingEvent['full_name'] ?: 'System') ?></p>
        <?php if (!empty($latestTrackingEvent['note'])): ?><p class="small text-break mt-2 mb-0"><?= nl2br(htmlspecialchars($latestTrackingEvent['note'])) ?></p><?php endif; ?>
      </div>
    </section>
    <?php endif; ?>
  </div>
  <div class="record-tracking-side">
<section class="verification-panel card" aria-labelledby="document-tracking-heading">
  <div class="verification-panel__heading">
    <div class="d-flex justify-content-between align-items-start gap-2">
      <h2 id="document-tracking-heading">Tracking: <?= htmlspecialchars($doc['doc_number']) ?></h2>
      <i class="bi bi-geo-alt-fill text-primary" aria-hidden="true"></i>
    </div>
    <p class="text-break"><?= htmlspecialchars($doc['title']) ?></p>
  </div>
  <div class="verification-metadata">
    <h3>LRDMS receiving timeline</h3>
    <ol class="list-unstyled mt-3 mb-0">
      <?php foreach ($trackingDates as $label => $date): ?>
      <li class="vtimeline__item record-tracking-step">
        <div class="vtimeline__title"><i class="bi <?= $date ? 'bi-check-circle-fill text-success' : 'bi-circle text-muted' ?> me-2" aria-hidden="true"></i><?= htmlspecialchars($label) ?></div>
        <div class="vtimeline__meta mt-1"><?= $date ? htmlspecialchars(date('M j, Y, g:i A', strtotime($date))) : 'No timestamp recorded' ?></div>
      </li>
      <?php endforeach; ?>
    </ol>
  </div>
  <details class="document-supporting-details mt-3">
    <summary><i class="bi bi-clock-history me-2" aria-hidden="true"></i>View LRDMS processing history (<?= count($trackingEvents) ?>)</summary>
    <div class="pt-3">
      <?php if (!$trackingEvents): ?>
        <p class="small text-muted mb-0">No processing events have been recorded for this document.</p>
      <?php endif; ?>
      <?php foreach ($trackingEvents as $event): ?>
      <div class="vtimeline__item">
        <div class="vtimeline__title"><?= htmlspecialchars($event['action']) ?></div>
        <div class="vtimeline__meta mt-1"><?= htmlspecialchars(date('M j, Y, g:i A', strtotime($event['created_at']))) ?> &middot; <?= htmlspecialchars($event['full_name'] ?: 'System') ?></div>
        <?php if (trim((string)($event['note'] ?? '')) !== ''): ?><p class="vtimeline__desc small text-break"><?= nl2br(htmlspecialchars($event['note'])) ?></p><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </details>
</section>

    <section class="card record-custodian mt-3" aria-labelledby="record-custodian-heading">
      <h3 id="record-custodian-heading">Record custodian</h3>
      <p class="mb-1"><?= htmlspecialchars(trim((string)($doc['responsible_custodian'] ?? '')) ?: 'No custodian assigned') ?></p>
      <?php if (trim((string)($doc['originating_office'] ?? '')) !== ''): ?><p class="small mb-0"><?= htmlspecialchars($doc['originating_office']) ?></p><?php endif; ?>
    </section>
  </div>
</div>

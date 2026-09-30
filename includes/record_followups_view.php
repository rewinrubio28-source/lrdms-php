<link rel="stylesheet" href="assets/css/followups.css?v=1">
<section class="followups">
  <div class="followups-heading">
    <div><h2>Pending Records &amp; Follow-up</h2><p>Monitor incoming records and record follow-ups with their source office.</p></div>
    <span class="followup-status <?= $followupDueCount ? 'is-due' : '' ?>"><?= $followupDueCount ?> follow-up<?= $followupDueCount === 1 ? '' : 's' ?> due</span>
  </div>
  <div class="followups-stats">
    <article class="card"><span>Pending records</span><strong><?= count($pendingRows) ?></strong><small>Awaiting records validation</small></article>
    <article class="card followups-stat-due"><span>Follow-ups due</span><strong><?= $followupDueCount ?></strong><small>Reached the next reminder date</small></article>
    <article class="card"><span>Average time pending</span><strong><?= $pendingRows ? number_format($pendingDaysTotal / count($pendingRows), 1) : '0' ?> <em>days</em></strong><small>Across the current pending queue</small></article>
  </div>
  <p class="followups-assumption"><i class="bi bi-info-circle" aria-hidden="true"></i> Working reminder: 15 days from the pending date, or received date when no pending date is set. After a follow-up, staff select the next reminder date. The official trigger is still subject to client confirmation.</p>
  <?php foreach ($followupErrors as $error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endforeach; ?>
  <div class="card">
    <form method="get" class="followups-filter">
      <input type="hidden" name="tab" value="followups">
      <div><label for="followup-search" class="form-label small">Search records</label><input id="followup-search" name="q" class="form-control" value="<?= htmlspecialchars($followupQuery, ENT_QUOTES, 'UTF-8') ?>" placeholder="Number, title, sponsor, committee or source"></div>
      <div><label for="followup-filter" class="form-label small">Reminder status</label><select id="followup-filter" name="filter" class="form-select"><?php foreach (['all' => 'All pending', 'due' => 'Follow-up due', 'scheduled' => 'Scheduled'] as $value => $label): ?><option value="<?= $value ?>" <?= $followupFilter === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
      <button type="submit" class="btn btn-primary">Apply filters</button>
      <a href="encoding.php?tab=followups" class="btn btn-outline-secondary">Reset</a>
    </form>
    <div class="table-responsive">
      <table class="table align-middle followups-table">
        <thead><tr><th>Record / source</th><th>Related committee</th><th>Days pending</th><th>Reminder status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($visibleFollowups as $record): $rid = (int)$record['id']; $retry = $followupErrors && ($followupId ?? 0) === $rid; ?>
          <tr>
            <td><span class="fw-semibold"><?= htmlspecialchars($record['doc_number']) ?></span><div><?= htmlspecialchars($record['title']) ?></div><small><?= htmlspecialchars($record['source_system']) ?></small></td>
            <td><?= htmlspecialchars($record['committee_name'] ?: 'Not assigned') ?></td>
            <td class="text-nowrap"><?= $record['pending_days'] ?> days</td>
            <td><span class="followup-status <?= $record['is_due'] ? 'is-due' : '' ?>"><?= $record['is_due'] ? 'Follow-up due' : 'Scheduled' ?></span><small class="d-block mt-1"><?= htmlspecialchars($record['due_date']->format('M j, Y')) ?></small></td>
            <td><button type="button" class="btn <?= $record['is_due'] ? 'btn-danger' : 'btn-outline-primary' ?> btn-sm" data-followup-toggle="followup-<?= $rid ?>" aria-controls="followup-<?= $rid ?>" aria-expanded="<?= $retry ? 'true' : 'false' ?>">Record follow-up</button><small class="d-block mt-1"><?= (int)$record['followup_count'] ?> recorded</small></td>
          </tr>
          <tr id="followup-<?= $rid ?>" <?= $retry ? '' : 'hidden' ?>><td colspan="5">
            <div class="followups-editor">
              <h3>Follow-up for <?= htmlspecialchars($record['doc_number']) ?></h3>
              <p class="small text-muted">Record contact you have made with the source office. Saving this form does not send a message.</p>
              <form method="post" action="encoding.php?tab=followups">
                <?php csrf_field(); ?><input type="hidden" name="action" value="record_followup"><input type="hidden" name="document_id" value="<?= $rid ?>">
                <div class="row g-3">
                  <div class="col-md-5"><label class="form-label" for="contact-<?= $rid ?>">Contact person / office</label><input id="contact-<?= $rid ?>" name="contact_person" required maxlength="180" class="form-control" value="<?= htmlspecialchars($retry ? $contact : '', ENT_QUOTES, 'UTF-8') ?>"></div>
                  <div class="col-md-3"><label class="form-label" for="method-<?= $rid ?>">Contact method</label><select id="method-<?= $rid ?>" name="contact_method" required class="form-select"><?php foreach ($followupMethods as $choice): ?><option <?= $retry && $method === $choice ? 'selected' : '' ?>><?= htmlspecialchars($choice) ?></option><?php endforeach; ?></select></div>
                  <div class="col-md-4"><label class="form-label" for="next-<?= $rid ?>">Next follow-up date</label><input type="date" id="next-<?= $rid ?>" name="next_due_date" required min="<?= $followupNow->modify('+1 day')->format('Y-m-d') ?>" value="<?= htmlspecialchars($retry ? $nextDate : '', ENT_QUOTES, 'UTF-8') ?>" class="form-control"></div>
                  <div class="col-12"><label class="form-label" for="note-<?= $rid ?>">Follow-up notes / response received</label><textarea id="note-<?= $rid ?>" name="followup_note" required maxlength="4000" rows="3" class="form-control"><?= htmlspecialchars($retry ? $note : '') ?></textarea></div>
                  <div class="col-12"><button type="submit" class="btn btn-primary">Save follow-up</button></div>
                </div>
              </form>
              <details class="mt-3"><summary>Follow-up history (<?= (int)$record['followup_count'] ?>)</summary>
                <?php if (empty($followupHistory[$rid])): ?><p class="small mt-2">No follow-ups recorded yet.</p><?php endif; ?>
                <?php foreach ($followupHistory[$rid] ?? [] as $event): ?>
                  <article class="followups-event"><strong><?= htmlspecialchars($event['full_name']) ?></strong> · <?= htmlspecialchars($event['created_at']) ?><div><?= htmlspecialchars($event['contact_method'] . ' — ' . $event['contact_person']) ?></div><p><?= nl2br(htmlspecialchars($event['note'])) ?></p><small>Next reminder: <?= htmlspecialchars($event['next_due_at']) ?></small></article>
                <?php endforeach; ?>
              </details>
            </div>
          </td></tr>
        <?php endforeach; ?>
        <?php if (!$visibleFollowups): ?><tr><td colspan="5" class="py-5 text-center text-muted"><?= $pendingRows ? 'No records match these filters.' : 'No pending records to follow up. Incoming records will appear here.' ?></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <nav class="d-flex justify-content-between align-items-center" aria-label="Follow-up pages"><span class="small">Page <?= $followupPage ?> of <?= $followupPages ?></span><div><?php foreach (['Previous' => $followupPage - 1, 'Next' => $followupPage + 1] as $label => $page): ?><?php if ($page >= 1 && $page <= $followupPages): ?><a class="btn btn-outline-secondary btn-sm" href="encoding.php?<?= htmlspecialchars(http_build_query(['tab' => 'followups', 'q' => $followupQuery, 'filter' => $followupFilter, 'page' => $page]), ENT_QUOTES, 'UTF-8') ?>"><?= $label ?></a><?php endif; ?><?php endforeach; ?></div></nav>
  </div>
</section>

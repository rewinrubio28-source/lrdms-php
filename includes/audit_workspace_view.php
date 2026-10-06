<link rel="stylesheet" href="assets/css/audit-workspace.css?v=7">
<div id="audit-workspace" class="audit-page">
 <section class="audit-hero topbar" data-banner-date="<?= date('M j, Y') ?>">
  <div class="d-flex align-items-center gap-2">
   <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="Open menu"><i class="bi bi-list"></i></button>
   <div><span class="topbar__eyebrow">AUDIT TRAIL</span><h1>Track. Verify. Ensure Accountability.</h1><p>View and monitor system activities, record changes, access events, and user actions.</p></div>
  </div>
  <div class="topbar__actions"></div>
 </section>
 <div class="audit-metrics">
 <?php foreach ([['total','Total Audit Events','bi-file-earmark-text'],['users','User Activities','bi-person-badge'],['records','Record Events','bi-folder2'],['security','Security Alerts','bi-shield-exclamation']] as [$key,$label,$icon]): ?>
  <section class="audit-metric"><span class="audit-metric-icon"><i class="bi <?= $icon ?>"></i></span><div><h2><?= $label ?></h2><strong><?= number_format((int)$stats[$key]) ?></strong><small>Last 30 days</small></div></section>
 <?php endforeach; ?>
 </div>
 <div class="audit-columns">
  <form id="audit-filters" class="audit-filter-card audit-panel" method="get" role="search">
   <h2><i class="bi bi-funnel"></i> Filter Audit Trail</h2>
   <?php if ($moduleFilter !== 'All'): ?><input type="hidden" name="module" value="<?= $escape($moduleFilter) ?>"><?php endif; ?>
   <fieldset><legend>Date Range</legend><label class="audit-date-label" for="audit-from">From</label><input id="audit-from" type="date" name="from" value="<?= $escape($auditExtra['from']) ?>"><label class="audit-date-label" for="audit-to">To</label><input id="audit-to" type="date" name="to" value="<?= $escape($auditExtra['to']) ?>"></fieldset>
   <label for="audit-event">Event Type</label><select id="audit-event" name="event"><option value="All">All Event Types</option><?php foreach ($events as $event): ?><option value="<?= $escape($event) ?>" <?= $eventFilter === $event ? 'selected' : '' ?>><?= $escape(audit_action_label($event)) ?></option><?php endforeach; ?></select>
   <label for="audit-actor">User</label><input id="audit-actor" name="actor" value="<?= $escape($auditExtra['actor']) ?>" placeholder="Search user...">
   <label for="audit-record">Record / Resource</label><input id="audit-record" name="record" value="<?= $escape($auditExtra['record']) ?>" placeholder="Search record details...">
   <label for="audit-office">Office</label><select id="audit-office" name="office"><option value="">All Offices</option><?php foreach ($offices as $office): ?><option value="<?= (int)$office['id'] ?>" <?= $auditExtra['office'] === (string)$office['id'] ? 'selected' : '' ?>><?= $escape($office['name']) ?></option><?php endforeach; ?></select>
   <label for="audit-role">System Role</label><select id="audit-role" name="role"><option value="All">All Roles</option><?php foreach (array_merge($roles, ['System']) as $role): ?><option <?= $roleFilter === $role ? 'selected' : '' ?>><?= $escape($role) ?></option><?php endforeach; ?></select>
   <label for="audit-outcome">Event Status</label><select id="audit-outcome" name="outcome"><option value="">All Statuses</option><?php foreach (['Recorded','Attention'] as $outcome): ?><option <?= $auditExtra['outcome'] === $outcome ? 'selected' : '' ?>><?= $outcome ?></option><?php endforeach; ?></select>
   <button class="audit-apply" type="submit"><i class="bi bi-search"></i> Apply Filters</button><a class="audit-reset audit-search-clear" href="audit_trail.php"><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
  </form>
  <section class="audit-panel audit-events">
  <div class="audit-search-wrap audit-events-search"><i class="bi bi-search"></i><input type="search" form="audit-filters" name="q" value="<?= $escape($q) ?>" placeholder="Search audit logs, records, users, or event details..." aria-label="Search audit logs" autocomplete="off"></div>
   <div class="audit-panel-heading"><h2><i class="bi bi-journal-text"></i> Audit Trail Events</h2><div><?php if (has_permission('audit','export')): ?><a class="audit-export" href="api/export_audit.php<?= $escape($exportQs) ?>"><i class="bi bi-download"></i> Export</a><?php endif; ?><span class="audit-range"><?= $total ? $offset + 1 : 0 ?>&ndash;<?= min($offset + $perPage, $total) ?> of <?= number_format($total) ?></span></div></div>
   <div class="audit-table-wrap"><table class="audit-table-view"><thead><tr><th>Date &amp; Time</th><th>Event Type</th><th>Record / Resource</th><th>User</th><th>System Role</th><th>Office / Division</th><th>Status</th></tr></thead><tbody>
   <?php foreach ($logs as $a): $outcome = audit_outcome($a); ?>
    <tr class="audit-event-row <?= $selectedEvent['id'] == $a['id'] ? 'is-selected' : '' ?>" tabindex="0" data-event-url="audit_trail.php?<?= $escape(http_build_query(array_merge($qsParts, ['page'=>$page,'selected'=>$a['id']]))) ?>" aria-label="View details for event <?= (int)$a['id'] ?>" aria-controls="audit-event-details">
     <td><time><?= $escape(date('M j, Y', strtotime($a['created_at']))) ?><small><?= $escape(date('g:i A', strtotime($a['created_at']))) ?></small></time></td>
     <td><span class="audit-event-label <?= $outcome === 'Attention' ? 'is-alert' : '' ?>"><i class="bi <?= audit_icon($a) ?>"></i><?= $escape(audit_action_label($a['action'])) ?></span></td>
     <td><span class="audit-resource" title="<?= $escape($a['detail']) ?>"><?= $escape($a['detail'] ?: 'System activity') ?></span><small><?= $escape(ucfirst($a['module'])) ?></small></td>
     <td><?= $escape(audit_display_name($a)) ?></td><td><?= $escape(audit_role_label($a) ?: 'Not recorded') ?></td><td><?= $escape($a['actor_division'] ?: $a['actor_office'] ?: 'Not recorded') ?></td>
     <td><span class="audit-status <?= $outcome === 'Attention' ? 'is-alert' : '' ?>"><?= $outcome ?></span></td>
    </tr>
   <?php endforeach; ?>
   <?php if (!$logs): ?><tr><td colspan="7"><div class="audit-empty"><i class="bi bi-search"></i><h3>No matching events</h3><p>Try a different date range or reset the filters.</p></div></td></tr><?php endif; ?>
   </tbody></table></div>
   <footer class="audit-pagination"><span>Page <?= $page ?> of <?= $totalPages ?></span><nav aria-label="Audit event pages">
    <?php if ($page > 1): ?><a class="page-link" href="audit_trail.php?page=<?= $page-1 ?><?= $escape($qs) ?>" aria-label="Previous page">&lsaquo;</a><?php endif; ?>
    <?php for ($p=max(1,$page-2); $p<=min($totalPages,$page+2); $p++): ?><a class="page-link <?= $p === $page ? 'is-active' : '' ?>" href="audit_trail.php?page=<?= $p ?><?= $escape($qs) ?>" <?= $p === $page ? 'aria-current="page"' : '' ?>><?= $p ?></a><?php endfor; ?>
    <?php if ($page < $totalPages): ?><a class="page-link" href="audit_trail.php?page=<?= $page+1 ?><?= $escape($qs) ?>" aria-label="Next page">&rsaquo;</a><?php endif; ?>
   </nav></footer>
   <p class="audit-table-note">Account role and office reflect the current user directory. Export includes up to 1,000 matching events.</p>
  </section>
  <aside id="audit-event-details" class="audit-panel audit-event-details" aria-label="Event details"><h2><i class="bi bi-shield-check"></i> Event Details</h2>
   <?php if ($selectedEvent): $a = $selectedEvent; $outcome = audit_outcome($a); ?>
    <div class="audit-detail-intro"><span class="audit-detail-icon <?= $outcome === 'Attention' ? 'is-alert' : '' ?>"><i class="bi <?= audit_icon($a) ?>"></i></span><div><span class="audit-status <?= $outcome === 'Attention' ? 'is-alert' : '' ?>"><?= $outcome ?></span><h3><?= $escape(audit_action_label($a['action'])) ?></h3><p>Event #<?= (int)$a['id'] ?> &middot; <?= $escape(ucfirst($a['module'])) ?></p></div></div>
    <dl><?php foreach (['Date & Time'=>audit_stamp($a['created_at']), 'User'=>audit_display_name($a), 'System Role'=>audit_role_label($a) ?: 'Not recorded', 'Office'=>$a['actor_office'] ?: 'Not recorded', 'Division'=>$a['actor_division'] ?: 'Not recorded', 'IP Address'=>$a['ip_address'] ?: 'Not recorded'] as $label=>$value): ?><dt><?= $label ?></dt><dd><?= $escape($value) ?></dd><?php endforeach; ?></dl>
    <div class="audit-additional"><h3><i class="bi bi-file-earmark-text"></i> Additional Information</h3><div><?= audit_summary_html($a) ?></div></div>
    <p class="audit-detail-note"><i class="bi bi-lock"></i> Audit events are read-only.</p>
   <?php else: ?><div class="audit-empty"><i class="bi bi-journal-text"></i><p>No event to display.</p></div><?php endif; ?>
  </aside>
 </div>
</div>
<script src="assets/js/audit-workspace.js?v=4" defer></script>

<section class="card mb-3" aria-label="Dashboard filters and reports">
  <form id="dashboard-filters" method="get" class="row g-2 align-items-end">
    <div class="col-sm-3"><label class="form-label" for="dash-from">Created from</label><input id="dash-from" class="form-control" type="date" name="from" value="<?= htmlspecialchars($filters['from']) ?>" required></div>
    <div class="col-sm-3"><label class="form-label" for="dash-to">Created through</label><input id="dash-to" class="form-control" type="date" name="to" value="<?= htmlspecialchars($filters['to']) ?>" required></div>
    <div class="col-sm-3"><label class="form-label" for="dash-type">Document type</label><select id="dash-type" name="type" class="form-select"><?php foreach (['All','Ordinance','Resolution','Committee Report','Minutes','Other'] as $option): ?><option <?= $filters['type']===$option?'selected':'' ?>><?= htmlspecialchars($option) ?></option><?php endforeach; ?></select></div>
    <div class="col-sm-3"><label class="form-label" for="dash-status">Current status</label><select id="dash-status" name="status" class="form-select"><?php foreach (['All','Draft','Submitted','Under Review','Enacted','Amended','Rejected','Superseded','Withdrawn'] as $option): ?><option <?= $filters['status']===$option?'selected':'' ?>><?= htmlspecialchars($option) ?></option><?php endforeach; ?></select></div>
    <div class="col-12 d-flex gap-2 flex-wrap align-items-center"><button class="btn btn-primary" type="submit">Apply filters</button><button id="dashboard-refresh" class="btn btn-outline-primary" type="button">Refresh now</button><a href="<?= htmlspecialchars(dashboard_drill_url($filters)) ?>" id="dashboard-records-link" class="btn btn-outline-secondary">View matching records</a>
      <?php foreach (['pdf'=>'PDF','xlsx'=>'Excel','csv'=>'CSV'] as $format=>$label): ?><a class="btn btn-outline-secondary" data-dashboard-export="<?= $format ?>" href="api/export_dashboard.php?<?= htmlspecialchars(http_build_query($filters+['format'=>$format])) ?>">Export <?= $label ?></a><?php endforeach; ?>
    </div>
  </form>
  <p class="small text-muted mt-3 mb-1">Counts cover records created in the selected period, using current status and access permissions. Search and audit activity follow the dates; account totals are current.</p>
  <p id="dashboard-refresh-status" class="small mb-0" role="status">Updated <?= htmlspecialchars(date('M j, Y g:i A',strtotime($generated_at))) ?>. Metrics refresh every 15 seconds while this tab is visible.</p>
</section>

<?php
require_once __DIR__.'/includes/report_schedules.php';
require_login(); $user=current_user();
if (!record_report_allowed($user)) { http_response_code(403); exit('Repository access is required to view records reports.'); }
header('Cache-Control: no-store');
$error=''; $report=null; $schedules=[]; $runs=[]; $ready=true;
try { get_db()->query('SELECT 1 FROM report_schedules LIMIT 0'); get_db()->query('SELECT 1 FROM report_runs LIMIT 0'); }
catch (PDOException $e) { $ready=false; }
try { $options=record_report_options($_GET); }
catch (InvalidArgumentException $e) { $options=record_report_options([]); $error=$e->getMessage(); }
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (!is_string($_POST['csrf_token']??null) || !validate_csrf()) throw new InvalidArgumentException('Security token expired. Refresh and try again.');
        if (!$ready) throw new InvalidArgumentException('Scheduled reports are not ready. Ask your administrator to apply the reports migration.');
        $action=$_POST['action']??'';
        if ($action==='schedule') {
            report_schedule_create(get_db(),$user,$_POST);
            $_SESSION['report_flash']='Schedule saved. The report will appear here after the next scheduled run.';
        } else {
            $id=filter_var($_POST['schedule_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if (!$id || !is_string($action)) throw new InvalidArgumentException('Invalid schedule action.');
            report_schedule_change(get_db(),$user,$id,$action); $_SESSION['report_flash']='Schedule updated.';
        }
        header('Location: reports.php'); exit;
    } catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
    catch (Throwable $e) { error_log('Reports schedule: '.$e->getMessage()); $error='Schedule could not be changed. Check your permissions or contact the administrator.'; }
}
if (isset($_GET['generate']) && $error==='') {
    try { $report=record_report_build(get_db(),$user,$options); }
    catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
    catch (Throwable $e) { error_log('Reports preview: '.$e->getMessage()); $error='Report could not be generated. Please retry.'; }
}
$canExport=record_report_allowed($user,'export'); $canPrint=record_report_allowed($user,'print');
if ($ready) {
    $stmt=get_db()->prepare('SELECT * FROM report_schedules WHERE user_id=? ORDER BY id DESC'); $stmt->execute([$user['id']]); $schedules=$stmt->fetchAll();
    $stmt=get_db()->prepare('SELECT r.id,r.status,r.scheduled_for,r.generated_at,r.error_message,s.title FROM report_runs r JOIN report_schedules s ON s.id=r.schedule_id WHERE r.user_id=? AND r.created_at>=? ORDER BY r.id DESC LIMIT 100'); $stmt->execute([$user['id'],date('Y-m-d H:i:s',strtotime('-30 days'))]); $runs=$stmt->fetchAll();
}
include __DIR__.'/includes/layout_top.php';
?>
<div class="topbar"><div class="d-flex align-items-center gap-2"><button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="Open menu"><i class="bi bi-list"></i></button><div><h1 class="topbar__title">Reports</h1><p class="module-banner-description">Customize records reports and manage automatic generation.</p></div></div></div>
<div class="container-fluid py-3">
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= record_report_escape($error) ?></div><?php endif; ?>
<?php if (isset($_SESSION['report_flash'])): ?><div class="alert alert-success" role="status"><?= record_report_escape($_SESSION['report_flash']) ?></div><?php unset($_SESSION['report_flash']); endif; ?>
<form method="get" class="card p-4 mb-4" id="report-options">
<input type="hidden" name="columns_present" value="1">
<h2 class="h5">Customize report</h2>
<p>Reports contain registered records you can currently view. Maximum 1,000 matching records; narrow your filters for larger datasets.</p>
<div class="row g-3">
<div class="col-md-6"><label class="form-label" for="report-title">Report title</label><input class="form-control" id="report-title" name="title" maxlength="120" required value="<?= record_report_escape($options['title']) ?>"></div>
<div class="col-md-6"><label class="form-label" for="report-q">Document number or title</label><input class="form-control" id="report-q" name="q" maxlength="200" value="<?= record_report_escape($options['q']) ?>"></div>
<?php foreach (['from'=>'Created from','to'=>'Created through'] as $key=>$label): ?><div class="col-md-3"><label class="form-label" for="report-<?= $key ?>"><?= $label ?></label><input class="form-control" type="date" id="report-<?= $key ?>" name="<?= $key ?>" required value="<?= record_report_escape($options[$key]) ?>"></div><?php endforeach; ?>
<?php foreach (['type'=>['All','Ordinance','Resolution','Committee Report','Minutes','Other'],'status'=>['All','Draft','Submitted','Under Review','Enacted','Amended','Rejected','Superseded','Withdrawn']] as $key=>$values): ?><div class="col-md-3"><label class="form-label" for="report-<?= $key ?>"><?= $key==='type'?'Document type':'Current status' ?></label><select class="form-select" id="report-<?= $key ?>" name="<?= $key ?>"><?php foreach ($values as $value): ?><option <?= $options[$key]===$value?'selected':'' ?>><?= record_report_escape($value) ?></option><?php endforeach; ?></select></div><?php endforeach; ?>
<div class="col-md-4"><label class="form-label" for="report-group">Group results</label><select class="form-select" id="report-group" name="group"><?php foreach (['none'=>'Individual records','doc_type'=>'Document type','status'=>'Current status','source_system'=>'Source system','month'=>'Creation month'] as $key=>$label): ?><option value="<?= $key ?>" <?= $options['group']===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><label class="form-label" for="report-sort">Sort individual records by</label><select class="form-select" id="report-sort" name="sort"><?php foreach (RECORD_REPORT_COLUMNS as $key=>$label): ?><option value="<?= $key ?>" <?= $options['sort']===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><label class="form-label" for="report-direction">Order</label><select class="form-select" id="report-direction" name="direction"><option value="asc" <?= $options['direction']==='asc'?'selected':'' ?>>Ascending</option><option value="desc" <?= $options['direction']==='desc'?'selected':'' ?>>Descending</option></select></div>
</div>
<fieldset class="mt-3"><legend class="h6">Columns for individual records</legend><div class="d-flex flex-wrap gap-3"><?php foreach (RECORD_REPORT_COLUMNS as $key=>$label): ?><label><input class="form-check-input me-1" type="checkbox" name="columns[]" value="<?= $key ?>" <?= in_array($key,$options['columns'],true)?'checked':'' ?>><?= $label ?></label><?php endforeach; ?></div></fieldset>
<p class="text-muted small mt-2">Grouped reports show group labels and counts, sorted by the group label in the selected order.</p>
<div><button type="submit" class="btn btn-primary" name="generate" value="1">Generate preview</button></div>
</form>
<?php if ($report): ?>
<section class="card p-4 mb-4"><h2 class="h5"><?= record_report_escape($options['title']) ?></h2><p><?= $report['count'] ?> matching records · Generated <?= record_report_escape($report['generated_at']) ?> (Asia/Manila)</p>
<div class="d-flex flex-wrap gap-2 mb-3"><?php foreach (['pdf'=>'PDF','xlsx'=>'Excel','csv'=>'CSV','print'=>'Print view'] as $format=>$label): if (($format==='print' && !$canPrint) || ($format!=='print' && !$canExport)) continue; ?><a class="btn btn-outline-primary" href="api/export_records_report.php?<?= record_report_escape(http_build_query($options+['format'=>$format])) ?>"><?= $label ?></a><?php endforeach; ?></div>
<p class="small text-muted">Exports and print views use these filters with current data at generation time.</p>
<div class="table-responsive" style="max-height:32rem"><table class="table table-striped"><thead><tr><?php foreach ($report['headers'] as $header): ?><th scope="col"><?= record_report_escape($header) ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach ($report['rows'] as $row): ?><tr><?php foreach ($row as $cell): ?><td><?= record_report_escape($cell) ?></td><?php endforeach; ?></tr><?php endforeach; ?><?php if (!$report['rows']): ?><tr><td colspan="<?= count($report['headers']) ?>">No matching records.</td></tr><?php endif; ?></tbody></table></div>
<?php if ($canExport && $ready): ?>
<form method="post" class="border-top mt-3 pt-3"><?php csrf_field(); ?><input type="hidden" name="action" value="schedule"><?php foreach ($options as $key=>$value): if (is_array($value)): foreach ($value as $column): ?><input type="hidden" name="columns[]" value="<?= record_report_escape($column) ?>"><?php endforeach; else: ?><input type="hidden" name="<?= record_report_escape($key) ?>" value="<?= record_report_escape($value) ?>"><?php endif; endforeach; ?>
<h3 class="h6">Schedule this report</h3><p>A private PDF will appear here. Daily reports cover yesterday; weekly reports cover the previous Monday–Sunday; monthly reports cover the previous calendar month. Those periods replace the preview's dates. Other filters and columns are preserved.</p>
<div class="row g-3 align-items-end"><div class="col-md-4"><label class="form-label" for="frequency">Frequency</label><select class="form-select" id="frequency" name="frequency"><option value="daily">Daily</option><option value="weekly">Weekly — Monday</option><option value="monthly">Monthly — first day</option></select></div><div class="col-md-4"><label class="form-label" for="run-time">Time (Asia/Manila)</label><input class="form-control" type="time" id="run-time" name="run_time" value="08:00" required></div><div class="col-md-4"><button class="btn btn-primary">Save schedule</button></div></div>
</form><?php endif; ?></section>
<?php endif; ?>
<section class="card p-4 mb-4"><h2 class="h5">Your schedules</h2><p>Reports are available only to you and remain subject to your current access permissions. Downloads are retained for 30 days. Scheduled reports may take a few minutes to appear.</p>
<?php if (!$ready): ?><div class="alert alert-warning">Scheduled reports are not ready. Your administrator must apply the reports migration.</div><?php elseif (!$schedules): ?><p class="text-muted">No schedules yet. Generate a preview, then save a schedule if your role has download permission.</p><?php else: ?><div class="table-responsive"><table class="table"><thead><tr><th>Title</th><th>Frequency</th><th>Next run (Asia/Manila)</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach ($schedules as $schedule): ?><tr><td><?= record_report_escape($schedule['title']) ?></td><td><?= record_report_escape($schedule['frequency'].' '.$schedule['run_time']) ?></td><td><?= $schedule['enabled']?record_report_escape($schedule['next_run_at']):'—' ?></td><td><?= $schedule['enabled']?'Active':'Paused' ?></td><td><?php if ($canExport): ?><form method="post" class="d-flex gap-2"><?php csrf_field(); ?><input type="hidden" name="schedule_id" value="<?= (int)$schedule['id'] ?>"><button class="btn btn-sm btn-outline-secondary" name="action" value="<?= $schedule['enabled']?'pause':'resume' ?>"><?= $schedule['enabled']?'Pause':'Resume' ?></button><button class="btn btn-sm btn-outline-danger" name="action" value="remove" onclick="return confirm('Remove this schedule and its generated reports?')">Remove</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<section class="card p-4"><h2 class="h5">Generation history</h2><p class="small text-muted">Latest 100 runs within the 30-day retention period. Refresh this page to see completed runs. After downtime, only the latest completed period is generated; earlier missed periods are skipped.</p>
<?php if (!$runs): ?><p>No generated reports yet.</p><?php else: ?><div class="table-responsive"><table class="table"><thead><tr><th>Report</th><th>Scheduled for</th><th>Result</th><th>Download / details</th></tr></thead><tbody><?php foreach ($runs as $run): ?><tr><td><?= record_report_escape($run['title']) ?></td><td><?= record_report_escape($run['scheduled_for']) ?></td><td><?= record_report_escape($run['status']) ?></td><td><?php if ($run['status']==='Ready' && $canExport): ?><a class="action-link" href="api/download_scheduled_report.php?id=<?= (int)$run['id'] ?>">Download PDF</a><?php else: ?><?= record_report_escape($run['error_message']??($run['status']==='Ready'?'Download permission unavailable.':'Generation is in progress.')) ?><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
</div>
<?php include __DIR__.'/includes/layout_bottom.php'; ?>

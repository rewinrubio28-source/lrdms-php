<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/session_workflow.php';
require_once __DIR__.'/includes/session_presentation.php';
require_login();
$pdo=get_db(); $user=current_user();
$labels=session_stage_labels();
$stage=is_string($_GET['stage'] ?? null) ? $_GET['stage'] : '';
if (!isset($labels[$stage])) $stage='';
$overdue=($_GET['overdue'] ?? '')==='1';
$query=is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
[$visibility,$params]=document_visibility_clause($user);
$rows=[]; $total=0; $page=max(1,(int)($_GET['page'] ?? 1)); $pages=1;
$stats=['total'=>0,'amendment'=>0,'overdue'=>0,'final'=>0];
if (session_tracking_available($pdo)) {
    $summary=$pdo->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(s.stage='amendment'),0) AS amendment, COALESCE(SUM(s.stage='amendment' AND s.amendment_due_at<NOW()),0) AS overdue, COALESCE(SUM(s.stage='third_session' AND s.final_attachment_id IS NOT NULL),0) AS final FROM documents d JOIN document_sessions s ON s.document_id=d.id WHERE (".$visibility.')');
    $summary->execute($params); $stats=$summary->fetch();
    $where=['('.$visibility.')'];
    if ($overdue) $where[]="s.stage='amendment' AND s.amendment_due_at<NOW()";
    if (isset($labels[$stage])) { $where[]='s.stage=?'; $params[]=$stage; }
    if ($query!=='') { $where[]='(d.doc_number LIKE ? OR d.title LIKE ?)'; $params[]='%'.$query.'%'; $params[]='%'.$query.'%'; }
    $from=' FROM documents d JOIN document_sessions s ON s.document_id=d.id LEFT JOIN committees c ON c.id=d.committee_id WHERE '.implode(' AND ',$where);
    $stmt=$pdo->prepare('SELECT COUNT(*)'.$from); $stmt->execute($params); $total=(int)$stmt->fetchColumn();
    $pages=max(1,(int)ceil($total/20)); $page=min($page,$pages);
    $stmt=$pdo->prepare('SELECT d.id,d.doc_number,d.title,s.stage,s.final_attachment_id,s.amendment_due_at,s.updated_at,c.name AS committee_name'.$from.' ORDER BY s.updated_at DESC,d.id DESC LIMIT 20 OFFSET '.(($page-1)*20));
    $stmt->execute($params); $rows=$stmt->fetchAll();
}
include __DIR__.'/includes/layout_top.php';
$escape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$filtered=$query!=='' || $stage!=='' || $overdue;
?>
<link rel="stylesheet" href="assets/css/session-tracking.css?v=1">
<div class="session-index">
 <div class="topbar" data-banner-date="<?= date('M j, Y') ?>"><div class="d-flex align-items-center gap-2"><button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="Open menu"><i class="bi bi-list" aria-hidden="true"></i></button><div><div class="topbar__eyebrow">Repository / Document progress</div><h1 class="topbar__title">Session Tracking</h1><p class="module-banner-description">Follow each document from its session return to the mayor’s signed copy.</p></div></div></div>
 <div class="session-page-intro"><div><p class="session-eyebrow">Workflow overview</p><h2 class="h5 mb-1">Keep the next step in view</h2><p>Monitor committee deadlines and continue processing from each document.</p></div><a class="btn btn-outline-primary" href="repository.php"><i class="bi bi-collection" aria-hidden="true"></i> Repository</a></div>
 <nav class="session-stats" aria-label="Quick tracking filters">
 <?php foreach ([['All tracked records','total','bi-files','repository_tracking.php',!$filtered],['For amendment','amendment','bi-pencil-square','?stage=amendment',$stage==='amendment' && !$overdue],['Overdue','overdue','bi-alarm','?overdue=1',$overdue],['Final documents','final','bi-file-earmark-pdf','?stage=third_session',$stage==='third_session' && !$overdue]] as [$label,$key,$icon,$href,$active]): ?>
 <a class="session-stat <?= $active ? 'is-active':'' ?> <?= $key==='overdue' ? 'session-stat--danger':'' ?>" href="<?= $escape($href) ?>" <?= $active ? 'aria-current="page"':'' ?>><i class="bi <?= $icon ?>" aria-hidden="true"></i><div><strong><?= (int)$stats[$key] ?></strong><small><?= $label ?></small></div></a>
 <?php endforeach; ?></nav>
 <section class="session-results" aria-label="Tracked documents">
 <form method="get" class="session-filter" role="search"><div class="session-filter-search"><label for="tracking-query">Find a document</label><input id="tracking-query" class="form-control" type="search" name="q" placeholder="Search document number or title" value="<?= $escape($query) ?>"></div><div class="session-filter-stage"><label for="tracking-stage">Session stage</label><select id="tracking-stage" class="form-select" name="stage"><option value="">All stages</option><?php foreach ($labels as $value=>$label): ?><option value="<?= $value ?>" <?= $stage===$value ? 'selected':'' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></div><?php if ($overdue): ?><input type="hidden" name="overdue" value="1"><?php endif; ?><button class="btn btn-primary"><i class="bi bi-search" aria-hidden="true"></i> Apply filters</button><?php if ($filtered): ?><a class="btn btn-outline-secondary" href="repository_tracking.php">Reset</a><?php endif; ?></form>
 <div class="session-results-meta"><span><strong><?= $total ?></strong> <?= $total===1 ? 'document':'documents' ?><?= $filtered ? ' matching your filters':'' ?><?php if ($overdue): ?> <span class="session-badge session-badge--danger">Overdue only</span><?php endif; ?></span><span>Recently updated first &middot; Philippine time</span></div>
 <?php if ($rows): ?><table class="session-table"><thead><tr><th scope="col">Document</th><th scope="col">Current stage</th><th scope="col">Committee</th><th scope="col">Amendment deadline</th><th scope="col"><span class="visually-hidden">Open document</span></th></tr></thead><tbody>
 <?php foreach ($rows as $row): $rowUrl='document.php?id='.(int)$row['id'].'&tab=tracking'; ?>
 <tr><td><span class="session-record-ref"><?= $escape($row['doc_number']) ?></span><a class="session-record-title" href="<?= $escape($rowUrl) ?>"><?= $escape($row['title']) ?></a><small>Updated <?= $escape(date('M j, Y',strtotime($row['updated_at']))) ?></small></td><td data-label="Current stage"><span class="session-badge session-badge--<?= session_stage_tone($row['stage']) ?>"><?= $escape($labels[$row['stage']] ?? $row['stage']) ?></span><?php if (!empty($row['final_attachment_id'])): ?><small>Print-ready</small><?php endif; ?></td><td data-label="Committee"><?= $escape($row['committee_name'] ?: 'Not assigned') ?></td><td data-label="Amendment deadline"><?php if ($row['stage']==='amendment'): $seconds=strtotime($row['amendment_due_at'])-time(); ?><strong><?= $escape(date('M j, Y',strtotime($row['amendment_due_at']))) ?></strong><small class="<?= $seconds<0 ? 'session-due-warning':'' ?>"><?= $seconds<0 ? max(1,(int)ceil(-$seconds/86400)).' day(s) overdue' : (int)ceil($seconds/86400).' day(s) remaining' ?></small><?php else: ?><span class="text-muted">No active deadline</span><?php endif; ?></td><td><a class="session-row-link" aria-label="Open tracking for <?= $escape($row['doc_number']) ?>" href="<?= $escape($rowUrl) ?>">Open tracking <i class="bi bi-arrow-right" aria-hidden="true"></i></a></td></tr>
 <?php endforeach; ?></tbody></table>
 <?php else: ?><div class="session-empty"><i class="bi <?= $filtered ? 'bi-search':'bi-signpost-split' ?>" aria-hidden="true"></i><h2><?= $filtered ? 'No matching documents':'Your session records will appear here' ?></h2><p><?= $filtered ? 'Try a different title or stage, or reset your filters to see all tracked records.':'Start with Send to Agenda in Document Intake. Returned session documents appear here after receiving.' ?></p><a class="btn btn-outline-primary" href="<?= $filtered ? 'repository_tracking.php':(session_can_manage($user) ? 'encoding.php':'repository.php') ?>"><?= $filtered ? 'Reset filters':(session_can_manage($user) ? 'Open Document Intake':'Browse Repository') ?></a></div><?php endif; ?>
 <?php if ($total>0): ?><nav class="session-pagination" aria-label="Session tracking pages"><span>Page <?= $page ?> of <?= $pages ?></span><div class="d-flex gap-2"><?php foreach ([$page-1=>'Previous',$page+1=>'Next'] as $target=>$label): if ($target>=1 && $target<=$pages): ?><a class="btn btn-outline-primary" href="?<?= $escape(http_build_query(['q'=>$query,'stage'=>$stage,'overdue'=>$overdue ? '1':'','page'=>$target])) ?>"><?= $label ?></a><?php else: ?><span class="btn btn-outline-secondary disabled" aria-disabled="true"><?= $label ?></span><?php endif; endforeach; ?></div></nav><?php endif; ?>
 </section>
</div>
<?php include __DIR__.'/includes/layout_bottom.php'; ?>
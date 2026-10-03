<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/rbac.php';
require_once __DIR__.'/includes/source_history.php';
require_login();
$user=current_user(); $pdo=get_db(); $id=(int)($_GET['id']??0);
$stmt=$pdo->prepare('SELECT * FROM documents WHERE id=?'); $stmt->execute([$id]); $doc=$stmt->fetch();
if (!$doc || !can_view_document($user,$doc) || !has_permission('repository','edit_metadata')) { http_response_code(404); exit('Document unavailable.'); }
$available=source_history_available($pdo); $error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!validate_csrf()) { http_response_code(403); $error='Refresh the page and try again.'; }
    elseif (!$available) $error='Source history is not available yet.';
    else try {
        source_history_add($pdo,$user,$id,$_POST);
        header('Location: document.php?id='.$id.'&source_history_saved=1#document-history-panel'); exit;
    } catch (InvalidArgumentException | RuntimeException $e) {
        $error=$e instanceof PDOException ? 'Could not save history. Please try again.' : $e->getMessage();
    }
}
$escape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$value=static fn($field)=>is_string($_POST[$field]??null)?$_POST[$field]:'';
include __DIR__.'/includes/layout_top.php';
?>
<div class="topbar" data-banner-date="<?= date('M j, Y') ?>"><div><h1 class="topbar__title">Record source history</h1><p class="module-banner-description">Describe a prior event reported with this document.</p></div></div>
<a class="btn btn-outline-primary btn-sm mb-3" href="document.php?id=<?= $id ?>">Back</a>
<section class="card p-4" style="max-width:900px"><h2 class="h5"><?= $escape($doc['doc_number']) ?> &middot; <?= $escape($doc['title']) ?></h2><p class="small text-muted">This adds historical information, not an approval or status change in LRDMS. Entries are retained; record a new clarification referencing the earlier entry if a correction is needed.</p>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= $escape($error) ?></div><?php endif; ?>
<?php if (!$available): ?><p class="alert alert-warning">Source history is not available yet. Contact your administrator.</p><?php else: ?>
<form method="post"><?php csrf_field(); ?><div class="row g-3">
<div class="col-md-6"><label for="evidence-type" class="form-label">History basis</label><select id="evidence-type" name="evidence_type" class="form-select" required><option value="">Select a basis</option><?php foreach (['Manual source record','Simulated demo'] as $basis): ?><option <?= $value('evidence_type')===$basis?'selected':'' ?>><?= $basis ?></option><?php endforeach; ?></select><p class="small text-muted">Use Simulated demo for fictional presentation data.</p></div>
<div class="col-md-6"><label for="event-date" class="form-label">Event date</label><input id="event-date" name="event_date" type="date" class="form-control" required value="<?= $escape($value('event_date')) ?>"></div>
<?php foreach (['event_title'=>'Event / action','source_office'=>'Source office / system','destination_office'=>'Forwarded to (optional)','actor_name'=>'Person / role at source (optional)','reference'=>'Supporting reference'] as $field=>$label): ?><div class="col-md-6"><label for="<?= $field ?>" class="form-label"><?= $label ?></label><input id="<?= $field ?>" name="<?= $field ?>" class="form-control" maxlength="180" <?= in_array($field,['event_title','source_office'],true)?'required':'' ?> value="<?= $escape($value($field)) ?>"><?php if ($field==='reference'): ?><p class="small text-muted">Required for manual source records. Enter a document/page reference or an attached filename.</p><?php endif; ?></div><?php endforeach; ?>
<div class="col-12"><label for="remarks" class="form-label">Remarks / description</label><textarea id="remarks" name="remarks" class="form-control" rows="4" required maxlength="2000"><?= $escape($value('remarks')) ?></textarea></div></div><button type="submit" class="btn btn-primary mt-3">Save source event</button></form><?php endif; ?></section>
<?php include __DIR__.'/includes/layout_bottom.php'; ?>

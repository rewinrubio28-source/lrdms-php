<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/rbac.php';
require_once __DIR__.'/includes/dataset_import.php';
require_permission('encoding','create');
header('Cache-Control: no-store');
$user=current_user(); $error=''; $preview=null;
function import_escape($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
if (isset($_GET['template'])) {
    $format=$_GET['template'];
    $record=['doc_number'=>'SAMPLE-001','title'=>'Sample imported legislative record','doc_type'=>'Ordinance','source_system'=>'System 1 - Lifecycle','status'=>'Enacted','classification'=>'INTERNAL','enactment_date'=>'2026-01-15'];
    if ($format==='json') {
        header('Content-Type: application/json; charset=UTF-8'); header('Content-Disposition: attachment; filename="records-template.json"'); echo json_encode([$record],JSON_PRETTY_PRINT); exit;
    }
    if ($format==='csv' || $format==='xlsx') {
        $rows=[DATASET_FIELDS,array_map(static fn($key)=>$record[$key]??'',DATASET_FIELDS)];
        if ($format==='xlsx') {
            require_once __DIR__.'/vendor/autoload.php';
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="records-template.xlsx"');
            echo (string)\Shuchkin\SimpleXLSXGen::fromArray(array_map(static fn($row)=>array_map(static fn($v)=>"\0".$v,$row),$rows));
        } else {
            header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="records-template.csv"');
            $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF"); foreach ($rows as $row) fputcsv($out,$row,',','"',''); fclose($out);
        }
        exit;
    }
    http_response_code(400); exit('Choose CSV, JSON or XLSX.');
}
if (!empty($_SESSION['dataset_preview']) && ($_SESSION['dataset_preview']['expires']<time() || $_SESSION['dataset_preview']['user_id']!==$user['id'])) unset($_SESSION['dataset_preview']);
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (!is_string($_POST['csrf_token']??null) || !validate_csrf()) throw new InvalidArgumentException('Security token expired. Refresh the page and try again.');
        $action=$_POST['action']??'';
        if ($action==='cancel') unset($_SESSION['dataset_preview']);
        elseif ($action==='preview') {
            unset($_SESSION['dataset_preview']);
            $file=$_FILES['dataset']??[];
            if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_string($file['tmp_name']??null) || !is_uploaded_file($file['tmp_name']) || !is_string($file['name']??null)) throw new InvalidArgumentException('Upload failed. Choose a CSV, JSON or XLSX file up to 5 MB.');
            $rows=dataset_parse($file['tmp_name'],$file['name']);
            $check=get_db()->prepare('SELECT 1 FROM documents WHERE doc_number=? LIMIT 1'); $seen=[];
            foreach ($rows as $i=>$row) {
                $key=mb_strtolower($row['doc_number'],'UTF-8'); $check->execute([$row['doc_number']]);
                if (isset($seen[$key]) || $check->fetchColumn()) throw new InvalidArgumentException('Record '.($i+1).': document number already exists or is repeated in this batch.');
                $seen[$key]=true;
            }
            $_SESSION['dataset_preview']=['user_id'=>$user['id'],'expires'=>time()+900,'token'=>bin2hex(random_bytes(24)),'rows'=>$rows];
            header('Location: import_records.php'); exit;
        } elseif ($action==='confirm') {
            $pending=$_SESSION['dataset_preview']??null;
            if (!$pending || !is_string($_POST['preview_token']??null) || !hash_equals($pending['token'],$_POST['preview_token'])) throw new InvalidArgumentException('Preview expired or already used. Upload the file again.');
            $count=dataset_save(get_db(),$user,$pending['rows']);
            unset($_SESSION['dataset_preview']);
            $_SESSION['flash_success']=$count.' records imported as private, pending validation. Review and register them in Incoming records.';
            header('Location: encoding.php'); exit;
        } else throw new InvalidArgumentException('Choose Preview, Import or Cancel.');
    } catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
    catch (Throwable $e) { error_log('Dataset import: '.$e->getMessage()); $error='Import could not be completed. No records were saved. Please retry or contact your administrator.'; }
}
$preview=$_SESSION['dataset_preview']??null;
include __DIR__.'/includes/layout_top.php';
?>
<link rel="stylesheet" href="assets/css/import-records.css?v=1">
<div class="topbar" data-banner-date="<?= date('M j, Y') ?>"><div class="d-flex align-items-center gap-2"><button class="sidebar-toggle" id="sidebar-toggle" type="button" aria-label="Open menu"><i class="bi bi-list"></i></button><div><span class="module-banner-eyebrow">DOCUMENT ENCODING &amp; SUBMISSION</span><h1 class="topbar__title">Import records</h1><p class="module-banner-description">Upload a dataset, check its details, and send records for validation.</p></div></div></div>
<div class="import-workspace">
<div class="import-toolbar"><ol class="import-steps" aria-label="Import progress"><li <?= !$preview?'aria-current="step"':'' ?>><span>1</span> Upload</li><li <?= $preview?'aria-current="step"':'' ?>><span>2</span> Review &amp; import</li><li><span>3</span> Validate records</li></ol><a href="encoding.php" class="btn btn-outline-secondary btn-sm">Back</a></div>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= import_escape($error) ?></div><?php endif; ?>
<div class="import-layout <?= $preview?'has-preview':'' ?>">
<aside class="import-guide card">
<h2>Start with a template</h2><p class="import-muted">Download your preferred format and replace the sample row.</p>
<div class="import-templates"><a class="btn btn-outline-primary btn-sm" href="?template=csv">CSV</a><a class="btn btn-outline-primary btn-sm" href="?template=xlsx">Excel</a><a class="btn btn-outline-primary btn-sm" href="?template=json">JSON</a></div>
<details class="import-format-guide" <?= !$preview?'open':'' ?>><summary>File requirements</summary><ul><li>Up to <strong>1,000 records</strong> and <strong>5 MB</strong>.</li><li>UTF-8 CSV, JSON array, or one-sheet XLSX.</li><li>Required columns: <code>doc_number</code>, <code>title</code>, <code>source_system</code>.</li><li>Keep document numbers and dates as text in Excel. Dates: YYYY-MM-DD.</li><li>Use values, not formulas. Old .xls files are unsupported.</li></ul></details>
<div class="import-info"><h3>After import</h3><p>Records go to <strong>Pending Validation</strong> as private copies. Review and register them in Incoming records.</p><p>Attachments must be added separately. Version links, ownership and registration are not imported.</p></div>
</aside>
<div class="import-main">
<?php if ($preview): ?>
<section class="card import-preview">
<div class="import-panel-heading"><div><h2>Review your records</h2><p class="import-muted">Check the details before saving this batch.</p></div><span class="import-count"><?= count($preview['rows']) ?> records</span></div>
<p class="import-preview-note">Preview expires at <?= import_escape(date('g:i A', $preview['expires'])) ?> (<?= import_escape(date_default_timezone_get()) ?>). Document numbers are checked again on import.</p>
<div class="table-responsive import-table-wrap" tabindex="0" role="region" aria-label="Scrollable import preview"><table class="table"><caption class="visually-hidden">All records in this import</caption><thead><tr><th scope="col">#</th><th scope="col">Document number</th><th scope="col">Title</th><th scope="col">Type</th><th scope="col">Source</th><th scope="col">Source status</th></tr></thead><tbody>
<?php foreach ($preview['rows'] as $i=>$row): ?><tr><td><?= $i+1 ?></td><?php foreach (['doc_number','title','doc_type','source_system','source_status'] as $key): ?><td><?= import_escape($row[$key]) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div>
<div class="import-confirm"><p class="import-muted">Existing records are never overwritten. If any record fails, the entire batch is cancelled.</p><form method="post" class="d-flex flex-wrap gap-2" data-import-form><?php csrf_field(); ?><input type="hidden" name="preview_token" value="<?= import_escape($preview['token']) ?>"><button class="btn btn-primary" name="action" value="confirm">Import <?= count($preview['rows']) ?> records</button><button class="btn btn-outline-secondary" name="action" value="cancel">Cancel preview</button></form></div>
</section>
<?php else: ?>
<form method="post" enctype="multipart/form-data" class="card import-upload" data-import-form><?php csrf_field(); ?><input type="hidden" name="action" value="preview"><h2>Upload your dataset</h2><p class="import-muted">Choose the file containing the records you want to receive.</p><div class="import-file-box"><i class="bi bi-file-earmark-arrow-up" aria-hidden="true"></i><label for="dataset" class="form-label">Select a dataset file</label><input id="dataset" class="form-control" type="file" name="dataset" accept=".csv,.json,.xlsx" required aria-describedby="file-note"><p id="file-note" class="import-muted">CSV, Excel (.xlsx), or JSON &middot; Maximum 5 MB</p></div><p class="import-muted mt-3">Nothing is saved until you review and confirm the import.</p><div><button class="btn btn-primary" type="submit">Validate and preview</button></div></form>
<?php endif; ?>
<p id="import-progress" role="status" aria-live="polite" class="mt-3"></p>
</div></div></div>
<script>
window.addEventListener('pageshow', function() {
  document.querySelectorAll('[data-import-form]').forEach(function(form) { delete form.dataset.busy; form.removeAttribute('aria-busy'); });
  document.getElementById('import-progress').textContent='';
});
document.querySelectorAll('[data-import-form]').forEach(function(form) {
  form.addEventListener('submit', function(event) {
    if (form.dataset.busy) { event.preventDefault(); return; }
    form.dataset.busy='1'; form.setAttribute('aria-busy','true');
    document.getElementById('import-progress').textContent=event.submitter && event.submitter.value==='confirm' ? 'Importing records. Please wait for confirmation.' : 'Processing your request. Please wait.';
  });
});
</script>
<?php include __DIR__.'/includes/layout_bottom.php'; ?>

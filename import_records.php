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
<div class="topbar"><div class="d-flex align-items-center gap-2"><button class="sidebar-toggle" id="sidebar-toggle" type="button" aria-label="Open menu"><i class="bi bi-list"></i></button><div><h1 class="topbar__title">Import records</h1><p class="module-banner-description">Receive a dataset from an existing source system.</p></div></div></div>
<div class="container-fluid py-3">
<a href="encoding.php" class="btn btn-outline-secondary mb-3">Back to Incoming records</a>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= import_escape($error) ?></div><?php endif; ?>
<div class="card p-4 mb-3">
<h2 class="h5">Prepare your dataset</h2>
<p>Import up to 1,000 records in a file up to 5 MB. Use UTF-8 CSV, a JSON array, or an Excel (.xlsx) workbook with one worksheet. Download a template and replace the sample record.</p>
<p>Required fields: <strong>doc_number, title, source_system</strong>. Keep document numbers and dates as text in Excel; dates use YYYY-MM-DD. Use literal values without formulas. Old .xls files are not supported.</p>
<p>Records enter the private <strong>Pending Validation</strong> queue. Attachments, version links, ownership and registration cannot be set by the dataset. Any error cancels the whole import; existing records are never overwritten.</p>
<div class="d-flex flex-wrap gap-2"><a class="btn btn-outline-primary" href="?template=csv">CSV template</a><a class="btn btn-outline-primary" href="?template=xlsx">Excel template</a><a class="btn btn-outline-primary" href="?template=json">JSON template</a></div>
</div>
<?php if ($preview): ?>
<div class="card p-4">
<h2 class="h5">Review <?= count($preview['rows']) ?> records</h2>
<p>Check the preview before importing. It expires after 15 minutes. Duplicate document numbers are checked again when you confirm.</p>
<div class="table-responsive" style="max-height:32rem"><table class="table table-striped"><caption>All records in this import</caption><thead><tr><th scope="col">Record</th><th scope="col">Document number</th><th scope="col">Title</th><th scope="col">Type</th><th scope="col">Source</th><th scope="col">Source status</th></tr></thead><tbody>
<?php foreach ($preview['rows'] as $i=>$row): ?><tr><td><?= $i+1 ?></td><?php foreach (['doc_number','title','doc_type','source_system','source_status'] as $key): ?><td><?= import_escape($row[$key]) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div>
<form method="post" class="mt-3" data-import-form><?php csrf_field(); ?><input type="hidden" name="preview_token" value="<?= import_escape($preview['token']) ?>"><button class="btn btn-primary" name="action" value="confirm">Import <?= count($preview['rows']) ?> records</button> <button class="btn btn-outline-secondary" name="action" value="cancel">Cancel preview</button></form>
</div>
<?php else: ?>
<form method="post" enctype="multipart/form-data" class="card p-4" data-import-form><?php csrf_field(); ?><input type="hidden" name="action" value="preview"><label for="dataset" class="form-label">Dataset file</label><input id="dataset" class="form-control mb-3" type="file" name="dataset" accept=".csv,.json,.xlsx" required aria-describedby="file-note"><p id="file-note" class="text-muted">Your file is validated before any records are saved.</p><div><button class="btn btn-primary" type="submit">Validate and preview</button></div></form>
<?php endif; ?>
<p id="import-progress" role="status" aria-live="polite" class="mt-3"></p>
</div>
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

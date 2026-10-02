<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/dashboard_data.php';
require_login(); $user=current_user(); $pdo=get_db();
try { $filters=dashboard_filters($_GET); }
catch (InvalidArgumentException $e) { http_response_code(422); exit(htmlspecialchars($e->getMessage())); }
[$clause,$params]=dashboard_record_scope($user,$filters);
$stmt=$pdo->prepare("SELECT COUNT(*) FROM documents d WHERE $clause"); $stmt->execute($params); $count=(int)$stmt->fetchColumn();
$pages=max(1,(int)ceil($count/50));
$page=min($pages,max(1,(int)filter_var($_GET['page']??1,FILTER_VALIDATE_INT)));
$stmt=$pdo->prepare("SELECT d.id,d.doc_number,d.title,d.doc_type,d.status,d.created_at FROM documents d WHERE $clause ORDER BY d.created_at DESC,d.id DESC LIMIT 50 OFFSET ".(($page-1)*50));
$stmt->execute($params); $records=$stmt->fetchAll();
include __DIR__.'/includes/layout_top.php';
?>
<div class="card"><h1 class="h4">Dashboard record details</h1><p>Created <?= htmlspecialchars($filters['from']) ?> through <?= htmlspecialchars($filters['to']) ?> · Type: <?= htmlspecialchars($filters['type']) ?> · Status: <?= htmlspecialchars($filters['status']) ?></p><p><?= $count ?> visible registered records. Page <?= $page ?> of <?= $pages ?>.</p><a class="action-link" href="dashboard.php?<?= htmlspecialchars(http_build_query($filters)) ?>">Back to dashboard</a>
<div class="table-responsive mt-3"><table class="table"><thead><tr><th>Document number</th><th>Title</th><th>Type</th><th>Current status</th><th>Created</th></tr></thead><tbody>
<?php foreach ($records as $record): ?><tr><td><a href="document.php?id=<?= (int)$record['id'] ?>"><?= htmlspecialchars($record['doc_number']) ?></a></td><td><?= htmlspecialchars($record['title']) ?></td><td><?= htmlspecialchars($record['doc_type']) ?></td><td><?= htmlspecialchars($record['status']) ?></td><td><?= htmlspecialchars($record['created_at']) ?></td></tr><?php endforeach; ?>
<?php if (!$records): ?><tr><td colspan="5">No matching records within your access permissions.</td></tr><?php endif; ?></tbody></table></div>
<nav aria-label="Record pages" class="d-flex gap-3"><?php if ($page>1): ?><a class="action-link" href="<?= htmlspecialchars(dashboard_drill_url($filters,['page'=>$page-1])) ?>">Previous</a><?php endif; ?><?php if ($page<$pages): ?><a class="action-link" href="<?= htmlspecialchars(dashboard_drill_url($filters,['page'=>$page+1])) ?>">Next</a><?php endif; ?></nav></div>
<?php include __DIR__.'/includes/layout_bottom.php'; ?>

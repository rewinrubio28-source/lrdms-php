<?php
/**
 * Migration — Document Attachments (multi-file per document).
 *
 * Adds support for multiple files attached to a single document
 * (e.g. a multi-page bill scanned as separate images or a ZIP of pages).
 * The existing documents.file_path column is kept as the primary / first
 * file for backward compatibility with all existing queries and views.
 *
 * Safe to re-run.
 */
require_once __DIR__ . '/../config/database.php';
$pdo = get_db();

$ran   = [];
$skips = [];

function table_exists($pdo, $table) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

if (table_exists($pdo, 'document_attachments')) {
    $skips[] = 'document_attachments table already exists';
} else {
    $pdo->exec(
        'CREATE TABLE document_attachments (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            document_id    INT NOT NULL,
            file_path      VARCHAR(500) NOT NULL,
            display_name   VARCHAR(255) NULL,
            sort_order     INT NOT NULL DEFAULT 0,
            created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_document (document_id, sort_order),
            FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
        ) ENGINE=InnoDB'
    );
    $ran[] = 'Created document_attachments table';
}

?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>LRDMS — Migration result</title>
<style>
  body{font-family:sans-serif; max-width:620px; margin:60px auto; color:#333;}
  code{background:#eee; padding:1px 5px; border-radius:3px;}
  .ok{color:#1e7a3d;} .skip{color:#8a94a6;} ul{margin:6px 0 0;} li{margin-bottom:3px;}
</style></head><body>
<h2>Migration complete ✅</h2>
<?php if ($ran): ?>
  <p class="ok"><strong>Applied:</strong></p>
  <ul class="ok"><?php foreach ($ran as $m): ?><li><?= htmlspecialchars($m) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php if ($skips): ?>
  <p class="skip"><strong>Already present (skipped):</strong></p>
  <ul class="skip"><?php foreach ($skips as $m): ?><li><?= htmlspecialchars($m) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<p>You can keep this file for re-runs (it is idempotent), or delete it once
you are done.</p>
</body></html>

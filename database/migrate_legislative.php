<?php
/**
 * Migration — Legislative Records upgrade (Version Control module).
 *
 * Adds the one piece of storage the upgraded Version Control page needed
 * that nothing in sql/schema.sql already covered: a relationships table
 * so a document can record that it Amends / Repeals / Substitutes /
 * Consolidates / relates to another document.
 *
 * Everything else in the upgrade (searchable document picker, legislative
 * record profile, version badges, related-legislation panel) is presentation
 * over the existing `documents`, `document_change_notes`, and `audit_log`
 * tables — no schema change needed for those.
 *
 * Safe to re-run: checks what already exists before changing it. Fresh
 * installs get this table from sql/schema.sql directly and do not need
 * to run this file.
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

if (table_exists($pdo, 'document_relationships')) {
    $skips[] = 'document_relationships table already exists';
} else {
    $pdo->exec(
        "CREATE TABLE document_relationships (
          id                 INT AUTO_INCREMENT PRIMARY KEY,
          document_id        INT NOT NULL,
          related_id         INT NOT NULL,
          relationship_type  ENUM('amends','repeals','substitutes','consolidates','related') NOT NULL,
          created_by         INT NOT NULL,
          created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_rel (document_id, related_id, relationship_type),
          KEY idx_document (document_id),
          KEY idx_related (related_id),
          FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
          FOREIGN KEY (related_id) REFERENCES documents(id) ON DELETE CASCADE,
          FOREIGN KEY (created_by) REFERENCES users(id)
        ) ENGINE=InnoDB"
    );
    $ran[] = 'Created document_relationships table';
}

// Helpful index for the searchable document picker (Legislative Document
// Search) — LIKE '%term%' can't use it for leading-wildcard matches, but it
// speeds up the "head documents only" filter and any exact/prefix lookups.
$idxCheck = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND INDEX_NAME = 'idx_next_version'"
);
$idxCheck->execute();
if ((int)$idxCheck->fetchColumn() > 0) {
    $skips[] = 'documents.idx_next_version already exists';
} else {
    $pdo->exec('CREATE INDEX idx_next_version ON documents (next_version_id)');
    $ran[] = 'Added index documents.idx_next_version (speeds up the head-documents lookup used by search)';
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>LRDMS — Migration result</title>
<style>
  body{font-family:sans-serif; max-width:620px; margin:60px auto; color:#333;}
  code{background:#eee; padding:1px 5px; border-radius:3px;}
  .ok{color:#1e7a3d;} .skip{color:#8a94a6;} ul{margin:6px 0 0;} li{margin-bottom:3px;}
</style></head><body>
<h2>Legislative Records migration complete ✅</h2>
<?php if ($ran): ?>
  <p class="ok"><strong>Applied:</strong></p>
  <ul class="ok"><?php foreach ($ran as $m): ?><li><?= htmlspecialchars($m) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php if ($skips): ?>
  <p class="skip"><strong>Already present (skipped):</strong></p>
  <ul class="skip"><?php foreach ($skips as $m): ?><li><?= htmlspecialchars($m) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<p>Safe to re-run. You can delete this file once you've run it against every environment.</p>
<p><a href="../version.php">Go to Version Control →</a></p>
</body></html>

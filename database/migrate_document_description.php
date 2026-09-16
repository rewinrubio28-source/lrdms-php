<?php
/**
 * Migration — Document Description column.
 *
 * Adds the `description` column to the `documents` table. encoding.php's
 * "Description / Filing Notes" field has always written to this column,
 * but it was missing from sql/schema.sql — so on any database created
 * from that file, filing a document via encoding.php fails with:
 *   PDOException: Unknown column 'description' in 'field list'
 *
 * This migration only adds the column; it does not touch any other table
 * or existing data.
 *
 * Safe to re-run: checks what already exists before changing it. Fresh
 * installs that import sql/schema.sql AFTER this fix was merged into it
 * will already have the column and this migration becomes a no-op.
 */
require_once __DIR__ . '/../config/database.php';
$pdo = get_db();

$ran   = [];
$skips = [];

function column_exists($pdo, $table, $column) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

if (column_exists($pdo, 'documents', 'description')) {
    $skips[] = "documents.description column already exists";
} else {
    // Placed right after `title` to match where it's used in encoding.php's form.
    $pdo->exec(
        "ALTER TABLE documents
         ADD COLUMN description TEXT NULL AFTER title"
    );
    $ran[] = "Added documents.description column";
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>LRDMS — Migration result</title>
<style>
  body{font-family:sans-serif; max-width:620px; margin:60px auto; color:#333;}
  code{background:#eee; padding:1px 5px; border-radius:3px;}
  .ok{color:#1e7a3d;} .skip{color:#8a94a6;} ul{margin:6px 0 0;} li{margin-bottom:3px;}
</style></head><body>
<h2>Document Description migration complete ✅</h2>
<?php if ($ran): ?>
  <p class="ok"><strong>Applied:</strong></p>
  <ul class="ok"><?php foreach ($ran as $m): ?><li><?= htmlspecialchars($m) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php if ($skips): ?>
  <p class="skip"><strong>Already present (skipped):</strong></p>
  <ul class="skip"><?php foreach ($skips as $m): ?><li><?= htmlspecialchars($m) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<p>Safe to re-run. You can delete this file once you've run it against every environment.</p>
<p><a href="../encoding.php">Go to Encoding →</a></p>
</body></html>
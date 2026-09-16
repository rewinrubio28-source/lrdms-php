<?php
/**
 * Migration — verified_at column.
 *
 * Adds `documents.verified_at` (DATETIME NULL).
 *
 * Why: the "Documents Awaiting Verification" queue in encoding.php used to be
 * computed as `is_public = 0 AND source_system <> 'Manual Encoding'`. That
 * accidentally also matched new versions created via Version Control's
 * "Save new version" — because an amendment copies is_public and
 * source_system forward from the document it's amending. A Records Officer
 * amending an already-verified internal document should never see that new
 * version show back up in the incoming-verification queue; that queue is
 * only for documents a records officer has not yet reviewed at all.
 *
 * verified_at now tracks that distinctly:
 *   - NULL            → never reviewed by a Records Officer (queue membership)
 *   - a timestamp      → reviewed, either by verifying an incoming push or by
 *                        being created internally (amend sets it immediately,
 *                        since the officer doing the amending is the review)
 *
 * Safe to re-run: checks what already exists before changing it.
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

if (column_exists($pdo, 'documents', 'verified_at')) {
    $skips[] = 'documents.verified_at column already exists';
} else {
    $pdo->exec(
        "ALTER TABLE documents
         ADD COLUMN verified_at DATETIME NULL AFTER is_public"
    );
    $ran[] = 'Added documents.verified_at column';

    // Backfill: any document that is already public, or was filed by the
    // reserved Manual Encoding path (records officer typed it in directly),
    // has effectively already been "reviewed" — mark it verified now so the
    // queue starts clean instead of retroactively flooding with old rows.
    $affected = $pdo->exec(
        "UPDATE documents
         SET verified_at = COALESCE(updated_at, created_at, NOW())
         WHERE is_public = 1 OR source_system = 'Manual Encoding'"
    );
    $ran[] = "Backfilled verified_at for $affected already-public/manual document(s)";
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>LRDMS — Migration result</title>
<style>
  body{font-family:sans-serif; max-width:620px; margin:60px auto; color:#333;}
  code{background:#eee; padding:1px 5px; border-radius:3px;}
  .ok{color:#1e7a3d;} .skip{color:#8a94a6;} ul{margin:6px 0 0;} li{margin-bottom:3px;}
</style></head><body>
<h2>verified_at migration complete ✅</h2>
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
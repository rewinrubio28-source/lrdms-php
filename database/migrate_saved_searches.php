<?php
/**
 * Migration — Saved Searches.
 *
 * Adds the one piece of storage the Saved Searches feature needs: a table
 * that remembers a user's search.php query configuration (keyword, mode,
 * and filters) under a name, so they can re-run it later. It does NOT
 * store search results, does not touch the documents table, and does not
 * change how search.php actually searches — see includes/saved_searches.php
 * and search.php for the feature itself.
 *
 * Safe to re-run: checks what already exists before changing it. Fresh
 * installs that import sql/schema.sql AFTER this feature was merged into
 * it will already have the table and this migration becomes a no-op.
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

if (table_exists($pdo, 'saved_searches')) {
    $skips[] = 'saved_searches table already exists';
} else {
    $pdo->exec(
        "CREATE TABLE saved_searches (
          id               INT AUTO_INCREMENT PRIMARY KEY,
          user_id          INT NOT NULL,
          name             VARCHAR(150) NOT NULL,
          search_criteria  TEXT NOT NULL,
          created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          KEY idx_user (user_id),
          FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );
    $ran[] = 'Created saved_searches table';
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>LRDMS — Migration result</title>
<style>
  body{font-family:sans-serif; max-width:620px; margin:60px auto; color:#333;}
  code{background:#eee; padding:1px 5px; border-radius:3px;}
  .ok{color:#1e7a3d;} .skip{color:#8a94a6;} ul{margin:6px 0 0;} li{margin-bottom:3px;}
</style></head><body>
<h2>Saved Searches migration complete ✅</h2>
<?php if ($ran): ?>
  <p class="ok"><strong>Applied:</strong></p>
  <ul class="ok"><?php foreach ($ran as $m): ?><li><?= htmlspecialchars($m) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php if ($skips): ?>
  <p class="skip"><strong>Already present (skipped):</strong></p>
  <ul class="skip"><?php foreach ($skips as $m): ?><li><?= htmlspecialchars($m) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<p>Safe to re-run. You can delete this file once you've run it against every environment.</p>
<p><a href="../search.php">Go to Search →</a></p>
</body></html>
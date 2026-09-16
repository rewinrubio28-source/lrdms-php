<?php
/**
 * Migration — Notifications (bell).
 *
 * Adds the one piece of storage the notification bell needs: a table
 * that keeps one row per recipient per event, so it can show an
 * unread count and a list of what's waiting for them. It rides on top
 * of the existing email-alert logic in includes/workflow.php — see
 * includes/notifications.php for the feature itself — and does not
 * change any other table.
 *
 * Safe to re-run: checks what already exists before changing it. Fresh
 * installs that import sql/schema.sql AFTER this feature was merged
 * into it will already have the table and this migration becomes a
 * no-op.
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

if (table_exists($pdo, 'notifications')) {
    $skips[] = 'notifications table already exists';
} else {
    $pdo->exec(
        "CREATE TABLE notifications (
          id          INT AUTO_INCREMENT PRIMARY KEY,
          user_id     INT NOT NULL,
          type        VARCHAR(40) NOT NULL,
          document_id INT NULL,
          message     VARCHAR(500) NOT NULL,
          is_read     TINYINT(1) NOT NULL DEFAULT 0,
          created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
          FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
          KEY idx_user_unread (user_id, is_read, created_at)
        ) ENGINE=InnoDB"
    );
    $ran[] = 'Created notifications table';
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>LRDMS — Migration result</title>
<style>
  body{font-family:sans-serif; max-width:620px; margin:60px auto; color:#333;}
  code{background:#eee; padding:1px 5px; border-radius:3px;}
  .ok{color:#1e7a3d;} .skip{color:#8a94a6;} ul{margin:6px 0 0;} li{margin-bottom:3px;}
</style></head><body>
<h2>Notifications migration complete ✅</h2>
<?php if ($ran): ?>
  <p class="ok"><strong>Applied:</strong></p>
  <ul class="ok"><?php foreach ($ran as $m): ?><li><?= htmlspecialchars($m) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php if ($skips): ?>
  <p class="skip"><strong>Already present (skipped):</strong></p>
  <ul class="skip"><?php foreach ($skips as $m): ?><li><?= htmlspecialchars($m) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<p>Safe to re-run. You can delete this file once you've run it against every environment.</p>
<p><a href="../dashboard.php">Go to Dashboard →</a></p>
</body></html>

<?php
/**
 * Revision plan v5 foundation migration.
 * Adds records-management metadata without taking ownership of legislative
 * lifecycle status. Run in the browser while signed in as Super Admin, or
 * from the local PHP CLI. Safe to re-run on MySQL/MariaDB.
 */
require_once __DIR__ . '/../config/database.php';
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../includes/rbac.php';
    require_permission('access', 'manage_roles');
}
$pdo = get_db();
$ran = [];
$skipped = [];

function v5_column_exists(PDO $pdo, string $table, string $column): bool {
    $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $q->execute([$table, $column]);
    return (int)$q->fetchColumn() > 0;
}
function v5_add_column(PDO $pdo, string $table, string $column, string $definition, array &$ran, array &$skipped): void {
    if (v5_column_exists($pdo, $table, $column)) { $skipped[] = "$table.$column already exists"; return; }
    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    $ran[] = "Added $table.$column";
}

$initializeRecordsState = !v5_column_exists($pdo, 'documents', 'records_status');
$documentColumns = [
    'records_status' => "ENUM('Submitted','Pending Validation','Returned for Correction','Validated','Registered','Active','Archive Eligible','Archive Preparation','Transferred to Archive System','Duplicate','Unauthorized Submission') NOT NULL DEFAULT 'Registered'",
    'classification' => "ENUM('PUBLIC','INTERNAL','RESTRICTED','CONFIDENTIAL') NOT NULL DEFAULT 'INTERNAL'",
    'originating_office' => 'VARCHAR(180) NULL',
    'originating_division' => 'VARCHAR(180) NULL',
    'submitter_position' => 'VARCHAR(180) NULL',
    'responsible_custodian' => 'VARCHAR(180) NULL',
    'related_legislative_item' => 'VARCHAR(180) NULL',
    'source_record_id' => 'VARCHAR(180) NULL',
    'source_status' => 'VARCHAR(100) NULL',
    'source_status_date' => 'DATETIME NULL',
    'status_last_synced' => 'DATETIME NULL',
    'received_at' => 'DATETIME NULL',
    'registered_at' => 'DATETIME NULL',
    'validation_note' => 'TEXT NULL',
    'pending_since' => 'DATETIME NULL',
    'follow_up_due_at' => 'DATETIME NULL',
    'agenda_monitoring_due_at' => 'DATETIME NULL',
    'retention_period_years' => 'SMALLINT UNSIGNED NULL',
    'disposal_reference' => 'VARCHAR(255) NULL',
];
foreach ($documentColumns as $column => $definition) v5_add_column($pdo, 'documents', $column, $definition, $ran, $skipped);
$userColumns = ['office_id' => 'INT NULL', 'division_id' => 'INT NULL', 'position_id' => 'INT NULL'];
foreach ($userColumns as $column => $definition) v5_add_column($pdo, 'users', $column, $definition, $ran, $skipped);

// Backfill only on the initial upgrade; preserve review outcomes on reruns.
if ($initializeRecordsState) {
    $pdo->exec("UPDATE documents SET records_status='Pending Validation', received_at=COALESCE(received_at,created_at) WHERE verified_at IS NULL AND source_system <> 'Manual Encoding'");
    $pdo->exec("UPDATE documents SET records_status='Registered', registered_at=COALESCE(registered_at,verified_at,created_at), received_at=COALESCE(received_at,created_at) WHERE verified_at IS NOT NULL OR source_system = 'Manual Encoding'");
}

$tables = [
    'offices' => "CREATE TABLE offices (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(180) NOT NULL UNIQUE, is_active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB",
    'divisions' => "CREATE TABLE divisions (id INT AUTO_INCREMENT PRIMARY KEY, office_id INT NULL, name VARCHAR(180) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, UNIQUE KEY uq_division_office (office_id,name), FOREIGN KEY (office_id) REFERENCES offices(id) ON DELETE SET NULL) ENGINE=InnoDB",
    'positions' => "CREATE TABLE positions (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(180) NOT NULL UNIQUE, is_active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB",
    'user_committees' => "CREATE TABLE user_committees (user_id INT NOT NULL, committee_id INT NOT NULL, PRIMARY KEY(user_id,committee_id), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY(committee_id) REFERENCES committees(id) ON DELETE CASCADE) ENGINE=InnoDB",
    'integration_receipts' => "CREATE TABLE integration_receipts (id BIGINT AUTO_INCREMENT PRIMARY KEY, source_system VARCHAR(100) NOT NULL, external_reference_id VARCHAR(180) NULL, payload_reference VARCHAR(255) NULL, received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, received_by INT NULL, processing_status ENUM('Pending Validation','Validated','Registered','Returned for Correction','Duplicate','Unauthorized Submission','Error') NOT NULL DEFAULT 'Pending Validation', lrdms_record_id INT NULL, error_message TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_receipt_status(processing_status,received_at), FOREIGN KEY(received_by) REFERENCES users(id) ON DELETE SET NULL, FOREIGN KEY(lrdms_record_id) REFERENCES documents(id) ON DELETE SET NULL) ENGINE=InnoDB",
    'access_requests' => "CREATE TABLE access_requests (id BIGINT AUTO_INCREMENT PRIMARY KEY, document_id INT NOT NULL, requester_id INT NULL, requester_name VARCHAR(180) NOT NULL, requester_email VARCHAR(180) NULL, purpose TEXT NOT NULL, request_letter_path VARCHAR(500) NULL, status ENUM('Pending','Granted','Denied','Appeal Pending','Closed') NOT NULL DEFAULT 'Pending', decision_note TEXT NULL, decided_by INT NULL, decided_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY idx_access_request_status(status,created_at), FOREIGN KEY(document_id) REFERENCES documents(id) ON DELETE CASCADE, FOREIGN KEY(requester_id) REFERENCES users(id) ON DELETE SET NULL, FOREIGN KEY(decided_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB",
    'access_request_reviews' => "CREATE TABLE access_request_reviews (id BIGINT AUTO_INCREMENT PRIMARY KEY, request_id BIGINT NOT NULL, reviewer_id INT NULL, action ENUM('Granted','Denied','Appeal Filed','Appeal Resolved','Note') NOT NULL, note TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(request_id) REFERENCES access_requests(id) ON DELETE CASCADE, FOREIGN KEY(reviewer_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB",
    'record_access_rules' => "CREATE TABLE record_access_rules (id BIGINT AUTO_INCREMENT PRIMARY KEY, document_id INT NOT NULL, user_id INT NULL, role_id INT NULL, office_id INT NULL, committee_id INT NULL, can_view TINYINT(1) NOT NULL DEFAULT 1, can_download TINYINT(1) NOT NULL DEFAULT 0, valid_until DATETIME NULL, created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(document_id) REFERENCES documents(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY(role_id) REFERENCES roles(id) ON DELETE CASCADE, FOREIGN KEY(office_id) REFERENCES offices(id) ON DELETE CASCADE, FOREIGN KEY(committee_id) REFERENCES committees(id) ON DELETE CASCADE, FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB",
    'record_validation_history' => "CREATE TABLE record_validation_history (id BIGINT AUTO_INCREMENT PRIMARY KEY, document_id INT NOT NULL, actor_id INT NULL, action ENUM('Validated','Returned for Correction','Registered','Duplicate','Unauthorized Submission','Visibility Changed') NOT NULL, note TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(document_id) REFERENCES documents(id) ON DELETE CASCADE, FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB",
    'archive_transfers' => "CREATE TABLE archive_transfers (id BIGINT AUTO_INCREMENT PRIMARY KEY, document_id INT NOT NULL, destination_system VARCHAR(100) NOT NULL DEFAULT 'System 8', status ENUM('Prepared','Transferred','Failed','Cancelled') NOT NULL DEFAULT 'Prepared', external_reference_id VARCHAR(180) NULL, prepared_by INT NULL, transferred_at DATETIME NULL, note TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(document_id) REFERENCES documents(id) ON DELETE CASCADE, FOREIGN KEY(prepared_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB",
    'retention_reviews' => "CREATE TABLE retention_reviews (id BIGINT AUTO_INCREMENT PRIMARY KEY, document_id INT NOT NULL, reviewed_by INT NULL, review_date DATE NOT NULL, outcome ENUM('Retain','Archive Eligible','Transfer Prepared','TBD') NOT NULL DEFAULT 'TBD', note TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(document_id) REFERENCES documents(id) ON DELETE CASCADE, FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB",
];
foreach ($tables as $table => $sql) {
    $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]);
    if ((int)$q->fetchColumn()) $skipped[] = "$table already exists";
    else { $pdo->exec($sql); $ran[] = "Created $table"; }
}
$pdo->exec('INSERT IGNORE INTO user_committees (user_id, committee_id) SELECT id, committee_id FROM users WHERE committee_id IS NOT NULL');
foreach ([['users','office_id','offices'], ['users','division_id','divisions'], ['users','position_id','positions']] as [$table,$column,$referenced]) {
    $constraint = 'fk_v5_' . $column;
    $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');
    $q->execute([$table,$constraint]);
    if (!(int)$q->fetchColumn()) { $pdo->exec("ALTER TABLE `$table` ADD CONSTRAINT `$constraint` FOREIGN KEY (`$column`) REFERENCES `$referenced`(id) ON DELETE SET NULL"); $ran[] = "Linked users.$column to $referenced"; }
    else $skipped[] = "$constraint already exists";
}

if (PHP_SAPI === 'cli') {
    echo "Revision Plan v5 migration finished.\n";
    foreach ($ran as $item) echo "Applied: $item\n";
    foreach ($skipped as $item) echo "Already present: $item\n";
    exit;
}

include __DIR__ . '/../includes/layout_top.php';
?>
<div class="topbar"><div><div class="topbar__eyebrow">Database update</div><h1 class="topbar__title">Revision Plan v5 Foundation</h1></div></div>
<div class="card"><h2 class="h5">Migration finished</h2><p>Records metadata and access, intake, validation, and archive support tables are ready.</p>
<h3 class="h6">Applied</h3><ul><?php foreach ($ran as $item): ?><li><?= htmlspecialchars($item) ?></li><?php endforeach; ?></ul>
<h3 class="h6">Already present</h3><ul><?php foreach ($skipped as $item): ?><li><?= htmlspecialchars($item) ?></li><?php endforeach; ?></ul>
<p class="small text-muted mb-0">This migration adds records-management state and provenance. It does not change legislative status or make records public.</p></div>
<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>

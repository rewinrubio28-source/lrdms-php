<?php
// CLI deployment migration; does not change existing records or their dates.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/database.php';
get_db()->exec("CREATE TABLE IF NOT EXISTS record_followups (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    recorded_by INT NOT NULL,
    contact_person VARCHAR(180) NOT NULL,
    contact_method VARCHAR(30) NOT NULL,
    note TEXT NOT NULL,
    next_due_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_followup_document (document_id, id),
    FOREIGN KEY (document_id) REFERENCES documents(id),
    FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "Record follow-up table ready.\n";

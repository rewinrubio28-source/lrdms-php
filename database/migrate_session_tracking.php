<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/database.php';
$pdo = get_db();
$pdo->exec("CREATE TABLE IF NOT EXISTS document_sessions (
 document_id INT PRIMARY KEY,
 stage VARCHAR(40) NOT NULL,
 revision INT NOT NULL DEFAULT 1,
 amendment_started_at DATETIME NULL,
 amendment_due_at DATETIME NULL,
 overdue_notified_at DATETIME NULL,
 final_attachment_id INT NULL,
 signed_attachment_id INT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_session_due (stage, amendment_due_at),
 FOREIGN KEY (document_id) REFERENCES documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS document_session_events (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 document_id INT NOT NULL,
 actor_id INT NULL,
 action VARCHAR(60) NOT NULL,
 stage VARCHAR(40) NOT NULL,
 note TEXT NOT NULL,
 attachment_id INT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_session_history (document_id,id),
 FOREIGN KEY (document_id) REFERENCES documents(id),
 FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "Session tracking tables ready. Existing documents unchanged.\n";

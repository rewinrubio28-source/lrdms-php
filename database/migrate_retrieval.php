<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/database.php';
$pdo = get_db();
$pdo->exec("CREATE TABLE IF NOT EXISTS document_copy_requests (
 id INT AUTO_INCREMENT PRIMARY KEY, document_id INT NOT NULL, requester_id INT NOT NULL,
 reason TEXT NOT NULL, status ENUM('Pending','Approved','Denied') NOT NULL DEFAULT 'Pending',
 reviewed_by INT NULL, review_note TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 reviewed_at DATETIME NULL, FOREIGN KEY(document_id) REFERENCES documents(id),
 FOREIGN KEY(requester_id) REFERENCES users(id), FOREIGN KEY(reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB");
$stmt = $pdo->prepare('INSERT INTO permissions (module, action, description) SELECT ?, ?, ? WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE module=? AND action=?)');
foreach (['download' => 'Download attachments of records you are allowed to view.', 'review_copy_requests' => 'Approve or deny requests for copies of records you can view. Cannot approve your own request.'] as $action => $description) {
    $stmt->execute(['repository', $action, $description, 'repository', $action]);
}
echo "Retrieval permissions and copy requests ready. No role grants changed.\n";

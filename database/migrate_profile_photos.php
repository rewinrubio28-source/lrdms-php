<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/database.php';
get_db()->exec('CREATE TABLE IF NOT EXISTS user_profile_photos (
 user_id INT NOT NULL PRIMARY KEY,
 mime_type VARCHAR(30) NOT NULL,
 image_data MEDIUMBLOB NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB');
echo "Profile photo storage ready.\n";

CREATE TABLE IF NOT EXISTS privacy_events (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 purpose VARCHAR(40) NOT NULL,
 decision VARCHAR(20) NOT NULL,
 notice_version VARCHAR(30) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_privacy_user (user_id, id),
 FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS privacy_requests (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 request_type VARCHAR(30) NOT NULL,
 details TEXT NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'Open',
 response TEXT NULL,
 reviewed_by INT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_privacy_request (status, created_at),
 FOREIGN KEY (user_id) REFERENCES users(id),
 FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB;

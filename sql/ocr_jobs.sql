CREATE TABLE IF NOT EXISTS ocr_jobs (
    document_id INT NOT NULL PRIMARY KEY,
    requested_by INT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'queued',
    token CHAR(32) NOT NULL,
    files_json LONGTEXT NOT NULL,
    original_text_hash CHAR(64) NOT NULL,
    file_index INT NOT NULL DEFAULT 0,
    file_count INT NOT NULL DEFAULT 0,
    pages_done INT NOT NULL DEFAULT 0,
    pages_total INT NULL,
    error_message VARCHAR(500) NULL,
    attempts INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    heartbeat_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    INDEX idx_ocr_queue (status, created_at),
    CONSTRAINT fk_ocr_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

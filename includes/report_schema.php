<?php
function report_schedule_schema(): array {
    return [
        "CREATE TABLE IF NOT EXISTS report_schedules (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, title VARCHAR(120) NOT NULL,
            options_json TEXT NOT NULL, frequency VARCHAR(10) NOT NULL, run_time CHAR(5) NOT NULL,
            enabled TINYINT NOT NULL DEFAULT 1, next_run_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY report_due (enabled,next_run_at), KEY report_owner (user_id),
            FOREIGN KEY (user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS report_runs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, schedule_id BIGINT NOT NULL, user_id INT NOT NULL,
            scheduled_for DATETIME NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'Processing', attempts INT NOT NULL DEFAULT 0,
            generated_at DATETIME NULL, snapshot_json MEDIUMTEXT NULL, pdf_bytes MEDIUMBLOB NULL,
            error_message VARCHAR(250) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY report_slot (schedule_id,scheduled_for), KEY report_run_owner (user_id,created_at),
            FOREIGN KEY (schedule_id) REFERENCES report_schedules(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];
}


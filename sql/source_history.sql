CREATE TABLE IF NOT EXISTS document_source_history (
 id INT AUTO_INCREMENT PRIMARY KEY,
 document_id INT NOT NULL,
 event_date DATE NOT NULL,
 event_title VARCHAR(180) NOT NULL,
 source_office VARCHAR(180) NOT NULL,
 destination_office VARCHAR(180) NULL,
 actor_name VARCHAR(180) NULL,
 reference VARCHAR(180) NULL,
 remarks TEXT NOT NULL,
 evidence_type ENUM('Manual source record','Simulated demo') NOT NULL,
 recorded_by INT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_source_history_document (document_id,event_date,id),
 FOREIGN KEY (document_id) REFERENCES documents(id),
 FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

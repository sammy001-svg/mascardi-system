-- Migration 017: Car Documents
-- Stores official documents per vehicle (logbook, NTSA cert, duty clearance, etc.)

CREATE TABLE IF NOT EXISTS car_documents (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    car_id       INT          NOT NULL,
    doc_type     VARCHAR(50)  NOT NULL DEFAULT 'other',
    title        VARCHAR(200) NOT NULL,
    file_path    VARCHAR(500) NOT NULL,
    file_name    VARCHAR(255) NOT NULL,
    file_size    INT UNSIGNED NULL,
    mime_type    VARCHAR(100) NULL,
    expiry_date  DATE         NULL,
    notes        TEXT         NULL,
    uploaded_by  INT          NULL,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX  idx_car_doc  (car_id, doc_type),
    INDEX  idx_expiry   (expiry_date),
    CONSTRAINT fk_doc_car      FOREIGN KEY (car_id)      REFERENCES cars(id)  ON DELETE CASCADE,
    CONSTRAINT fk_doc_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

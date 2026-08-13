-- Reception photos table for MySQL

CREATE TABLE IF NOT EXISTS reception_photos (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    file_name VARCHAR(255) NOT NULL,
    storage_path VARCHAR(512) NOT NULL,
    mime_type VARCHAR(64) NOT NULL,
    uploader_name VARCHAR(128) DEFAULT NULL,
    table_number INT DEFAULT NULL,
    likes_count INT DEFAULT 0,
    is_approved TINYINT(1) DEFAULT 1,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_reception_photos_uploaded_at ON reception_photos(uploaded_at DESC);
CREATE INDEX idx_reception_photos_approved ON reception_photos(is_approved, uploaded_at DESC);



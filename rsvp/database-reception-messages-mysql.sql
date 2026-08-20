-- Reception guest messages for the couple (MySQL/MariaDB)

CREATE TABLE IF NOT EXISTS reception_messages (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    guest_name VARCHAR(128) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reception_messages_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

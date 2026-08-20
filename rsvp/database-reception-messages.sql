-- Reception guest messages for the couple (PostgreSQL)

CREATE TABLE IF NOT EXISTS reception_messages (
    id BIGSERIAL PRIMARY KEY,
    guest_name VARCHAR(128) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_reception_messages_created ON reception_messages(created_at);

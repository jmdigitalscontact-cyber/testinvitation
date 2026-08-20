-- ============================================================
--  WEDDING RSVP SYSTEM — SINGLE-FILE DATABASE MIGRATION (MySQL)
-- ============================================================
--  One file. Everything your GoDaddy MySQL database needs.
--
--  HOW TO USE (phpMyAdmin — easiest):
--    1. Log in to GoDaddy cPanel → phpMyAdmin.
--    2. Click the database name in the left column
--       (or create a new database first, e.g. wedding_rsvp).
--    3. Click the "Import" tab at the top.
--    4. Choose this file (database-full-mysql.sql).
--    5. Click "Go" / "Import". Done!
--
--  The file is safe to run more than once (IF NOT EXISTS).
--  It creates all 10 tables + all indexes + foreign keys.
--
--  After importing, run:
--      php rsvp/create-admin.php
--  (or visit rsvp/setup.php in your browser) to create the
--  admin user you'll use to sign in to the dashboard.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1) INVITATIONS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invitations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    invitation_id VARCHAR(50) UNIQUE NOT NULL,
    guest_name VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    max_guests INT NOT NULL DEFAULT 1,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    email VARCHAR(255),
    phone VARCHAR(20),
    notes TEXT,
    invited_guest_names JSON
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_invitations_invitation_id ON invitations(invitation_id);
CREATE INDEX idx_invitations_status ON invitations(status);

-- ------------------------------------------------------------
-- 2) RSVP RESPONSES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rsvp_responses (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    invitation_id VARCHAR(50) NOT NULL,
    attending VARCHAR(10) NOT NULL,
    attendee_count INT DEFAULT 0,
    attendees JSON,
    dietary_restrictions TEXT,
    special_notes TEXT,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    edited_once BOOLEAN DEFAULT FALSE,
    CONSTRAINT fk_rsvp_invitation FOREIGN KEY (invitation_id)
        REFERENCES invitations(invitation_id) ON DELETE CASCADE,
    CONSTRAINT unique_invitation_response UNIQUE (invitation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_rsvp_responses_invitation_id ON rsvp_responses(invitation_id);

-- ------------------------------------------------------------
-- 3) ATTENDEES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS attendees (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    rsvp_response_id BIGINT NOT NULL,
    invitation_id VARCHAR(50) NOT NULL,
    attendee_name VARCHAR(255) NOT NULL,
    dietary_restrictions VARCHAR(255),
    special_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_attendees_response FOREIGN KEY (rsvp_response_id)
        REFERENCES rsvp_responses(id) ON DELETE CASCADE,
    CONSTRAINT fk_attendees_invitation FOREIGN KEY (invitation_id)
        REFERENCES invitations(invitation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_attendees_invitation_id ON attendees(invitation_id);

-- ------------------------------------------------------------
-- 4) ADMIN USERS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_users (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 5) LOGIN ATTEMPTS  (guest + admin brute-force protection)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    invitation_id VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    success BOOLEAN DEFAULT FALSE,
    source VARCHAR(20) DEFAULT 'guest'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_login_attempts_invitation_ip ON login_attempts(invitation_id, ip_address);
CREATE INDEX idx_login_attempts_attempt_time ON login_attempts(attempt_time);
CREATE INDEX idx_login_attempts_source ON login_attempts(source);

-- ------------------------------------------------------------
-- 6) QR CODES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS qr_codes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    invitation_id VARCHAR(50) NOT NULL UNIQUE,
    qr_code_data VARCHAR(500) NOT NULL,
    qr_image_path VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_qr_invitation FOREIGN KEY (invitation_id)
        REFERENCES invitations(invitation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_qr_codes_invitation_id ON qr_codes(invitation_id);

-- ------------------------------------------------------------
-- 7) SESSIONS  (guest invitation sessions)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sessions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    invitation_id VARCHAR(50) NOT NULL UNIQUE,
    token VARCHAR(255) NOT NULL UNIQUE,
    expiry_time TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sessions_invitation FOREIGN KEY (invitation_id)
        REFERENCES invitations(invitation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_sessions_token ON sessions(token);
CREATE INDEX idx_sessions_expiry ON sessions(expiry_time);

-- ------------------------------------------------------------
-- 8) ADMIN SESSIONS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_sessions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    admin_id BIGINT NOT NULL UNIQUE,
    token VARCHAR(255) NOT NULL UNIQUE,
    expiry_time TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_admin_sessions_admin FOREIGN KEY (admin_id)
        REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_admin_sessions_token ON admin_sessions(token);
CREATE INDEX idx_admin_sessions_expiry ON admin_sessions(expiry_time);

-- ------------------------------------------------------------
-- 9) TABLE ASSIGNMENTS  (reception seating)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS table_assignments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    invitation_id VARCHAR(50) NOT NULL,
    attendee_id BIGINT NULL,
    table_number INT NOT NULL,
    seat_number INT NULL,
    assigned_by BIGINT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_table_assignments_invitation FOREIGN KEY (invitation_id)
        REFERENCES invitations(invitation_id) ON DELETE CASCADE,
    CONSTRAINT fk_table_assignments_attendee FOREIGN KEY (attendee_id)
        REFERENCES attendees(id) ON DELETE CASCADE,
    CONSTRAINT fk_table_assignments_admin FOREIGN KEY (assigned_by)
        REFERENCES admin_users(id) ON DELETE SET NULL,
    CONSTRAINT unique_invitation_attendee UNIQUE (invitation_id, attendee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_table_assignments_invitation_id ON table_assignments(invitation_id);
CREATE INDEX idx_table_assignments_attendee_id ON table_assignments(attendee_id);
CREATE INDEX idx_table_assignments_table_number ON table_assignments(table_number);

-- ------------------------------------------------------------
-- 10) RECEPTION PHOTOS
-- ------------------------------------------------------------
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_reception_photos_uploaded_at ON reception_photos(uploaded_at);
CREATE INDEX idx_reception_photos_approved ON reception_photos(is_approved, uploaded_at DESC);

-- ------------------------------------------------------------
-- 11) RECEPTION TEAM VOTES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reception_votes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    voter_token CHAR(64) NOT NULL,
    team ENUM('bride', 'groom') NOT NULL,
    voted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reception_voter_token (voter_token),
    INDEX idx_reception_votes_team (team)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reception_messages (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    guest_name VARCHAR(128) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reception_messages_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
--  DONE. All tables created.
-- ============================================================
--  Next steps after import:
--   1. php rsvp/create-admin.php          → create your admin login
--   2. Edit rsvp/.env with your DB creds  → see .env.example
--   3. Visit rsvp/setup.php to verify all checks are green
-- ============================================================


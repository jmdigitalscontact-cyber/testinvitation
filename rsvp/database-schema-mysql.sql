-- Wedding RSVP System Database Schema (MySQL/MariaDB)
-- Use with phpMyAdmin or MySQL command line

CREATE TABLE IF NOT EXISTS invitations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    invitation_id VARCHAR(50) UNIQUE NOT NULL,
    guest_name VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    max_guests INT NOT NULL DEFAULT 1,
    status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'responded', 'declined')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    email VARCHAR(255),
    phone VARCHAR(20),
    notes TEXT,
    invited_guest_names JSON
);

CREATE INDEX idx_invitations_invitation_id ON invitations(invitation_id);
CREATE INDEX idx_invitations_status ON invitations(status);

CREATE TABLE IF NOT EXISTS rsvp_responses (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    invitation_id VARCHAR(50) NOT NULL,
    attending VARCHAR(10) NOT NULL CHECK (attending IN ('yes', 'no', 'maybe')),
    attendee_count INT DEFAULT 0,
    attendees JSON,
    dietary_restrictions TEXT,
    special_notes TEXT,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    edited_once BOOLEAN DEFAULT FALSE,
    CONSTRAINT fk_rsvp_invitation FOREIGN KEY (invitation_id) REFERENCES invitations(invitation_id) ON DELETE CASCADE,
    CONSTRAINT unique_invitation_response UNIQUE (invitation_id)
);

CREATE INDEX idx_rsvp_responses_invitation_id ON rsvp_responses(invitation_id);

CREATE TABLE IF NOT EXISTS attendees (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    rsvp_response_id BIGINT NOT NULL,
    invitation_id VARCHAR(50) NOT NULL,
    attendee_name VARCHAR(255) NOT NULL,
    dietary_restrictions VARCHAR(255),
    special_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_attendees_response FOREIGN KEY (rsvp_response_id) REFERENCES rsvp_responses(id) ON DELETE CASCADE,
    CONSTRAINT fk_attendees_invitation FOREIGN KEY (invitation_id) REFERENCES invitations(invitation_id) ON DELETE CASCADE
);

CREATE INDEX idx_attendees_invitation_id ON attendees(invitation_id);

CREATE TABLE IF NOT EXISTS admin_users (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    invitation_id VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    success BOOLEAN DEFAULT FALSE,
    source VARCHAR(20) DEFAULT 'guest'
);

CREATE INDEX idx_login_attempts_invitation_ip ON login_attempts(invitation_id, ip_address);
CREATE INDEX idx_login_attempts_attempt_time ON login_attempts(attempt_time);
CREATE INDEX idx_login_attempts_source ON login_attempts(source);

CREATE TABLE IF NOT EXISTS qr_codes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    invitation_id VARCHAR(50) NOT NULL UNIQUE,
    qr_code_data VARCHAR(500) NOT NULL,
    qr_image_path VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_qr_invitation FOREIGN KEY (invitation_id) REFERENCES invitations(invitation_id) ON DELETE CASCADE
);

CREATE INDEX idx_qr_codes_invitation_id ON qr_codes(invitation_id);


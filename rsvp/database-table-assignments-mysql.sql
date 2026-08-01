-- Table Assignments for Reception Seating (MySQL)
-- This table allows assigning tables to invitations or individual attendees

CREATE TABLE IF NOT EXISTS table_assignments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    invitation_id VARCHAR(50) NOT NULL,
    attendee_id BIGINT NULL,
    table_number INT NOT NULL,
    seat_number INT NULL,
    assigned_by BIGINT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_table_assignments_invitation FOREIGN KEY (invitation_id) REFERENCES invitations(invitation_id) ON DELETE CASCADE,
    CONSTRAINT fk_table_assignments_attendee FOREIGN KEY (attendee_id) REFERENCES attendees(id) ON DELETE CASCADE,
    CONSTRAINT fk_table_assignments_admin FOREIGN KEY (assigned_by) REFERENCES admin_users(id) ON DELETE SET NULL,
    CONSTRAINT unique_invitation_attendee UNIQUE (invitation_id, attendee_id)
);

CREATE INDEX idx_table_assignments_invitation_id ON table_assignments(invitation_id);
CREATE INDEX idx_table_assignments_attendee_id ON table_assignments(attendee_id);
CREATE INDEX idx_table_assignments_table_number ON table_assignments(table_number);


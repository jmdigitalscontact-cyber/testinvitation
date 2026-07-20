-- Add edited_once column to track if RSVP has been edited
ALTER TABLE rsvp_responses ADD COLUMN IF NOT EXISTS edited_once BOOLEAN DEFAULT FALSE;

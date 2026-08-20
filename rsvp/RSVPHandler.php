<?php
// RSVP Handler Class

class RSVPHandler {
    private $db;
    private $mysqli;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->mysqli = $this->db->getConnection();
    }

    /**
     * Save RSVP response (one-time edit allowed)
     * @param string $invitation_id
     * @param string $attending (yes, no, maybe)
     * @param int $attendee_count
     * @param array $attendees
     * @param string $dietary_restrictions
     * @param string $special_notes
     * @return array|false
     */
    public function submitRSVP($invitation_id, $attending, $attendee_count, $attendees = [], $dietary_restrictions = '', $special_notes = '') {
        // Validate invitation exists and max guests
        $invitation = $this->getInvitationInfo($invitation_id);
        if (!$invitation) {
            return ['success' => false, 'error' => 'Invalid invitation ID'];
        }

        $normalizedAttendees = [];
        foreach ((array)$attendees as $attendee) {
            if (!is_array($attendee)) {
                continue;
            }
            $name = trim((string)($attendee['name'] ?? $attendee['attendee_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $isGoingValue = $attendee['attending'] ?? $attendee['going'] ?? $attendee['is_going'] ?? false;
            if (is_string($isGoingValue)) {
                $isGoing = in_array(strtolower(trim($isGoingValue)), ['1', 'true', 'yes', 'y', 'on', 'checked'], true);
            } else {
                $isGoing = (bool)$isGoingValue;
            }
            $normalizedAttendees[] = [
                'name' => $name,
                'attending' => $isGoing,
                'dietary_restrictions' => (string)($attendee['dietary_restrictions'] ?? ''),
                'special_notes' => (string)($attendee['special_notes'] ?? '')
            ];
        }

        $selectedAttendees = array_values(array_filter($normalizedAttendees, function ($attendee) {
            return !empty($attendee['attending']);
        }));

        if ($attending === '' || $attending === null) {
            $attending = !empty($selectedAttendees) ? 'yes' : 'no';
        }

        // Validate attending count
        if ($attending === 'yes') {
            $actual_attendee_count = count($selectedAttendees);

            // Validate that provided attendees don't exceed max guests
            if ($actual_attendee_count > $invitation['max_guests']) {
                return ['success' => false, 'error' => "You provided {$actual_attendee_count} names but only {$invitation['max_guests']} guests are allowed. Please remove " . ($actual_attendee_count - $invitation['max_guests']) . " name(s)."];
            }

            // At least one attendee must be provided
            if ($actual_attendee_count === 0) {
                return ['success' => false, 'error' => 'Please provide at least one attendee name'];
            }

            // Use the actual count
            $attendee_count = $actual_attendee_count;
        }

        if ($attending === 'no') {
            $attendee_count = 0;
        }

        // First submission may be corrected once by the guest.
        $existing = $this->getRSVPResponse($invitation_id);

        try {
            if ($existing) {
                if (!empty($existing['edited_once'])) {
                    return [
                        'success' => false,
                        'error' => 'Your one allowed RSVP update has already been used. Please contact Jason & Rhona Mae for any further changes.',
                        'locked' => true,
                        'edit_used' => true,
                    ];
                }

                $attendees_json = json_encode($normalizedAttendees);
                $stmt = $this->mysqli->prepare("
                    UPDATE rsvp_responses
                    SET attending = ?, attendee_count = ?, attendees = ?,
                        dietary_restrictions = ?, special_notes = ?, edited_once = TRUE,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE invitation_id = ? AND COALESCE(edited_once, FALSE) = FALSE
                ");
                $stmt->bind_param(
                    "sissss",
                    $attending,
                    $attendee_count,
                    $attendees_json,
                    $dietary_restrictions,
                    $special_notes,
                    $invitation_id
                );
                if (!$stmt->execute()) {
                    throw new Exception("Failed to update RSVP: " . $stmt->error);
                }
                $updated = (int)$this->mysqli->affected_rows > 0;
                $stmt->close();

                // Atomic guard: only one concurrent request may consume the edit.
                if (!$updated) {
                    return [
                        'success' => false,
                        'error' => 'Your one allowed RSVP update has already been used. Please contact Jason & Rhona Mae for any further changes.',
                        'locked' => true,
                        'edit_used' => true,
                    ];
                }

                $rsvp_id = (int)$existing['id'];
                $new_status = ($attending === 'yes') ? 'responded' : (($attending === 'no') ? 'declined' : 'responded');
                $stmt = $this->mysqli->prepare("UPDATE invitations SET status = ? WHERE invitation_id = ?");
                $stmt->bind_param("ss", $new_status, $invitation_id);
                $stmt->execute();
                $stmt->close();

                if (!empty($selectedAttendees) && $attending !== 'no') {
                    $this->saveAttendeesList($rsvp_id, $invitation_id, $selectedAttendees);
                } else {
                    $stmt = $this->mysqli->prepare("DELETE FROM attendees WHERE rsvp_response_id = ?");
                    $stmt->bind_param("i", $rsvp_id);
                    $stmt->execute();
                    $stmt->close();
                }

                return [
                    'success' => true,
                    'message' => 'RSVP updated successfully. Your one guest update has now been used.',
                    'rsvp_id' => $rsvp_id,
                    'invitation_id' => $invitation_id,
                    'updated' => true,
                    'edit_used' => true,
                ];
            }

            // Create the first response; one guest correction remains available.
            $stmt = $this->mysqli->prepare("
                INSERT INTO rsvp_responses 
                (invitation_id, attending, attendee_count, attendees, dietary_restrictions, special_notes) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $attendees_json = json_encode($normalizedAttendees);
            $stmt->bind_param("ssisss", $invitation_id, $attending, $attendee_count, $attendees_json, $dietary_restrictions, $special_notes);

            if (!$stmt->execute()) {
                throw new Exception("Failed to create RSVP: " . $stmt->error);
            }

            $rsvp_id = $this->db->lastInsertId();
            $stmt->close();

            // Update invitation status
            $new_status = ($attending === 'yes') ? 'responded' : (($attending === 'no') ? 'declined' : 'responded');
            $stmt = $this->mysqli->prepare("
                UPDATE invitations SET status = ? WHERE invitation_id = ?
            ");
            $stmt->bind_param("ss", $new_status, $invitation_id);
            $stmt->execute();
            $stmt->close();

            // Save individual attendees
            if (!empty($selectedAttendees) && $attending !== 'no') {
                $this->saveAttendeesList($rsvp_id, $invitation_id, $selectedAttendees);
            }

            return [
                'success' => true,
                'message' => 'RSVP submitted successfully',
                'rsvp_id' => $rsvp_id,
                'invitation_id' => $invitation_id,
                'updated' => false,
                'edit_available' => true,
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Admin override for accidental or incorrect guest submissions.
     */
    public function adminUpdateRSVP($invitation_id, $attending, $attendees = [], $dietary_restrictions = '', $special_notes = '') {
        $invitation = $this->getInvitationInfo($invitation_id);
        if (!$invitation) {
            return ['success' => false, 'error' => 'Invalid invitation ID'];
        }

        if (!in_array($attending, ['yes', 'no', 'maybe'], true)) {
            return ['success' => false, 'error' => 'Invalid attendance value'];
        }

        $normalizedAttendees = [];
        foreach ((array)$attendees as $attendee) {
            if (is_string($attendee)) {
                $name = trim($attendee);
                if ($name === '') {
                    continue;
                }
                $normalizedAttendees[] = [
                    'name' => $name,
                    'attending' => true,
                    'dietary_restrictions' => '',
                    'special_notes' => '',
                ];
                continue;
            }

            if (!is_array($attendee)) {
                continue;
            }

            $name = trim((string)($attendee['name'] ?? $attendee['attendee_name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $isGoingValue = $attendee['attending'] ?? $attendee['going'] ?? $attendee['is_going'] ?? true;
            if (is_string($isGoingValue)) {
                $isGoing = in_array(strtolower(trim($isGoingValue)), ['1', 'true', 'yes', 'y', 'on', 'checked'], true);
            } else {
                $isGoing = (bool)$isGoingValue;
            }

            $normalizedAttendees[] = [
                'name' => $name,
                'attending' => $isGoing,
                'dietary_restrictions' => (string)($attendee['dietary_restrictions'] ?? ''),
                'special_notes' => (string)($attendee['special_notes'] ?? ''),
            ];
        }

        $selectedAttendees = array_values(array_filter($normalizedAttendees, function ($attendee) {
            return !empty($attendee['attending']);
        }));

        if ($attending === 'yes' && empty($selectedAttendees)) {
            $selectedAttendees[] = [
                'name' => $invitation['guest_name'],
                'attending' => true,
                'dietary_restrictions' => '',
                'special_notes' => '',
            ];
        }

        if ($attending === 'yes' && count($selectedAttendees) > (int)$invitation['max_guests']) {
            return [
                'success' => false,
                'error' => 'Attendee count exceeds the invitation maximum of ' . (int)$invitation['max_guests'] . '.',
            ];
        }

        $attendee_count = $attending === 'yes' ? count($selectedAttendees) : 0;
        $existing = $this->getRSVPResponse($invitation_id);

        try {
            $attendees_json = json_encode($normalizedAttendees);

            if ($existing) {
                $stmt = $this->mysqli->prepare("
                    UPDATE rsvp_responses
                    SET attending = ?, attendee_count = ?, attendees = ?, dietary_restrictions = ?, special_notes = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE invitation_id = ?
                ");
                $stmt->bind_param("sissss", $attending, $attendee_count, $attendees_json, $dietary_restrictions, $special_notes, $invitation_id);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to update RSVP: ' . $stmt->error);
                }
                $stmt->close();
                $rsvp_id = (int)$existing['id'];
            } else {
                $stmt = $this->mysqli->prepare("
                    INSERT INTO rsvp_responses
                    (invitation_id, attending, attendee_count, attendees, dietary_restrictions, special_notes, edited_once)
                    VALUES (?, ?, ?, ?, ?, ?, 0)
                ");
                $stmt->bind_param("ssisss", $invitation_id, $attending, $attendee_count, $attendees_json, $dietary_restrictions, $special_notes);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to create RSVP: ' . $stmt->error);
                }
                $rsvp_id = (int)$this->db->lastInsertId();
                $stmt->close();
            }

            $new_status = ($attending === 'yes' || $attending === 'maybe')
                ? 'responded'
                : (($attending === 'no') ? 'declined' : 'responded');
            $stmt = $this->mysqli->prepare("UPDATE invitations SET status = ? WHERE invitation_id = ?");
            $stmt->bind_param("ss", $new_status, $invitation_id);
            $stmt->execute();
            $stmt->close();

            if (!empty($selectedAttendees) && $attending !== 'no') {
                $this->saveAttendeesList($rsvp_id, $invitation_id, $selectedAttendees);
            } else {
                $stmt = $this->mysqli->prepare("DELETE FROM attendees WHERE rsvp_response_id = ?");
                $stmt->bind_param("i", $rsvp_id);
                $stmt->execute();
                $stmt->close();
            }

            return [
                'success' => true,
                'message' => 'RSVP updated by admin.',
                'invitation_id' => $invitation_id,
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Clear a guest RSVP so they can submit again.
     */
    public function adminClearRSVP($invitation_id) {
        $invitation = $this->getInvitationInfo($invitation_id);
        if (!$invitation) {
            return ['success' => false, 'error' => 'Invalid invitation ID'];
        }

        $existing = $this->getRSVPResponse($invitation_id);
        if (!$existing) {
            return ['success' => false, 'error' => 'No RSVP response to reset.'];
        }

        try {
            $stmt = $this->mysqli->prepare("DELETE FROM rsvp_responses WHERE invitation_id = ?");
            $stmt->bind_param("s", $invitation_id);
            if (!$stmt->execute()) {
                throw new Exception('Failed to clear RSVP: ' . $stmt->error);
            }
            $stmt->close();

            $pendingStatus = 'pending';
            $stmt = $this->mysqli->prepare("UPDATE invitations SET status = ? WHERE invitation_id = ?");
            $stmt->bind_param("ss", $pendingStatus, $invitation_id);
            $stmt->execute();
            $stmt->close();

            return [
                'success' => true,
                'message' => 'RSVP reset. Guest can submit again.',
                'invitation_id' => $invitation_id,
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Save individual attendees list
     * @param int $rsvp_id
     * @param string $invitation_id
     * @param array $attendees
     */
    private function saveAttendeesList($rsvp_id, $invitation_id, $attendees) {
        // Delete existing attendees for this RSVP
        $stmt = $this->mysqli->prepare("DELETE FROM attendees WHERE rsvp_response_id = ?");
        $stmt->bind_param("i", $rsvp_id);
        $stmt->execute();
        $stmt->close();

        // Insert new attendees
        $stmt = $this->mysqli->prepare("
            INSERT INTO attendees (rsvp_response_id, invitation_id, attendee_name, dietary_restrictions, special_notes) 
            VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($attendees as $attendee) {
            $name = $attendee['name'] ?? $attendee['attendee_name'] ?? '';
            $dietary = $attendee['dietary_restrictions'] ?? '';
            $notes = $attendee['special_notes'] ?? '';

            $stmt->bind_param("issss", $rsvp_id, $invitation_id, $name, $dietary, $notes);
            $stmt->execute();
        }
        $stmt->close();
    }

    /**
     * Get RSVP response for an invitation
     * @param string $invitation_id
     * @return array|false
     */
    public function getRSVPResponse($invitation_id) {
        $stmt = $this->mysqli->prepare("
            SELECT * FROM rsvp_responses WHERE invitation_id = ?
        ");

        $stmt->bind_param("s", $invitation_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            return false;
        }

        $response = $result->fetch_assoc();
        $response['attendees'] = json_decode($response['attendees'], true);
        $stmt->close();

        return $response;
    }

    /**
     * Get attendees for an RSVP response
     * @param int $rsvp_id
     * @return array
     */
    public function getAttendees($rsvp_id) {
        $stmt = $this->mysqli->prepare("
            SELECT * FROM attendees WHERE rsvp_response_id = ?
        ");

        $stmt->bind_param("i", $rsvp_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $attendees = [];
        while ($row = $result->fetch_assoc()) {
            $attendees[] = $row;
        }
        $stmt->close();

        return $attendees;
    }

    /**
     * Get invitation info
     * @param string $invitation_id
     * @return array|false
     */
    private function getInvitationInfo($invitation_id) {
        $stmt = $this->mysqli->prepare("
            SELECT id, invitation_id, guest_name, max_guests, status FROM invitations WHERE invitation_id = ?
        ");

        $stmt->bind_param("s", $invitation_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            return false;
        }

        $invitation = $result->fetch_assoc();
        $stmt->close();
        return $invitation;
    }

    /**
     * Get RSVP summary (for admin dashboard)
     * @return array
     */
    public function getRSVPSummary() {
        $stmt = $this->mysqli->prepare("
            SELECT 
                COUNT(*) as total_invitations,
                SUM(CASE WHEN status = 'responded' THEN 1 ELSE 0 END) as responded,
                SUM(CASE WHEN status = 'declined' THEN 1 ELSE 0 END) as declined,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(max_guests) as total_slots,
                COALESCE(SUM(CASE WHEN r.attending = 'yes' THEN r.attendee_count ELSE 0 END), 0) as confirmed_guests,
                COALESCE(SUM(CASE WHEN r.attending = 'no' THEN 1 ELSE 0 END), 0) as declined_guests
            FROM invitations i
            LEFT JOIN rsvp_responses r ON i.invitation_id = r.invitation_id
        ");

        $stmt->execute();
        $result = $stmt->get_result();
        $summary = $result->fetch_assoc();
        $stmt->close();

        return $summary;
    }
}

?>

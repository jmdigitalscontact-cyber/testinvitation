# Wedding RSVP System - API Documentation

Complete API reference for integrating with the Wedding RSVP system.

## Base URL

```
http://localhost/rsvp/api.php?action={action}
```

## Authentication

All guest-related endpoints require a valid authentication token obtained from the `verify-invitation` endpoint.

Admin endpoints require admin token (to be implemented).

## Response Format

All responses are in JSON format with the following structure:

### Success Response
```json
{
    "success": true,
    "message": "Operation successful",
    "data": {}
}
```

### Error Response
```json
{
    "success": false,
    "error": "Error message describing what went wrong"
}
```

---

## Guest API Endpoints

### 1. Verify Invitation

Authenticate a guest with their invitation ID and password.

**Endpoint:** `POST /api.php?action=verify-invitation`

**Request Body:**
```json
{
    "invitation_id": "INV-ABC123XYZ456",
    "password": "guest_password"
}
```

**Response (Success):**
```json
{
    "success": true,
    "message": "Authentication successful",
    "data": {
        "token": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6",
        "guest_name": "Smith Family",
        "max_guests": 3,
        "invitation_id": "INV-ABC123XYZ456"
    }
}
```

**Response (Error):**
```json
{
    "success": false,
    "error": "Invalid invitation ID or password"
}
```

**Status Codes:**
- `200` - Authentication successful
- `401` - Invalid credentials
- `400` - Missing required fields
- `429` - Too many login attempts (rate limited)

**Rate Limiting:** 5 failed attempts per 15 minutes


### 2. Get Invitation Details

Retrieve guest information and RSVP status for an authenticated session.

**Endpoint:** `GET /api.php?action=get-invitation-details&token={token}`

**Parameters:**
- `token` (required) - Authentication token from verify-invitation

**Response (Success):**
```json
{
    "success": true,
    "data": {
        "id": 1,
        "invitation_id": "INV-ABC123XYZ456",
        "guest_name": "Smith Family",
        "max_guests": 3,
        "status": "pending",
        "rsvp_status": "pending",
        "attendee_count": 0,
        "dietary_restrictions": "",
        "special_notes": ""
    }
}
```

**Response (Error):**
```json
{
    "success": false,
    "error": "Invalid or expired token"
}
```

**Status Codes:**
- `200` - Request successful
- `401` - Invalid or expired token
- `400` - Missing token parameter


### 3. Submit RSVP

Submit or update RSVP response.

**Endpoint:** `POST /api.php?action=submit-rsvp`

**Request Body:**
```json
{
    "token": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6",
    "attending": "yes",
    "attendee_count": 2,
    "attendees": [
        {
            "name": "John Smith",
            "dietary_restrictions": "Vegetarian"
        },
        {
            "name": "Jane Smith",
            "dietary_restrictions": "None"
        }
    ],
    "dietary_restrictions": "No shellfish",
    "special_notes": "Looking forward to the celebration!"
}
```

**Field Descriptions:**
- `token` (string, required) - Authentication token
- `attending` (enum, required) - Values: `yes`, `no`, `maybe`
- `attendee_count` (int, required) - Number of guests (ignored if attending=no)
- `attendees` (array, optional) - List of attendee objects with `name` and `dietary_restrictions`
- `dietary_restrictions` (string, optional) - General dietary notes
- `special_notes` (string, optional) - Any additional messages

**Response (Success):**
```json
{
    "success": true,
    "message": "RSVP submitted successfully",
    "rsvp_id": 1,
    "invitation_id": "INV-ABC123XYZ456"
}
```

**Response (Error - Exceeds Max Guests):**
```json
{
    "success": false,
    "error": "Maximum 3 guests allowed"
}
```

**Validation Rules:**
- If `attending` is `yes`, `attendee_count` must be ≤ `max_guests`
- If `attending` is `no`, `attendee_count` is set to 0
- At least one attendee name required if `attending=yes`
- Cannot exceed max_guests limit

**Status Codes:**
- `200` - RSVP submitted successfully
- `400` - Validation error
- `401` - Invalid or expired token


### 4. Get RSVP Status

Retrieve current RSVP response status.

**Endpoint:** `GET /api.php?action=get-rsvp-status&token={token}`

**Parameters:**
- `token` (required) - Authentication token

**Response (Success - Has RSVP):**
```json
{
    "success": true,
    "data": {
        "id": 1,
        "invitation_id": "INV-ABC123XYZ456",
        "attending": "yes",
        "attendee_count": 2,
        "attendees": [
            {
                "name": "John Smith",
                "dietary_restrictions": "Vegetarian"
            },
            {
                "name": "Jane Smith",
                "dietary_restrictions": "None"
            }
        ],
        "dietary_restrictions": "No shellfish",
        "special_notes": "Looking forward...",
        "submitted_at": "2026-03-22 14:30:00",
        "updated_at": "2026-03-22 14:35:00"
    }
}
```

**Response (Success - No RSVP Yet):**
```json
{
    "success": true,
    "data": null
}
```

**Status Codes:**
- `200` - Request successful
- `401` - Invalid or expired token

---

## Admin API Endpoints

### 1. Create Invitation

Create a new guest invitation.

**Endpoint:** `POST /api.php?action=create-invitation`

**Request Body:**
```json
{
    "guest_name": "Smith Family",
    "max_guests": 3,
    "password": "secure_password_here",
    "email": "smith@example.com"
}
```

**Field Descriptions:**
- `guest_name` (string, required) - Name of guest or family group
- `max_guests` (int, required) - Maximum number of guests allowed (1-10)
- `password` (string, required) - Secure password for invitation access
- `email` (string, optional) - Guest email address

**Response (Success):**
```json
{
    "success": true,
    "message": "Invitation created successfully",
    "data": {
        "invitation_id": "INV-ABC123XYZ456",
        "guest_name": "Smith Family",
        "max_guests": 3,
        "qr_code": {
            "success": true,
            "qr_image_path": "qr_codes/INV-ABC123XYZ456.png",
            "qr_url": "https://localhost/rsvp/index.php?invite=INV-ABC123XYZ456"
        }
    }
}
```

**Response (Error):**
```json
{
    "success": false,
    "error": "Missing required fields"
}
```

**Status Codes:**
- `200` - Invitation created
- `400` - Validation error
- `500` - Database error


### 2. Get All Invitations

Retrieve all invitations with their current status.

**Endpoint:** `GET /api.php?action=get-invitations`

**Response (Success):**
```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "invitation_id": "INV-ABC123XYZ456",
            "guest_name": "Smith Family",
            "max_guests": 3,
            "status": "responded",
            "rsvp_status": "yes",
            "confirmed_count": 2,
            "email": "smith@example.com",
            "created_at": "2026-03-22 10:00:00"
        },
        {
            "id": 2,
            "invitation_id": "INV-XYZ789ABC123",
            "guest_name": "Johnson Family",
            "max_guests": 2,
            "status": "pending",
            "rsvp_status": "pending",
            "confirmed_count": 0,
            "email": "johnson@example.com",
            "created_at": "2026-03-22 11:00:00"
        }
    ]
}
```

**Status Codes:**
- `200` - Request successful
- `500` - Database error


### 3. Generate QR Code

Get or regenerate QR code for an invitation.

**Endpoint:** `GET /api.php?action=generate-qr&invitation_id={invitation_id}`

**Parameters:**
- `invitation_id` (required) - Unique invitation identifier

**Response (Success):**
```json
{
    "success": true,
    "data": {
        "id": 1,
        "invitation_id": "INV-ABC123XYZ456",
        "qr_code_data": "https://localhost/rsvp/index.php?invite=INV-ABC123XYZ456",
        "qr_image_path": "qr_codes/INV-ABC123XYZ456.png",
        "created_at": "2026-03-22 10:00:00"
    }
}
```

**Response (Error - Not Found):**
```json
{
    "success": false,
    "error": "QR code not found"
}
```

**Status Codes:**
- `200` - QR code retrieved
- `404` - Invitation not found


### 4. Get RSVP Summary

Get statistics and summary of all RSVPs.

**Endpoint:** `GET /api.php?action=get-rsvp-summary`

**Response (Success):**
```json
{
    "success": true,
    "data": {
        "total_invitations": 10,
        "responded": 7,
        "declined": 2,
        "pending": 1,
        "total_slots": 25,
        "confirmed_guests": 18,
        "declined_guests": 5
    }
}
```

**Data Fields:**
- `total_invitations` - Total number of invitations created
- `responded` - Number of invitations with responses
- `declined` - Number of guests declining
- `pending` - Number of invitations not yet responded
- `total_slots` - Total available guest slots
- `confirmed_guests` - Total guests confirmed attending
- `declined_guests` - Total guests declining

**Status Codes:**
- `200` - Request successful


### 5. Export RSVP Data

Export all RSVP responses in a structured format.

**Endpoint:** `GET /api.php?action=export-rsvp`

**Response (Success):**
```json
{
    "success": true,
    "data": [
        {
            "guest_name": "Smith Family",
            "max_guests": 3,
            "attending": "yes",
            "attendee_count": 2,
            "dietary_restrictions": "No shellfish",
            "special_notes": "Looking forward...",
            "submitted_at": "2026-03-22 14:30:00"
        }
    ]
}
```

**Status Codes:**
- `200` - Export successful

---

## Error Handling

### Common Error Codes

| Status | Error | Meaning |
|--------|-------|---------|
| 400 | Missing required fields | Required parameters not provided |
| 401 | Invalid invitation ID or password | Authentication failed |
| 401 | Invalid or expired token | Session token is invalid or expired |
| 400 | Maximum X guests allowed | Attendee count exceeds limit |
| 429 | Too many login attempts | Rate limited - wait 15 minutes |
| 500 | Database error | Server-side database issue |

### Rate Limiting

- **Login Attempts:** 5 failed attempts per 15-minute window
- **Lockout Duration:** 1 hour after exceeding limit
- **Tracked By:** Invitation ID + IP Address

---

## Token Management

### Token Generation
- Generated upon successful authentication
- Unique per authenticated session
- 32-character hexadecimal string

### Token Expiration
- Expires after 1 hour of generation
- Automatically refreshed on successful request
- Must re-authenticate when expired

### Token Security
- Tokens should be stored securely on client
- Do not expose tokens in URLs (use POST body)
- Use HTTPS in production to prevent interception

---

## Data Validation Rules

### Invitation ID
- Format: `INV-` followed by 12 uppercase alphanumeric characters
- Example: `INV-ABC123XYZ456`
- Unique across system

### Password
- Minimum length: 6 characters
- No restrictions on special characters
- Case-sensitive

### Guest Count
- Minimum: 1
- Maximum: 10
- Must be positive integer

### Attending Status
- Valid values: `yes`, `no`, `maybe`
- Case-insensitive in requests

---

## Integration Examples

### JavaScript/jQuery

```javascript
// Step 1: Authenticate
const verifyResponse = await fetch('api.php?action=verify-invitation', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        invitation_id: 'INV-ABC123XYZ456',
        password: 'password123'
    })
});

const authData = await verifyResponse.json();
const token = authData.data.token;

// Step 2: Submit RSVP
const rsvpResponse = await fetch('api.php?action=submit-rsvp', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        token: token,
        attending: 'yes',
        attendee_count: 2,
        attendees: [
            { name: 'John Doe', dietary_restrictions: 'None' }
        ]
    })
});

const result = await rsvpResponse.json();
console.log(result);
```

### cURL

```bash
# Step 1: Authenticate
curl -X POST http://localhost/rsvp/api.php?action=verify-invitation \
  -H "Content-Type: application/json" \
  -d '{
    "invitation_id": "INV-ABC123XYZ456",
    "password": "password123"
  }'

# Step 2: Submit RSVP
curl -X POST http://localhost/rsvp/api.php?action=submit-rsvp \
  -H "Content-Type: application/json" \
  -d '{
    "token": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6",
    "attending": "yes",
    "attendee_count": 2
  }'
```

### PHP

```php
<?php
// Verify invitation
$ch = curl_init('http://localhost/rsvp/api.php?action=verify-invitation');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'invitation_id' => 'INV-ABC123XYZ456',
    'password' => 'password123'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
$data = json_decode($response, true);
$token = $data['data']['token'];

// Submit RSVP
curl_setopt($ch, CURLOPT_URL, 'http://localhost/rsvp/api.php?action=submit-rsvp');
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'token' => $token,
    'attending' => 'yes',
    'attendee_count' => 2
]));

$rsvpResponse = curl_exec($ch);
curl_close($ch);

echo $rsvpResponse;
?>
```

---

## Rate Limiting Headers

Response headers include rate limit information:

```
X-RateLimit-Limit: 5
X-RateLimit-Remaining: 2
X-RateLimit-Reset: 1647961200
```

---

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.0 | 2026-03-22 | Initial release |

---

## Support & Troubleshooting

For API issues:
1. Verify token has not expired
2. Check invitation ID format
3. Ensure request body is valid JSON
4. Review rate limiting status
5. Check server logs for detailed errors

---

**Last Updated:** March 22, 2026  
**API Version:** 1.0

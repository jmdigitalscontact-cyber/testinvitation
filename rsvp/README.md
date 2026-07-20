# Wedding RSVP System - Complete Implementation Guide

A secure, modern RSVP management system for wedding invitations with QR codes, unique passwords, guest count tracking, and an admin dashboard.

## 📋 Table of Contents

1. [Features](#features)
2. [System Architecture](#system-architecture)
3. [Database Schema](#database-schema)
4. [Setup Instructions](#setup-instructions)
5. [API Endpoints](#api-endpoints)
6. [Frontend Flow](#frontend-flow)
7. [Security Features](#security-features)
8. [Configuration](#configuration)
9. [Troubleshooting](#troubleshooting)

---

## ✨ Features

### Guest Features
- **Unique QR Codes**: Each invitation has a unique, scannable QR code
- **Secure Authentication**: Password-protected access to RSVP details
- **Guest Tracking**: Set maximum number of guests per invitation
- **RSVP Management**: Submit, update, or view RSVP status
- **Additional Information**: Collect dietary restrictions and special messages
- **Responsive Design**: Works on mobile, tablet, and desktop

### Admin Features
- **Bulk Invitation Creation**: Generate multiple invitations at once
- **QR Code Display**: View and download QR codes for printing
- **Real-time Dashboard**: See RSVP statistics and response rates
- **Guest Management**: View all guest details and responses
- **Data Export**: Export responses to CSV for analysis
- **Response Tracking**: Monitor confirmed, declined, and pending RSVPs

---

## 🏗️ System Architecture

```
Wedding RSVP System
├── Frontend
│   ├── index.php (Guest RSVP Page)
│   └── admin.php (Admin Dashboard)
├── Backend
│   ├── api.php (API Router)
│   ├── Database.php (Database Connection)
│   ├── Authentication.php (Auth Logic)
│   ├── RSVPHandler.php (RSVP Operations)
│   └── QRCodeGenerator.php (QR Generation)
├── Database
│   ├── invitations
│   ├── rsvp_responses
│   ├── attendees
│   ├── login_attempts
│   └── qr_codes
└── Assets
    └── qr_codes/ (Generated QR images)
```

---

## 🗄️ Database Schema

### invitations Table
```sql
- id: INT (Primary Key)
- invitation_id: VARCHAR(50) (Unique)
- guest_name: VARCHAR(255)
- password_hash: VARCHAR(255)
- max_guests: INT
- status: ENUM (pending, responded, declined)
- created_at: TIMESTAMP
- updated_at: TIMESTAMP
- email: VARCHAR(255)
- phone: VARCHAR(20)
- notes: TEXT
```

### rsvp_responses Table
```sql
- id: INT (Primary Key)
- invitation_id: VARCHAR(50) (Foreign Key)
- attending: ENUM (yes, no, maybe)
- attendee_count: INT
- attendees: JSON
- dietary_restrictions: TEXT
- special_notes: TEXT
- submitted_at: TIMESTAMP
- updated_at: TIMESTAMP
```

### attendees Table
```sql
- id: INT (Primary Key)
- rsvp_response_id: INT (Foreign Key)
- invitation_id: VARCHAR(50) (Foreign Key)
- attendee_name: VARCHAR(255)
- dietary_restrictions: VARCHAR(255)
- special_notes: TEXT
- created_at: TIMESTAMP
```

### login_attempts Table
```sql
- id: INT (Primary Key)
- invitation_id: VARCHAR(50)
- ip_address: VARCHAR(45)
- attempt_time: TIMESTAMP
- success: BOOLEAN
```

### qr_codes Table
```sql
- id: INT (Primary Key)
- invitation_id: VARCHAR(50) (Unique Foreign Key)
- qr_code_data: VARCHAR(500)
- qr_image_path: VARCHAR(255)
- created_at: TIMESTAMP
```

---

## 🚀 Setup Instructions

### Prerequisites
- PHP 7.4+
- MySQL 5.7+
- Apache/Nginx web server
- XAMPP or Local Environment

### Step 1: Create Database

Use phpMyAdmin or MySQL command line:

```bash
# Create database
CREATE DATABASE wedding_rsvp;
USE wedding_rsvp;

# Import the schema from database-schema.sql
```

Or copy the SQL from `database-schema.sql` and execute in phpMyAdmin.

### Step 2: Configure Database Connection

Edit `config.php`:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');        // Your MySQL username
define('DB_PASS', '');             // Your MySQL password
define('DB_NAME', 'wedding_rsvp');
```

### Step 3: Create QR Codes Directory

```bash
mkdir -p /path/to/rsvp/qr_codes
chmod 755 /path/to/rsvp/qr_codes
```

### Step 4: Verify Installation

1. Navigate to: `http://localhost/rsvp/index.php`
2. Navigate to: `http://localhost/rsvp/admin.php`

---

## 📡 API Endpoints

### Guest Endpoints

#### 1. Verify Invitation
**POST** `/api.php?action=verify-invitation`

Request:
```json
{
    "invitation_id": "INV-ABC123XYZ456",
    "password": "guest_password"
}
```

Response:
```json
{
    "success": true,
    "message": "Authentication successful",
    "data": {
        "token": "hex_token_string",
        "guest_name": "Smith Family",
        "max_guests": 3,
        "invitation_id": "INV-ABC123XYZ456"
    }
}
```

#### 2. Get Invitation Details
**GET** `/api.php?action=get-invitation-details&token={token}`

Response:
```json
{
    "success": true,
    "data": {
        "invitation_id": "INV-ABC123XYZ456",
        "guest_name": "Smith Family",
        "max_guests": 3,
        "status": "pending",
        "rsvp_status": "pending",
        "attendee_count": 0
    }
}
```

#### 3. Submit RSVP
**POST** `/api.php?action=submit-rsvp`

Request:
```json
{
    "token": "hex_token_string",
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

Response:
```json
{
    "success": true,
    "message": "RSVP submitted successfully",
    "rsvp_id": 1,
    "invitation_id": "INV-ABC123XYZ456"
}
```

#### 4. Get RSVP Status
**GET** `/api.php?action=get-rsvp-status&token={token}`

Response:
```json
{
    "success": true,
    "data": {
        "id": 1,
        "invitation_id": "INV-ABC123XYZ456",
        "attending": "yes",
        "attendee_count": 2,
        "attendees": [...],
        "dietary_restrictions": "No shellfish",
        "special_notes": "Looking forward...",
        "submitted_at": "2026-03-22 14:30:00"
    }
}
```

### Admin Endpoints

#### 1. Create Invitation
**POST** `/api.php?action=create-invitation`

Request:
```json
{
    "guest_name": "Smith Family",
    "max_guests": 3,
    "password": "secure_password",
    "email": "smith@example.com"
}
```

Response:
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
            "invitation_id": "INV-ABC123XYZ456",
            "qr_image_path": "qr_codes/INV-ABC123XYZ456.png"
        }
    }
}
```

#### 2. Get All Invitations
**GET** `/api.php?action=get-invitations`

Response:
```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "invitation_id": "INV-ABC123XYZ456",
            "guest_name": "Smith Family",
            "max_guests": 3,
            "status": "pending",
            "rsvp_status": "pending",
            "confirmed_count": 0
        }
    ]
}
```

#### 3. Get RSVP Summary
**GET** `/api.php?action=get-rsvp-summary`

Response:
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

#### 4. Export RSVP Data
**GET** `/api.php?action=export-rsvp`

Response: CSV data format

---

## 🎯 Frontend Flow

### Guest RSVP Flow

```
┌─────────────────────────┐
│  Guest Scans QR Code    │
│  (or visits with link)  │
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────┐
│ Authentication Screen   │
│ - Enter Invitation ID   │
│ - Enter Password        │
└────────────┬────────────┘
             │
        ┌────┴────┐
        │          │
        ▼          ▼
   Success     Failed
        │          │
        ▼          │
┌────────────────┐ │
│ RSVP Form      │ │
│ - Select Att.  │ │
│ - Add Guests   │ │
│ - Diet. Notes  │ │
│ - Messages     │ │
└────────┬───────┘ │
         │         │
         ▼         │
┌────────────────┐ │
│ Confirmation   │ │
│ - Show Summary │ │
└────────────────┘ │
                   │
              (retry)
```

### Admin Dashboard Flow

```
Dashboard (Stats) ──→ Invitations ──→ Create New ──→ Generate QR
                   └─→ Responses ──→ View Details
                   └─→ Export ──→ Download CSV
```

---

## 🔐 Security Features

### Authentication
1. **Unique Passwords**: Each invitation has a unique, hashed password
2. **Password Hashing**: Uses PHP's `password_hash()` with BCRYPT
3. **Token-Based Sessions**: Generates unique tokens for authenticated sessions
4. **Token Expiry**: Sessions expire after 1 hour

### Brute Force Protection
1. **Rate Limiting**: Maximum 5 login attempts per 15-minute window
2. **IP Tracking**: Logs IP addresses for monitoring
3. **Automatic Lockout**: Account locked for 1 hour after max attempts
4. **Attempt Logging**: All login attempts are recorded

### Data Protection
1. **Prepared Statements**: Prevents SQL injection
2. **Input Sanitization**: All inputs are sanitized and escaped
3. **HTTPS Recommended**: Use SSL/TLS certificates in production
4. **CORS Headers**: Restricts cross-origin requests

### QR Code Security
1. **ID Only Encoding**: QR code contains only invitation ID (not password)
2. **Unique URLs**: Each QR code links to unique invitation page
3. **Password Required**: Full access blocked without password

---

## ⚙️ Configuration

### Rate Limiting Settings (config.php)
```php
define('MAX_LOGIN_ATTEMPTS', 5);        // Max failed attempts
define('LOGIN_ATTEMPT_WINDOW', 900);    // 15 minutes
define('LOCKOUT_DURATION', 3600);       // 1 hour lockout
```

### Session Timeout (Authentication.php)
```php
$expiry = time() + 3600; // 1 hour session timeout
```

### QR Code Settings
- Size: 300x300 pixels
- Format: PNG
- Storage: `/rsvp/qr_codes/`

---

## 🐛 Troubleshooting

### Database Connection Issues

**Problem**: "Database Connection Failed"

**Solution**:
1. Check MySQL is running
2. Verify credentials in `config.php`
3. Ensure `wedding_rsvp` database exists
4. Check user permissions

### QR Code Not Generating

**Problem**: QR codes not appearing

**Solution**:
1. Create `/qr_codes` directory
2. Set proper permissions: `chmod 755 qr_codes`
3. Check internet connection (API uses QR server)
4. Verify API URL is accessible

### Rate Limiting Issues

**Problem**: Account locked during testing

**Solution**:
1. Wait 1 hour, or
2. Manually delete from `login_attempts` table:
```sql
DELETE FROM login_attempts 
WHERE invitation_id = 'INV-ABC123XYZ456';
```

### Password Mismatch

**Problem**: Password verification fails

**Solution**:
1. Ensure password is entered correctly
2. Check for leading/trailing spaces
3. Verify database password hash is valid
4. Check password encoding (UTF-8)

### CORS Issues

**Problem**: Cross-origin requests blocked

**Solution**:
1. Check CORS headers in `config.php`
2. Ensure frontend and backend are on same origin
3. Or modify CORS settings for production

---

## 📊 Sample Data

### Create Test Invitations

Use admin panel or API:

```json
{
    "guest_name": "Smith Family",
    "max_guests": 3,
    "password": "smith2026",
    "email": "smith@example.com"
}
```

### Testing RSVP Submission

1. Visit: `http://localhost/rsvp/index.php`
2. Enter invitation ID and password
3. Select attendance option
4. Add attendee names
5. Submit

---

## 📝 License

This RSVP system is provided as-is for wedding planning purposes.

---

## 🤝 Support

For issues or questions:
1. Check the troubleshooting section
2. Review error logs
3. Verify database configuration
4. Ensure all files are in correct directories

---

## 🔄 Future Enhancements

- Email notifications for guests
- Admin authentication dashboard
- Guest messaging system
- Advanced analytics
- Mobile app
- Payment integration for events with fees
- Dietary restrictions pre-set menu
- Table assignments
- Photo gallery integration

---

**Version**: 1.0  
**Last Updated**: March 22, 2026

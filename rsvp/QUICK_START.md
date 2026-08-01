# Wedding RSVP System - Quick Start Guide

## Getting started (Default: MySQL/MariaDB + phpMyAdmin)

### Step 1: Create Database

**Using phpMyAdmin (GoDaddy / shared hosting):**
1. Log into your hosting control panel
2. Open phpMyAdmin
3. Click **New** → create database `wedding_rsvp` (utf8mb4_general_ci)

**Or using command line:**
```bash
mysql -u root -p -e "CREATE DATABASE wedding_rsvp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### Step 2: Import Schema

**Using phpMyAdmin:** Select database → **Import** → choose `database-schema-mysql.sql` → **Go**

**Or using command line:**
```bash
mysql -u root -p wedding_rsvp < rsvp/database-schema-mysql.sql
mysql -u root -p wedding_rsvp < rsvp/database-schema-additional-mysql.sql
mysql -u root -p wedding_rsvp < rsvp/database-table-assignments-mysql.sql
mysql -u root -p wedding_rsvp < rsvp/database-reception-photos-mysql.sql
```

### Step 3: Configure

Create `.env` in project root:
```ini
DB_HOST=localhost
DB_PORT=3306
DB_NAME=wedding_rsvp
DB_USER=root
DB_PASS=your_password
DB_ENGINE=mysql
PUBLIC_BASE_URL=http://localhost:3000
```

### Step 4: Create Admin

```bash
php rsvp/create-admin.php
```

The script now prompts for a username and a strong password (at least 8 characters). Alternatively, pass them as arguments:

```bash
php rsvp/create-admin.php admin YourStrongPassword
```

> **Important:** Never use the old default `admin` / `password`. Always use a strong, unique password.

### Step 5: Start Dev Server

```powershell
.\start-dev.ps1
```
Or: `npm run dev`

### Step 6: Verify

1. Visit: http://localhost:3000/rsvp/setup.php — all checks green
2. **Admin:** http://localhost:3000/rsvp/admin.php (`admin` / your chosen password)
3. **Guests:** http://localhost:3000/rsvp.html or http://localhost:3000/rsvp/index.php

---

## Step 7: Email Invitations (optional but recommended)

1. In `.env`, set your sender address:

```ini
MAIL_FROM=noreply@yourdomain.com
MAIL_FROM_NAME=Jason & Rhona Mae
```

2. On most shared hosts (GoDaddy, etc.) PHP's built-in `mail()` works without SMTP.
3. For Gmail/SendGrid/Mailgun SMTP, add:

```ini
MAIL_USE_SMTP=true
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=your_user
MAIL_PASSWORD=your_password
MAIL_ENCRYPTION=tls
```

4. In the **Admin → Invitations** tab, use the **Send** button on any invitation that has an email address. Or check **"Send invitation email right away"** when creating an invitation.

---

## Step 8: Google Sheets Export (optional)

To push RSVP data directly to a shared Google Sheet (so the couple can view it):

1. Go to the [Google Cloud Console](https://console.cloud.google.com) and create a project (or reuse one).
2. Enable the **Google Sheets API**.
3. Create a **Service Account** → download the JSON key file.
4. Create a Google Sheet, click **Share**, and grant **Editor** access to the service account email (found in the JSON: `client_email`).
5. Copy the spreadsheet ID from the sheet URL: `https://docs.google.com/spreadsheets/d/<THIS_PART>/edit`
6. Set in `.env`:

```ini
GOOGLE_SHEETS_ID=your_spreadsheet_id
GOOGLE_SHEETS_CREDENTIALS_PATH=/full/path/to/service-account.json
```

7. In **Admin → Export**, use **Export invitations** / **Export responses** to push data to the sheet. Use **Open sheet** to jump straight to it.


---

## PostgreSQL (legacy)

If you need PostgreSQL instead, set `DB_ENGINE=pgsql` in `.env` and see [POSTGRES_SETUP.md](POSTGRES_SETUP.md).

---

## File structure

```
Invitation/
├── start-dev.ps1          # Dev server on :3000
├── .env                   # DB_* credentials (not in git)
└── rsvp/
    ├── index.php          # Guest RSVP page
    ├── admin.php          # Admin dashboard
    ├── setup.php          # Installation wizard
    ├── api.php            # API endpoints
    ├── apply-schema.php   # Run SQL migrations
    ├── database-schema-mysql.sql      # MySQL schema (primary)
    ├── database-schema.sql            # PostgreSQL schema (legacy)
    └── MYSQL_SETUP.md    # Detailed MySQL / GoDaddy guide
```

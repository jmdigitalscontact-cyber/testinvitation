# MySQL/MariaDB Setup Guide (GoDaddy phpMyAdmin)

This guide is for deploying the Wedding RSVP system on **GoDaddy shared hosting** (or any host with MySQL/MariaDB + phpMyAdmin).

## Stack

| Piece | Details |
|-------|---------|
| App | PHP 7.4+ with `mysqli` extension |
| Database | MySQL 5.7+ or MariaDB 10.2+ |
| DB Admin | **phpMyAdmin** (included with GoDaddy hosting) |

---

## Step 1: Create Database in phpMyAdmin

1. Log into your **GoDaddy account** → **My Products** → **Hosting** → **Manage**
2. Click **phpMyAdmin** (in the Databases section)
3. In phpMyAdmin, click **New** in the left sidebar
4. Enter a database name (e.g., `wedding_rsvp`)
5. Choose **utf8mb4_general_ci** as the collation
6. Click **Create**

> **Note down the database name, username, and password** — you'll need them in Step 2.

---

## Step 2: Import Database Schema

### Option A: Import via phpMyAdmin (Recommended)

1. In phpMyAdmin, select your database from the left sidebar
2. Click the **Import** tab at the top
3. Click **Choose File** and select the `database-schema-mysql.sql` file from this project
4. Click **Go** to import

### Option B: Import via Command Line (if you have SSH)

```bash
mysql -u YOUR_USERNAME -p YOUR_DATABASE_NAME < rsvp/database-schema-mysql.sql
mysql -u YOUR_USERNAME -p YOUR_DATABASE_NAME < rsvp/database-schema-additional-mysql.sql
mysql -u YOUR_USERNAME -p YOUR_DATABASE_NAME < rsvp/database-table-assignments-mysql.sql
mysql -u YOUR_USERNAME -p YOUR_DATABASE_NAME < rsvp/database-reception-photos-mysql.sql
```

---

## Step 3: Configure Database Connection

Create a `.env` file in the project root (one level above `rsvp/`) with the following:

```ini
# MySQL Database Configuration
DB_HOST=localhost
DB_PORT=3306
DB_NAME=wedding_rsvp
DB_USER=your_database_username
DB_PASS=your_database_password
DB_ENGINE=mysql

# Public URL where guests will access the site
PUBLIC_BASE_URL=https://yourdomain.com
```

> **GoDaddy tip:** The database host is usually `localhost` for shared hosting.
> Your database username and password are the ones you created in the GoDaddy Database section.

---

## Step 4: Create Admin User

After importing the schema, run the admin creation script:

```bash
php rsvp/create-admin.php
```

The script now prompts for a username and a strong password (at least 8 characters). You can also pass them as arguments:

```bash
php rsvp/create-admin.php admin YourStrongPassword
```

> **Important:** Never use the default `admin` / `password`. Always use a strong, unique password, and store it securely.

---

## Step 5: Upload Files to GoDaddy

1. Upload all project files to your GoDaddy hosting via FTP or cPanel File Manager
2. Ensure the `rsvp/qr_codes/` directory is writable (set permissions to 755 or 775)
3. Navigate to `https://yourdomain.com/rsvp/setup.php` to verify everything is working

---

## Step 6: Email Invitations (optional but recommended)

1. Set your sender address in `.env`:

```ini
MAIL_FROM=noreply@yourdomain.com
MAIL_FROM_NAME=Jason & Rhona Mae
```

2. GoDaddy shared hosting supports PHP's built-in `mail()` — no SMTP needed for basic sending.
3. For SMTP (Gmail/SendGrid/Mailgun):

```ini
MAIL_USE_SMTP=true
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=your_user
MAIL_PASSWORD=your_password
MAIL_ENCRYPTION=tls
```

4. In **Admin → Invitations**, add an email to an invitation, then click **Send**. Or check **"Send invitation email right away"** when creating an invitation.

---

## Step 7: Google Sheets Export (optional)

To let the couple view the RSVP guest count directly in a Google Sheet:

1. Go to [Google Cloud Console](https://console.cloud.google.com) → create a project
2. Enable the **Google Sheets API**
3. Create a **Service Account** → download the JSON key file
4. Create a Google Sheet → **Share** → grant **Editor** to the service account `client_email`
5. Copy the spreadsheet ID from the URL: `https://docs.google.com/spreadsheets/d/<THIS_PART>/edit`
6. Set in `.env`:

```ini
GOOGLE_SHEETS_ID=your_spreadsheet_id
GOOGLE_SHEETS_CREDENTIALS_PATH=/path/to/service-account.json
```

7. In **Admin → Export**, click **Export invitations** / **Export responses** to push data to the sheet.



---

## Accessing the Application

| Page | URL |
|------|-----|
| RSVP Setup Check | `https://yourdomain.com/rsvp/setup.php` |
| Admin Panel | `https://yourdomain.com/rsvp/admin.php` |
| Guest RSVP | `https://yourdomain.com/rsvp/index.php` |

---

## Troubleshooting on GoDaddy

### Problem: "Database Connection Failed"

**Solutions:**
1. Verify your database credentials in `.env` are correct
2. Make sure the database name matches exactly what you created in phpMyAdmin
3. Some GoDaddy plans use a prefix for database names (e.g., `youruser_wedding_rsvp`)
4. Try using `127.0.0.1` instead of `localhost` as the host

### Problem: "Table doesn't exist"

**Solutions:**
1. Re-import the SQL schema files in phpMyAdmin
2. Ensure you selected the correct database before importing

### Problem: "500 Internal Server Error"

**Solutions:**
1. Check if your PHP version supports `mysqli` (GoDaddy usually does)
2. Check error logs in cPanel → Error Logs
3. Make sure file permissions are correct (644 for files, 755 for directories)

---

## phpMyAdmin Quick Reference

| Task | How to do it |
|------|--------------|
| View tables | Select your database → see list on left |
| Run SQL query | Click **SQL** tab in the top menu |
| Export database | Click **Export** tab → **Quick** → **Go** |
| Import SQL file | Click **Import** tab → choose file → **Go** |
| Browse table data | Click table name → **Browse** tab |
| Edit table structure | Click table name → **Structure** tab |
| Add/edit/delete rows | Click table name → **Browse** → edit icons |

---

## Migration from PostgreSQL

If you previously used PostgreSQL for local development, you can switch to MySQL by:

1. Creating the database in phpMyAdmin
2. Importing the MySQL schema files (Step 2 above)
3. Setting `DB_ENGINE=mysql` in `.env`
4. Updating database credentials to your MySQL ones

No PHP code changes are needed — the system detects the engine automatically.

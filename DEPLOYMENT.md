# 🚀 Deployment Guide — From GitHub to Live Hosting

This guide walks you through deploying this project from a GitHub repository to a
PHP/MySQL web host (GoDaddy shared hosting, cPanel, HostGator, etc.).

> **TL;DR** — GitHub stores the *code*. Your hosting server holds the *files + secrets*.
> You push to GitHub, then get those files onto the server, create a database,
> create a `.env` with the live credentials, and you're live.

---

## What lives where

| Thing | Where it lives | In Git? |
|-------|---------------|---------|
| Source code (PHP, HTML, CSS, JS, SQL) | GitHub repo | ✅ Yes |
| `.env` (DB password, mail keys, encryption key) | **Only on the server** | ❌ No (ignored) |
| Database (invitations, RSVPs, tables) | Hosting MySQL server | ❌ No (runtime data) |
| QR code images | `rsvp/qr_codes/` on server | ❌ No (regenerated) |
| Reception photo uploads | `reception/uploads/` on server | ❌ No (runtime data) |
| Google Sheets credentials | `credentials/*.json` on server | ❌ No (ignored) |

The `.gitignore` and `.htaccess` files are already configured so that **secrets and
runtime files can never leak** through GitHub or the public web.

---

## The 3 deployment paths

Pick whichever matches what your host supports. **Path A is the most common.**

### Path A — cPanel File Manager / FTP (simplest, works everywhere)

1. **On GitHub:** open your repo → green **Code** button → **Download ZIP**.
2. **On your host:** log into cPanel → **File Manager** → open `public_html`
   (this is your website root — where `index.html` will be served from).
3. **Upload** the ZIP and **Extract** it there. You should now see:
   ```
   public_html/
   ├── index.html          ← wedding site home
   ├── rsvp/               ← RSVP + admin backend
   ├── reception/          ← reception venue app
   ├── css/  js/  images/  ← static assets
   └── .htaccess           ← security rules
   ```
4. Delete the ZIP file after extraction.

### Path B — cPanel "Git Version Control"

1. In cPanel find **Git Version Control** → **Create**.
2. Repository path: `public_html`
3. Clone URL: `https://github.com/YOUR_USER/YOUR_REPO.git`
4. After it clones, click **Manage** → **Update from Remote** whenever you push new commits.

### Path C — SSH + git (requires shell access)

```bash
cd public_html
git clone https://github.com/YOUR_USER/YOUR_REPO.git .
# On every update:
git pull origin main
```

---

## Step-by-step live deployment

### 1. Create the database (hosting panel)

1. Go to your host's **MySQL Databases** (GoDaddy: *Databases → MySQL*; cPanel: *MySQL® Databases*).
2. Create a database, e.g. `wedding_rsvp`.
3. Create a database user + strong password, and **grant all privileges** to the database.
4. Note the **database name, username, host (usually `localhost`), and password**.

### 2. Import the schema

**Via phpMyAdmin** (recommended):
1. Open **phpMyAdmin** → select your new database.
2. **Import** tab → choose the SQL files from the repo, in this order:
   1. `rsvp/database-schema-mysql.sql`
   2. `rsvp/database-schema-additional-mysql.sql`
   3. `rsvp/database-table-assignments-mysql.sql`
   4. `rsvp/database-reception-photos-mysql.sql`
3. Click **Go** after each file.

**Via SSH:**
```bash
mysql -u USER -p DBNAME < rsvp/database-schema-mysql.sql
mysql -u USER -p DBNAME < rsvp/database-schema-additional-mysql.sql
mysql -u USER -p DBNAME < rsvp/database-table-assignments-mysql.sql
mysql -u USER -p DBNAME < rsvp/database-reception-photos-mysql.sql
```

### 3. Create the `.env` file (CRITICAL — on the server, not in git)

Create a file named `.env` at the **project root** (one level above `rsvp/`,
same folder as `index.html`). Use the hosting **File Manager → New File** or
FTP. Base it on `rsvp/.env.example`:

```ini
# ── Database ──────────────────────────────────────────────
DB_HOST=localhost
DB_PORT=3306
DB_NAME=wedding_rsvp
DB_USER=your_db_user
DB_PASS=your_db_password
DB_ENGINE=mysql

# ── Live site URL (NO trailing slash) ─────────────────────
# This is what QR codes and invitation emails point to.
PUBLIC_BASE_URL=https://www.yourdomain.com

# ── Environment ───────────────────────────────────────────
# "production" enforces HTTPS redirect + HSTS
ENVIRONMENT=production

# ── Email invitations (optional but recommended) ──────────
MAIL_FROM=noreply@yourdomain.com
MAIL_FROM_NAME=Jason & Rhona Mae
# For SMTP (Gmail/SendGrid/Mailgun):
MAIL_USE_SMTP=false
# MAIL_HOST=smtp.example.com
# MAIL_PORT=587
# MAIL_USERNAME=your_user
# MAIL_PASSWORD=your_password
# MAIL_ENCRYPTION=tls

# ── Encryption (strongly recommended) ─────────────────────
# Generate one with:  php -r "echo bin2hex(random_bytes(32));"
ENCRYPTION_KEY=your-64-char-hex-key

# ── Google Sheets export (optional) ───────────────────────
# GOOGLE_SHEETS_ID=your_spreadsheet_id
# GOOGLE_SHEETS_CREDENTIALS_PATH=/home/user/public_html/credentials/google-sheets.json

# ── Reception venue (optional) ────────────────────────────
# RECEPTION_API_KEY=choose-a-long-random-secret-key

# ── Security toggles (leave OFF for production) ───────────
ENABLE_SETUP=false
ENABLE_BULK_IMPORT=false
ENABLE_DEV_ADMIN_BYPASS=false
ENABLE_DEV_GUEST_BYPASS=false
```

> ⚠️ `.env` is ignored by Git (see `.gitignore`). It must be created directly on
> the server — it will never be in the GitHub repo. The `.htaccess` rules also
> block web access to it.

### 4. Set folder permissions

Ensure these directories are **writable** by PHP (755 or 775):

```
rsvp/qr_codes/        ← QR PNGs are generated here
reception/uploads/    ← guest photos are stored here
logs/                 ← audit / rate-limit logs
```

In cPanel File Manager: right-click folder → **Change Permissions**.

### 5. Create the admin user

Run via SSH if available, or use your host's **Terminal** feature:

```bash
php rsvp/create-admin.php
```

It will prompt for a username and a password (min 8 chars).
If you don't have SSH, you can temporarily run `rsvp/setup.php` after enabling it
(see next step) — but the recommended path is the CLI script.

### 6. Verify with the setup wizard (then disable it!)

1. Temporarily set `ENABLE_SETUP=true` in `.env`.
2. Visit `https://www.yourdomain.com/rsvp/setup.php`.
3. Confirm all checks are green (Database, Directories, Files, Email, Sheets).
4. **Immediately set `ENABLE_SETUP=false`** and save. The wizard is now locked.

### 7. Final checks before going live

- [ ] `.env` has `ENVIRONMENT=production`
- [ ] `.env` has the real `PUBLIC_BASE_URL` (https, no trailing slash)
- [ ] `ENABLE_SETUP=false`, `ENABLE_BULK_IMPORT=false`
- [ ] SSL certificate installed (let's encrypt / host-provided)
- [ ] Visit `https://www.yourdomain.com/rsvp/admin.php` → sign in
- [ ] Create a test invitation → verify QR code + email send
- [ ] Scan the QR with your phone → complete an RSVP → check the dashboard
- [ ] Delete any test data in `rsvp/qr_codes/` and `reception/uploads/`

---

## Updating the live site after changes

**Path A (FTP/ZIP):**
1. Download the new ZIP from GitHub.
2. Upload + extract, overwriting the files.
3. **Do NOT** overwrite `.env`, `rsvp/qr_codes/`, or `reception/uploads/` (keep the server versions).

**Path B/C (Git):**
```bash
git pull origin main
```
The `.env` and runtime folders stay untouched because they're not tracked.

---

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| `Database Connection Failed` | Check `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` in `.env`. GoDaddy sometimes needs `127.0.0.1` instead of `localhost`. |
| `Table doesn't exist` | Re-run the 4 SQL schema imports into the correct database. |
| `403 Setup wizard is disabled` | Expected! Set `ENABLE_SETUP=true` only while verifying, then disable. |
| Redirect loop on HTTPS | Your SSL/proxy may set `X-Forwarded-Proto`; the app already respects it. Ensure SSL is active in cPanel. |
| QR codes show old URL | QR links are stored in the DB. Use **Admin → View QR** — the code regenerates them against the new `PUBLIC_BASE_URL`. |
| Email not sending | Check `MAIL_FROM` and server `mail()` support, or configure `MAIL_USE_SMTP=true` with real SMTP settings. |
| Blank page / 500 error | Check `logs/` and the host error log. Common cause: PHP version < 7.4 or missing `mysqli` extension. |

---

## Quick reference — live URLs

| Page | URL |
|------|-----|
| Wedding site | `https://www.yourdomain.com/` |
| Admin dashboard | `https://www.yourdomain.com/rsvp/admin.php` |
| Guest RSVP (QR) | `https://www.yourdomain.com/index.html?invite=INV-XXXX` |
| Reception venue | `https://www.yourdomain.com/reception/` |


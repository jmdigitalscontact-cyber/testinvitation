# 🚀 Go-Live Deployment Guide — GoDaddy

This guide gives you **two ways** to deploy. Pick whichever you're comfortable with:

| Option | What it is | Best if... |
|--------|-----------|------------|
| **Option A — Manual upload** | Upload files with FileZilla / File Manager | You don't use GitHub |
| **Option B — GitHub Actions** | Connect GitHub → GoDaddy, auto-deploy on every push | You want automatic deploys |

Both options use the **same single-file database import** (`rsvp/database-full-mysql.sql`).

---

# OPTION A — Manual Upload (no GitHub needed)

## What you'll need

- GoDaddy cPanel login (or Plesk) for your hosting plan
- An FTP program (e.g. FileZilla) OR GoDaddy's built-in **File Manager**
- The folder you have this project in (the one with `index.html`, `rsvp/`, `reception/`, `css/`, `js/`, `images/`, `audio/`)

---

## Step 1 — Upload the files

1. Log in to your GoDaddy account → **Hosting** → your plan → **Manage**.
2. Open **File Manager** (or use FileZilla with the FTP credentials from cPanel).
3. Go to your website's document root. This is usually:
   - **`public_html`** (most common)
   - or `httpdocs` / `html` (depends on plan)
4. **Upload the entire contents** of your project folder **into** `public_html`.
   - You should end up with `public_html/index.html`, `public_html/rsvp/`, `public_html/reception/`, etc.
   - ⚠️ Do **not** upload a nested folder like `public_html/testInvitation/`. The files should be directly in the root.

Your `public_html` should look like this:

```
public_html/
├── index.html          ← wedding website home
├── rsvp/               ← RSVP + admin system
├── reception/          ← reception photo wall
├── css/
├── js/
├── images/
├── audio/
├── sw.js
└── .htaccess
```

> 💡 If you use FileZilla, just select all files/folders locally and drag them into `public_html`.

---

## Step 2 — Create the database in GoDaddy

1. In cPanel, scroll to **Databases** → **MySQL® Databases**.
2. Create a new database, e.g. `youruser_weddingrsvp`.
3. Create a database **user**, e.g. `youruser_wedding`.
   - Set a **strong password** (write it down).
4. **Add the user to the database** and grant **ALL PRIVILEGES**.

Write down these four values — you'll need them in Step 4:

| Setting | Example |
|---------|---------|
| DB name | `youruser_weddingrsvp` |
| DB user | `youruser_wedding` |
| DB password | the one you chose |
| DB host | usually `localhost` |

---

## Step 3 — Import the single migration file (the easy part 🎉)

You only need **ONE file**: `rsvp/database-full-mysql.sql`

1. In cPanel, open **phpMyAdmin** (under **Databases**).
2. Click your database name in the left column (the one you just created).
3. Click the **Import** tab at the top.
4. Under **File to import**, click **Choose File** and select:
   ```
   rsvp/database-full-mysql.sql
   ```
5. Leave everything as default and click **Go** / **Import**.

✅ When it finishes, you'll see a green success message.
All **10 tables** are now created:
`invitations`, `rsvp_responses`, `attendees`, `admin_users`, `login_attempts`, `qr_codes`, `sessions`, `admin_sessions`, `table_assignments`, `reception_photos`.

> The file is safe to import again if you ever need to reset (it uses `IF NOT EXISTS`).

---

## Step 4 — Configure the `.env` file

The project reads a file called `.env` to know how to connect to the database.

1. On your computer, open the file: **`rsvp/.env.example`**
2. **Save a copy as `.env`** (in the `rsvp/` folder — same folder as `.env.example`).
   - ⚠️ Some editors hide the file if the name starts with a dot. Use your editor's "show hidden files" option.
3. Open the new `.env` and fill in your GoDaddy database values:

```ini
DB_HOST=localhost
DB_PORT=3306
DB_NAME=youruser_weddingrsvp
DB_USER=youruser_wedding
DB_PASS=your_strong_password
DB_ENGINE=mysql

# Change to your real domain once it's pointing at GoDaddy:
PUBLIC_BASE_URL=https://www.yourdomain.com

# Production:
ENVIRONMENT=production

# Email (optional — for sending invitations):
MAIL_FROM=noreply@yourdomain.com
MAIL_FROM_NAME=Jason & Rhona Mae

# Google Sheets (optional):
# GOOGLE_SHEETS_ID=...
# GOOGLE_SHEETS_CREDENTIALS_PATH=...
```

4. **Upload** this `.env` file into the `rsvp/` folder on your server
   (next to `rsvp/api.php`, `rsvp/config.php`, etc.).

> 🔒 The `.htaccess` file in `rsvp/` already blocks `.env` from being downloaded by visitors.

---

## Step 5 — Create your admin account

You need an admin username + password to sign in to the dashboard.

**Option A — via hosting terminal (if available):**
In cPanel → **Terminal**, run:
```bash
cd public_html/rsvp
php create-admin.php
```
It will ask for a username and a password (8+ characters).

**Option B — no terminal? Use the Setup Wizard:**
1. Visit in your browser: `https://www.yourdomain.com/rsvp/setup.php`
2. The wizard will check your install.
3. If your DB credentials in `.env` are correct, the database check will be green.
4. Run the admin creation through the wizard link, or use your hosting provider's phpMyAdmin to insert an admin row (see the docs in `rsvp/` for the exact INSERT).

> ⚠️ After setup is done, set `ENABLE_SETUP=false` in `.env` so the wizard can't be used by others.

---

## Step 6 — Verify everything

1. **Website:** `https://www.yourdomain.com/` → should show the wedding site.
2. **Admin:** `https://www.yourdomain.com/rsvp/admin.php` → sign in with your admin user.
3. **Setup check:** `https://www.yourdomain.com/rsvp/setup.php` → all green.

Test the full flow:
- Create an invitation in the admin dashboard.
- View its QR code.
- Open the invite link / scan the QR to submit an RSVP.
- Confirm the response shows up in the admin **Responses** tab.

---

## Step 7 — Email invitations (optional but recommended)

GoDaddy shared hosting supports PHP `mail()`, so it often works with **no SMTP**.

1. In `.env`, make sure you have:
   ```ini
   MAIL_FROM=noreply@yourdomain.com
   MAIL_FROM_NAME=Jason & Rhona Mae
   ```
2. In the admin **Invitations** tab, add a guest email, then click **Send**.
   - Or check **"Send invitation email right away"** when creating an invitation.

If GoDaddy blocks `mail()`, use SMTP instead (Gmail, SendGrid, Mailgun):
```ini
MAIL_USE_SMTP=true
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=you@gmail.com
MAIL_PASSWORD=your_app_password
MAIL_ENCRYPTION=tls
```

---

## Step 8 — Google Sheets export (optional)

1. Create a **Service Account** in Google Cloud and download the JSON key.
2. Share your Google Sheet with the service account email (Editor).
3. Put the spreadsheet ID + JSON path in `.env`:
   ```ini
   GOOGLE_SHEETS_ID=your_spreadsheet_id
   GOOGLE_SHEETS_CREDENTIALS_PATH=/home/youruser/public_html/credentials/google-sheets.json
   ```
4. Upload the JSON key to `credentials/google-sheets.json` on the server.
5. Use **Admin → Export** to push data to the sheet.

---

## Troubleshooting

| Problem | Likely fix |
|---------|-----------|
| White page / 500 error | Check `.env` DB values. Enable `display_errors` temporarily in `config.php`. |
| "Database Connection Failed" | Wrong DB name / user / password in `.env`, or DB host isn't `localhost`. |
| Tables missing | Re-import `rsvp/database-full-mysql.sql` in phpMyAdmin. |
| Can't sign in to admin | Run `php rsvp/create-admin.php` again (it won't overwrite an existing user with the same name — use a new username, or delete the old row in phpMyAdmin first). |
| QR codes not showing | Make sure the `rsvp/qr_codes/` folder is writable (permissions 755 or 775). |
| Invitation email not sending | Configure SMTP (Step 7), or contact GoDaddy about outbound mail on shared hosting. |
| Setup wizard accessible | Set `ENABLE_SETUP=false` in `.env`. |

---

## Quick reference — files to upload to `public_html`

```
(index.html, sw.js, .htaccess, css/, js/, images/, audio/)
rsvp/          ← entire rsvp folder (including database-full-mysql.sql)
reception/     ← entire reception folder
```

That's it — no Git, no GitHub, no command line required.

---

# OPTION B — Deploy Automatically via GitHub Actions

Your project is already connected to a GitHub repo:
`https://github.com/jmdigitalscontact-cyber/testinvitation.git`

We've added a ready-made workflow file:
```
.github/workflows/deploy.yml
```

## How it works

Every time you **push to the `main` branch** on GitHub, GitHub Actions
automatically uploads your files to GoDaddy over FTP/SFTP. No manual
uploading needed after the initial setup.

## One-time setup (about 5 minutes)

1. **Make sure your code is pushed to GitHub** (see the git steps below if needed).
2. **Create an FTP account in GoDaddy:**
   - cPanel → **FTP Accounts** → create one (or use your main FTP login).
   - Note the FTP hostname (usually `ftp.yourdomain.com` or your IP).
3. **Add GitHub Secrets:**
   - Go to your repo on GitHub: **Settings → Secrets and variables → Actions → New repository secret**.
   - Add these three secrets exactly:

     | Secret name | Value |
     |-------------|-------|
     | `FTP_SERVER` | your GoDaddy FTP host, e.g. `ftp.yourdomain.com` |
     | `FTP_USERNAME` | your GoDaddy FTP username |
     | `FTP_PASSWORD` | your GoDaddy FTP password |

4. **Push to `main`** (or edit any file and commit). GitHub Actions runs the workflow and uploads the files.

## What the workflow does (and doesn't) do

✅ **Uploads** all site files (HTML, PHP, CSS, JS, images, `.htaccess`) into `public_html/`

✅ **Preserves** server-only files — uploaded guest photos and generated QR codes are never deleted

❌ **Does NOT upload** your `.env` (it has secrets). Upload `rsvp/.env` once manually via FileZilla.

❌ **Does NOT create the database.** Import `rsvp/database-full-mysql.sql` once via phpMyAdmin (Step 3 of Option A).

## Git push cheat sheet (if needed)

```bash
git add .
git commit -m "Ready for live deployment"
git push origin main
```

After the push:
1. Go to your repo on GitHub → **Actions** tab.
2. You'll see the **"Deploy to GoDaddy"** workflow running.
3. When it turns green ✅, your files are live on GoDaddy.

> 💡 The first time you connect GitHub to GoDaddy, you still need to do these **one-time** manual steps:
> 1. Import `rsvp/database-full-mysql.sql` in phpMyAdmin
> 2. Upload `rsvp/.env` with your DB credentials
> 3. Run `php rsvp/create-admin.php` (or setup wizard) to create your admin login

After that, **every future change is just a `git push` away** 🎉


# Reception venue app

Mobile SPA for guests at the reception (QR code at the venue).

## URLs

- App: `{PUBLIC_BASE_URL}/reception/?key={RECEPTION_API_KEY}`
- API: `{PUBLIC_BASE_URL}/rsvp/api.php`

## Data flow

1. **Guest names** — When a guest RSVPs **Yes** with attendee names, rows are saved in the `attendees` table (see `RSVPHandler.php`).
2. **Table numbers** — Assign tables in **Admin** (`/rsvp/admin.php`) using **Assign table** on each invitation. Table data is stored in `table_assignments`.
3. **Search tab** — Loads the guest list in the background; names and tables are shown only after a guest types at least 2 characters of their name (nothing is listed on first open).

Table numbers are **not** set automatically at RSVP; admin assignment is required before search shows a table.

## Setup

```powershell
php rsvp/apply-schema.php
```

Ensure `reception/uploads/` exists and is writable by PHP.

## Configuration (.env)

- `RECEPTION_API_KEY` — required for QR-only access. The app and API now require this key.
- `RECEPTION_UPLOAD_MAX_BYTES` — default 10485760 (10MB)
- `RECEPTION_UPLOAD_MAX_PER_HOUR` — default 500 per IP (venue Wi-Fi shares one IP)
- `RECEPTION_WEBP_QUALITY` — optional WebP conversion quality (50-100, default 82)

Also raise PHP body limits (defaults are often 2M). Use `php-dev.ini` locally:

```powershell
php -c php-dev.ini -S localhost:3000
```

On cPanel / shared hosting, `.user.ini` at the project root sets `upload_max_filesize=12M` and `post_max_size=16M`.

## QR-only access setup

1. Set `RECEPTION_API_KEY` in `.env` to a long random string.
2. Build the QR URL:
	`{PUBLIC_BASE_URL}/reception/?key={RECEPTION_API_KEY}`
3. Generate and print a QR code for that full URL.
4. Do not add reception links on the public invitation pages.

If the key is missing or invalid, guest search and uploads are blocked.

## Server-side access gate

The reception entry point (`reception/index.php`) **validates the `key` on the
server** before serving any app content. This prevents the page from being
viewed without a valid QR-scanned key:

- `reception/index.php` — the gated entry point (checks `?key=` against `RECEPTION_API_KEY`).
  - Valid key → serves `reception/app.html`
  - Missing / invalid key → **403 Access Denied** (no app content is sent)
- `reception/app.html` — the actual SPA content (served only through the gate).

Do not link directly to `app.html`; guests must always go through `/reception/?key=...`.
The QR code URL already points at `/reception/?key=...`, which is validated by the gate.

## Photo upload performance

- All uploaded photos are converted server-side to `.webp` before saving.
- This keeps gallery payload smaller for faster loading during live reception use.
- Server requirement: PHP GD extension with WebP support enabled.

## Assets

- Replace `assets/floor-plan.png` with your venue layout image.
- Edit `data/menu.json` for the dinner menu.
- Edit `data/floor-plan.json` for table hotspot positions (percent-based) used when highlighting from Search.

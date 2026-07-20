# PostgreSQL + local dev server (no XAMPP required)

## Stack

| Piece | Details |
|-------|---------|
| App | PHP 8.x with `pdo_pgsql` |
| Web (dev) | PHP built-in server on **http://localhost:3000** |
| Database | PostgreSQL `wedding_rsvp` on port **5432** |

MySQL and XAMPP Apache are **not** used for this project.

---

## 1. Prerequisites

1. **PostgreSQL** running locally with database `wedding_rsvp`.
2. **PHP** on PATH (or at `D:\xampp\php\php.exe` without starting Apache/MySQL).

Check PHP:

```powershell
php -v
php -m | findstr pgsql
```

If `php` is not found, add `D:\xampp\php` to your user PATH or install PHP:

```powershell
winget install PHP.PHP.8.2
```

---

## 2. Configuration

Copy environment file:

```powershell
copy .env.example .env
```

Edit [`.env`](../.env):

- `PG_PASS` — if your `postgres` user has a password
- `PUBLIC_BASE_URL` — must match how guests open the site (default `http://localhost:3000`). QR codes use this URL.

If you changed hosts or ports, run `php rsvp/regenerate-qr-codes.php` to refresh all QR images.

**Guest RSVP access:** Guests must use their personal QR code or invitation link (`?invite=INV-...`). Manual invitation ID/password login is disabled for security.

---

## 3. Database schema

From project root:

```powershell
php rsvp/apply-schema.php
php rsvp/create-admin.php
```

Default admin (if newly created): **admin** / **password**

---

## 4. Start dev server

```powershell
.\start-dev.ps1
```

Or:

```powershell
npm run dev
```

### URLs

| Page | URL |
|------|-----|
| Home | http://localhost:3000/ |
| RSVP | http://localhost:3000/rsvp.html |
| Admin | http://localhost:3000/rsvp/admin.php |
| Setup | http://localhost:3000/rsvp/setup.php |

---

## 5. Troubleshooting

| Problem | Fix |
|---------|-----|
| `php` not recognized | New terminal after PATH change, or use `D:\xampp\php\php.exe` |
| Database connection failed | Check Postgres service; verify `.env` `PG_*` values |
| CORS / API errors from browser | Ensure `ALLOWED_ORIGINS` includes `http://localhost:3000` |
| Port 3000 in use | Change port in `start-dev.ps1` (e.g. `localhost:3001`) |

---

## Production

Do not use the PHP built-in server in production. Deploy PHP + PostgreSQL on a proper host (VPS, PaaS, or managed PHP hosting with Postgres).

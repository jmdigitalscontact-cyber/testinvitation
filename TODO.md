# Reception Server-Side Access Gate — Task Tracking

## Goal
Prevent the reception page from being viewed without a valid QR-scanned key by adding server-side protection.

## Steps
- [x] 1. Rename `reception/index.html` → `reception/app.html`
- [x] 2. Create `reception/index.php` server-side gate (validates `key` against `RECEPTION_API_KEY`, serves app or 403)
- [x] 3. Verify the reception flow locally (valid key → app; no key → 403)
- [x] 4. Push changes to GitHub

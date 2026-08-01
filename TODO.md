# Live-Readiness Checklist — Wedding RSVP + Admin Dashboard

## Steps

- [x] 1. Harden `.gitignore` — ignore `.env`, logs, test uploads, QR PNGs, credentials
- [x] 2. Add `.htaccess` protection (root, rsvp, uploads, qr_codes, logs)
- [x] 3. Fix `admin.php` — add invitation/edit password fields, fix colspan
- [x] 4. Fix `admin-dashboard.js` — null-guard password fields
- [x] 5. Fix `api.php` — auto-generate invitation password, remove stale TODO
- [x] 6. Gate `bulk-import.php` behind `ENABLE_BULK_IMPORT`
- [x] 7. Gate `setup.php` behind `ENABLE_SETUP`
- [x] 8. Add admin login rate limiting in `Authentication.php` + `api.php` + DB schema
- [x] 9. Fix `config.php` — per-response content-type + `X-Forwarded-Proto` HTTPS
- [x] 10. Fix `admin.html` broken link
- [x] 11. Harden `admin-auth.js` — remove localStorage token persistence
- [x] 12. Redirect legacy `rsvp/index.php` to modern guest flow
- [x] 13. Final review + update deployment docs



# Go-Live Fixes — Wedding RSVP + Admin Dashboard

Approved plan to fix remaining issues before going live.

## Steps

- [x] 1. Fix `rsvp/api.php` — `handleGetInvitations()` excludes `password_hash` from the response
- [x] 2. Fix `rsvp/api.php` — `handleAssignTable()` uses the authenticated admin ID (not hardcoded `1`)
- [x] 3. Mark step 13 complete in `TODO.md` (final review + deployment docs)
- [x] 4. Run PHP lint verification on modified files
- [x] 5. Create single-file DB migration `rsvp/database-full-mysql.sql` for GoDaddy
- [x] 6. Wire `apply-schema.php` to use the consolidated migration file
- [x] 7. Create `rsvp/GODADDY_DEPLOY.md` — manual upload guide (no Git/GitHub needed)
- [x] 8. Add GitHub Actions workflow `.github/workflows/deploy.yml` (FTP auto-deploy to GoDaddy)
- [x] 9. Update `GODADDY_DEPLOY.md` with Option B (GitHub Actions) setup instructions


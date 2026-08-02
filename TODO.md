# Fix: HTML entities displayed instead of actual characters

**Problem:** Guest names like `I'm John` display as `I&#39;m John` because `sanitize()` in `rsvp/api.php` runs `htmlspecialchars(..., ENT_QUOTES)` on input BEFORE storing in the DB, then the frontend `escapeHtml()` re-encodes on output → double-encoded text.

## Steps

- [x] 1. `rsvp/api.php` — change `sanitize()` to trim-only (no `htmlspecialchars`)
- [x] 2. `rsvp/api.php` — add recursive `htmlDecode()` helper
- [x] 3. `rsvp/api.php` — decode existing DB data on read paths:
  - `handleVerifyInvitationQR`
  - `handleGetInvitationDetails`
  - `handleGetRSVPStatus`
  - `handleCheckRSVPSubmitted`
  - `handleGetInvitations`
  - `handleGetRSVPSummary`
  - `handleGetTableAssignments`
- [x] 4. `rsvp/api.php` — decode attendee names in `extractGuestNamesFromExportRow()` (CSV/Sheets export)
- [x] 4b. `rsvp/Authentication.php` — decode `guest_name` + `invited_guest_names` in `verifyInvitationByQRCode()` and `getInvitationDetails()`
- [x] 5. Run `php -l` lint on modified files
- [x] 6. Commit and push to GitHub (`origin/main`)

---

# Admin Dashboard: QR Download button + pagination (5/page)

- [x] 1. `rsvp/admin.php` — change "Send" column header → "Download"
- [x] 2. `rsvp/admin.php` — add pagination controls (Prev / page info / Next) under All invitations table
- [x] 3. `rsvp/admin.php` — add "Download QR" button inside the QR modal
- [x] 4. `rsvp/admin-dashboard.js` — replace Send button with Download button (`action=download-qr`)
- [x] 5. `rsvp/admin-dashboard.js` — add `downloadQRCode()` (uses `generate-qr` API + `<a download>`)
- [x] 6. `rsvp/admin-dashboard.js` — add pagination state + `renderInvitationsPage()`, `invitationsPrevPage()`, `invitationsNextPage()` (5 per page)
- [x] 7. `rsvp/admin-dashboard.js` — update delegated click handler: `download-qr` → `downloadQRCode()`; wire QR modal download button
- [x] 8. `rsvp/admin.css` — add `.admin-pagination` + `.admin-pagination-info` styles
- [x] 9. Commit and push to GitHub (`origin/main`)

---

# Admin Dashboard: Pagination for dashboard tables (5/page)

- [x] 1. `rsvp/admin.php` — add pagination controls under "Unused guest slots" table
- [x] 2. `rsvp/admin.php` — add pagination controls under "Guest list by invitation" table
- [x] 3. `rsvp/admin-dashboard.js` — add `paginateRows()` + `updatePaginationControls()` helpers
- [x] 4. `rsvp/admin-dashboard.js` — paginate "Unused guest slots" (`unusedSlotsRows`, `renderUnusedSlotsPage`, `unusedPrevPage`/`unusedNextPage`)
- [x] 5. `rsvp/admin-dashboard.js` — paginate "Guest list by invitation" (`qrGuestListRows`, `renderQrGuestListPage`, `qrGuestPrevPage`/`qrGuestNextPage`)
- [ ] 6. Commit and push to GitHub (`origin/main`)



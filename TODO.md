# Fix: HTML entities displayed instead of actual characters

**Problem:** Guest names like `I'm John` display as `I&#39;m John` because `sanitize()` in `rsvp/api.php` runs `htmlspecialchars(..., ENT_QUOTES)` on input BEFORE storing in the DB, then the frontend `escapeHtml()` re-encodes on output → double-encoded text.

## Steps

- [ ] 1. `rsvp/api.php` — change `sanitize()` to trim-only (no `htmlspecialchars`)
- [ ] 2. `rsvp/api.php` — add recursive `htmlDecode()` helper
- [ ] 3. `rsvp/api.php` — decode existing DB data on read paths:
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


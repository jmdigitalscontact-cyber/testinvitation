# RSVP Already-Submitted Detection — Task Plan

## Steps
1. [x] `rsvp/api.php` — In `handleVerifyInvitationQR()`, check if an RSVP response exists and return `rsvp_submitted` + `rsvp_status` in the response data.
2. [x] `index.html` — Add a `#rsvp-done-state` block inside `#rsvp-form-state` showing the "already completed RSVP" message.
3. [x] `js/script.js` — In `renderInvitedParty()` / `loadInvitationDetails()`, if `rsvp_submitted` is true, hide the tick form and show the done-state message without rendering tickable names.
4. [x] `css/styles.css` — Add minimal styles for the done-state message.
5. [x] Test the flow (already-submitted guest shows done message; pending guest shows tick form).

## Notes
- `handleGetInvitationDetails()` also returns `rsvp_submitted` + `rsvp_status` so the token-based flow (legacy link) behaves the same way.
- Service worker cache bumped to `the-berbers-v2` so guests get the updated HTML/CSS/JS instead of the old cached tick form.

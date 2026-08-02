# Task: Make all invited names appear UNTICKED when guest scans QR code

## Context
Client wants: when a guest scans the QR code on the invitation, the invited names
should NOT be pre-ticked. Guests should actively tick each name that will attend.

## Steps
- [x] Investigate RSVP QR scan flow (js/script.js renderInvitedParty)
- [x] Remove `checkbox.checked = true;` so checkboxes start unchecked
- [x] Remove `row.classList.add('is-checked');` so rows start with neutral styling
- [x] Verify behavior: open `index.html?invite=<INV-...>` and confirm all names appear unticked


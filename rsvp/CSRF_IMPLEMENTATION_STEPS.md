# 🔐 CSRF Token Fix - Admin Authentication Implementation

## Problem Statement

**Error**: "Unauthorized (CSRF token missing or mismatch)"

**Root Cause**: 
- Admin API endpoints require BOTH Authorization header and X-CSRF-Token header
- These must be the same value (a session token from successful admin login)
- The current admin.php doesn't include these headers in API calls

---

## ✅ Solution Overview

### 3 Components Needed:

1. **admin-auth.js** ✅ (Already created)
   - Handles login/logout
   - Stores tokens in localStorage
   - Provides `AdminAuth.apiCall()` helper for authenticated requests

2. **Update admin.php** (Instructions below)
   - Include admin-auth.js
   - Replace fetch() with AdminAuth.apiCall()
   - Update all admin-level API calls

3. **Default Admin Account** (For testing)
   - Usually created via setup script
   - Credentials: admin / password (change these!)

---

## 🚀 Implementation Steps

### Step 1: Add admin-auth.js to admin.php

Find the `<script>` tag section near the end of admin.php and add this line at the very top:

**Location**: Right after `<script>` tag opens in admin.php

```html
<script>
    // Add this line at the TOP
    // (Note: You may need to adjust path if admin-auth.js is in different location)
</script>

<!-- Add this line before the existing <script> section -->
<script src="admin-auth.js"></script>
```

**Or as a backup**, add it right before the closing `</body>` tag:

```html
    </script>
    
    <!-- Add authentication manager -->
    <script src="admin-auth.js"></script>
</body>
```

---

### Step 2: Replace `fetch()` with `AdminAuth.apiCall()`

**For EVERY admin API call**, change from:

```javascript
// ❌ OLD - DOESN'T INCLUDE CSRF TOKEN
fetch('api.php?action=create-invitation', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json'
    },
    body: JSON.stringify({...})
})
```

To:

```javascript
// ✅ NEW - INCLUDES CSRF TOKEN
AdminAuth.apiCall('api.php?action=create-invitation', {
    method: 'POST',
    body: JSON.stringify({...})
})
```

**Key Difference**: 
- `AdminAuth.apiCall()` automatically adds the Authorization and X-CSRF-Token headers
- No need to manually add Content-Type - it's added automatically

---

### Step 3: Update Specific Functions in admin.php

Replace these functions. Search for them in admin.php and update:

#### Function 1: `createInvitation`

**Find this code around line ~850**:

```javascript
function createInvitation(event) {
    event.preventDefault();

    const guestName = document.getElementById('guest-name').value;
    const maxGuests = parseInt(document.getElementById('max-guests').value);
    const password = document.getElementById('invite-password').value;
    const email = document.getElementById('invite-email').value;

    // ❌ OLD CODE - CHANGE THIS:
    fetch('api.php?action=create-invitation', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            guest_name: guestName,
            max_guests: maxGuests,
            password: password,
            email: email
        })
    })
```

**Replace with**:

```javascript
function createInvitation(event) {
    event.preventDefault();

    const guestName = document.getElementById('guest-name').value;
    const maxGuests = parseInt(document.getElementById('max-guests').value);
    const password = document.getElementById('invite-password').value;
    const email = document.getElementById('invite-email').value;

    // ✅ NEW CODE - USE AdminAuth.apiCall()
    AdminAuth.apiCall('api.php?action=create-invitation', {
        method: 'POST',
        body: JSON.stringify({
            guest_name: guestName,
            max_guests: maxGuests,
            password: password,
            email: email
        })
    })
```

#### Function 2: `loadStats`

**Find around line ~709**:

```javascript
Promise.all([
    // ❌ OLD - CHANGE THESE:
    fetch('api.php?action=get-invitations').then(r => r.json()),
    fetch('api.php?action=get-rsvp-summary').then(r => r.json())
])
```

**Replace with**:

```javascript
Promise.all([
    // ✅ NEW - USE AdminAuth.apiCall()
    AdminAuth.apiCall('api.php?action=get-invitations').then(r => r.json()),
    AdminAuth.apiCall('api.php?action=get-rsvp-summary').then(r => r.json())
])
```

#### Function 3: `loadInvitations`

**Find around line ~886**:

```javascript
function loadInvitations() {
    // ❌ OLD - CHANGE THIS:
    fetch('api.php?action=get-invitations')
```

**Replace with**:

```javascript
function loadInvitations() {
    // ✅ NEW - USE AdminAuth.apiCall()
    AdminAuth.apiCall('api.php?action=get-invitations')
```

#### Function 4: `loadResponses`

**Find around line ~923**:

```javascript
function loadResponses() {
    fetch('api.php?action=get-rsvp-summary')
```

**Replace with**:

```javascript
function loadResponses() {
    AdminAuth.apiCall('api.php?action=get-rsvp-summary')
```

#### Function 5: Other API calls

Search for and replace ALL remaining `fetch('api.php?action=` calls with `AdminAuth.apiCall('api.php?action=`

Common ones to update:
- `generateQRCode()` - shows QR
- `exportToGoogleSheets()` - export data
- `loadTableAssignments()` - table management
- `assignTable()` - table operations

---

## 📋 Complete List of Functions to Update

Search for these patterns and update:

| Function | Line | Pattern |
|----------|------|---------|
| `createInvitation` | ~850 | `fetch('api.php?action=create-invitation` |
| `loadInvitations` | ~886 | `fetch('api.php?action=get-invitations')` |
| `loadResponses` | ~923 | `fetch('api.php?action=get-rsvp-summary')` |
| `loadStats` | ~709 | `Promise.all([fetch(...)` |
| `showQRCode` | ~983 | `fetch(\`api.php?action=generate-qr` |
| `exportToGoogleSheets` | ~1005 | `fetch(\`api.php?action=export` |
| `loadTableAssignments` | ~1088 | `Promise.all([fetch(...)]` |
| `assignTable` | ~1540 | `fetch(\`api.php?action=assign-table` |

---

## 🧪 Testing the Fix

### Step 1: Reload admin.php

1. Go to: `http://localhost/testing/rsvp/admin.php`
2. You should see a **Login Modal** (if not already logged in)

### Step 2: Login

- **Username**: admin (or your admin username)
- **Password**: password (or your admin password)
- Click **Login**

### Step 3: Try Creating an Invitation

1. Click "Invitations" tab
2. Fill in:
   - Guest Name: "John Smith"
   - Maximum Guests: "1"
   - Password: "test123"
   - Email: (optional)
3. Click "Create Invitation"

**Expected Result** ✅: Invitation created successfully!

---

## 🔑 How It Works

**Flow Diagram**:

```
1. Page Loads
   ↓
2. admin-auth.js loads and checks localStorage
   ↓
3. No token found → Show Login Modal
   ↓
4. User enters credentials → POST to api.php?action=admin-login
   ↓
5. Server returns token + csrf_token
   ↓
6. admin-auth.js stores tokens in localStorage
   ↓
7. User can now make API calls
   ↓
8. Every API call:
   - Authorization: Bearer {token}
   - X-CSRF-Token: {csrf_token}
   ↓
9. Server validates both match → Request allowed ✓
```

---

## 🔒 Security Notes

### localStorage Security
- Tokens stored in localStorage are accessible to JavaScript
- For production, consider using httpOnly cookies instead
- Current implementation is suitable for internal admin dashboards

### Token Expiration
- Tokens expire after configured session timeout (default: 3600 seconds)
- When expired, login modal appears automatically
- User needs to re-login

### CSRF Protection
- Token must be in BOTH Authorization header AND X-CSRF-Token header
- They must be identical
- Server validates with hash_equals() (constant-time comparison)
- Prevents CSRF attacks from malicious websites

---

## ⚠️ Common Issues & Solutions

### Issue 1: Still showing "CSRF token missing"

**Solution**: Make sure you updated ALL fetch() calls, not just some.

Search admin.php for: `fetch('api.php?action=`

Replace with: `AdminAuth.apiCall('api.php?action=`

### Issue 2: Login modal appears but won't accept credentials

**Solution**: Check that Authentication.php can reach the database.

```bash
# Test database connection
cd rsvp
php -r "require 'config.php'; require 'Database.php'; $db = Database::getInstance(); echo 'Connected!'"
```

### Issue 3: Tokens not persisting after reload

**Solution**: Check browser localStorage is enabled.

Open DevTools → Application → Local Storage → Check if tokens are there

### Issue 4: "Invalid or expired admin token"

**Solution**: Token expired. Logout and login again.

```javascript
// In browser console:
AdminAuth.logout();
```

---

## 🚀 Optional Enhancements

### 1. Add Logout Button

```html
<!-- Add to navbar -->
<button onclick="AdminAuth.logout()" style="float: right; padding: 10px 20px; background: #d32f2f; color: white; border: none; border-radius: 4px; cursor: pointer;">
    Logout
</button>
```

### 2. Display Current User

```javascript
// In navbar or header
const token = AdminAuth.getToken();
if (token) {
    console.log('Currently logged in. Token:', token);
}
```

### 3. Auto-logout on Tab Close

```javascript
window.addEventListener('beforeunload', function() {
    // Optional: Clear tokens on tab close
    // AdminAuth.logout();
});
```

---

## 📞 Summary

✅ **What to do**:
1. Add `<script src="admin-auth.js"></script>` to admin.php
2. Replace all `fetch()` with `AdminAuth.apiCall()`
3. Reload admin.php and test

✅ **What happens**:
- Login modal appears on first load
- Tokens stored in localStorage
- All API calls automatically include CSRF tokens
- Error goes away ✓

✅ **Time to implement**: ~15 minutes

---

**Files Needed**:
- ✅ admin-auth.js (created)
- ✅ admin.php (modify - add script include and update fetch calls)
- ✅ api.php (no changes needed)


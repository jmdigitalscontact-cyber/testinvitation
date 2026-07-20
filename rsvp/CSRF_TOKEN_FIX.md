<?php
/**
 * CSRF Token & Admin Authentication Fix
 * 
 * This script shows how to properly handle CSRF tokens and admin authentication
 * in the admin.php interface
 * 
 * Key Points:
 * 1. Admin must login first via handleAdminLogin()
 * 2. Server returns: token and csrf_token (same value)
 * 3. ALL admin API calls must include both:
 *    - Authorization header: "Bearer {token}"
 *    - X-CSRF-Token header: "{csrf_token}"
 * 4. These are stored in browser and reused for all requests
 */

// === WHAT'S HAPPENING ===
// The error "Unauthorized (CSRF token missing or mismatch)" means:
// 
// ❌ PROBLEM:
// The createInvitation() function lacks required headers:
// - No Authorization header (Bearer token)
// - No X-CSRF-Token header
//
// ✅ SOLUTION:
// - Add admin login modal that appears on page load
// - Store token in localStorage/sessionStorage after login
// - Add helper function to inject headers into all API calls
// - Update ALL fetch() calls to include the token

// === JAVASCRIPT IMPLEMENTATION ===

/*

// 1. ADD THIS TO THE TOP OF admin.php <script> TAG:

// ============================================
// ADMIN AUTHENTICATION & CSRF TOKEN MANAGEMENT
// ============================================

// Store admin credentials
let adminToken = null;
let adminCsrfToken = null;

// Initialize on page load
function initializeAdmin() {
    // Check if already logged in
    const storedToken = localStorage.getItem('admin_token');
    const storedCsrf = localStorage.getItem('admin_csrf_token');
    
    if (storedToken && storedCsrf) {
        adminToken = storedToken;
        adminCsrfToken = storedCsrf;
        console.log('✓ Admin already logged in');
        hideLoginModal();
        loadStats(); // Start loading data
        
    } else {
        console.log('Admin login required');
        showLoginModal();
    }
}

// 2. ADD THIS HELPER FUNCTION:
// Helper to add CSRF token to all admin API calls
function apiCall(url, options = {}) {
    if (!adminToken) {
        alert('Not authenticated. Please login first.');
        showLoginModal();
        return Promise.reject('Not authenticated');
    }
    
    // Add headers to the request
    const headers = {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${adminToken}`,
        'X-CSRF-Token': adminCsrfToken,
        ...options.headers
    };
    
    return fetch(url, {
        ...options,
        headers: headers
    });
}

// 3. ADD THIS LOGIN/LOGOUT FUNCTIONS:
function showLoginModal() {
    const modal = document.createElement('div');
    modal.id = 'admin-login-modal';
    modal.className = 'modal';
    modal.style.display = 'block';
    modal.style.zIndex = '9999';
    
    modal.innerHTML = `
        <div class="modal-content" style="width: 400px; margin-top: 100px;">
            <div class="modal-header">Admin Login</div>
            <div id="login-message"></div>
            <form onsubmit="handleAdminLogin(event)">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" id="admin-username" placeholder="Enter username" required>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" id="admin-password" placeholder="Enter password" required>
                </div>
                <button type="submit" class="btn btn-primary">Login</button>
            </form>
        </div>
    `;
    
    document.body.appendChild(modal);
}

function hideLoginModal() {
    const modal = document.getElementById('admin-login-modal');
    if (modal) {
        modal.remove();
    }
}

function handleAdminLogin(event) {
    event.preventDefault();
    
    const username = document.getElementById('admin-username').value;
    const password = document.getElementById('admin-password').value;
    
    fetch('api.php?action=admin-login', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            username: username,
            password: password
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Store tokens
            adminToken = data.token;
            adminCsrfToken = data.csrf_token;
            
            localStorage.setItem('admin_token', adminToken);
            localStorage.setItem('admin_csrf_token', adminCsrfToken);
            
            console.log('✓ Admin logged in successfully');
            hideLoginModal();
            loadStats(); // Start loading data
            
        } else {
            const msg = document.getElementById('login-message');
            msg.textContent = data.error || 'Login failed';
            msg.className = 'message error';
        }
    })
    .catch(error => {
        alert('Login error: ' + error);
    });
}

function logoutAdmin() {
    adminToken = null;
    adminCsrfToken = null;
    localStorage.removeItem('admin_token');
    localStorage.removeItem('admin_csrf_token');
    location.reload();
}

// 4. UPDATE ALL fetch() CALLS IN admin.php:
// Change FROM:
//     fetch('api.php?action=create-invitation', {
//         method: 'POST',
//         headers: {
//             'Content-Type': 'application/json'
//         },
//         body: JSON.stringify(...)
//     })
//
// Change TO:
//     apiCall('api.php?action=create-invitation', {
//         method: 'POST',
//         body: JSON.stringify(...)
//     })

// 5. EXAMPLE: Update createInvitation function:
function createInvitation_FIXED(event) {
    event.preventDefault();

    const guestName = document.getElementById('guest-name').value;
    const maxGuests = parseInt(document.getElementById('max-guests').value);
    const password = document.getElementById('invite-password').value;
    const email = document.getElementById('invite-email').value;

    // Use apiCall() instead of fetch() directly
    apiCall('api.php?action=create-invitation', {
        method: 'POST',
        body: JSON.stringify({
            guest_name: guestName,
            max_guests: maxGuests,
            password: password,
            email: email
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage('invitations-message', 'Invitation created successfully!', 'success');
            document.getElementById('guest-name').value = '';
            document.getElementById('max-guests').value = '1';
            document.getElementById('invite-password').value = '';
            document.getElementById('invite-email').value = '';
            loadInvitations();
            loadStats();
        } else {
            showMessage('invitations-message', data.error || 'Failed to create invitation', 'error');
        }
    });
}

// 6. UPDATE loadInvitations:
function loadInvitations_FIXED() {
    apiCall('api.php?action=get-invitations')
        .then(response => response.json())
        .then(data => {
            // ... rest of the function remains the same
        });
}

// 7. UPDATE loadStats:
function loadStats_FIXED() {
    Promise.all([
        apiCall('api.php?action=get-invitations').then(r => r.json()),
        apiCall('api.php?action=get-rsvp-summary').then(r => r.json())
    ])
    .then(([invitationsRes, responsesRes]) => {
        // ... rest of the function remains the same
    });
}

// 8. CALL THIS WHEN PAGE LOADS:
// Add to the bottom of the <script> section in admin.php:
document.addEventListener('DOMContentLoaded', function() {
    initializeAdmin(); // Initialize authentication
});

*/

?>

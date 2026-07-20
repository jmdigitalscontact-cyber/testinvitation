<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wedding RSVP</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            max-width: 600px;
            width: 100%;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
            border-radius: 10px 10px 0 0;
        }

        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }

        .header p {
            opacity: 0.9;
        }

        .content {
            padding: 30px;
        }

        .section {
            display: none;
        }

        .section.active {
            display: block;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 600;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        button {
            flex: 1;
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: #e0e0e0;
            color: #333;
        }

        .btn-secondary:hover {
            background: #d0d0d0;
        }

        .btn-small {
            padding: 8px 16px;
            font-size: 14px;
        }

        .message {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: none;
        }

        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            display: block;
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            display: block;
        }

        .message.info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
            display: block;
        }

        .attendee-item {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 15px;
        }

        .attendee-item h4 {
            margin-bottom: 10px;
            color: #333;
        }

        .attendance-options {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }

        .radio-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .radio-group input[type="radio"] {
            width: auto;
            margin: 0;
            cursor: pointer;
        }

        .loading {
            text-align: center;
            padding: 20px;
        }

        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .alert {
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        .add-attendee-btn {
            margin-top: 10px;
        }

        .remove-btn {
            background: #e74c3c;
            color: white;
            padding: 6px 12px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
        }

        .remove-btn:hover {
            background: #c0392b;
        }

        .summary {
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-radius: 5px;
            padding: 20px;
            margin-top: 20px;
        }

        .summary h3 {
            margin-bottom: 15px;
            color: #333;
        }

        .summary-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e0e0e0;
        }

        .summary-item:last-child {
            border-bottom: none;
        }

        @media (max-width: 600px) {
            .container {
                border-radius: 0;
            }

            .header {
                border-radius: 0;
            }

            .button-group {
                flex-direction: column;
            }

            .attendance-options {
                flex-direction: column;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Wedding RSVP</h1>
            <p>Please let us know if you can join us!</p>
        </div>

        <div class="content">
            <!-- Authentication Section -->
            <div id="auth-section" class="section active">
                <div class="message" id="auth-message"></div>
                
                <h2 style="margin-bottom: 20px;">Access Your Invitation</h2>
                
                <div class="form-group">
                    <label for="invitation-id">Invitation ID or Code</label>
                    <input type="text" id="invitation-id" placeholder="Enter your invitation code">
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" placeholder="Enter your password">
                </div>

                <button class="btn-primary" onclick="authenticateInvitation()">
                    Continue
                </button>

                <div class="alert alert-warning" style="margin-top: 20px; display: none;" id="rate-limit-warning">
                    Too many failed attempts. Please try again in 15 minutes.
                </div>
            </div>

            <!-- RSVP Form Section -->
            <div id="rsvp-section" class="section">
                <h2 style="margin-bottom: 20px;">Redirecting to RSVP Form...</h2>
            </div>
        </div>
    </div>

    <script>
        // State management
        let currentInvitation = null;
        let currentToken = null;
        let currentRSVP = null;

        // Check if invitation ID is in URL (from QR code scan)
        window.addEventListener('load', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const inviteId = urlParams.get('invite');
            
            if (inviteId) {
                document.getElementById('invitation-id').value = inviteId;
            }
        });

        function showMessage(elementId, message, type) {
            const messageEl = document.getElementById(elementId);
            messageEl.textContent = message;
            messageEl.className = 'message ' + type;
        }

        function authenticateInvitation() {
            const invitationId = document.getElementById('invitation-id').value.trim();
            const password = document.getElementById('password').value;

            if (!invitationId || !password) {
                showMessage('auth-message', 'Please enter both invitation ID and password', 'error');
                return;
            }

            const loading = '<div class="loading"><div class="spinner"></div></div>';
            document.getElementById('auth-section').innerHTML = loading;

            fetch('api.php?action=verify-invitation', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    invitation_id: invitationId,
                    password: password
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    currentInvitation = data.data;
                    currentToken = data.data.token;
                    loadRSVPSection();
                } else {
                    reloadAuthSection();
                    showMessage('auth-message', data.error || 'Authentication failed', 'error');
                }
            })
            .catch(error => {
                reloadAuthSection();
                showMessage('auth-message', 'An error occurred: ' + error.message, 'error');
            });
        }

        function reloadAuthSection() {
            location.reload();
        }

        function loadRSVPSection() {
            // Redirect to main RSVP form on index.html with authentication token
            const redirectUrl = '../index.html?token=' + encodeURIComponent(currentToken) + 
                                '&guest=' + encodeURIComponent(currentInvitation.guest_name);
            window.location.href = redirectUrl;
        }

        function logout() {
            currentInvitation = null;
            currentToken = null;
            location.reload();
        }

        function switchSection(sectionId) {
            document.querySelectorAll('.section').forEach(section => {
                section.classList.remove('active');
            });
            document.getElementById(sectionId).classList.add('active');
        }
    </script>
</body>
</html>

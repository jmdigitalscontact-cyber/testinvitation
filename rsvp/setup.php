<?php
/**
 * Setup Wizard for Wedding RSVP System
 * This file helps verify installation and setup
 */

require_once 'config.php';
require_once 'Database.php';

$status = [
    'database' => false,
    'config' => false,
    'directories' => false,
    'files' => false
];

$messages = [];

// Check config
if (defined('PG_HOST') && defined('PG_USER') && defined('PG_DB')) {
    $status['config'] = true;
} else {
    $messages[] = ['error' => 'Config file not properly configured'];
}

// Check database connection
try {
    $db = Database::getInstance();
    $mysqli = $db->getConnection();
    
    // Check tables
    $result = $mysqli->query("SHOW TABLES LIKE 'invitations'");
    if ($result && $result->num_rows > 0) {
        $status['database'] = true;
    } else {
        $messages[] = ['warning' => 'Database tables not found. Did you run database-schema.sql?'];
    }
} catch (Exception $e) {
    $messages[] = ['error' => 'Database connection failed: ' . $e->getMessage()];
}

// Check directories
$qr_path = QR_CODE_PATH;
if (is_dir($qr_path) && is_writable($qr_path)) {
    $status['directories'] = true;
} else {
    $messages[] = ['warning' => "QR code directory not writable: $qr_path"];
}

// Check required files
$required_files = [
    'api.php',
    'Database.php',
    'Authentication.php',
    'RSVPHandler.php',
    'QRCodeGenerator.php',
    'index.php',
    'admin.php'
];

$all_files_exist = true;
foreach ($required_files as $file) {
    if (!file_exists(dirname(__FILE__) . '/' . $file)) {
        $messages[] = ['error' => "Missing file: $file"];
        $all_files_exist = false;
    }
}

if ($all_files_exist) {
    $status['files'] = true;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RSVP System - Setup Wizard</title>
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
            max-width: 800px;
            width: 100%;
            padding: 40px;
        }

        h1 {
            color: #333;
            margin-bottom: 10px;
        }

        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 16px;
        }

        .status-section {
            margin-bottom: 30px;
        }

        .status-section h2 {
            font-size: 18px;
            color: #333;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .status-badge {
            display: inline-block;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 12px;
        }

        .status-badge.success {
            background: #28a745;
        }

        .status-badge.error {
            background: #dc3545;
        }

        .status-badge.warning {
            background: #ffc107;
        }

        .messages {
            background: #f5f5f5;
            border-left: 4px solid #667eea;
            padding: 15px;
            margin-top: 10px;
            border-radius: 4px;
        }

        .message {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
            padding: 10px;
            border-radius: 4px;
        }

        .message:last-child {
            margin-bottom: 0;
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
            border-left: 3px solid #dc3545;
        }

        .message.warning {
            background: #fff3cd;
            color: #856404;
            border-left: 3px solid #ffc107;
        }

        .message.success {
            background: #d4edda;
            color: #155724;
            border-left: 3px solid #28a745;
        }

        .next-steps {
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 5px;
            padding: 20px;
            margin-top: 30px;
        }

        .next-steps h3 {
            color: #0066cc;
            margin-bottom: 15px;
        }

        .next-steps ol {
            margin-left: 20px;
            color: #333;
            line-height: 1.8;
        }

        .next-steps li {
            margin-bottom: 8px;
        }

        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 30px;
        }

        a, button {
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 14px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: #e0e0e0;
            color: #333;
        }

        .btn-secondary:hover {
            background: #d0d0d0;
        }

        .overall-status {
            text-align: center;
            padding: 20px;
            background: #f5f5f5;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .overall-status h2 {
            color: #28a745;
            margin-bottom: 10px;
        }

        .checklist {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .check-item {
            padding: 15px;
            text-align: center;
            border-radius: 5px;
            background: #f5f5f5;
        }

        .check-item.pass {
            background: #d4edda;
            border: 1px solid #c3e6cb;
        }

        .check-item.fail {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
        }

        .check-item strong {
            display: block;
            margin-top: 10px;
        }

        @media (max-width: 600px) {
            .container {
                padding: 20px;
            }

            .button-group {
                flex-direction: column;
            }

            .checklist {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🎉 Wedding RSVP System Setup Wizard</h1>
        <p class="subtitle">Verify your installation and complete setup</p>

        <div class="overall-status">
            <?php
            $all_pass = !array_key_exists(false, $status) || count(array_filter($status)) >= 3;
            if ($all_pass) {
                echo '<h2>✓ Setup Complete!</h2>';
                echo '<p>Your RSVP system is ready to use.</p>';
            } else {
                echo '<h2>⚠ Setup Incomplete</h2>';
                echo '<p>Please complete the steps below.</p>';
            }
            ?>
        </div>

        <div class="checklist">
            <div class="check-item <?php echo $status['config'] ? 'pass' : 'fail'; ?>">
                <span><?php echo $status['config'] ? '✓' : '✗'; ?></span>
                <strong>Config</strong>
            </div>
            <div class="check-item <?php echo $status['database'] ? 'pass' : 'fail'; ?>">
                <span><?php echo $status['database'] ? '✓' : '✗'; ?></span>
                <strong>Database</strong>
            </div>
            <div class="check-item <?php echo $status['directories'] ? 'pass' : 'fail'; ?>">
                <span><?php echo $status['directories'] ? '✓' : '✗'; ?></span>
                <strong>Directories</strong>
            </div>
            <div class="check-item <?php echo $status['files'] ? 'pass' : 'fail'; ?>">
                <span><?php echo $status['files'] ? '✓' : '✗'; ?></span>
                <strong>Files</strong>
            </div>
        </div>

        <?php if (!empty($messages)): ?>
            <div class="messages">
                <?php foreach ($messages as $msg): ?>
                    <?php foreach ($msg as $type => $text): ?>
                        <div class="message <?php echo $type; ?>">
                            <?php echo $text; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="next-steps">
            <h3>📋 Next Steps</h3>
            <ol>
                <li><strong>Create Sample Invitation:</strong> Go to <a href="admin.php" target="_blank">Admin Panel</a> and create your first invitation</li>
                <li><strong>Test RSVP:</strong> Scan the generated QR code or use the invitation ID and password</li>
                <li><strong>View Dashboard:</strong> Check the admin dashboard for statistics</li>
                <li><strong>Export Data:</strong> Export responses as CSV when ready</li>
            </ol>
        </div>

        <div class="button-group">
            <a href="index.php" class="btn-secondary">View RSVP Page</a>
            <a href="admin.php" class="btn-primary">Go to Admin Panel</a>
        </div>

        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e0e0e0; color: #666; font-size: 12px;">
            <p>📖 See <a href="README.md" target="_blank" style="color: #667eea;">README.md</a> for detailed documentation and API endpoints.</p>
        </div>
    </div>
</body>
</html>

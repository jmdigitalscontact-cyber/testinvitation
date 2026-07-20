<?php
/**
 * Bulk Invitation Generator
 * Create multiple invitations from a CSV file
 */

require_once 'config.php';
require_once 'Database.php';
require_once 'QRCodeGenerator.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file']['tmp_name'];
    
    if (!file_exists($file)) {
        $error = "No file uploaded";
    } else {
        $handle = fopen($file, 'r');
        $db = Database::getInstance();
        $mysqli = $db->getConnection();
        $qr_gen = new QRCodeGenerator();
        
        $created = 0;
        $failed = 0;
        $results = [];
        
        // Skip header row
        fgetcsv($handle);
        
        while ($row = fgetcsv($handle)) {
            if (count($row) < 3) continue;
            
            $guest_name = trim($row[0]);
            $max_guests = (int)trim($row[1]);
            $password = trim($row[2]);
            $email = isset($row[3]) ? trim($row[3]) : '';
            
            if (empty($guest_name) || $max_guests < 1 || empty($password)) {
                $failed++;
                $results[] = [
                    'guest' => $guest_name,
                    'status' => 'error',
                    'message' => 'Missing required fields'
                ];
                continue;
            }
            
            // Generate unique invitation ID
            $invitation_id = 'INV-' . strtoupper(substr(md5(time() . $guest_name . mt_rand()), 0, 12));
            $password_hash = password_hash($password, PASSWORD_BCRYPT);
            
            $stmt = $mysqli->prepare("
                INSERT INTO invitations (invitation_id, guest_name, password_hash, max_guests, email) 
                VALUES (?, ?, ?, ?, ?)
            ");
            
            if ($stmt && $stmt->bind_param("sssss", $invitation_id, $guest_name, $password_hash, $max_guests, $email) && $stmt->execute()) {
                // Generate QR code
                $qr_result = $qr_gen->generateQRCode($invitation_id, $guest_name);
                
                $created++;
                $results[] = [
                    'guest' => $guest_name,
                    'status' => 'success',
                    'invitation_id' => $invitation_id,
                    'message' => 'Created successfully',
                    'qr' => $qr_result ? 'Generated' : 'Failed'
                ];
            } else {
                $failed++;
                $results[] = [
                    'guest' => $guest_name,
                    'status' => 'error',
                    'message' => 'Database error'
                ];
            }
            
            if ($stmt) $stmt->close();
        }
        
        fclose($handle);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Invitation Generator - RSVP System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        h1 {
            color: #333;
            margin-bottom: 10px;
        }

        .subtitle {
            color: #666;
            margin-bottom: 30px;
        }

        .section {
            margin-bottom: 30px;
        }

        .section h2 {
            font-size: 18px;
            color: #333;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
        }

        .upload-box {
            border: 2px dashed #667eea;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            background: #f9f9f9;
            margin-bottom: 20px;
        }

        .upload-box input[type="file"] {
            display: none;
        }

        .upload-box label {
            display: inline-block;
            padding: 12px 24px;
            background: #667eea;
            color: white;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }

        .upload-box label:hover {
            background: #764ba2;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .file-info {
            color: #666;
            margin-top: 10px;
            font-size: 14px;
        }

        .csv-template {
            background: #f5f5f5;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
            font-family: monospace;
            overflow-x: auto;
        }

        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        button, a {
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s;
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

        .results {
            margin-top: 30px;
        }

        .results-summary {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .result-card {
            padding: 15px;
            border-radius: 5px;
            text-align: center;
        }

        .result-card.success {
            background: #d4edda;
            color: #155724;
        }

        .result-card.error {
            background: #f8d7da;
            color: #721c24;
        }

        .result-card .number {
            font-size: 28px;
            font-weight: 700;
            display: block;
            margin: 10px 0;
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th {
            background: #f5f5f5;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #e0e0e0;
        }

        td {
            padding: 15px;
            border-bottom: 1px solid #e0e0e0;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-success {
            background: #d4edda;
            color: #155724;
        }

        .status-error {
            background: #f8d7da;
            color: #721c24;
        }

        .message {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: none;
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            display: block;
        }

        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            display: block;
        }

        .instructions {
            background: #e7f3ff;
            border-left: 4px solid #0066cc;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            line-height: 1.6;
        }

        .instructions ol {
            margin-left: 20px;
        }

        .instructions li {
            margin-bottom: 8px;
        }

        .sample-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .sample-table th,
        .sample-table td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
            font-size: 12px;
        }

        .sample-table th {
            background: #e0e0e0;
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .results-summary {
                grid-template-columns: 1fr;
            }

            .button-group {
                flex-direction: column;
            }

            table {
                font-size: 12px;
            }

            td, th {
                padding: 10px 5px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📋 Bulk Invitation Generator</h1>
        <p class="subtitle">Create multiple invitations from a CSV file</p>

        <?php if (isset($error)): ?>
            <div class="message error"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if (isset($created) && isset($failed)): ?>
            <div class="results">
                <div class="results-summary">
                    <div class="result-card success">
                        <div>Created</div>
                        <div class="number"><?php echo $created; ?></div>
                    </div>
                    <div class="result-card error">
                        <div>Failed</div>
                        <div class="number"><?php echo $failed; ?></div>
                    </div>
                    <div class="result-card" style="background: #d1ecf1; color: #0c5460;">
                        <div>Total</div>
                        <div class="number"><?php echo $created + $failed; ?></div>
                    </div>
                </div>

                <h3 style="margin-top: 30px; margin-bottom: 15px;">Results Details</h3>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Guest Name</th>
                                <th>Invitation ID</th>
                                <th>Status</th>
                                <th>Message</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $result): ?>
                                <tr>
                                    <td><?php echo $result['guest']; ?></td>
                                    <td><code><?php echo $result['invitation_id'] ?? '-'; ?></code></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $result['status']; ?>">
                                            <?php echo ucfirst($result['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $result['message']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="button-group">
                    <a href="admin.php" class="btn-primary">View in Admin Panel</a>
                    <button class="btn-secondary" onclick="location.reload()">Import More</button>
                </div>
            </div>
        <?php else: ?>
            <div class="section">
                <h2>Instructions</h2>
                <div class="instructions">
                    <ol>
                        <li>Prepare a CSV file with the following columns:
                            <ul style="margin-top: 8px;">
                                <li><strong>Guest Name</strong> (required) - Name of guest or family</li>
                                <li><strong>Max Guests</strong> (required) - Number of guests allowed</li>
                                <li><strong>Password</strong> (required) - Access password for invitation</li>
                                <li><strong>Email</strong> (optional) - Guest email address</li>
                            </ul>
                        </li>
                        <li>Include a header row with column names</li>
                        <li>Upload the CSV file below</li>
                        <li>Review the results and generated invitations</li>
                    </ol>
                </div>
            </div>

            <div class="section">
                <h2>CSV Format Example</h2>
                <div class="csv-template">
                    <table class="sample-table">
                        <thead>
                            <tr>
                                <th>Guest Name</th>
                                <th>Max Guests</th>
                                <th>Password</th>
                                <th>Email</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Smith Family</td>
                                <td>3</td>
                                <td>smith2026</td>
                                <td>smith@example.com</td>
                            </tr>
                            <tr>
                                <td>Johnson Family</td>
                                <td>2</td>
                                <td>johnson2026</td>
                                <td>johnson@example.com</td>
                            </tr>
                            <tr>
                                <td>Williams Individual</td>
                                <td>1</td>
                                <td>williams2026</td>
                                <td>williams@example.com</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="section">
                <h2>Upload CSV File</h2>
                <form method="POST" enctype="multipart/form-data">
                    <div class="upload-box">
                        <label for="csv_file">
                            📁 Choose CSV File
                        </label>
                        <input type="file" id="csv_file" name="csv_file" accept=".csv" required onchange="updateFileName(this)">
                        <div class="file-info" id="file-info">No file selected</div>
                    </div>

                    <div class="button-group">
                        <button type="submit" class="btn-primary">Upload & Create Invitations</button>
                        <a href="admin.php" class="btn-secondary">Back to Admin</a>
                    </div>
                </form>
            </div>

            <div class="section">
                <h2>Download Template</h2>
                <p style="margin-bottom: 15px; color: #666;">Use this template CSV to get started.</p>
                <button class="btn-primary" onclick="downloadTemplate()">📥 Download CSV Template</button>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function updateFileName(input) {
            const fileInfo = document.getElementById('file-info');
            if (input.files && input.files[0]) {
                fileInfo.textContent = '✓ Selected: ' + input.files[0].name + ' (' + (input.files[0].size / 1024).toFixed(2) + ' KB)';
            } else {
                fileInfo.textContent = 'No file selected';
            }
        }

        function downloadTemplate() {
            const csv = 'Guest Name,Max Guests,Password,Email\n' +
                        'Smith Family,3,smith2026,smith@example.com\n' +
                        'Johnson Family,2,johnson2026,johnson@example.com\n' +
                        'Williams Individual,1,williams2026,williams@example.com\n' +
                        'Brown Family,4,brown2026,brown@example.com\n';

            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'invitation-template.csv';
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);
        }
    </script>
</body>
</html>

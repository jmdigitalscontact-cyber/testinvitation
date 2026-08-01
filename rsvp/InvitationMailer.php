<?php
/**
 * InvitationMailer
 * -----------------------------------------------------------
 * Sends invitation emails to guests with their personal link
 * and QR code image.
 *
 * Transport options (configured via .env):
 *   - Default: PHP mail()  (works on most shared hosts incl. GoDaddy)
 *   - SMTP:    set MAIL_USE_SMTP=true plus MAIL_HOST/PORT/USERNAME/PASSWORD
 *              (Gmail, SendGrid, Mailgun, etc.)
 *
 * .env variables:
 *   MAIL_FROM            noreply@yourdomain.com
 *   MAIL_FROM_NAME       Jason & Rhona Mae
 *   MAIL_USE_SMTP        false | true
 *   MAIL_HOST            smtp.example.com
 *   MAIL_PORT            587
 *   MAIL_USERNAME        (SMTP username)
 *   MAIL_PASSWORD        (SMTP password)
 *   MAIL_ENCRYPTION      tls | ssl | none
 */

class InvitationMailer {
    private $from;
    private $fromName;
    private $useSmtp;
    private $smtpHost;
    private $smtpPort;
    private $smtpUser;
    private $smtpPass;
    private $smtpEncryption;

    public function __construct() {
        $this->from = EnvironmentLoader::get('MAIL_FROM', 'noreply@yourdomain.com');
        $this->fromName = EnvironmentLoader::get('MAIL_FROM_NAME', 'Jason & Rhona Mae');
        $this->useSmtp = isTruthyMailValue(EnvironmentLoader::get('MAIL_USE_SMTP', 'false'));
        $this->smtpHost = EnvironmentLoader::get('MAIL_HOST', '');
        $this->smtpPort = (int)EnvironmentLoader::get('MAIL_PORT', 587);
        $this->smtpUser = EnvironmentLoader::get('MAIL_USERNAME', '');
        $this->smtpPass = EnvironmentLoader::get('MAIL_PASSWORD', '');
        $this->smtpEncryption = strtolower((string)EnvironmentLoader::get('MAIL_ENCRYPTION', 'tls'));
    }

    /**
     * Send a wedding invitation email.
     *
     * @param string $toEmail     Recipient email address
     * @param string $guestName   Primary guest / family name
     * @param string $invitationId
     * @param string $inviteUrl   Personal landing URL
     * @param string $qrImagePath Optional QR image path (relative or full URL)
     * @return array{success:bool, message:string}
     */
    public function sendInvitation($toEmail, $guestName, $invitationId, $inviteUrl, $qrImagePath = '') {
        if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'A valid recipient email is required.'];
        }

        $subject = 'You\'re Invited to Jason & Rhona Mae\'s Wedding';
        $bodyHtml = $this->buildHtmlBody($guestName, $invitationId, $inviteUrl, $qrImagePath);
        $bodyText = $this->buildTextBody($guestName, $invitationId, $inviteUrl);

        try {
            if ($this->useSmtp && $this->smtpHost) {
                $ok = $this->sendViaSmtp($toEmail, $subject, $bodyHtml, $bodyText);
            } else {
                $ok = $this->sendViaMail($toEmail, $subject, $bodyHtml, $bodyText);
            }

            if ($ok) {
                return [
                    'success' => true,
                    'message' => "Invitation email sent to {$toEmail}.",
                    'to' => $toEmail
                ];
            }

            return [
                'success' => false,
                'message' => 'Mail function returned false. Check server mail configuration (server error log).'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Mail transport error: ' . $e->getMessage()
            ];
        }
    }

    // ──────────────────────────────────────────────────────────
    // Body builders
    // ──────────────────────────────────────────────────────────

    private function buildHtmlBody($guestName, $invitationId, $inviteUrl, $qrImagePath) {
        $safeGuest = htmlspecialchars($guestName, ENT_QUOTES, 'UTF-8');
        $safeUrl = htmlspecialchars($inviteUrl, ENT_QUOTES, 'UTF-8');
        $safeId = htmlspecialchars($invitationId, ENT_QUOTES, 'UTF-8');

        $qrHtml = '';
        if ($qrImagePath) {
            $qrHtml = '<p style="margin:18px 0 6px;"><img src="' . htmlspecialchars($qrImagePath, ENT_QUOTES, 'UTF-8') . '" alt="Invitation QR code" width="160" height="160" style="border:1px solid #e5ddd5;border-radius:10px;padding:6px;background:#fff;"></p>';
        }

        return '
        <div style="font-family:Arial,Helvetica,sans-serif;background:#f7f4f1;padding:24px;">
          <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e7ded6;">
            <div style="background:linear-gradient(135deg,#b98a5e,#8a5f3d);padding:28px 24px;text-align:center;">
              <h1 style="margin:0;color:#ffffff;font-size:24px;letter-spacing:1px;">Jason &amp; Rhona Mae</h1>
              <p style="margin:8px 0 0;color:#f7e9db;font-size:14px;">Together with their families, they request the honor of your presence</p>
            </div>
            <div style="padding:26px 26px 30px;">
              <p style="margin:0 0 14px;font-size:16px;color:#3a2b1d;">Dear <strong>' . $safeGuest . '</strong>,</p>
              <p style="margin:0 0 14px;font-size:15px;color:#4a3826;line-height:1.6;">We would be delighted to have you celebrate our wedding day with us. Kindly confirm your attendance using your personal invitation link below.</p>
              <p style="margin:18px 0 6px;text-align:center;">
                <a href="' . $safeUrl . '" style="display:inline-block;background:#b98a5e;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 28px;border-radius:999px;font-size:15px;">Respond to Invitation</a>
              </p>
              ' . $qrHtml . '
              <p style="margin:18px 0 8px;font-size:13px;color:#6b5844;">You can also scan the QR code above or visit:</p>
              <p style="margin:0 0 16px;word-break:break-all;font-size:13px;color:#8a6f52;"><a href="' . $safeUrl . '" style="color:#8a6f52;">' . $safeUrl . '</a></p>
              <p style="margin:16px 0 0;padding-top:14px;border-top:1px solid #eee5dc;font-size:12px;color:#9c8872;">Invitation code: <strong>' . $safeId . '</strong></p>
              <p style="margin:8px 0 0;font-size:12px;color:#9c8872;">If you have any questions, please reply to this email or contact Jason &amp; Rhona Mae directly.</p>
            </div>
          </div>
        </div>';
    }

    private function buildTextBody($guestName, $invitationId, $inviteUrl) {
        return "Dear {$guestName},\n\n"
            . "We would be delighted to have you celebrate our wedding day with us.\n"
            . "Kindly confirm your attendance using your personal invitation link:\n\n"
            . "{$inviteUrl}\n\n"
            . "Invitation code: {$invitationId}\n\n"
            . "If you have any questions, please contact Jason & Rhona Mae directly.\n";
    }

    // ──────────────────────────────────────────────────────────
    // Transport: PHP mail()
    // ──────────────────────────────────────────────────────────

    private function sendViaMail($toEmail, $subject, $bodyHtml, $bodyText) {
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $fromAddress = $this->from;
        $fromName = $this->fromName;

        $headers = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $headers[] = 'From: ' . $this->encodeHeader($fromName) . ' <' . $fromAddress . '>';
        $headers[] = 'Reply-To: ' . $fromAddress;

        return @mail($toEmail, $encodedSubject, $bodyHtml, implode("\r\n", $headers), '-f ' . $fromAddress);
    }

    // ──────────────────────────────────────────────────────────
    // Transport: SMTP (stream sockets, STARTTLS + AUTH LOGIN)
    // ──────────────────────────────────────────────────────────

    private function sendViaSmtp($toEmail, $subject, $bodyHtml, $bodyText) {
        $host = $this->smtpHost;
        $port = $this->smtpPort;
        $encryption = $this->smtpEncryption;

        if (empty($host)) {
            throw new Exception('MAIL_HOST is empty but MAIL_USE_SMTP is true.');
        }

        $remote = $host . ':' . $port;
        $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);

        $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
        if (!$fp) {
            throw new Exception("Could not connect to SMTP server {$remote}: {$errstr} ({$errno})");
        }

        $this->smtpRead($fp); // 220 greeting

        if ($encryption === 'ssl') {
            // Already connected via TLS stream above when scheme used; handled below.
        }

        $this->smtpCommand($fp, 'EHLO ' . (gethostname() ?: 'localhost'), 250);

        if ($encryption === 'tls') {
            $this->smtpCommand($fp, 'STARTTLS', 220);
            $crypto = stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if (!$crypto) {
                fclose($fp);
                throw new Exception('STARTTLS negotiation failed.');
            }
            $this->smtpCommand($fp, 'EHLO ' . (gethostname() ?: 'localhost'), 250);
        }

        if ($this->smtpUser !== '') {
            $this->smtpCommand($fp, 'AUTH LOGIN', 334);
            $this->smtpCommand($fp, base64_encode($this->smtpUser), 334);
            $this->smtpCommand($fp, base64_encode($this->smtpPass), 235);
        }

        $fromAddress = $this->from;
        $this->smtpCommand($fp, 'MAIL FROM:<' . $fromAddress . '>', 250);
        $this->smtpCommand($fp, 'RCPT TO:<' . $toEmail . '>', 250);
        $this->smtpCommand($fp, 'DATA', 354);

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = [
            'From: ' . $this->encodeHeader($this->fromName) . ' <' . $fromAddress . '>',
            'To: <' . $toEmail . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit'
        ];

        $message = implode("\r\n", $headers) . "\r\n\r\n" . $bodyHtml;
        $message = str_replace("\r\n.", "\r\n..", $message);
        $this->smtpCommand($fp, $message . "\r\n.", 250);

        $this->smtpCommand($fp, 'QUIT', 221);
        fclose($fp);

        return true;
    }

    private function smtpCommand($fp, $command, $expectedCode) {
        fwrite($fp, $command . "\r\n");
        $response = $this->smtpRead($fp);
        $code = (int)substr($response, 0, 3);
        if ($code !== $expectedCode) {
            fclose($fp);
            throw new Exception("SMTP error: expected {$expectedCode}, got {$code} ({$response})");
        }
        return $response;
    }

    private function smtpRead($fp) {
        $response = '';
        while ($line = fgets($fp, 515)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return trim($response);
    }

    // ──────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────

    private function encodeHeader($value) {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }
}

/**
 * Small helper so the class stays usable if config.php is already included.
 */
function isTruthyMailValue($value) {
    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}


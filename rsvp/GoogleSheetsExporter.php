<?php

class GoogleSheetsExporter {
    private $spreadsheetId;
    private $credentialsPath;
    private $credentials;
    private $accessToken;
    private $tokenExpiry;
    
    public function __construct($spreadsheetId, $credentialsPath) {
        $this->spreadsheetId = $spreadsheetId;
        $this->credentialsPath = $credentialsPath;
        $this->loadCredentials();
        $this->obtainAccessToken();
    }
    
    private function loadCredentials() {
        if (!file_exists($this->credentialsPath)) {
            throw new Exception("Credentials file not found: " . $this->credentialsPath);
        }
        
        $this->credentials = json_decode(file_get_contents($this->credentialsPath), true);
        
        if (!$this->credentials) {
            throw new Exception("Invalid JSON credentials file");
        }
    }
    
    private function obtainAccessToken() {
        $ch = curl_init('https://oauth2.googleapis.com/token');
        
        $postData = [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $this->createJWT()
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("Failed to obtain access token: " . $response);
        }
        
        $tokenData = json_decode($response, true);
        $this->accessToken = $tokenData['access_token'];
        $this->tokenExpiry = time() + $tokenData['expires_in'];
    }
    
    private function createJWT() {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'RS256']);
        $now = time();
        
        $payload = json_encode([
            'iss' => $this->credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now
        ]);
        
        $headerEncoded = $this->base64UrlEncode($header);
        $payloadEncoded = $this->base64UrlEncode($payload);
        $signatureInput = $headerEncoded . '.' . $payloadEncoded;
        
        $signature = '';
        openssl_sign($signatureInput, $signature, $this->credentials['private_key'], 'SHA256');
        $signatureEncoded = $this->base64UrlEncode($signature);
        
        return $signatureInput . '.' . $signatureEncoded;
    }
    
    private function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * Export invitations to Google Sheet
     */
    public function exportInvitations($invitations) {
        try {
            // Prepare headers
            $headers = [
                'Invitation ID',
                'Guest Name',
                'Max Guests',
                'Status'
            ];
            
            // Prepare data rows with decoded HTML entities
            $rows = [$headers];
            foreach ($invitations as $inv) {
                $rows[] = [
                    $inv['invitation_id'],
                    html_entity_decode($inv['guest_name'], ENT_QUOTES, 'UTF-8'),
                    $inv['max_guests'],
                    $inv['status']
                ];
            }
            
            // Clear existing data and write new
            $this->updateSheet('Invitations', $rows);
            
            return [
                'success' => true,
                'message' => count($invitations) . ' invitations exported to Google Sheet',
                'sheetUrl' => $this->getSheetUrl()
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error exporting to Google Sheets: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Export RSVP responses to Google Sheet
     */
    public function exportResponses($responses) {
        try {
            // Prepare headers
            $headers = [
                'Guest Name',
                'Invitation ID',
                'Attending',
                'Guest Count',
                'Guest Names'
            ];
            
            // Prepare data rows with decoded HTML entities
            $rows = [$headers];
            foreach ($responses as $resp) {
                $guestNames = $resp['guest_names'] ?? '';
                if ($guestNames === '' && !empty($resp['special_notes'])) {
                    $guestNames = html_entity_decode($resp['special_notes'], ENT_QUOTES, 'UTF-8');
                }
                $attending = strtolower((string)($resp['attending'] ?? ''));
                $attendingLabel = $attending === 'yes' ? 'Yes' : ($attending === 'no' ? 'No' : ucfirst($attending));

                $rows[] = [
                    html_entity_decode($resp['guest_name'], ENT_QUOTES, 'UTF-8'),
                    $resp['invitation_id'],
                    $attendingLabel,
                    $resp['attendee_count'] ?? 0,
                    $guestNames
                ];
            }
            
            // Clear existing data and write new
            $this->updateSheet('Responses', $rows);
            
            return [
                'success' => true,
                'message' => count($responses) . ' responses exported to Google Sheet',
                'sheetUrl' => $this->getSheetUrl()
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error exporting to Google Sheets: ' . $e->getMessage()
            ];
        }
    }
    
    private function updateSheet($sheetName, $values) {
        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}/values/{$sheetName}!A1:Z?valueInputOption=RAW";
        
        $ch = curl_init($url);
        
        $requestBody = json_encode(['values' => $values]);
        
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $requestBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json'
            ],
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception("Failed to update sheet: " . $response);
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Get the direct URL to the Google Sheet
     */
    public function getSheetUrl() {
        return "https://docs.google.com/spreadsheets/d/{$this->spreadsheetId}/edit";
    }
}

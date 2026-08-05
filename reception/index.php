<?php
/**
 * Reception app — server-side access gate.
 *
 * Guests reach the reception SPA via the QR code, which carries the access key
 * in the URL:  /reception/?key=<RECEPTION_API_KEY>
 *
 * This gate validates that key server-side (timing-safe) BEFORE any of the app
 * HTML is sent. Without a valid key the request is rejected with 403 and the
 * app content is never delivered.
 */

require_once __DIR__ . '/../rsvp/config.php';
require_once __DIR__ . '/../rsvp/EnvironmentLoader.php';

// Force a non-cacheable response so a stale served page can never linger.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$expected = trim((string)EnvironmentLoader::get('RECEPTION_API_KEY', ''));
$provided = trim((string)($_GET['key'] ?? ''));

$valid = $expected !== '' && $provided !== '' && hash_equals($expected, $provided);

if (!$valid) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<title>Access Denied</title>'
        . '<style>'
        . 'body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;'
        . 'background:#1a2e23;color:#e8e0d0;display:flex;align-items:center;'
        . 'justify-content:center;min-height:100vh;margin:0;text-align:center;}'
        . '.card{max-width:22rem;padding:2rem;background:#24382c;border-radius:1rem;'
        . 'box-shadow:0 8px 30px rgba(0,0,0,.35);}'
        . 'h1{font-size:1.5rem;margin:0 0 .5rem;}'
        . 'p{color:#b8b0a0;margin:0;line-height:1.5;}'
        . '</style></head><body>'
        . '<div class="card"><h1>Access Denied</h1>'
        . '<p>Please scan the QR code on your invitation to enter the reception.</p>'
        . '</div></body></html>';
    exit;
}

// Valid key — serve the app entry point.
readfile(__DIR__ . '/app.html');

<?php
$base = 'https://theberberswedding.com';
$paths = [
    '/reception/',
    '/reception/index.html',
    '/reception/index.php',
    '/reception/app.html',
];

foreach ($paths as $p) {
    $url = $base . $p;
    $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header[0])) {
        preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m);
        $status = (int)($m[1] ?? 0);
    }
    $size = is_string($body) ? strlen($body) : 0;
    $firstLine = is_string($body) ? trim(substr(preg_replace('/\s+/', ' ', $body), 0, 120)) : '';
    echo "URL: $url\n  HTTP $status | {$size} bytes\n  Preview: $firstLine\n\n";
}

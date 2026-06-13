<?php
ignore_user_abort(true); // Continue even if caller disconnects
set_time_limit(600);     // Allow up to 10 min for all satellites
require_once 'config.php';

$_isLocalCall = ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1'
             || ($_SERVER['REMOTE_ADDR'] ?? '') === '::1';
if (!$_isLocalCall) {
    require_once __DIR__ . '/auth.php';
    auth_check();
}

header('Content-Type: application/json');

$slug = $_POST['slug'] ?? '';
if (!$slug) {
    echo json_encode(['error' => 'No slug provided']);
    exit;
}

$masterPostDir = __DIR__ . '/posts/' . $slug;
if (!is_dir($masterPostDir)) {
    echo json_encode(['error' => "Post directory not found: $slug"]);
    exit;
}

$satellites = defined('SATELLITE_PROJECTS') ? SATELLITE_PROJECTS : [];
if (empty($satellites)) {
    echo json_encode(['success' => true, 'message' => 'No satellites configured']);
    exit;
}

$results = [];

foreach ($satellites as $satellite) {
    $satUrl = rtrim($satellite['url'], '/');

    // ── Appeler le propagation handler du satellite (même flow que auto-daily-csv) ──
    // Le satellite copie lui-même ses images source, réécrit le contenu avec sa config,
    // et génère son index.html — on n'impose rien depuis le main site.
    $ch = curl_init($satUrl . '/auto-daily-csv.php');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['propagate_slug' => $slug]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 180,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        $results[] = ['satellite' => $satUrl, 'status' => 'error', 'message' => $curlError];
        continue;
    }

    $decoded   = json_decode($response, true);
    $results[] = [
        'satellite' => $satUrl,
        'status'    => ($httpCode === 200 && !empty($decoded['success'])) ? 'ok' : 'error',
        'http_code' => $httpCode,
        'response'  => $decoded ?? substr($response, 0, 200),
    ];
}

echo json_encode(['success' => true, 'slug' => $slug, 'satellites' => $results]);

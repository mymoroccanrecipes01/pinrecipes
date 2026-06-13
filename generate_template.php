<?php

require_once(__DIR__ . '/config.php');

// Relay $_POST directly to generate_pinterest.php
// generate_pinterest.php uses TEMPLATE_CONFIG which reads from $_POST
$ch = curl_init(BASE_URL . 'generate_pinterest.php');

curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $_POST,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
]);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    curl_close($ch);
    echo json_encode(['success' => false, 'error' => curl_error($ch)]);
    exit;
}

curl_close($ch);

$result = json_decode($response, true);

if ($result && isset($result['success']) && $result['success']) {
    echo json_encode([
        'success'      => true,
        'filename'     => $result['filename']     ?? null,
        'url'          => $result['url']          ?? '',
        'path'         => $result['path']         ?? '',
        'pathrelative' => $result['pathrelative'] ?? '',
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error'   => $result['error'] ?? 'Erreur lors de la génération',
    ]);
}

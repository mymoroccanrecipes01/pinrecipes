<?php
/**
 * Endpoint vote visiteur — étoiles recette.
 * POST: slug=<slug>&stars=<1..5>
 * Stocke les votes dans posts/{slug}/ratings.json (1 vote / IP / slug),
 * recalcule le rating effectif (seed + votes) et le persiste dans post.json
 * pour que le schema AggregateRating reste cohérent à la prochaine régénération.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/rating-helpers.php';

$slug  = trim($_POST['slug'] ?? '');
$stars = (int)($_POST['stars'] ?? 0);

// Validation
if ($slug === '' || !preg_match('/^[a-z0-9\-]+$/i', $slug)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'slug invalide']);
    exit;
}
if ($stars < 1 || $stars > 5) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'stars hors limite (1-5)']);
    exit;
}

$postDir  = __DIR__ . '/posts/' . $slug;
$postJson = $postDir . '/post.json';
if (!is_dir($postDir) || !is_file($postJson)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'post introuvable']);
    exit;
}

$ratingsPath = $postDir . '/ratings.json';

// Charger les votes existants
$store = ['votes' => []];
if (is_file($ratingsPath)) {
    $decoded = json_decode((string)file_get_contents($ratingsPath), true);
    if (is_array($decoded) && isset($decoded['votes']) && is_array($decoded['votes'])) {
        $store = $decoded;
    }
}

// Anti-spam léger : 1 vote / IP (hashée) / slug
$ipHash = substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $slug), 0, 16);
foreach ($store['votes'] as $v) {
    if (($v['ip'] ?? '') === $ipHash) {
        // Déjà voté → renvoyer l'état actuel sans double comptage
        $current = rating_compute($slug, $ratingsPath);
        echo json_encode(['success' => true, 'value' => $current['value'], 'count' => $current['count'], 'already' => true]);
        exit;
    }
}

// Enregistrer le vote
$store['votes'][] = ['ip' => $ipHash, 'stars' => $stars, 'ts' => date('c')];
file_put_contents($ratingsPath, json_encode($store, JSON_PRETTY_PRINT));

// Recalculer (seed déterministe + votes) + persister le cache dans post.json
$computed = rating_compute($slug, $ratingsPath);

$postData = json_decode((string)file_get_contents($postJson), true);
if (is_array($postData)) {
    $postData['rating'] = ['value' => $computed['value'], 'count' => $computed['count']];
    file_put_contents($postJson, json_encode($postData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

echo json_encode(['success' => true, 'value' => $computed['value'], 'count' => $computed['count']]);

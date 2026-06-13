<?php
/**
 * Script one-shot — ajoute un rating seeded à tous les posts existants qui n'en ont pas.
 * Usage CLI:  php backfill-ratings.php
 * Usage web:  backfill-ratings.php?run=1   (admin requis)
 *
 * Le rating est déterministe par slug (rating_seed). Les posts qui ont déjà un `rating`
 * (incluant d'éventuels votes visiteurs) ne sont pas touchés.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rating-helpers.php';

$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    require_once __DIR__ . '/auth.php';
    auth_check();
    if (!auth_is_admin())            { http_response_code(403); exit("Accès refusé.\n"); }
    if (($_GET['run'] ?? '') !== '1') { exit("Ajoute ?run=1 pour exécuter le backfill.\n"); }
}

$postsDir = __DIR__ . '/posts';
$dirs = is_dir($postsDir) ? glob($postsDir . '/*', GLOB_ONLYDIR) : [];

$updated = 0; $skipped = 0; $errors = 0;

foreach ($dirs as $dir) {
    $jsonPath = $dir . '/post.json';
    if (!is_file($jsonPath)) continue;

    $data = json_decode((string)file_get_contents($jsonPath), true);
    if (!is_array($data)) { $errors++; continue; }

    if (isset($data['rating']['value'], $data['rating']['count'])) {
        $skipped++;
        continue;
    }

    $slug = $data['slug'] ?? basename($dir);
    $data['rating'] = rating_seed($slug);

    $ok = file_put_contents($jsonPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($ok === false) { $errors++; continue; }

    $updated++;
    echo "  ✓ $slug → {$data['rating']['value']}★ ({$data['rating']['count']} ratings)\n";
}

echo "\n=== Backfill terminé: $updated mis à jour, $skipped déjà OK, $errors erreurs ===\n";
echo "Note: régénère le HTML des posts pour propager l'AggregateRating dans le schema.\n";

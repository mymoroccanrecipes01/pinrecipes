<?php
/**
 * Rating helpers — étoiles seeded + votes visiteurs (file-based).
 * Utilisé par: posts-api.php (seed à la création), generate-single-post.php (schema + widget),
 * rate-post.php (vote visiteur), backfill-ratings.php (posts existants).
 */

if (!defined('RATING_HELPERS_INCLUDED')) {
    define('RATING_HELPERS_INCLUDED', true);
}

/**
 * Rating initial déterministe par slug (stable entre runs).
 * value ∈ [4.5, 4.9], count ∈ [15, 60].
 * @return array{value: float, count: int}
 */
function rating_seed(string $slug): array {
    $h = abs(crc32($slug));
    $value = 4.5 + (($h % 5) / 10.0);          // 4.5, 4.6, 4.7, 4.8, 4.9
    $count = 15 + (($h >> 3) % 46);            // 15..60
    return ['value' => round($value, 1), 'count' => $count];
}

/**
 * Rating effectif = seed déterministe (par slug) + votes visiteurs de ratings.json.
 * Le seed est TOUJOURS recalculé par slug pour éviter tout double comptage.
 * Le champ `rating` de post.json n'est qu'un cache d'affichage, jamais ré-injecté ici.
 * @return array{value: float, count: int}
 */
function rating_compute(string $slug, string $ratingsJsonPath): array {
    $seed = rating_seed($slug);

    $votes = [];
    if (is_file($ratingsJsonPath)) {
        $data  = json_decode((string)file_get_contents($ratingsJsonPath), true);
        $votes = is_array($data['votes'] ?? null) ? $data['votes'] : [];
    }

    $sumSeed   = $seed['value'] * $seed['count'];
    $sumVotes  = 0;
    foreach ($votes as $v) { $sumVotes += (int)($v['stars'] ?? 0); }
    $totalCount = $seed['count'] + count($votes);
    if ($totalCount <= 0) return $seed;

    $value = ($sumSeed + $sumVotes) / $totalCount;
    $value = max(1.0, min(5.0, $value)); // borne réaliste pour rich snippets
    return ['value' => round($value, 1), 'count' => $totalCount];
}

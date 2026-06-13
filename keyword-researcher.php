<?php
/**
 * Keyword Researcher — Génère des titres de recettes SEO basés sur Google Trends
 * Usage CLI: php keyword-researcher.php [--count=20]
 * Ou inclus depuis auto-pipeline.php
 */

if (!defined('KEYWORD_RESEARCHER_INCLUDED')) {
    define('KEYWORD_RESEARCHER_INCLUDED', true);
}

require_once __DIR__ . '/config.php';

$isCli = php_sapi_name() === 'cli';
set_time_limit(0);

$QUEUE_FILE      = __DIR__ . '/keywords-queue.json';
$POSTS_INDEX     = __DIR__ . '/posts/index.json';
$CAT_INDEX       = __DIR__ . '/categories/index.json';
$GENERATE_COUNT  = 20; // Nombre de titres à générer par run

// Lire --count= depuis argv
if ($isCli) {
    foreach ($argv as $arg) {
        if (preg_match('/^--count=(\d+)$/', $arg, $m)) {
            $GENERATE_COUNT = (int)$m[1];
        }
    }
}

function kr_log($msg) {
    global $isCli;
    if ($isCli) echo "  [KR] $msg\n";
}

// ── 1. Charger la queue existante ────────────────────────────────────────────
$queue = ['pending' => [], 'processed' => [], 'last_generated' => null];
if (file_exists($QUEUE_FILE)) {
    $queue = json_decode(file_get_contents($QUEUE_FILE), true) ?: $queue;
}
$processedSlugs = $queue['processed'] ?? [];

// ── 2. Charger les slugs existants depuis posts/index.json ───────────────────
$existingSlugs = [];
if (file_exists($POSTS_INDEX)) {
    $idx = json_decode(file_get_contents($POSTS_INDEX), true);
    $existingSlugs = $idx['folders'] ?? [];
}
// Ajouter aussi les pending déjà dans la queue (éviter doublons)
foreach ($queue['pending'] as $p) {
    $pendingSlug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $p['title'] ?? ''), '-'));
    $existingSlugs[] = $pendingSlug;
}
$allKnownSlugs = array_unique(array_merge($existingSlugs, $processedSlugs));

// ── 3. Charger les catégories disponibles ────────────────────────────────────
$categories = [];
if (file_exists($CAT_INDEX)) {
    $catIdx = json_decode(file_get_contents($CAT_INDEX), true);
    if (!empty($catIdx['folders'])) {
        $categories = array_keys($catIdx['folders']);
    }
}
if (empty($categories)) {
    // Fallback si index.json des catégories n'a pas le bon format
    foreach (glob(__DIR__ . '/categories/*/category.json') as $f) {
        $categories[] = basename(dirname($f));
    }
}

// ── 4. Fetch Google Trends RSS (food category, US) ───────────────────────────
kr_log("Fetching Google Trends RSS...");
$trendingKeywords = [];
$trendsUrl = 'https://trends.google.com/trends/trendingsearches/daily/rss?geo=US';
$ch = curl_init($trendsUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_FOLLOWLOCATION => true,
]);
$rssContent = curl_exec($ch);
$rssCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($rssCode === 200 && $rssContent) {
    // Extraire les <title> des items (skip le premier qui est le titre du feed)
    preg_match_all('/<title><!\[CDATA\[(.*?)\]\]><\/title>|<title>(.*?)<\/title>/s', $rssContent, $matches);
    $allTitles = array_filter(array_map(
        fn($a, $b) => trim($a ?: $b),
        $matches[1], $matches[2]
    ));
    $allTitles = array_values(array_slice($allTitles, 1, 30)); // skip feed title
    $trendingKeywords = $allTitles;
    kr_log("Google Trends: " . count($trendingKeywords) . " trending topics récupérés");
} else {
    kr_log("Google Trends indisponible (HTTP $rssCode), utilisation de la saison courante seulement");
}

// ── 5. Déterminer la saison actuelle ─────────────────────────────────────────
$month = (int)date('n');
if ($month >= 3 && $month <= 5)       $season = 'Spring';
elseif ($month >= 6 && $month <= 8)   $season = 'Summer';
elseif ($month >= 9 && $month <= 11)  $season = 'Fall';
else                                   $season = 'Winter';

// ── 6. Construire le prompt OpenAI ───────────────────────────────────────────
$existingSample = array_slice($allKnownSlugs, -50); // 50 slugs récents comme contexte
$existingList   = implode(', ', $existingSample);
$trendsStr      = !empty($trendingKeywords) ? implode(', ', array_slice($trendingKeywords, 0, 20)) : 'no specific trends available';
$catList        = implode(', ', $categories);
$currentDate    = date('Y-m-d');

$systemPrompt = defined('KEYWORD_SYSTEM_PROMPT') && KEYWORD_SYSTEM_PROMPT
    ? KEYWORD_SYSTEM_PROMPT
    : "You are an SEO expert for a food recipe blog. You MUST return ONLY valid JSON, no markdown, no text before or after.";

$userPromptTemplate = defined('KEYWORD_USER_PROMPT') && KEYWORD_USER_PROMPT
    ? KEYWORD_USER_PROMPT
    : "Generate exactly {COUNT} unique, SEO-optimized recipe blog post titles for a food blog.\n\nContext:\n- Date: {DATE}\n- Season: {SEASON}\n- Currently trending topics (use as inspiration for food angles): {TRENDS}\n- Available categories: {CATEGORIES}\n- Already published slugs (DO NOT repeat similar topics): {EXISTING}\n\nRequirements:\n- Each title must be 6-12 words\n- Include high-traffic keywords naturally (e.g. \"easy\", \"homemade\", \"creamy\", \"30-minute\", \"best\")\n- Mix of: quick meals, desserts, dinner ideas, comfort food, healthy options, seasonal recipes\n- Specific titles (not generic like \"Chicken Recipe\" — bad; \"Creamy Garlic Butter Chicken Thighs in 30 Minutes\" — good)\n- Capitalize properly as blog post titles\n\nReturn ONLY this JSON:\n{\n  \"titles\": [\n    \"Title One Here\",\n    \"Title Two Here\"\n  ]\n}";

$userPrompt = str_replace(
    ['{COUNT}', '{DATE}', '{SEASON}', '{TRENDS}', '{CATEGORIES}', '{EXISTING}'],
    [$GENERATE_COUNT, $currentDate, $season, $trendsStr, $catList, $existingList],
    $userPromptTemplate
);

// ── 7. Appel OpenAI ──────────────────────────────────────────────────────────
kr_log("Calling OpenAI to generate $GENERATE_COUNT recipe titles...");
$apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
$model  = defined('OPENAI_CONTENT_MODEL') ? OPENAI_CONTENT_MODEL : 'gpt-4o-mini';

$payload = [
    'model'           => $model,
    'messages'        => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user',   'content' => $userPrompt],
    ],
    'max_tokens'      => 2000,
    'temperature'     => 0.8,
    'response_format' => ['type' => 'json_object'],
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    kr_log("ERREUR OpenAI HTTP $httpCode: $response");
    return 0;
}

$resp   = json_decode($response, true);
$text   = $resp['choices'][0]['message']['content'] ?? '';
$parsed = json_decode($text, true);

if (empty($parsed['titles']) || !is_array($parsed['titles'])) {
    kr_log("ERREUR: Réponse OpenAI invalide: $text");
    return 0;
}

$newTitles = $parsed['titles'];
kr_log("OpenAI a généré " . count($newTitles) . " titres");

// ── 8. Filtrer les doublons et ajouter à la queue ────────────────────────────
$added = 0;
$now   = date('Y-m-d H:i:s');

foreach ($newTitles as $title) {
    $title = trim($title);
    if (empty($title)) continue;

    // Générer le slug pour vérifier doublon
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));

    // Vérifier si déjà connu
    $isDuplicate = false;
    foreach ($allKnownSlugs as $known) {
        similar_text($slug, $known, $pct);
        if ($pct > 75) { $isDuplicate = true; break; }
    }
    if ($isDuplicate) {
        kr_log("Skip doublon: $title");
        continue;
    }

    $queue['pending'][] = [
        'title'    => $title,
        'added_at' => $now,
    ];
    $allKnownSlugs[] = $slug; // éviter doublons dans cette même batch
    $added++;
}

$queue['last_generated'] = $now;

// ── 9. Sauvegarder la queue ──────────────────────────────────────────────────
file_put_contents($QUEUE_FILE, json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
kr_log("$added nouveaux titres ajoutés à la queue. Total pending: " . count($queue['pending']));

if ($isCli) {
    echo "\n  Titres ajoutés:\n";
    foreach (array_slice($queue['pending'], -$added) as $p) {
        echo "    - " . $p['title'] . "\n";
    }
    echo "\n";
}

return $added;

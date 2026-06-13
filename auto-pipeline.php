<?php
/**
 * Auto Pipeline — Orchestrateur 100% automatisé
 * Étape 1: Recherche keywords trending (keyword-researcher.php)
 * Étape 2: Génération posts via posts-api.php?action=analyze + sauvegarde directe
 *
 * Usage CLI: php auto-pipeline.php [--limit=10] [--research-only] [--generate-only]
 * Windows Task Scheduler: run-pipeline.bat
 */

$isCli = php_sapi_name() === 'cli';
set_time_limit(0);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/config.php';

// ── Paramètres CLI ───────────────────────────────────────────────────────────
$settings     = json_decode(file_get_contents(__DIR__ . '/settings.json'), true) ?: [];
$limitMin = (int)($settings['pipelineLimitMin'] ?? 5);
$limitMax = (int)($settings['pipelineLimitMax'] ?? 15);
$limit    = rand($limitMin, $limitMax);

if ($isCli) {
    foreach ($argv as $arg) {
        if (preg_match('/^--limit=(\d+)$/', $arg, $m)) $limit = (int)$m[1];
    }
}

// ── Paths ────────────────────────────────────────────────────────────────────
$LOG_FILE     = __DIR__ . '/downloads/pipeline-log.json';
$PIPELINE_LOG = __DIR__ . '/processing.log';
$POSTS_DIR      = __DIR__ . '/posts';
$BASE_URL_LOCAL = BASE_URL; // Dérivé automatiquement depuis config.php

// ── Helpers ──────────────────────────────────────────────────────────────────
function plog($msg, $level = 'INFO') {
    global $isCli, $PIPELINE_LOG;
    $ts   = date('Y-m-d H:i:s');
    $line = "[$ts] [PIPELINE] [$level] $msg\n";
    if (!is_dir(dirname($PIPELINE_LOG))) mkdir(dirname($PIPELINE_LOG), 0755, true);
    file_put_contents($PIPELINE_LOG, $line, FILE_APPEND);
    if ($isCli) echo $line;
}

function loadQueue($file) {
    if (!file_exists($file)) return ['pending' => [], 'processed' => [], 'last_generated' => null];
    return json_decode(file_get_contents($file), true) ?: ['pending' => [], 'processed' => [], 'last_generated' => null];
}

function saveQueue($file, $queue) {
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0755, true);
    file_put_contents($file, json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * Convertir un titre en slug
 */
function makeSlug($title) {
    $slug = strtolower($title);
    $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
    $slug = preg_replace('/[\s-]+/', '-', $slug);
    return trim($slug, '-');
}

/**
 * Générer un slug unique (éviter doublons dans posts/)
 */
function uniqueSlug($title, $postsDir) {
    $base    = makeSlug($title);
    $slug    = $base;
    $counter = 1;
    while (is_dir($postsDir . '/' . $slug)) {
        $slug = $base . '-' . $counter++;
    }
    return $slug;
}

/**
 * Appel HTTP GET/POST vers localhost
 */
function httpCall($url, $postData = null, $jsonBody = null, $timeoutSec = 30) {
    $ch = curl_init($url);
    $headers = ['X-CLI-Secret: ' . (defined('CLI_SECRET') ? CLI_SECRET : '')];

    if ($jsonBody !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        $headers[] = 'Content-Type: application/json';
    } elseif ($postData !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeoutSec,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) return ['success' => false, 'error' => "cURL: $curlErr"];
    if ($httpCode !== 200) return ['success' => false, 'error' => "HTTP $httpCode: " . substr($response, 0, 300)];

    $json = json_decode($response, true);
    if ($json === null) return ['success' => false, 'error' => 'Non-JSON: ' . substr($response, 0, 300)];
    return $json;
}

/**
 * POST via curl binary (contourne les bugs PHP curl CLI sur Linux/HTTPS)
 */
function curlBinaryPost($url, array $data, $bearerToken, $timeout = 90) {
    if (!function_exists('shell_exec')) {
        plog('  ⚠️ DEBUG: shell_exec désactivé', 'WARNING');
        return null;
    }
    $curlBin = trim(shell_exec('which curl 2>/dev/null') ?: '');
    if (!$curlBin) {
        plog('  ⚠️ DEBUG: curl binary introuvable', 'WARNING');
        return null;
    }
    plog("  🔧 DEBUG: curl=$curlBin timeout={$timeout}s");
    $cmd = sprintf(
        '%s -s --max-time %d --connect-timeout 30 --http1.1 -X POST %s -H %s -H %s -d %s 2>&1',
        $curlBin,
        (int)$timeout,
        escapeshellarg($url),
        escapeshellarg('Content-Type: application/json'),
        escapeshellarg('Authorization: Bearer ' . $bearerToken),
        escapeshellarg(json_encode($data))
    );
    plog("  🔧 DEBUG: lancement curl...");
    $output = shell_exec($cmd);
    plog("  🔧 DEBUG: curl terminé, réponse=" . strlen((string)$output) . " octets");
    if ($output !== null && $output !== '') {
        $firstChars = substr($output, 0, 120);
        plog("  🔧 DEBUG: début réponse: " . str_replace("\n", ' ', $firstChars));
    }
    return ($output !== null && $output !== '') ? $output : null;
}

/**
 * GET via curl binary
 */
function curlBinaryGet($url, $timeout = 60) {
    $curlBin = trim(shell_exec('which curl 2>/dev/null') ?: '/usr/bin/curl');
    $cmd = sprintf(
        '%s -s --max-time %d --connect-timeout 30 --http1.1 %s 2>/dev/null',
        $curlBin, (int)$timeout, escapeshellarg($url)
    );
    $output = shell_exec($cmd);
    return ($output !== null && $output !== '') ? $output : null;
}

/**
 * Générer 3 images AI via OpenAI et les sauvegarder dans posts/{slug}/images/
 */
function generateImages($postTitle, $slug, $postsDir) {
    $apiKey   = defined('OPENAI_API_KEY')     ? OPENAI_API_KEY     : '';
    $model    = defined('OPENAI_IMAGE_MODEL') ? OPENAI_IMAGE_MODEL : 'dall-e-3';
    $quality  = defined('OPENAI_IMAGE_QUALITY') ? OPENAI_IMAGE_QUALITY : 'standard';
    $size     = defined('OPENAI_IMAGE_SIZE')  ? OPENAI_IMAGE_SIZE  : '1024x1024';

    $imagesDir = $postsDir . '/' . $slug . '/images';
    if (!is_dir($imagesDir)) mkdir($imagesDir, 0755, true);

    // Utiliser IMG_PROMPT_1/2/3 du config si définis, sinon fallback
    $cfgP1 = (defined('IMG_PROMPT_1') && IMG_PROMPT_1) ? str_replace('{title}', $postTitle, IMG_PROMPT_1) : null;
    $cfgP2 = (defined('IMG_PROMPT_2') && IMG_PROMPT_2) ? str_replace('{title}', $postTitle, IMG_PROMPT_2) : null;
    $cfgP3 = (defined('IMG_PROMPT_3') && IMG_PROMPT_3) ? str_replace('{title}', $postTitle, IMG_PROMPT_3) : null;

    $prompts = [
        // Image 1 — Vue overhead légèrement inclinée, plat complet visible
        $cfgP1 ?? "Stunning overhead 15-degree tilt food photography of {$postTitle} — the exact finished dish fully plated and ready to eat, showing every detail: the texture of the surface, the color contrast of ingredients, any sauce or glaze catching the light. Placed on a white marble surface with a linen napkin and rustic wooden spoon beside it. Bright soft natural daylight, no shadows. Ultra-sharp, every ingredient crisp and identifiable. Vibrant natural colors, hyper-realistic, not AI-looking. Portrait format, scroll-stopping Pinterest photo.",

        // Image 2 — 45° angle, focus on the most appetizing part
        $cfgP2 ?? "Close-up 45-degree angle food photography of {$postTitle} — the complete plated dish on a wide shallow white ceramic plate on a light oak wooden table. Shot to highlight the most appetizing detail: glistening sauce, golden crust, creamy texture, melting cheese, or juicy layers — whatever makes this specific dish irresistible. Warm soft side lighting creating natural depth and shadows. Beautiful bokeh background, razor-sharp focus on the food. Hyper-realistic, mouthwatering, fine-dining quality. Pinterest portrait.",

        // Image 3 — Eye-level hero shot, drama et profondeur
        $cfgP3 ?? "Eye-level hero shot of {$postTitle} — the identical finished dish plated on a dark matte slate plate on a dark walnut wood surface. Camera at dish height, creating dramatic depth. The dish fills 85% of the frame. Moody warm side light from left creating rich highlights and deep shadows that emphasize texture — caramelized edges, glistening glaze, vibrant herbs on top, steam-free crisp finish. Extreme detail: every grain, every drizzle, every garnish visible. Ultra-realistic, magazine-cover quality, irresistible. Portrait format.",
    ];

    $savedImages  = [];
    $totalImgCost = 0.0;
    $imgFallback  = defined('OPENAI_IMAGE_COST') ? (float)OPENAI_IMAGE_COST : 0.015;
    $imgPricing   = ['gpt-image-1' => ['in' => 5.0, 'out' => 40.0], 'gpt-image-1-mini' => ['in' => 2.0, 'out' => 8.0]];

    // ── Générer les images une par une (séquentiel — plus fiable sur Linux CLI) ─
    foreach ($prompts as $i => $prompt) {
        $n        = $i + 1;
        $fileName = $slug . '_pinrecipes_image_' . $n . '.webp';
        $filePath = $imagesDir . '/' . $fileName;

        plog("  🖼️  Image $n/" . count($prompts) . " en cours...");

        $data     = ['model' => $model, 'prompt' => $prompt, 'quality' => $quality, 'size' => $size, 'n' => 1];
        $response = curlBinaryPost('https://api.openai.com/v1/images/generations', $data, $apiKey, 90);

        if ($response === null) {
            plog("  ⚠️ Image $n timeout ou erreur", 'WARNING');
            continue;
        }

        plog("  🔧 DEBUG: json_decode...");
        $result = json_decode($response, true);
        plog("  🔧 DEBUG: json_decode OK, keys=" . implode(',', array_keys($result ?? [])));

        $savedRaw = false;
        $imgData  = null;
        if (isset($result['data'][0]['b64_json'])) {
            plog("  🔧 DEBUG: base64_decode...");
            $imgData = base64_decode($result['data'][0]['b64_json']);
        } elseif (isset($result['data'][0]['url'])) {
            plog("  🔧 DEBUG: téléchargement URL...");
            $imgData = curlBinaryGet($result['data'][0]['url'], 60);
        } else {
            plog("  ⚠️ Image $n échouée: " . ($result['error']['message'] ?? json_encode(array_keys($result ?? []))), 'WARNING');
            continue;
        }

        if ($imgData) {
            plog("  🔧 DEBUG: source " . strlen($imgData) . " bytes → conversion WebP...");
            // Convertir en WebP (allège git + Cloudflare : PNG ~2.5MB → webp ~300KB).
            // $filePath garde l'extension .webp définie plus haut.
            $img = @imagecreatefromstring($imgData);
            if ($img !== false) {
                $savedRaw = imagewebp($img, $filePath, 82);
                imagedestroy($img);
                if ($savedRaw) {
                    plog("  🔧 DEBUG: WebP OK → $fileName (" . (int)(filesize($filePath) / 1024) . " KB)");
                }
            }
            if (!$savedRaw) {
                // Fallback : GD indisponible → sauvegarder le binaire brut avec la bonne extension
                $magic = substr($imgData, 0, 4);
                if ($magic === "\x89PNG") {
                    $fileName = $slug . '_pinrecipes_image_' . $n . '.png';
                    $filePath = $imagesDir . '/' . $fileName;
                }
                $savedRaw = (file_put_contents($filePath, $imgData) !== false);
                plog("  🔧 DEBUG: fallback brut " . ($savedRaw ? 'OK' : 'ÉCHOUÉE') . " → $fileName");
            }
        }

        if (!$savedRaw) { continue; }

        $savedImages[$i] = [
            'fileName'     => $fileName,
            'filePath'     => 'posts/' . $slug . '/images/' . $fileName,
            'relativePath' => $slug . '/images/' . $fileName,
            'originalUrl'  => 'openai-generated',
            'order'        => $n,
            'type'         => 'main',
        ];
        $usage   = $result['usage'] ?? [];
        $pricing = $imgPricing[$model] ?? null;
        if ($pricing && ($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0) > 0) {
            $totalImgCost += ($usage['input_tokens'] / 1_000_000) * $pricing['in']
                           + ($usage['output_tokens'] / 1_000_000) * $pricing['out'];
        } else {
            $totalImgCost += $imgFallback;
        }
        plog("  🖼️  Image $n générée: $fileName");
    }

    ksort($savedImages);
    return ['images' => array_values($savedImages), 'cost' => $totalImgCost];
}

/**
 * Générer et sauvegarder un post complet depuis un titre
 */
function generateAndSavePost($title, $postsDir, $baseUrl) {
    // ── 1. Appel posts-api.php?action=analyze (vraie API JSON) ──────────────
    $analyzeUrl = $baseUrl . 'posts-api.php?action=analyze';
    $result     = httpCall($analyzeUrl, null, json_encode(['source_text' => $title]), 300);
    $textCost   = (float)($result['cost'] ?? 0.0);

    if (empty($result['success']) || empty($result['data'])) {
        return ['success' => false, 'error' => $result['error'] ?? 'Pas de données retournées'];
    }

    $postData = $result['data'];
    $slug     = uniqueSlug($postData['title'] ?? $title, $postsDir);
    $postDir  = $postsDir . '/' . $slug;

    if (!mkdir($postDir, 0755, true) && !is_dir($postDir)) {
        return ['success' => false, 'error' => "Impossible créer dossier: $postDir"];
    }

    // ── 2. Générer les images AI ─────────────────────────────────────────────
    plog("  Génération images AI...");
    $imgResult = generateImages($postData['title'] ?? $title, $slug, $postsDir);
    $images    = $imgResult['images'];
    $imgCost   = $imgResult['cost'];
    $totalCost = $textCost + $imgCost;
    plog(sprintf("  💰 Coût: texte=\$%.4f | images=\$%.4f | total=\$%.4f", $textCost, $imgCost, $totalCost));

    // ── 3. Sauvegarder post.json ─────────────────────────────────────────────
    $post = array_merge($postData, [
        'id'                  => 'post_' . time() . '_' . rand(100, 999),
        'slug'                => $slug,
        'uniqueSlug'          => $slug,
        'isOnline'            => true,
        'images'              => $images,
        'image'               => !empty($images) ? $images[0]['fileName'] : '',
        'image_path'          => !empty($images) ? $images[0]['filePath'] : '',
        'image_dir'           => $slug . '/images',
        'generated_from_text' => true,
        'has_rich_structure'  => true,
        'createdAt'           => date('Y-m-d\TH:i:sP'),
        'updatedAt'           => date('Y-m-d\TH:i:sP'),
        'generation_metadata' => [
            'source'     => 'auto-pipeline',
            'generated'  => date('Y-m-d H:i:s'),
            'title_seed' => $title,
        ],
    ]);

    $postFile = $postDir . '/post.json';
    if (file_put_contents($postFile, json_encode($post, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
        return ['success' => false, 'error' => "Impossible écrire $postFile"];
    }
    plog("  post.json sauvegardé");

    // ── 4. Générer HTML — même approche que button "Regenerate HTML posts" dans config
    plog("  Génération HTML...");
    if (!class_exists('PostHTMLGenerator')) {
        if (!defined('POST_HTML_FUNCTIONS_ONLY')) define('POST_HTML_FUNCTIONS_ONLY', true);
        require_once __DIR__ . '/generate-single-post.php';
    }
    if (class_exists('PostHTMLGenerator')) {
        try {
            $gen = new PostHTMLGenerator(__DIR__ . '/posts/' . $slug . '/post.json');
            $gen->saveFile(__DIR__ . '/posts/' . $slug . '/index.html');
            plog("  ✅ HTML généré: posts/$slug/index.html");
        } catch (Throwable $__e) {
            plog("  ⚠️ HTML non généré: " . $__e->getMessage(), 'WARNING');
        }
    } else {
        plog("  ⚠️ PostHTMLGenerator class non trouvée", 'WARNING');
    }

    return ['success' => true, 'slug' => $slug, 'title' => $post['title'], 'images' => count($images), 'cost' => $totalCost];
}


/**
 * Propager tous les slugs générés vers tous les satellites en parallèle (curl_multi).
 * Au lieu de: slug1→sat1, slug1→sat2, slug2→sat1... (séquentiel, jusqu'à 300s × N)
 * On fait:    toutes les combinaisons slugs×satellites lancées simultanément.
 */
function propagateAllToSatellites(array $slugs) {
    $satellites = defined('SATELLITE_PROJECTS') ? SATELLITE_PROJECTS : [];
    if (empty($satellites) || empty($slugs)) return;

    $multi   = curl_multi_init();
    $handles = []; // ['ch' => resource, 'slug' => string, 'satName' => string]

    foreach ($slugs as $slug) {
        foreach ($satellites as $sat) {
            $satUrl   = rtrim($sat['url'], '/');
            $satName  = basename(str_replace('\\', '/', $sat['path']));

            // Route via 127.0.0.1 so REMOTE_ADDR = 127.0.0.1 → satellite auth bypass works
            $parsedSat  = parse_url($satUrl);
            $satHost    = $parsedSat['host'] ?? '127.0.0.1';
            $satPath    = $parsedSat['path'] ?? '';
            $localEndpoint = 'http://127.0.0.1' . $satPath . '/auto-daily-csv.php';

            $ch = curl_init($localEndpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'satellite'      => '1',
                    'propagate_slug' => $slug,
                    'src_posts_dir'  => __DIR__ . '/posts',
                ]),
                CURLOPT_TIMEOUT        => 300,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_POSTREDIR      => 3,
                CURLOPT_HTTPHEADER     => ['Host: ' . $satHost],
                CURLOPT_NOSIGNAL       => 1,
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[] = ['ch' => $ch, 'slug' => $slug, 'satName' => $satName];
            plog("  📡 Propagation lancée → $satName ($slug)");
        }
    }

    // Attendre que toutes les propagations terminent
    $running = null;
    do {
        curl_multi_exec($multi, $running);
        if ($running) curl_multi_select($multi, 5.0);
    } while ($running > 0);

    // Lire les résultats
    foreach ($handles as $item) {
        $ch      = $item['ch'];
        $slug    = $item['slug'];
        $satName = $item['satName'];

        $response = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);

        $result = json_decode($response, true);
        if ($httpCode === 200 && ($result['success'] ?? false)) {
            $rw = ($result['rewritten'] ?? false) ? ' (rewrite ✅)' : ' (copy only)';
            plog("  ✅ $satName: $slug$rw");
        } else {
            plog("  ⚠️ $satName: $slug — " . ($result['message'] ?? "HTTP $httpCode"), 'WARNING');
        }
    }

    curl_multi_close($multi);

    // ── Phase 2 : déclencher templates + isOnline sur chaque satellite ──────────
    // On appelle auto-daily-csv.php du satellite avec satellite=1 (sans propagate_slug)
    // → il traite tous les posts isOnline=false de son propre posts/ et génère les templates
    $satsDone = [];
    foreach ($satellites as $sat) {
        $satUrl  = rtrim($sat['url'], '/');
        $satName = basename(str_replace('\\', '/', $sat['path']));
        if (in_array($satUrl, $satsDone)) continue;
        $satsDone[] = $satUrl;

        $parsedSat     = parse_url($satUrl);
        $satHost       = $parsedSat['host'] ?? '127.0.0.1';
        $satPath       = $parsedSat['path'] ?? '';
        $localEndpoint = 'http://127.0.0.1' . $satPath . '/auto-daily-csv.php';

        plog("  🎨 Templates+isOnline → $satName...");
        $ch = curl_init($localEndpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => 'satellite=1',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POSTREDIR      => 3,
            CURLOPT_HTTPHEADER     => ['Host: ' . $satHost],
            CURLOPT_NOSIGNAL       => 1,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($resp, true);
        if ($code === 200 && ($data['success'] ?? false)) {
            plog("  ✅ $satName: " . ($data['count'] ?? 0) . " articles, " . ($data['rows'] ?? 0) . " pins");
        } else {
            plog("  ⚠️ $satName templates: HTTP $code — " . ($data['message'] ?? mb_substr($resp ?? '', 0, 80)), 'WARNING');
        }
    }
}

// ── Démarrage ────────────────────────────────────────────────────────────────
plog("=== Pipeline démarré | limit=$limit ===");
if (!is_dir(__DIR__ . '/downloads')) mkdir(__DIR__ . '/downloads', 0755, true);

// ── Guard: CSV du jour déjà généré avec suffisamment de pins → skip ──────────
$_csvGuardMin = defined('CSV_GUARD_MIN_ROWS') ? (int)CSV_GUARD_MIN_ROWS : 5;
if ($_csvGuardMin > 0) {
    $_todayCsv = __DIR__ . '/downloads/pinterest_' . date('Y-m-d') . '.csv';
    if (file_exists($_todayCsv)) {
        $_csvRows = max(0, count(file($_todayCsv, FILE_SKIP_EMPTY_LINES)) - 1); // -1 pour le header
        if ($_csvRows > $_csvGuardMin) {
            plog("⏭️  CSV du jour déjà présent ($_csvRows lignes > seuil $_csvGuardMin) — pipeline ignoré pour aujourd'hui.");
            exit(0);
        }
        plog("⚠️  CSV du jour trouvé mais seulement $_csvRows lignes (seuil: $_csvGuardMin) — pipeline continue.", 'WARNING');
    }
}

$stats      = [
    'date'                => date('Y-m-d H:i:s'),
    'generated'           => 0,
    'errors'              => 0,
    'titles'              => [],
    'error_list'          => [],
    'keyword_source_used' => defined('KEYWORD_SOURCE') ? KEYWORD_SOURCE : 'prompt',
];
$totalCost  = 0.0;

// ── Génération Pinterest CSV (keywords + titres via OpenAI) ─────────────────
if (!defined('PINTEREST_KR_INCLUDED')) {
    require_once __DIR__ . '/pinterest-keyword-researcher.php';
}

// Lire la source effective utilisée (écrite par pkr_fetchSmartAuto si source='auto')
$_kwSidecar = __DIR__ . '/downloads/last-keyword-source.json';
if (file_exists($_kwSidecar)) {
    $_kwInfo = json_decode(file_get_contents($_kwSidecar), true) ?: [];
    if (!empty($_kwInfo['source']) && strtotime($_kwInfo['date'] ?? '') >= strtotime('today')) {
        $stats['keyword_source_used'] = $_kwInfo['source'];
    }
}

// ── Lecture CSV depuis chemin fixé ───────────────────────────────────────────
$_keywordsDir = defined('KEYWORDS_PIN_DIR') ? KEYWORDS_PIN_DIR : (__DIR__ . '/../keywordsPIN');
// Resolve any '..' so glob() works correctly on Linux
if ($_keywordsDir) $_keywordsDir = realpath($_keywordsDir) ?: $_keywordsDir;

plog("DEBUG CSV dir: " . var_export($_keywordsDir, true));
plog("DEBUG is_dir: " . var_export(($_keywordsDir ? is_dir($_keywordsDir) : false), true));

$batch = [];
$_csvBasename = '';

if ($_keywordsDir && is_dir($_keywordsDir)) {
    $_csvFiles = glob($_keywordsDir . '/*.csv') ?: [];
    plog("DEBUG glob: " . count($_csvFiles) . " fichier(s) trouvé(s)");
    if (!empty($_csvFiles)) {
        usort($_csvFiles, fn($a, $b) => filemtime($b) - filemtime($a));
        $_csvFile     = $_csvFiles[0];
        $_csvBasename = basename($_csvFile);
        plog("DEBUG CSV file: $_csvFile");

        if (($_fh = fopen($_csvFile, 'r')) !== false) {
            fgetcsv($_fh); // skip header
            while (($_row = fgetcsv($_fh)) !== false) {
                $t = trim($_row[1] ?? '');
                plog("DEBUG row: col0=" . var_export($_row[0] ?? null, true) . " col1=" . var_export($_row[1] ?? null, true));
                if ($t !== '') $batch[] = ['title' => $t, 'retry_count' => 0];
            }
            fclose($_fh);
        } else {
            plog("DEBUG fopen FAILED: $_csvFile", 'WARNING');
        }
        plog("📥 CSV: " . count($batch) . " titres chargés depuis $_csvBasename");
    }
}

if (empty($batch)) {
    plog("Aucun titre trouvé dans le CSV, rien à générer.", 'WARNING');
    exit(0);
}

// ────────────────────────────────────────────────────────────────────────────
// ÉTAPE 1 — Génération directe via posts-api.php?action=analyze
// (Source unique : dernier fichier CSV depuis KEYWORDS_PIN_DIR)
// ────────────────────────────────────────────────────────────────────────────
plog("Batch de " . count($batch) . " titres:");
foreach ($batch as $i => $item) {
    plog("  " . ($i + 1) . ". " . $item['title']);
}

$generatedSlugs = [];

foreach ($batch as $item) {
    $title = $item['title'];
    plog("Génération: $title");

    $maxAttempts = 3;
    $result      = null;
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $result = generateAndSavePost($title, $POSTS_DIR, $BASE_URL_LOCAL);
        if ($result['success']) break;
        $errMsg = $result['error'] ?? '';
        if (strpos($errMsg, 'Invalid JSON') === false && strpos($errMsg, 'Syntax error') === false) break;
        if ($attempt < $maxAttempts) {
            plog("  ⚠️ Invalid JSON — retry $attempt/$maxAttempts...", 'WARNING');
            sleep(3);
        }
    }

    if ($result['success']) {
        $slug       = $result['slug'];
        $postCost   = $result['cost'] ?? 0.0;
        $totalCost += $postCost;
        plog("  ✅ Sauvegardé: posts/$slug/post.json");
        $stats['generated']++;
        $stats['titles'][] = ['title' => $title, 'slug' => $slug];
        $generatedSlugs[] = $slug;
    } else {
        plog("  ❌ Erreur '$title': " . $result['error'], 'ERROR');
        $stats['errors']++;
        $stats['error_list'][] = "[$title] " . $result['error'];
    }

}

// ── Régénérer index + sitemaps + RSS une seule fois pour tout le batch ───────
if ($stats['generated'] > 0) {
    plog("Mise à jour index / sitemaps / RSS...");
    $indexUrl = $BASE_URL_LOCAL . 'posts-generater.php?action=posts_index';
    $ch = curl_init($indexUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    curl_exec($ch);
    curl_close($ch);
    plog("✅ Index / sitemaps / RSS mis à jour");
}

// ── Propagation satellites (tous slugs × tous satellites en parallèle) ───────
if (!empty($generatedSlugs)) {
    $satCount = count(defined('SATELLITE_PROJECTS') ? SATELLITE_PROJECTS : []);
    plog("📡 Propagation " . count($generatedSlugs) . " post(s) × $satCount satellite(s) en parallèle...");
    propagateAllToSatellites($generatedSlugs);
}

// ── Log final ────────────────────────────────────────────────────────────────
plog(sprintf("=== Terminé: %d générés, %d erreurs | source=%s | 💰 Coût total session: \$%.4f ===",
    $stats['generated'], $stats['errors'], $stats['keyword_source_used'], $totalCost));

$logs = file_exists($LOG_FILE) ? (json_decode(file_get_contents($LOG_FILE), true) ?: []) : [];
$logs[] = $stats;
if (count($logs) > 30) $logs = array_slice($logs, -30);
file_put_contents($LOG_FILE, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// ── Suppression du fichier CSV des titres Pinterest ──────────────────────────
if (!empty($_csvFile) && file_exists($_csvFile)) {
    if (unlink($_csvFile)) {
        plog("🗑️  CSV supprimé: $_csvBasename");
    } else {
        plog("⚠️  Impossible de supprimer: $_csvBasename", 'WARNING');
    }
}

// ── Auto-daily-csv : templates + isOnline + git push + CSV Pinterest ─────────
if ($stats['generated'] > 0) {
    plog("📋 Lancement auto-daily-csv (templates + CSV Pinterest)...");

    // Appel via HTTP localhost — évite crash CLI, bypass auth via satellite=1
    $host     = parse_url(BASE_URL, PHP_URL_HOST) ?? '127.0.0.1';
    $path     = rtrim(parse_url(BASE_URL, PHP_URL_PATH) ?? '', '/');
    $localUrl = 'http://127.0.0.1' . $path . '/auto-daily-csv.php';

    $ch = curl_init($localUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'satellite=1',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 600,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER     => ['Host: ' . $host],
        CURLOPT_NOSIGNAL       => 1,
    ]);
    $csvResp = curl_exec($ch);
    $csvErr  = curl_error($ch);
    $csvCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($csvErr) {
        plog("❌ auto-daily-csv curl error: $csvErr", 'WARNING');
    } else {
        $csvData = json_decode($csvResp, true);
        if ($csvData && ($csvData['success'] ?? false)) {
            plog("✅ CSV Pinterest OK — " . ($csvData['count'] ?? 0) . " articles, " . ($csvData['rows'] ?? 0) . " pins, push: " . ($csvData['push']['main'] ?? 'n/a'));
        } else {
            plog("⚠️  auto-daily-csv HTTP=$csvCode: " . ($csvData['message'] ?? mb_substr($csvResp ?? '', 0, 100)), 'WARNING');
        }
    }
}

exit($stats['errors'] > 0 ? 1 : 0);

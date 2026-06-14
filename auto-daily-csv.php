<?php
ignore_user_abort(true); // Continue running even if caller disconnects (fire & forget)
ob_start();
$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
}
require_once 'config.php';
// Satellite calls come from the main site via curl (no session) — allow from localhost only
$_isLocalCall = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', '']);
$_isSatelliteCall = isset($_POST['satellite'])  && $_POST['satellite']  === '1' && $_isLocalCall;
$_isForceSlugCall = isset($_POST['force_slug']) && $_POST['force_slug'] !== ''  && $_isLocalCall;
if (!$isCli && !$_isSatelliteCall && !$_isForceSlugCall) {
    require_once __DIR__ . '/auth.php';
    auth_check();
}
set_time_limit(0);

// POSTS_DIR: allows satellite to read articles from another site (e.g. pinposts)
$postsDir = (defined('POSTS_DIR') && POSTS_DIR)
    ? (realpath(__DIR__ . '/' . POSTS_DIR) ?: (__DIR__ . '/' . POSTS_DIR))
    : __DIR__ . '/posts';
$_pSettings  = file_exists(__DIR__ . '/settings.json') ? (json_decode(file_get_contents(__DIR__ . '/settings.json'), true) ?? []) : [];
$_limitMin   = (int)($_pSettings['pipelineLimitMin'] ?? 5);
$_limitMax   = (int)($_pSettings['pipelineLimitMax'] ?? 15);
$limit       = $isCli
    ? rand($_limitMin, $_limitMax)
    : (isset($_POST['limit']) ? max(1, min(50, (int)$_POST['limit'])) : rand($_limitMin, $_limitMax));
$dryRun        = !$isCli && isset($_POST['dry_run']) && $_POST['dry_run'] === 'true';
$isSatellite   = !$isCli && isset($_POST['satellite']) && $_POST['satellite'] === '1';
$propagateSlug = trim($_POST['propagate_slug'] ?? '');
$forceSlug     = preg_replace('/[^a-z0-9\-]/', '', trim($_POST['force_slug'] ?? ''));
// Source prioritaire : settings.json (toggle UI), fallback : constante site-config.json
$_linkPinDefault = isset($_pSettings['linkPinActive']) ? (bool)$_pSettings['linkPinActive'] : (defined('LINK_PIN_ACTIVE') && LINK_PIN_ACTIVE);
$linkActive      = isset($_POST['linkPinToggle']) ? ($_POST['linkPinToggle'] === '1') : $_linkPinDefault;

// Base URL images raw GitHub — toujours utilisé pour mediaUrl dans le CSV
$rawImageBase = 'https://' . HOST_NAME; // fallback
if (defined('GITHUB_REPO') && defined('BRANCH')) {
    $_branch      = BRANCH ?: 'main';
    $_repo        = preg_replace('#^https://github\.com/#', '', rtrim(GITHUB_REPO, '/'));
    $_repo        = preg_replace('/\.git$/', '', $_repo);
    $rawImageBase = 'https://raw.githubusercontent.com/' . $_repo . '/refs/heads/' . $_branch;
}
// Base URL link destination : HOST_NAME si link pin actif, sinon raw GitHub
$imageBase = $linkActive ? ('https://' . HOST_NAME) : $rawImageBase;

// ── Progress helper ───────────────────────────────────────────────────────────
$_progressFile = __DIR__ . '/downloads/progress.json';
function progress($icon, $message, $detail = '') {
    global $_progressFile, $isCli;
    if (!is_dir(dirname($_progressFile))) mkdir(dirname($_progressFile), 0755, true);
    file_put_contents($_progressFile, json_encode([
        'icon'    => $icon,
        'message' => $message,
        'detail'  => $detail,
        'time'    => date('H:i:s'),
        'done'    => false,
    ]));
    if ($isCli) echo "  $icon $message" . ($detail ? " — $detail" : '') . "\n";
}
// Reset progress
progress('⏳', 'Démarrage...');

// ── Startup diagnostics (CLI only) ───────────────────────────────────────────
if ($isCli) {
    echo "\n[" . date('Y-m-d H:i:s') . "] === auto-daily-csv.php START ===\n";
    echo "  PHP: " . PHP_VERSION . " | SAPI: " . php_sapi_name() . "\n";
    echo "  __DIR__: " . __DIR__ . "\n";
    echo "  BASE_URL: " . (defined('BASE_URL') ? BASE_URL : 'NOT DEFINED') . "\n";
    echo "  HOST_NAME: " . (defined('HOST_NAME') ? HOST_NAME : 'NOT DEFINED') . "\n";
    echo "  REPO_PATH: " . (defined('REPO_PATH') ? REPO_PATH : 'NOT DEFINED') . "\n";
    echo "  REPO_PATH exists: " . (defined('REPO_PATH') && is_dir(REPO_PATH) ? 'YES' : 'NO') . "\n";
    echo "  GIT_MODE: " . (defined('GIT_MODE') ? GIT_MODE : 'NOT DEFINED') . "\n";
    echo "  GITHUB_REPO: " . (defined('GITHUB_REPO') ? GITHUB_REPO : 'NOT DEFINED') . "\n";
    echo "  BRANCH: " . (defined('BRANCH') ? BRANCH : 'NOT DEFINED') . "\n";
    echo "  SSH_KEY defined: " . (defined('SSH_KEY') && SSH_KEY ? 'YES (' . strlen(SSH_KEY) . ' chars)' : 'NO') . "\n";
    echo "  GITHUB_USER: " . (defined('GITHUB_USER') && GITHUB_USER ? GITHUB_USER : 'NOT SET') . "\n";
    echo "  linkActive: " . ($linkActive ? 'true' : 'false') . "\n";
    echo "  rawImageBase: $rawImageBase\n";
    echo "  limit: $limit\n";
    echo "  postsDir: $postsDir\n";
    echo "  postsDir exists: " . (is_dir($postsDir) ? 'YES' : 'NO') . "\n";
    echo "\n";
}

// ── Satellite: rewrite post.json content via AI ────────────────────────────
function rewritePostForSatellite(array $post): ?array {
    $api    = defined('GENERATION_API') ? GENERATION_API : 'openai';
    $apiKey = ($api === 'anthropic')
        ? (defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '')
        : (defined('OPENAI_API_KEY')    ? OPENAI_API_KEY    : '');

    if (empty($apiKey) || strpos($apiKey, 'votre-cl') !== false) return null;
    if (!defined('REWRITE_POST_PROMPT') || empty(REWRITE_POST_PROMPT))  return null;

    $sourceText = $post['description'] ?? '';
    if (empty($sourceText)) return null;

    $prompt       = REWRITE_POST_PROMPT . "\n\nSource Text:\n" . $sourceText;
    $systemPrompt = "You are a professional food blogger writing authentic, human-like post content. "
                  . "You MUST return ONLY valid JSON with no markdown, no explanations, no text before or after.";

    if ($api === 'anthropic') {
        $model = defined('ANTHROPIC_MODEL') ? ANTHROPIC_MODEL : 'claude-sonnet-4-6';
        $data  = ['model' => $model, 'max_tokens' => 12000, 'system' => $systemPrompt,
                  'messages' => [['role' => 'user', 'content' => $prompt]]];
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $apiKey, 'anthropic-version: 2023-06-01'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) return null;
        $resp = json_decode($response, true);
        $text = $resp['content'][0]['text'] ?? null;
    } else {
        $model = defined('OPENAI_CONTENT_MODEL') ? OPENAI_CONTENT_MODEL : 'gpt-4o-mini';
        $data  = ['model' => $model, 'messages' => [
                      ['role' => 'system', 'content' => $systemPrompt],
                      ['role' => 'user',   'content' => $prompt],
                  ], 'max_tokens' => 12000, 'temperature' => 0.6,
                  'response_format' => ['type' => 'json_object']];
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) return null;
        $resp = json_decode($response, true);
        $text = $resp['choices'][0]['message']['content'] ?? null;
    }

    if (empty($text)) return null;

    // Strip markdown fences if any
    $text = trim($text);
    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/s', $text, $m)) $text = trim($m[1]);

    $newData = json_decode($text, true);
    if (!$newData) {
        if (preg_match('/\{[\s\S]*\}/s', $text, $m)) $newData = json_decode($m[0], true);
    }
    return $newData ?: null;
}

// ── 0. Handle propagate_slug (satellite receives this from main pipeline) ────
// src_posts_dir is passed by auto-pipeline.php — absolute path to main site's posts/
if (!$isCli && $propagateSlug !== '') {
    $propagateSlug  = preg_replace('/[^a-z0-9\-]/', '', $propagateSlug);
    $_srcRaw        = trim($_POST['src_posts_dir'] ?? '');
    $_srcPostsDir   = ($_srcRaw && is_dir($_srcRaw)) ? rtrim($_srcRaw, '/') : $postsDir;
    $srcPostJson    = $_srcPostsDir . '/' . $propagateSlug . '/post.json';
    ob_end_clean();
    if (!file_exists($srcPostJson)) {
        echo json_encode(['success' => false, 'message' => 'Post introuvable: ' . $propagateSlug, 'looked_in' => $postsDir]);
        exit;
    }
    $post    = json_decode(file_get_contents($srcPostJson), true);
    $rewritten = rewritePostForSatellite($post);
    if ($rewritten) {
        foreach (['images','id','slug','isOnline','createdAt','updatedAt','author_id'] as $k) {
            if (array_key_exists($k, $post)) $rewritten[$k] = $post[$k];
        }
        foreach (['pin_variations','pinterest_boards','category_id'] as $k) {
            if (empty($rewritten[$k]) && !empty($post[$k])) $rewritten[$k] = $post[$k];
        }
        $post = $rewritten;
    }
    $satImgDir = __DIR__ . '/posts/' . $propagateSlug . '/images';
    if (!is_dir($satImgDir)) mkdir($satImgDir, 0755, true);
    $satImages = [];
    foreach ($post['images'] ?? [] as $img) {
        if (($img['type'] ?? '') === 'template') continue;
        $srcFile = $_srcPostsDir . '/' . $propagateSlug . '/images/' . ($img['fileName'] ?? '');
        $dstFile = $satImgDir . '/' . ($img['fileName'] ?? '');
        if ($srcFile && file_exists($srcFile) && !file_exists($dstFile)) copy($srcFile, $dstFile);
        $satImages[] = $img;
    }
    $post['images']    = $satImages;
    $post['isOnline']  = false;
    $post['image_dir'] = $propagateSlug . '/images';
    $post['slug']      = $propagateSlug;
    $satDir = __DIR__ . '/posts/' . $propagateSlug;
    if (!is_dir($satDir)) mkdir($satDir, 0755, true);
    $satPost = array_diff_key($post, array_flip(['_path','_slug','_src','_templates']));
    file_put_contents($satDir . '/post.json', json_encode($satPost, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

    // Générer index.html — même approche que button "Regenerate HTML posts" dans config
    $htmlGenerated = false;
    if (!class_exists('PostHTMLGenerator')) {
        $__src = file_get_contents(__DIR__ . '/generate-single-post.php');
        preg_match('/class PostHTMLGenerator \{.*?^\}/ms', $__src, $__m);
        if (!empty($__m[0])) eval($__m[0]);
        unset($__src, $__m);
    }
    if (class_exists('PostHTMLGenerator')) {
        try {
            $gen = new PostHTMLGenerator($satDir . '/post.json');
            $gen->saveFile($satDir . '/index.html');
            $htmlGenerated = true;
        } catch (Throwable $__e) {
            // index.html non critique, on continue
        }
    }

    echo json_encode(['success' => true, 'slug' => $propagateSlug, 'rewritten' => (bool)$rewritten, 'images' => count($satImages), 'html' => $htmlGenerated]);
    exit;
}

// ── 1. Load category slug → name map ─────────────────────────────────────────
$catIndexPath = __DIR__ . '/categories/index.json';
$catIndex     = file_exists($catIndexPath) ? json_decode(file_get_contents($catIndexPath), true) : [];
$catIdToSlug  = [];
if (!empty($catIndex['folders'])) {
    foreach ($catIndex['folders'] as $slug => $id) {
        $catIdToSlug[$id] = $slug;
    }
}

// ── 2. Pick $limit offline articles with >= 3 source images ──────────────────
progress('🔍', 'Recherche articles offline...');
$postsBase = (defined('POSTS_DIR') && POSTS_DIR) ? realpath(__DIR__ . '/' . POSTS_DIR) : __DIR__ . '/posts';

if ($forceSlug !== '') {
    // ── Force mode: process a single specific slug (from manual creation) ──────
    $jsonPath = $postsDir . '/' . $forceSlug . '/post.json';
    if (!file_exists($jsonPath)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Post introuvable: ' . $forceSlug]);
        exit;
    }
    $post    = json_decode(file_get_contents($jsonPath), true);
    $srcImgs = array_values(array_filter(
        $post['images'] ?? [],
        fn($i) => ($i['type'] ?? '') !== 'template'
    ));
    if (count($srcImgs) < 3) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Moins de 3 images source pour: ' . $forceSlug]);
        exit;
    }
    $imgDir      = $postsBase . '/' . $forceSlug . '/images/';
    $missingFiles = false;
    foreach (array_slice($srcImgs, 0, 3) as $si) {
        if (!file_exists($imgDir . $si['fileName'])) { $missingFiles = true; break; }
    }
    if ($missingFiles) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Images source manquantes pour: ' . $forceSlug]);
        exit;
    }
    $post['_path'] = $jsonPath;
    $post['_slug'] = $forceSlug;
    $post['_src']  = $srcImgs;
    $selected      = [$post];

} else {
    // ── Normal mode: pick $limit oldest offline articles ──────────────────────
    $candidates = [];
    foreach (glob($postsDir . '/*/post.json') as $jsonPath) {
        $post = json_decode(file_get_contents($jsonPath), true);
        if (!$post) continue;
        // Skip si déjà online ET a déjà ses templates (rien à faire)
        $hasTemplates = !empty(array_filter($post['images'] ?? [], fn($i) => ($i['type'] ?? '') === 'template'));
        if (($post['isOnline'] ?? false) && $hasTemplates) continue;

        $srcImgs = array_values(array_filter(
            $post['images'] ?? [],
            fn($i) => ($i['type'] ?? '') !== 'template'
        ));
        if (count($srcImgs) < 3) continue;

        $slug  = basename(dirname($jsonPath));
        $imgDir = $postsBase . '/' . $slug . '/images/';
        $missingFiles = false;
        foreach (array_slice($srcImgs, 0, 3) as $si) {
            if (!file_exists($imgDir . $si['fileName'])) { $missingFiles = true; break; }
        }
        if ($missingFiles) {
            progress('⚠️', "Photos source manquantes, skip: $slug", $slug);
            continue;
        }

        $post['_path']    = $jsonPath;
        $post['_slug']    = $slug;
        $post['_src']     = $srcImgs;

        $createAt = $post['CreateAt'] ?? $post['createdAt'] ?? '2000-01-01';
        $candidates[] = ['post' => $post, 'date' => $createAt];
    }

    if (empty($candidates)) {
        ob_end_clean();
        $msg = '⏳ Aucun article offline avec images source disponible';
        file_put_contents($_progressFile, json_encode(['icon' => '⏳', 'message' => $msg, 'detail' => '', 'time' => date('H:i:s'), 'done' => true]));
        if ($isCli) echo $msg . "\n";
        else echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }

    // Ordre aléatoire — chaque batch produit un ordre différent
    shuffle($candidates);
    $selected = array_slice(array_column($candidates, 'post'), 0, $limit);
}

// ── 2b. Fresh-Pin Recycling — repin d'anciens posts ONLINE avec design frais ──
// Pinterest récompense les pins frais, pas les posts neufs. On régénère un pin
// pour des posts déjà publiés (sans toucher leur date de publication / SEO).
$recycleActive = defined('PINTEREST_RECYCLE_ACTIVE') ? PINTEREST_RECYCLE_ACTIVE : false;
if ($recycleActive && $forceSlug === '' && !$isSatellite) {
    $recycleCount   = defined('PINTEREST_RECYCLE_COUNT')    ? (int)PINTEREST_RECYCLE_COUNT    : 10;
    $recycleMinDays = defined('PINTEREST_RECYCLE_MIN_DAYS') ? (int)PINTEREST_RECYCLE_MIN_DAYS : 7;
    $selectedSlugs  = array_flip(array_map(fn($p) => $p['_slug'], $selected));
    $recycleCands   = [];

    foreach (glob($postsDir . '/*/post.json') as $jsonPath) {
        $post = json_decode(file_get_contents($jsonPath), true);
        if (!$post) continue;
        if (!($post['isOnline'] ?? false)) continue;           // uniquement posts publiés
        $slug = basename(dirname($jsonPath));
        if (isset($selectedSlugs[$slug])) continue;            // pas déjà dans ce batch

        // Respecter le délai minimum depuis le dernier recyclage
        $lastRecycled = $post['lastRecycledAt'] ?? null;
        if ($lastRecycled && (time() - strtotime($lastRecycled)) < $recycleMinDays * 86400) continue;

        $srcImgs = array_values(array_filter($post['images'] ?? [], fn($i) => ($i['type'] ?? '') !== 'template'));
        if (count($srcImgs) < 3) continue;
        $imgDir = $postsBase . '/' . $slug . '/images/';
        $missing = false;
        foreach (array_slice($srcImgs, 0, 3) as $si) {
            if (!file_exists($imgDir . $si['fileName'])) { $missing = true; break; }
        }
        if ($missing) continue;

        $post['_path']    = $jsonPath;
        $post['_slug']    = $slug;
        $post['_src']     = $srcImgs;
        $post['_recycle'] = true;  // tag : ne pas réinitialiser la date de publication
        // Priorité : jamais recyclé d'abord, puis le plus ancien recyclage
        $recycleCands[]   = ['post' => $post, 'sort' => $lastRecycled ?? '0000'];
    }

    if (!empty($recycleCands)) {
        usort($recycleCands, fn($a, $b) => strcmp($a['sort'], $b['sort']));
        $recyclePick = array_slice(array_column($recycleCands, 'post'), 0, $recycleCount);
        $selected    = array_merge($selected, $recyclePick);
        if ($isCli) echo "  ♻️  Recycling: " . count($recyclePick) . " anciens posts re-pinnés\n";
    }
}

// ── 3. Generate templates (same for main and satellite — uses own config) ──────
// Source images URL base: POSTS_BASE_URL if reading from external posts dir
$srcBaseUrl      = (defined('POSTS_BASE_URL') && POSTS_BASE_URL) ? rtrim(POSTS_BASE_URL, '/') . '/' : BASE_URL;
$usesExternalPosts = defined('POSTS_DIR') && POSTS_DIR;

$totalSelected = count($selected);
foreach ($selected as $artIdx => &$post) {
    $slug     = $post['_slug'];
    $src      = $post['_src'];
    $jsonPath = $post['_path'];
    $artNum   = $artIdx + 1;

    progress('🎨', "Article $artNum/$totalSelected — génération templates", $slug);

    // For satellite: delete old templates from OWN posts/ dir (not from source)
    $ownPostPath = __DIR__ . '/posts/' . $slug . '/post.json';
    $ownImages   = ($usesExternalPosts && file_exists($ownPostPath))
        ? (json_decode(file_get_contents($ownPostPath), true)['images'] ?? [])
        : ($post['images'] ?? []);
    $ownImages = deletePostTemplates($slug, $ownImages, __DIR__);

    // ── Satellite: rewrite FIRST so pin_variations are fresh for template titles ──
    if ($usesExternalPosts && !$dryRun) {
        progress('✍️', "Article $artNum/$totalSelected — réécriture IA", $slug);
        $rewritten = rewritePostForSatellite($post);
        if ($rewritten) {
            // Always keep internal working fields + identity from source
            foreach (['_path', '_slug', '_src', 'images', 'id', 'isOnline',
                      'createdAt', 'CreateAt', 'updatedAt', 'author_id'] as $k) {
                if (array_key_exists($k, $post)) $rewritten[$k] = $post[$k];
            }
            // Keep source pin_variations/boards only if rewrite didn't produce them
            foreach (['pin_variations', 'pinterest_boards', 'category_id'] as $k) {
                if (empty($rewritten[$k]) && !empty($post[$k])) $rewritten[$k] = $post[$k];
            }
            $post = $rewritten;
            progress('✅', "Article $artNum/$totalSelected — réécriture OK", $slug);
        } else {
            progress('⏭️', "Article $artNum/$totalSelected — réécriture ignorée (pas de crédit)", $slug);
        }
    }

    // Build title variations (uses rewritten pin_variations if available)
    $fallbackTitle = $post['title'] ?? $slug;
    $v = (isset($post['pin_variations']) && count($post['pin_variations']) >= 4)
        ? $post['pin_variations']
        : [
            ['title' => $fallbackTitle, 'description' => ''],
            ['title' => $fallbackTitle, 'description' => ''],
            ['title' => $fallbackTitle, 'description' => ''],
            ['title' => $fallbackTitle, 'description' => ''],
        ];

    $ingredients = json_encode($post['ingredients'] ?? [], JSON_UNESCAPED_UNICODE);
    $combos = [
        ['image1' => $srcBaseUrl . $src[0]['filePath'], 'image2' => $srcBaseUrl . $src[1]['filePath'], 'title' => $v[0]['title'], 'index' => 4],
        ['image1' => $srcBaseUrl . $src[0]['filePath'], 'image2' => $srcBaseUrl . $src[2]['filePath'], 'title' => $v[1]['title'], 'index' => 5],
        ['image1' => $srcBaseUrl . $src[1]['filePath'], 'image2' => $srcBaseUrl . $src[0]['filePath'], 'title' => $v[2]['title'], 'index' => 6],
        ['image1' => $srcBaseUrl . $src[1]['filePath'], 'image2' => $srcBaseUrl . $src[2]['filePath'], 'title' => $v[3]['title'], 'index' => 7],
        // Extra templates — couleurs propres (no_inherit), link selon config
        ['image1' => $srcBaseUrl . $src[0]['filePath'], 'title' => $v[0]['title'], 'index' => 8, 'template' => 'recipe_card',  'no_inherit' => '1', 'ingredients' => $ingredients, 'no_link' => !_cfg('recipe_card_LINK_ACTIVE',  false)],
        ['image1' => $srcBaseUrl . $src[0]['filePath'], 'title' => $v[0]['title'], 'index' => 9, 'template' => 'overlay_list', 'no_inherit' => '1', 'ingredients' => $ingredients, 'no_link' => !_cfg('overlay_list_LINK_ACTIVE', false)],
    ];

    // ── Générer les 6 templates en PARALLÈLE via curl_multi ────────────────────
    $newTemplates = [];
    $multi        = curl_multi_init();
    $mHandles     = [];
    $_templateUrl = BASE_URL . 'generate_pinterest.php';
    if ($isCli) echo "  🖼️  Template URL: $_templateUrl\n";

    foreach ($combos as $ci => $combo) {
        $postData = [
            'image1'     => $combo['image1'],
            'image2'     => $combo['image2'] ?? '',
            'title'      => $combo['title'],
            'uniqueSlug' => $slug,
            'folder'     => 'posts',
            'index'      => $combo['index'],
        ];
        if (!empty($combo['template']))   $postData['template']    = $combo['template'];
        if (!empty($combo['no_inherit'])) $postData['no_inherit']  = '1';
        if (isset($combo['ingredients'])) $postData['ingredients'] = $combo['ingredients'];

        if ($isCli) echo "  🖼️  Template idx=$ci image1=" . basename($combo['image1']) . " title=" . mb_substr($combo['title'], 0, 40) . "\n";

        $ch = curl_init($_templateUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postData,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_NOSIGNAL       => 1,
        ]);
        curl_multi_add_handle($multi, $ch);
        $mHandles[$ci] = ['ch' => $ch, 'combo' => $combo];
    }

    progress('🖼️', "Article $artNum/$totalSelected — " . count($combos) . " templates en parallèle", $slug);
    if ($isCli) echo "  ⏳ curl_multi start — " . count($combos) . " handles\n";
    $_tplStart = microtime(true);

    $running = null;
    do {
        curl_multi_exec($multi, $running);
        if ($running) curl_multi_select($multi, 5.0);
    } while ($running > 0);

    if ($isCli) echo "  ✅ curl_multi done in " . round(microtime(true) - $_tplStart, 1) . "s\n";

    foreach ($mHandles as $ci => $item) {
        $ch    = $item['ch'];
        $combo = $item['combo'];

        $response = curl_multi_getcontent($ch);
        $curlErr  = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);

        if ($isCli) echo "  🖼️  Template idx=$ci HTTP=$httpCode err=" . ($curlErr ?: 'none') . " resp=" . mb_substr($response ?? '', 0, 120) . "\n";

        $result = json_decode($response, true);
        if ($result && ($result['success'] ?? false)) {
            $entry = [
                'fileName'     => $result['filename'],
                'filePath'     => $result['path'],
                'relativePath' => $result['pathrelative'],
                'originalUrl'  => $result['url'],
                'type'         => !empty($combo['template']) ? $combo['template'] : 'template',
            ];
            if (!empty($combo['no_link'])) $entry['no_link'] = true;
            $newTemplates[$ci] = $entry;
        } elseif ($isCli) {
            echo "  ❌ Template idx=$ci failed — " . ($result['message'] ?? 'no json') . "\n";
        }
    }
    curl_multi_close($multi);
    ksort($newTemplates);
    $newTemplates = array_values($newTemplates);

    if (count($newTemplates) < 4) {
        progress('⚠️', "Article $artNum/$totalSelected — " . count($newTemplates) . "/6 templates", $slug);
    }

    // Ordre aléatoire des templates
    shuffle($newTemplates);

    $post['_templates'] = $newTemplates;

    if ($usesExternalPosts) {
        // Satellite: images = source images (from main site) + own templates
        $srcImages = array_values(array_filter($ownImages, fn($i) => ($i['type'] ?? '') !== 'template'));
        $post['images'] = array_merge($srcImages, $newTemplates);

        // Sync post.json to own posts/ (content already rewritten above, just add images)
        if (!$dryRun) {
            $ownSlugDir = __DIR__ . '/posts/' . $slug;
            if (!is_dir($ownSlugDir)) mkdir($ownSlugDir, 0755, true);
            // $post already has rewritten content — strip internal working keys before saving
            $satPost = array_diff_key($post, array_flip(['_path', '_slug', '_src', '_templates']));
            $satPost['images'] = $post['images'];
            // Ensure image_dir and slug match the actual folder (AI slug may differ)
            $satPost['image_dir'] = $slug . '/images';
            $satPost['slug'] = $slug;
            file_put_contents($ownPostPath, json_encode($satPost, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    } else {
        // Main: save templates back to own post.json
        if (!empty($post['_recycle'])) {
            // Recyclé : remplacer les anciens templates par les frais (éviter l'accumulation)
            $post['images'] = array_merge(deletePostTemplates($slug, $post['images'], __DIR__), $newTemplates);
        } else {
            $post['images'] = array_merge($post['images'], $newTemplates);
        }
        if (!$dryRun) {
            $saved = json_decode(file_get_contents($jsonPath), true);
            $saved['images'] = $post['images'];
            file_put_contents($jsonPath, json_encode($saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }
}
unset($post);

// Filter: need 4 templates
$selected = array_values(array_filter($selected, fn($p) => count($p['_templates'] ?? []) >= 4));

if (empty($selected)) {
    ob_end_clean();
    $msg = '❌ Génération templates échouée pour tous les articles';
    if ($isCli) echo $msg . "\n";
    else echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

// ── 3b. Video pins — générer les reels MP4 (réutilise le pipeline Facebook) ───
// Les MP4 sont inclus dans le git add -A final → URL raw GitHub valide dans le CSV.
$videoPinsActive = defined('PINTEREST_VIDEO_PINS_ACTIVE') ? PINTEREST_VIDEO_PINS_ACTIVE : false;
// Guard: video base URL must be set + reachable for Pinterest to download the MP4
if ($videoPinsActive) {
    $_videoBase = (defined('PINTEREST_VIDEO_BASE_URL') && PINTEREST_VIDEO_BASE_URL)
        ? PINTEREST_VIDEO_BASE_URL
        : ('https://' . HOST_NAME);
    if (!defined('PINTEREST_VIDEO_BASE_URL') || !PINTEREST_VIDEO_BASE_URL) {
        if ($isCli) echo "  ⚠️  PINTEREST_VIDEO_PINS_ACTIVE=true mais PINTEREST_VIDEO_BASE_URL n'est pas défini.\n"
                       . "      Les MP4 seront pointés vers https://" . HOST_NAME . " — si le serveur n'expose pas ce dossier publiquement, Pinterest renverra 'Failed to download video'.\n"
                       . "      → Définis PINTEREST_VIDEO_BASE_URL dans site-config.json avec l'URL directe du serveur.\n";
    }
}
$videoReadySlugs = []; // slug => true si reel disponible
if ($videoPinsActive && !$dryRun) {
    if (!defined('FB_REEL_FUNCTIONS_ONLY')) define('FB_REEL_FUNCTIONS_ONLY', true);
    require_once __DIR__ . '/generate-facebook-reel.php';
    if (function_exists('fb_buildReelComplete')) {
        foreach ($selected as $post) {
            $slug = $post['_slug'];
            $res  = fb_buildReelComplete($slug);
            if (!empty($res['ok'])) {
                $videoReadySlugs[$slug] = true;
                if ($isCli) echo "  🎬 Reel " . (!empty($res['skipped']) ? "(cache)" : "généré") . ": $slug\n";
            } elseif ($isCli) {
                echo "  ⚠️  Reel échoué pour $slug: " . ($res['error'] ?? '?') . "\n";
            }
        }
    }
}

// ── 4. CSV helper (building happens after git push) ───────────────────────────
function csvField($value) {
    $value = preg_replace('/[\r\n\v\f\x{0085}\x{2028}\x{2029}]+/u', ' ', (string)$value);
    $value = str_replace('"', '""', $value);
    return '"' . trim($value) . '"';
}
// For human-readable text fields (title, description, keywords) — strip quotes instead of escaping
function csvText($value) {
    $value = preg_replace('/[\r\n\v\f\x{0085}\x{2028}\x{2029}]+/u', ' ', (string)$value);
    $value = str_replace(['"', ','], '', $value); // remove quotes and commas
    $value = preg_replace('/\s+/', ' ', $value);
    return '"' . trim($value) . '"';
}
$csvFiles = []; // built after git push — images must be live before CSV is generated

$pushResult  = [];
$gitPushOk   = true;
$publishDate = date('Y-m-d\TH:i:sP');

if (!$dryRun) {

    // ── 5. Satellites FIRST (articles still offline → they can process them) ──
    if (!$isSatellite && !empty(SATELLITE_PROJECTS)) {

        // Force mode: propagate slug to each satellite first, then run force_slug pipeline
        if ($forceSlug !== '') {
            $dbgLog = __DIR__ . '/downloads/force_slug_debug.log';
            file_put_contents($dbgLog, date('[H:i:s] ') . "force_slug=$forceSlug — start step5\n", FILE_APPEND);
            foreach (SATELLITE_PROJECTS as $satellite) {
                $satUrl  = rtrim($satellite['url'], '/');
                $satName = basename(rtrim($satellite['path'], '/\\'));
                // Step A: propagate post + images to satellite
                progress('📡', "Propagation → $satName", $forceSlug);
                file_put_contents($dbgLog, date('[H:i:s] ') . "Step A propagate_slug → $satUrl\n", FILE_APPEND);
                $ch = curl_init($satUrl . '/auto-daily-csv.php');
                curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 180,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_POSTFIELDS => http_build_query(['propagate_slug' => $forceSlug])]);
                $respA = curl_exec($ch); $errA = curl_error($ch); $codeA = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
                file_put_contents($dbgLog, date('[H:i:s] ') . "Step A done — HTTP=$codeA err=" . ($errA ?: 'none') . " resp=" . substr($respA, 0, 200) . "\n", FILE_APPEND);
                // Step B: force pipeline (templates + isOnline) on satellite
                progress('🎨', "Templates satellite → $satName", $forceSlug);
                file_put_contents($dbgLog, date('[H:i:s] ') . "Step B force_slug → $satUrl\n", FILE_APPEND);
                $ch = curl_init($satUrl . '/auto-daily-csv.php');
                curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 600,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_POSTFIELDS => http_build_query(['force_slug' => $forceSlug, 'linkPinToggle' => $linkActive ? '1' : '0'])]);
                $respB = curl_exec($ch); $errB = curl_error($ch); $codeB = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
                file_put_contents($dbgLog, date('[H:i:s] ') . "Step B done — HTTP=$codeB err=" . ($errB ?: 'none') . " resp=" . substr($respB, 0, 200) . "\n", FILE_APPEND);
            }
            file_put_contents($dbgLog, date('[H:i:s] ') . "step5 done — goto after_satellites\n", FILE_APPEND);
            // Skip the rest of step 5 (no multi-curl needed)
            goto after_satellites;
        }

        $satCount  = count(SATELLITE_PROJECTS);
        $satStatus = [];
        $mh        = curl_multi_init();
        $handles   = [];

        foreach (SATELLITE_PROJECTS as $satellite) {
            // Use path as unique key — hostname alone collides when multiple satellites are on the same server
            $satName = basename(rtrim($satellite['path'], '/\\'));
            $satPath = realpath(__DIR__ . '/' . $satellite['path']) ?: '';
            $ch = curl_init($satellite['url'] . '/auto-daily-csv.php');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query(['limit' => $limit, 'satellite' => '1', 'linkPinToggle' => $linkActive ? '1' : '0']),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 600,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[(int)$ch] = ['ch' => $ch, 'name' => $satName, 'path' => $satPath];
            $satStatus[$satName] = '⏳';
        }

        // Map handle int → satellite path for progress polling
        $handleToPath = [];
        foreach ($handles as $intKey => $h) {
            $handleToPath[$intKey] = $h['path'];
        }

        $satLiveStatus = array_fill_keys(array_keys($satStatus), '⏳ En attente...');

        $buildDetail = function() use (&$satStatus, &$satLiveStatus) {
            $parts = [];
            foreach ($satStatus as $name => $finalStatus) {
                if ($finalStatus !== '⏳') {
                    $parts[] = $name . ': ' . $finalStatus;
                } else {
                    $parts[] = $name . ': ' . ($satLiveStatus[$name] ?? '⏳');
                }
            }
            return implode(' | ', $parts);
        };

        progress('📡', "Satellites en parallèle ($satCount)...", $buildDetail());
        $lastPoll = 0;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.3);

            // Poll satellite progress.json every 1s
            if (time() - $lastPoll >= 1) {
                $lastPoll = time();
                foreach ($handles as $intKey => $h) {
                    if ($satStatus[$h['name']] !== '⏳') continue; // already done
                    $satPath = $handleToPath[$intKey] ?? '';
                    if (!$satPath) continue;
                    $progFile = $satPath . '/downloads/progress.json';
                    if (!file_exists($progFile)) continue;
                    $prog = json_decode(file_get_contents($progFile), true);
                    if ($prog && isset($prog['message'])) {
                        $satLiveStatus[$h['name']] = ($prog['icon'] ?? '') . ' ' . $prog['message']
                            . ($prog['detail'] ? ' — ' . $prog['detail'] : '');
                    }
                }
                progress('📡', "Satellites ($satCount en cours)...", $buildDetail());
            }

            while (($info = curl_multi_info_read($mh)) !== false) {
                if ($info['msg'] !== CURLMSG_DONE) continue;
                $ch   = $info['handle'];
                $name = $handles[(int)$ch]['name'] ?? '?';
                $data = json_decode(curl_multi_getcontent($ch), true);
                if ($data && ($data['success'] ?? false)) {
                    $satStatus[$name] = '✅ ' . ($data['count'] ?? 0) . ' articles';
                } else {
                    $satStatus[$name] = '❌ ' . ($data['message'] ?? 'HTTP ' . curl_getinfo($ch, CURLINFO_HTTP_CODE));
                }
                $pushResult[$name] = $satStatus[$name];
                progress('📡', 'Satellites: ' . $buildDetail());
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
        } while ($running > 0);
        curl_multi_close($mh);
        progress('📡', 'Satellites terminés', $buildDetail());
    }

    // ── Snapshot for rollback ─────────────────────────────────────────────────
    if (!$isSatellite) {
        file_put_contents(
            __DIR__ . '/downloads/last-daily-session.json',
            json_encode([
                'date'     => $publishDate,
                'filename' => 'pinterest_daily_' . date('Y-m-d') . '.csv',
                'articles' => array_map(fn($p) => [
                    'slug'     => $p['_slug'],
                    'path'     => $p['_path'],
                    'original' => [
                        'createdAt' => $p['createdAt'] ?? null,
                        'CreateAt'  => $p['CreateAt']  ?? null,
                        'updatedAt' => $p['updatedAt'] ?? null,
                    ],
                ], $selected),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    after_satellites:
    // ── 6. Set isOnline=true ──────────────────────────────────────────────────
    progress('🌐', 'Mise en ligne des articles...');
    foreach ($selected as $post) {
        $slug = $post['_slug'];

        // For satellite: update own post.json (already created in step 3); skip source post
        $targetPath = $usesExternalPosts
            ? (__DIR__ . '/posts/' . $slug . '/post.json')
            : $post['_path'];

        // Post recyclé : déjà publié → préserver sa date, marquer lastRecycledAt seulement.
        if (!empty($post['_recycle'])) {
            if (file_exists($targetPath)) {
                $postData = json_decode(file_get_contents($targetPath), true);
                if (is_array($postData)) {
                    $postData['lastRecycledAt'] = date('Y-m-d');
                    file_put_contents($targetPath, json_encode($postData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            }
            continue; // ne pas resync isOnline/dates aux satellites
        }

        if (file_exists($targetPath)) {
            $postData = json_decode(file_get_contents($targetPath), true);
            $postData['isOnline']  = true;
            $postData['updatedAt'] = $publishDate;
            if (array_key_exists('createdAt', $postData)) $postData['createdAt'] = $publishDate;
            if (array_key_exists('CreateAt',  $postData)) $postData['CreateAt']  = $publishDate;
            file_put_contents($targetPath, json_encode($postData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        // Main site: sync isOnline to satellites
        if (!$usesExternalPosts) {
            foreach (SATELLITE_PROJECTS as $satellite) {
                $satPath = realpath(__DIR__ . '/' . $satellite['path']);
                if (!$satPath) continue;
                $satJson = $satPath . '/posts/' . $slug . '/post.json';
                if (!file_exists($satJson)) continue;
                $satPost = json_decode(file_get_contents($satJson), true);
                if (!$satPost) continue;
                // In normal mode: only sync if satellite already has its templates (its pipeline ran)
                // In force_slug mode: always sync — satellite pipeline was already called in step 5
                if ($forceSlug === '') {
                    $satHasTemplates = !empty(array_filter($satPost['images'] ?? [], fn($i) => ($i['type'] ?? '') === 'template'));
                    if (!$satHasTemplates) continue;
                }
                $satPost['isOnline']  = true;
                $satPost['updatedAt'] = $publishDate;
                if (array_key_exists('createdAt', $satPost)) $satPost['createdAt'] = $publishDate;
                if (array_key_exists('CreateAt',  $satPost)) $satPost['CreateAt']  = $publishDate;
                file_put_contents($satJson, json_encode($satPost, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }
    }

    // Rebuild posts/index.json with full metadata (1 file = all post data, no N fetches on front-end)
    _rebuild_posts_index(__DIR__);

    // Git push géré séparément via push.php
    $gitPushOk = true;

    // Save lastDailyRun
    $settingsFile = __DIR__ . '/settings.json';
    $settings = file_exists($settingsFile) ? (json_decode(file_get_contents($settingsFile), true) ?? []) : [];
    $settings['lastDailyRun'] = date('Y-m-d');
    file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT));
}

// ── 4. Build CSV (after git push — images must be live) ───────────────────────
if ($isCli) echo "\n  📄 Building CSV — linkActive=" . ($linkActive ? 'true' : 'false') . " gitPushOk=" . (($gitPushOk ?? true) ? 'true' : 'false') . "\n";
// Si linkActive=true, images servies depuis HOST_NAME (VPS) — pas besoin de git push
if (!$linkActive && !($gitPushOk ?? true)) {
    ob_end_clean();
    $msg = '❌ Git push échoué — CSV non généré pour éviter des liens cassés. ' . ($pushResult['main'] ?? '');
    file_put_contents($_progressFile, json_encode(['icon' => '❌', 'message' => 'Git push échoué', 'detail' => $pushResult['main'] ?? '', 'time' => date('H:i:s'), 'done' => true]));
    if ($isCli) echo $msg . "\n";
    else echo json_encode(['success' => false, 'message' => $msg, 'push' => $pushResult]);
    exit;
}
progress('📄', 'Génération CSV...');

$csvDateInput = isset($_POST['csv_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['csv_date'])
    ? $_POST['csv_date'] : date('Y-m-d');
$today  = new DateTime($csvDateInput);
$header = 'Title,Media URL,Pinterest board,Thumbnail,Description,Link,Publish date,Keywords';

// ── Build 5 separate CSV files — 1 per template group, ~10 pins each ─────────
$csvFiles       = [];
$fbScheduleTimes = []; // slug → unix timestamp, capturé depuis weekIdx=0 (premier groupe Pinterest)

foreach ([0, 1, 2, 3, 4] as $weekIdx) {
    $groupDate     = clone $today;
    $groupDate->modify('+' . ($weekIdx * CSV_PUBLISH_SPACING_DAYS) . ' days');
    $groupDateStr  = $groupDate->format('Y-m-d');
    $lines         = [$header];
    $startHour     = (int)(defined('PIN_SCHEDULE_START') ? PIN_SCHEDULE_START : 16);
    $currentMinute = $startHour * 60;
    $seenTitles    = [];

    foreach ($selected as $post) {
        $templates = $post['_templates'];
        if (!isset($templates[$weekIdx])) continue;
        $template = $templates[$weekIdx];
        $slug     = $post['_slug'];

        // Title + Description
        $variations = $post['pin_variations'] ?? [];
        if (!empty($variations[$weekIdx])) {
            $title       = $variations[$weekIdx]['title']       ?? $post['title'] ?? '';
            $description = $variations[$weekIdx]['description'] ?? $post['description'] ?? '';
        } else {
            $title       = $post['title']       ?? '';
            $description = $post['description'] ?? '';
        }

        // Keywords from hashtags
        // Strip engagement CTAs from description BEFORE hashtag extraction so
        // words like "SAVE", "FOR LATER", "PIN IT" don't bleed into keyword tags.
        $engagementCtaPattern = '/\b(SAVE|FOR LATER|PIN IT|PIN THIS|CLICK|BOOKMARK|TRY IT|MAKE IT|GRAB IT)\b\.?/i';
        $description = trim(preg_replace($engagementCtaPattern, '', $description));
        $description = trim(preg_replace('/\s{2,}/', ' ', $description));

        $keywords = '';
        if (preg_match_all('/#(\w+)/', $description, $matches)) {
            $rawTags     = $matches[1];
            $description = preg_replace('/#\w+/', '', $description);
            $description = trim(preg_replace('/\s+/', ' ', $description));
            // Strip any CTA suffix that got fused to the last hashtag (e.g. #FamilyDinnerSAVE)
            $cleanTags = array_map(function($tag) {
                return preg_replace('/(SAVE|LATER|PINIT|CLICK|BOOKMARK|TRYIT|MAKEIT|GRABIT)$/i', '', $tag);
            }, $rawTags);
            $cleanTags = array_filter($cleanTags, fn($t) => strlen($t) >= 3);
            $keywords  = implode(', ', $cleanTags);
        } else {
            $rawHashtags = is_array($post['hashtags'] ?? null)
                ? implode(' ', $post['hashtags'])
                : ($post['hashtags'] ?? '');
            if (preg_match_all('/#(\w+)/', $rawHashtags, $hm)) {
                $cleanTags = array_map(function($tag) {
                    return preg_replace('/(SAVE|LATER|PINIT|CLICK|BOOKMARK|TRYIT|MAKEIT|GRABIT)$/i', '', $tag);
                }, $hm[1]);
                $cleanTags = array_filter($cleanTags, fn($t) => strlen($t) >= 3);
                $keywords  = implode(', ', $cleanTags);
            }
        }

        // Limits + deduplicate title within this file
        if (mb_strlen($title) > 100) $title = mb_substr($title, 0, 97) . '...';
        $titleKey = mb_strtolower(trim($title));
        if (isset($seenTitles[$titleKey])) {
            $seenTitles[$titleKey]++;
            $suffix = ' ' . $seenTitles[$titleKey];
            $title  = mb_substr($title, 0, 100 - mb_strlen($suffix)) . $suffix;
        } else {
            $seenTitles[$titleKey] = 1;
        }
        if (mb_strlen($description) > 500) $description = mb_substr($description, 0, 497) . '...';

        // Media URL — toujours raw GitHub (image doit être accessible publiquement)
        $mediaUrl = $rawImageBase . '/posts/' . $slug . '/images/' . $template['fileName'];

        // Board — pick per template layout, fallback to board_name or category
        $templateKey     = $template['template'] ?? 'classic';
        $pinterestBoards = $post['pinterest_boards'] ?? [];
        $rawBoard = $pinterestBoards[$templateKey]
            ?? $pinterestBoards['classic']
            ?? $post['board_name']
            ?? '';
        $boardName = '';
        if (!empty($rawBoard)) {
            $boardName = strtolower(str_replace(' ', '-', $rawBoard));
        } elseif (!empty($post['category_id'])) {
            $boardName = $catIdToSlug[$post['category_id']] ?? '';
        }
        $boardName = preg_replace('/[^a-z0-9\-]/', '', $boardName);
        if (empty($boardName)) $boardName = 'posts';

        // Link — recipe_card/overlay_list suivent leur config propre, autres templates suivent linkActive
        preg_match('/_image_(\d+)/', $template['fileName'] ?? '', $imgMatch);
        $imgNum = $imgMatch[1] ?? ($weekIdx + 1);
        $tplType = $template['type'] ?? 'template';
        $isNoLink = ($tplType === 'recipe_card'  && !_cfg('recipe_card_LINK_ACTIVE',  false))
                 || ($tplType === 'overlay_list' && !_cfg('overlay_list_LINK_ACTIVE', false))
                 || (!in_array($tplType, ['recipe_card', 'overlay_list']) && !empty($template['no_link']));
        $link = ($linkActive && !$isNoLink)
            ? $imageBase . '/posts/' . $slug . '/?src=' . $slug . '-image-' . $imgNum
            : '';

        // Publish date — ~1h apart starting at 16:00
        $minutesFromMidnight = $currentMinute + rand(0, 15);
        $currentMinute      += 60 + rand(-10, 10);
        $isNextDay           = $minutesFromMidnight >= 1440;
        $actualDate          = clone $groupDate;
        if ($isNextDay) $actualDate->modify('+1 day');
        $h           = (int)(($minutesFromMidnight % 1440) / 60);
        $m           = $minutesFromMidnight % 60;
        $publishDate = $actualDate->format('Y-m-d') . 'T' . sprintf('%02d:%02d:00', $h, $m);

        // Capturer le timestamp du premier groupe pour FB scheduling
        if ($weekIdx === 0 && !isset($fbScheduleTimes[$slug])) {
            $fbScheduleTimes[$slug] = strtotime($publishDate);
        }

        $lines[] = implode(',', [
            csvText($title),
            csvField($mediaUrl),
            csvField($boardName),
            '',              // Thumbnail: vide pour image pin (Media URL est déjà l'image)
            csvText($description),
            csvField($link),
            csvField($publishDate),
            csvField($keywords),
        ]);

        // ── Video pin : 1 ligne MP4 par post, uniquement sur le 1er groupe ────
        // Media URL = reel MP4 servi DIRECTEMENT par le serveur (PAS git → push rapide).
        // Thumbnail = image du pin (cover, sur raw GitHub).
        if ($weekIdx === 0 && !empty($videoReadySlugs[$slug])) {
            $_vBase = (defined('PINTEREST_VIDEO_BASE_URL') && PINTEREST_VIDEO_BASE_URL)
                    ? PINTEREST_VIDEO_BASE_URL
                    : ('https://' . HOST_NAME);
            // Si l'URL pointe sur une IP brute (pas de domaine), forcer HTTP —
            // les IPs n'ont généralement pas de cert SSL valide, HTTPS échoue côté Pinterest.
            if (preg_match('/^https?:\/\/\d+\.\d+\.\d+\.\d+/', $_vBase)) {
                $_vBase = preg_replace('/^https:\/\//', 'http://', $_vBase);
            }
            $videoUrl   = rtrim($_vBase, '/') . '/posts/' . $slug . '/images/' . $slug . '_reel.mp4';
            $videoMin   = $currentMinute + rand(0, 15);
            $videoDateO = clone $groupDate;
            if ($videoMin >= 1440) $videoDateO->modify('+1 day');
            $vh = (int)(($videoMin % 1440) / 60); $vm = $videoMin % 60;
            $videoDate  = $videoDateO->format('Y-m-d') . 'T' . sprintf('%02d:%02d:00', $vh, $vm);
            $currentMinute += 60 + rand(-10, 10);

            $lines[] = implode(',', [
                csvText($title),
                csvField($videoUrl),    // Media URL = MP4
                csvField($boardName),
                csvField($mediaUrl),    // Thumbnail = cover image
                csvText($description),
                csvField($link),
                csvField($videoDate),
                csvField($keywords),
            ]);
        }
    }

    if (count($lines) > 1) { // at least 1 pin in this group
        $csvFiles[] = [
            'filename' => 'pinterest_' . $groupDateStr . '.csv',
            'content'  => implode("\r\n", $lines),
            'rows'     => count($lines) - 1,
        ];
    }
}

// ── 7. Save CSV files to downloads/ ──────────────────────────────────────────
$downloadsDir = __DIR__ . '/downloads';
if (!is_dir($downloadsDir)) mkdir($downloadsDir, 0755, true);

$totalRows = 0;
foreach ($csvFiles as $f) {
    $csvPath = $downloadsDir . '/' . $f['filename'];
    $written = file_put_contents($csvPath, $f['content']);
    if ($isCli) echo "  📄 Saved: " . $f['filename'] . " (" . $f['rows'] . " rows, " . ($written !== false ? $written . ' bytes' : 'FAILED') . ")\n";
    $totalRows += $f['rows'];
}

// Save FB schedule manifest — read by fb-from-csv.php to schedule on Facebook
if (!empty($fbScheduleTimes) && !empty($selected)) {
    $fbScheduleData = [];
    foreach ($selected as $post) {
        $slug   = $post['_slug'];
        $srcImg = $post['_src'][0] ?? null;
        if (!isset($fbScheduleTimes[$slug]) || !$srcImg) continue;
        $fbScheduleData[] = [
            'slug'         => $slug,
            'scheduled_ts' => $fbScheduleTimes[$slug],
            'title'        => $post['title']       ?? $slug,
            'description'  => $post['description'] ?? '',
            'img_file'     => $srcImg['fileName']  ?? '',
        ];
    }
    if (!empty($fbScheduleData)) {
        $schedPath = $downloadsDir . '/fb-schedule-' . date('Y-m-d') . '.json';
        file_put_contents($schedPath, json_encode($fbScheduleData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($isCli) echo "  📘 FB schedule saved: fb-schedule-" . date('Y-m-d') . ".json (" . count($fbScheduleData) . " posts)\n";
    }
}

$firstFilename = $csvFiles[0]['filename'] ?? ('pinterest_daily_' . date('Y-m-d') . '.csv');
$fileNames     = implode(', ', array_column($csvFiles, 'filename'));

// ── Git push (fin du pipeline — après CSV sauvegardé) ────────────────────────
$_pushLog = __DIR__ . '/downloads/push-debug.log';
$_plog = function(string $msg) use ($_pushLog) {
    $line = '[' . date('H:i:s') . '] ' . $msg . "\n";
    file_put_contents($_pushLog, $line, FILE_APPEND);
};

if (!$dryRun && defined('REPO_PATH') && is_dir(REPO_PATH)) {
    file_put_contents($_pushLog, '[' . date('Y-m-d H:i:s') . '] === GIT PUSH START ===' . "\n");
    $_plog('REPO_PATH=' . REPO_PATH);
    $_plog('GIT_MODE=' . (defined('GIT_MODE') ? GIT_MODE : 'undef'));
    $_plog('GITHUB_REPO=' . (defined('GITHUB_REPO') ? GITHUB_REPO : 'undef'));
    $_plog('BRANCH=' . (defined('BRANCH') ? BRANCH : 'undef'));
    $_plog('GITHUB_USER=' . (defined('GITHUB_USER') ? GITHUB_USER : 'undef'));
    $_plog('GITHUB_PASSWORD=' . (defined('GITHUB_PASSWORD') && GITHUB_PASSWORD ? 'YES(' . strlen(GITHUB_PASSWORD) . 'chars)' : 'NOT SET'));
    $_plog('SSH_KEY=' . (defined('SSH_KEY') && SSH_KEY ? 'YES(' . strlen(SSH_KEY) . 'chars)' : 'NOT SET'));

    progress('🚀', 'Git push...');
    $prevDir = getcwd();
    chdir(REPO_PATH);
    $_plog('cwd=' . getcwd());

    $sshCmd     = '';
    $tmpKeyFile = null;
    $remoteUrl  = defined('GITHUB_REPO') ? GITHUB_REPO : '';

    if (defined('GIT_MODE') && GIT_MODE === 'ssh' && defined('SSH_KEY') && SSH_KEY) {
        $_plog('Mode: SSH');
        $tmpKeyFile = tempnam(sys_get_temp_dir(), 'git_ssh_');
        $keyContent = str_replace(["\r\n", "\r"], "\n", SSH_KEY);
        if (!str_ends_with($keyContent, "\n")) $keyContent .= "\n";
        file_put_contents($tmpKeyFile, $keyContent);
        chmod($tmpKeyFile, 0600);
        $sshCmd = 'GIT_SSH_COMMAND=' . escapeshellarg('ssh -i ' . $tmpKeyFile . ' -o StrictHostKeyChecking=no -o BatchMode=yes') . ' ';
        $_plog('tmpKeyFile=' . $tmpKeyFile . ' perms=' . decoct(fileperms($tmpKeyFile) & 0777));
    } elseif (defined('GITHUB_USER') && GITHUB_USER && defined('GITHUB_PASSWORD') && GITHUB_PASSWORD && $remoteUrl) {
        $_plog('Mode: HTTPS');
        if (strpos($remoteUrl, '@') !== false) {
            $remoteUrl = preg_replace('#^https://[^@]+@#', 'https://', $remoteUrl);
        }
        $remoteUrl = str_replace('https://', 'https://' . urlencode(GITHUB_USER) . ':' . urlencode(GITHUB_PASSWORD) . '@', $remoteUrl);
        exec('git remote set-url origin ' . escapeshellarg($remoteUrl) . ' 2>&1');
        $_plog('Remote set to: https://[user]:[token]@' . (parse_url($remoteUrl, PHP_URL_HOST) ?? '?') . (parse_url($remoteUrl, PHP_URL_PATH) ?? ''));
    } else {
        $_plog('Mode: NONE — no auth configured');
    }

    $msg = "Update from web: " . date('Y-m-d H:i:s');

    $addOut = []; exec($sshCmd . 'git add -A 2>&1', $addOut);
    $_plog('git add: ' . implode(' | ', $addOut));

    $commitOut = []; $commitCode = 0;
    exec($sshCmd . 'git commit -m ' . escapeshellarg($msg) . ' 2>&1', $commitOut, $commitCode);
    $nothingToCommit = stripos(implode('', $commitOut), 'nothing to commit') !== false;
    $_plog('git commit code=' . $commitCode . ': ' . implode(' | ', array_slice($commitOut, 0, 3)));

    $pullOut = []; $pullCode = 0;
    exec($sshCmd . 'git pull --rebase origin ' . escapeshellarg(BRANCH) . ' 2>&1', $pullOut, $pullCode);
    $_plog('git pull code=' . $pullCode . ': ' . implode(' | ', array_slice($pullOut, 0, 3)));

    $pushCode = 1; $pushOut = [];
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        if ($attempt > 1) sleep([0,5,10,20,30][$attempt-1]);
        $pushOut = [];
        exec($sshCmd . 'git push origin ' . escapeshellarg(BRANCH) . ' --force 2>&1', $pushOut, $pushCode);
        $_plog("git push attempt=$attempt code=$pushCode: " . implode(' | ', array_slice($pushOut, 0, 5)));
        if ($pushCode === 0) break;
    }

    if ($pushCode === 0 || $nothingToCommit) {
        $pushResult['main'] = '✅ push OK';
        $_plog('RESULT: SUCCESS');
    } else {
        $pushResult['main'] = '❌ push failed: ' . mb_substr(trim(implode(' | ', array_filter(array_map('trim', $pushOut)))), 0, 200);
        $_plog('RESULT: FAILED — ' . $pushResult['main']);
    }

    if ($tmpKeyFile && file_exists($tmpKeyFile)) unlink($tmpKeyFile);
    chdir($prevDir);
    $_plog('=== GIT PUSH END ===');
} else {
    $_plog('[' . date('Y-m-d H:i:s') . '] SKIPPED — dryRun=' . ($dryRun?'true':'false') . ' REPO_PATH=' . (defined('REPO_PATH') ? REPO_PATH . ' exists=' . (is_dir(REPO_PATH)?'yes':'no') : 'NOT DEFINED'));
}

// ── Facebook cross-post — schedule directement pendant la création du CSV ─────
if (!$dryRun && defined('FACEBOOK_CROSSPOST_ACTIVE') && FACEBOOK_CROSSPOST_ACTIVE && !empty($selected) && !empty($fbScheduleTimes)) {
    if ($isCli) echo "\n  📘 Facebook scheduling — " . count($selected) . " articles\n";
    require_once __DIR__ . '/fb-crosspost.php';
    fb_crosspost_pins(
        $selected,
        (int)(defined('PIN_SCHEDULE_START') ? PIN_SCHEDULE_START : 16),
        (int)(defined('PIN_SCHEDULE_END')   ? PIN_SCHEDULE_END   : 4),
        $rawImageBase,
        $fbScheduleTimes
    );
}

// Mark progress as done
file_put_contents($_progressFile, json_encode([
    'icon'    => '✅',
    'message' => 'Terminé — ' . count($selected) . ' articles, ' . $totalRows . ' pins, ' . count($csvFiles) . ' fichiers',
    'detail'  => $fileNames,
    'time'    => date('H:i:s'),
    'done'    => true,
]));

ob_end_clean();

if ($isCli) {
    echo "[" . date('Y-m-d H:i:s') . "] ✅ " . count($csvFiles) . " CSV sauvegardés ($totalRows lignes, " . count($selected) . " articles)\n";
    foreach ($csvFiles as $f) {
        echo "   📄 downloads/" . $f['filename'] . " (" . $f['rows'] . " pins)\n";
    }
    foreach ($selected as $p) {
        echo "   • " . ($p['title'] ?? $p['_slug']) . "\n";
    }
} else {
    echo json_encode([
        'success'  => true,
        'count'    => count($selected),
        'rows'     => $totalRows,
        'dry_run'  => $dryRun,
        'articles' => array_map(fn($p) => ['slug' => $p['_slug'], 'title' => $p['title'] ?? ''], $selected),
        'files'    => array_map(fn($f) => [
            'filename' => $f['filename'],
            'rows'     => $f['rows'],
            'csvData'  => base64_encode($f['content']),
        ], $csvFiles),
        // backward compat
        'filename' => $firstFilename,
        'csvData'  => base64_encode($csvFiles[0]['content'] ?? ''),
        'push'     => $pushResult ?? [],
    ], JSON_UNESCAPED_UNICODE);
}

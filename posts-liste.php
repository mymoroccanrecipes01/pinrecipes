<?php
// ========================================
// TRAITEMENT DES REQUÊTES AJAX EN PREMIER
// (avant toute inclusion pour éviter les erreurs HTML)
// ========================================

// Handle AJAX get images
if (isset($_GET['dir'])) {
    $dir = $_GET['dir'] ?? '';
    $images = [];

    if ($dir && is_dir($dir)) {
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                $images[] = $dir . '/' . $file;
            }
        }
    }

    header('Content-Type: application/json');
    echo json_encode($images);
    exit;
}

// Handle AJAX update isOnline - DOIT ÊTRE AVANT require_once config.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_ajax') {
    // On inclut seulement ce qui est nécessaire pour cette requête
    require_once 'config.php';

    header('Content-Type: application/json');

    try {
        if (!isset($_POST['post_path'])) {
            throw new Exception('post_path manquant');
        }

        $postPath = $_POST['post_path'];
        $isOnline = isset($_POST['is_online']) && $_POST['is_online'] === 'true';

        $post = getpost($postPath);
        if (!$post) {
            throw new Exception('Post non trouvé');
        }

        $post['isOnline'] = $isOnline;
        $post['createdAt'] = date('Y-m-d\TH:i:sP');
        $post['updatedAt'] = date('Y-m-d\TH:i:sP');

        $result = savepost($postPath, $post);
        if ($result === false) {
            throw new Exception('Erreur lors de la sauvegarde du post');
        }

        // ✅ GÉNÉRATIONS AUTOMATIQUES APRÈS SAUVEGARDE (les erreurs ici ne bloquent pas)
        try {
            $postsDir = __DIR__ . '/posts';
            generateSitemaps($postsDir);
            // generatePinterestRSSFeed($postsDir);
            // generateAllCategoryRSSFeeds('./categories', $postsDir);
        } catch (Exception $e) {
            error_log('Erreur génération automatique: ' . $e->getMessage());
            // On continue quand même, l'update du post est réussi
        }
        // Rebuild enriched index.json so front-end loads 1 file instead of N
        try { _rebuild_posts_index(__DIR__); } catch (Exception $e) { error_log('_rebuild_posts_index: ' . $e->getMessage()); }

        // 🔄 Sync isOnline vers satellites
        $slugMatch = [];
        if (preg_match('#posts/([^/]+)/post\.json#', $postPath, $slugMatch)) {
            $syncSlug = $slugMatch[1];
            if ($isOnline) {
                // Propagation complète : copie + rewrite + templates + isOnline (fire & forget)
                $pipelineUrl = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                             . rtrim(dirname($_SERVER['REQUEST_URI'] ?? '/'), '/') . '/auto-daily-csv.php';
                $ch = curl_init($pipelineUrl);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => http_build_query(['force_slug' => $syncSlug]),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 5,
                ]);
                curl_exec($ch);
                curl_close($ch);
            } else {
                // Juste passer isOnline=false dans les satellites (pas de pipeline)
                foreach (SATELLITE_PROJECTS as $satellite) {
                    $satPath = realpath(__DIR__ . '/' . $satellite['path']);
                    if (!$satPath) continue;
                    $satJson = $satPath . '/posts/' . $syncSlug . '/post.json';
                    if (file_exists($satJson)) {
                        $satPost = json_decode(file_get_contents($satJson), true);
                        if ($satPost) {
                            $satPost['isOnline'] = false;
                            $satPost['updatedAt'] = date('Y-m-d\TH:i:sP');
                            file_put_contents($satJson, json_encode($satPost, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        }
                    }
                }
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Post mis à jour avec succès!',
            'isOnline' => $isOnline
        ]);

    } catch (Exception $e) {
        error_log('Erreur update isOnline: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Erreur: ' . $e->getMessage()
        ]);
    }
    exit;
}

// ========================================
// AJAX: Generate missing index.html for posts
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_missing_html') {
    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    }, E_ERROR | E_PARSE);

    try {
        ob_start();

        // If the current drag-drop layout was sent, persist it to site-config.json
        // BEFORE config.php reads and defines POST_LAYOUT — this way the constant
        // will reflect the user's latest order even without an explicit save first.
        if (!empty($_POST['OVERRIDE_LAYOUT']) && !defined('HOST_NAME')) {
            $__newLayout = json_decode($_POST['OVERRIDE_LAYOUT'], true);
            if (is_array($__newLayout) && count($__newLayout) > 0) {
                $__scFile = __DIR__ . '/site-config.json';
                $__sc     = file_exists($__scFile) ? (json_decode(file_get_contents($__scFile), true) ?: []) : [];
                $__sc['POST_LAYOUT'] = $__newLayout;
                file_put_contents($__scFile, json_encode($__sc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                unset($__scFile, $__sc, $__newLayout);
            }
        }

        if (!defined('HOST_NAME')) require_once __DIR__ . '/config.php';

        // Extract PostHTMLGenerator class exactly like generate-post.php does
        if (!class_exists('PostHTMLGenerator')) {
            $__src = file_get_contents(__DIR__ . '/generate-single-post.php');
            preg_match('/class PostHTMLGenerator \{.*?^}/ms', $__src, $__m);
            if (!empty($__m[0])) eval($__m[0]);
            unset($__src, $__m);
        }

        $postsDir  = __DIR__ . '/posts';
        $generated = [];
        $errors    = [];

        $dirs = glob($postsDir . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($dirs as $dir) {
            $slug       = basename($dir);
            $jsonPath   = $dir . '/post.json';
            $outputPath = $dir . '/index.html';
            if (!file_exists($jsonPath)) continue;

            try {
                $gen = new PostHTMLGenerator($jsonPath);
                $gen->saveFile($outputPath);
                $generated[] = $slug;
            } catch (Throwable $e) {
                $errors[] = ['slug' => $slug, 'error' => $e->getMessage()];
            }
        }

        ob_end_clean();
        restore_error_handler();
        header('Content-Type: application/json');
        echo json_encode([
            'success'         => true,
            'generated'       => $generated,
            'generated_count' => count($generated),
            'skipped_count'   => 0,
            'errors'          => $errors
        ]);
        exit;

    } catch (Throwable $__ex) {
        while (ob_get_level() > 0) ob_end_clean();
        restore_error_handler();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $__ex->getMessage(), 'file' => basename($__ex->getFile()), 'line' => $__ex->getLine()]);
        exit;
    }
}

// ========================================
// AJAX: CSV endpoints (early — before config.php)
// ========================================

// Safe CSV path — PHP 7 compatible, no str_ends_with
function _csvSafePath($file) {
    $file = basename((string)$file);
    if ($file === '' || strtolower(substr($file, -4)) !== '.csv') return '';
    $path = __DIR__ . '/downloads/' . $file;
    return file_exists($path) ? $path : '';
}

if (isset($_GET['action']) && $_GET['action'] === 'download_daily_csv') {
    ob_end_clean();
    error_reporting(0);
    $file = basename((string)($_GET['file'] ?? ''));
    if ($file === '' || !_csvSafePath($file)) {
        $found = glob(__DIR__ . '/downloads/pinterest_*.csv') ?: [];
        rsort($found);
        $file = $found ? basename($found[0]) : '';
    }
    $path = _csvSafePath($file);
    if (!$path) { http_response_code(404); echo '{"error":"CSV non trouve"}'; exit; }
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    readfile($path);
    exit;
}

// ── AJAX: Delete post ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_post') {
    ob_end_clean();
    header('Content-Type: application/json');
    $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_POST['slug'] ?? '')));
    if (!$slug) { echo json_encode(['success' => false, 'error' => 'Slug invalide']); exit; }
    $postDir = __DIR__ . '/posts/' . $slug;
    if (!is_dir($postDir)) { echo json_encode(['success' => false, 'error' => 'Post introuvable']); exit; }
    // Supprimer le dossier récursivement
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($postDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $f) { $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath()); }
    rmdir($postDir);
    // Mettre à jour posts/index.json
    $indexFile = __DIR__ . '/posts/index.json';
    if (file_exists($indexFile)) {
        $idx = json_decode(file_get_contents($indexFile), true) ?? [];
        if (isset($idx['folders'][$slug])) { unset($idx['folders'][$slug]); }
        $idx['folders'] = array_filter($idx['folders'] ?? []);
        file_put_contents($indexFile, json_encode($idx, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    echo json_encode(['success' => true, 'slug' => $slug]);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'delete_csv') {
    ob_end_clean();
    error_reporting(0);
    header('Content-Type: application/json');
    $path = _csvSafePath($_GET['file'] ?? '');
    if (!$path) { echo '{"success":false,"error":"Fichier introuvable"}'; exit; }
    unlink($path);
    echo json_encode(['success' => true, 'deleted' => basename($path)]);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'check_daily_csv') {
    ob_end_clean();
    error_reporting(0);
    header('Content-Type: application/json');
    $files = glob(__DIR__ . '/downloads/pinterest_*.csv') ?: [];
    rsort($files);
    $today = date('Y-m-d');
    $list  = [];
    foreach ($files as $f) {
        $name = basename($f);
        preg_match('/(\d{4}-\d{2}-\d{2})/', $name, $m);
        $date  = isset($m[1]) ? $m[1] : '';
        $label = $date === '' ? $name
               : ($date === $today  ? 'Aujourd\'hui'
               : ($date  >  $today  ? 'Le ' . $date
               :                      'En retard ' . $date));
        $list[] = [
            'filename' => $name,
            'date'     => $date !== '' ? $date : $name,
            'label'    => $label,
            'rows'     => max(0, count(file($f)) - 1),
        ];
    }
    echo json_encode(['exists' => !empty($list), 'files' => $list, 'date' => $today]);
    exit;
}

// ========================================
// AJAX: Save pipeline limit settings
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_pipeline_limits') {
    header('Content-Type: application/json');
    $settingsFile = __DIR__ . '/settings.json';
    $settings = file_exists($settingsFile) ? (json_decode(file_get_contents($settingsFile), true) ?? []) : [];
    $min = max(1, (int)($_POST['min'] ?? 5));
    $max = max($min, (int)($_POST['max'] ?? 15));
    $settings['pipelineLimitMin'] = $min;
    $settings['pipelineLimitMax'] = $max;
    file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT));
    echo json_encode(['success' => true, 'min' => $min, 'max' => $max]);
    exit;
}

// ========================================
// AJAX: Toggle linkPinActive setting
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_link_pin') {
    header('Content-Type: application/json');
    $settingsFile = __DIR__ . '/settings.json';
    $settings = file_exists($settingsFile) ? (json_decode(file_get_contents($settingsFile), true) ?? []) : [];
    $settings['linkPinActive'] = isset($_POST['value']) && $_POST['value'] === 'true';
    file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT));
    echo json_encode(['success' => true, 'linkPinActive' => $settings['linkPinActive']]);
    exit;
}

// ========================================
// AJAX: Post selected slugs to Facebook
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fb_post_slugs') {
    ob_end_clean();
    header('Content-Type: application/json');
    if (!defined('HOST_NAME')) require_once __DIR__ . '/config.php';

    $slugsRaw = $_POST['slugs'] ?? [];
    if (!is_array($slugsRaw) || empty($slugsRaw)) {
        echo json_encode(['success' => false, 'error' => 'Aucun article sélectionné']); exit;
    }
    $slugs = array_map(fn($s) => preg_replace('/[^a-z0-9\-]/', '', strtolower($s)), $slugsRaw);
    $slugs = array_filter($slugs);

    $pageId   = defined('FACEBOOK_PAGE_ID')      ? FACEBOOK_PAGE_ID      : '';
    $token    = defined('FACEBOOK_ACCESS_TOKEN') ? FACEBOOK_ACCESS_TOKEN : '';
    $hashtags = defined('FACEBOOK_HASHTAGS')     ? FACEBOOK_HASHTAGS     : '';
    $pageUrl  = defined('FACEBOOK_PAGE_URL')     ? FACEBOOK_PAGE_URL     : '';
    $siteBase = 'https://' . (defined('HOST_NAME') ? HOST_NAME : ($_SERVER['HTTP_HOST'] ?? 'localhost'));

    if (!$pageId || !$token) {
        echo json_encode(['success' => false, 'error' => 'FACEBOOK_PAGE_ID ou FACEBOOK_ACCESS_TOKEN manquant dans la config']); exit;
    }

    $results = [];
    foreach ($slugs as $slug) {
        $jsonPath = __DIR__ . '/posts/' . $slug . '/post.json';
        if (!file_exists($jsonPath)) {
            $results[] = ['slug' => $slug, 'ok' => false, 'msg' => 'post.json introuvable'];
            continue;
        }
        $post = json_decode(file_get_contents($jsonPath), true);
        if (!$post) {
            $results[] = ['slug' => $slug, 'ok' => false, 'msg' => 'post.json invalide'];
            continue;
        }

        // Pick first source image (non-template)
        $srcImages = array_values(array_filter($post['images'] ?? [], fn($i) => ($i['type'] ?? '') !== 'template'));
        if (empty($srcImages)) $srcImages = $post['images'] ?? [];
        if (empty($srcImages)) {
            $results[] = ['slug' => $slug, 'ok' => false, 'msg' => 'Pas d\'image disponible'];
            continue;
        }
        $imgFile = $srcImages[0]['fileName'] ?? $srcImages[0]['filePath'] ?? '';
        if (!$imgFile) {
            $results[] = ['slug' => $slug, 'ok' => false, 'msg' => 'Chemin image invalide'];
            continue;
        }
        $imgUrl = $siteBase . '/posts/' . $slug . '/images/' . basename($imgFile);

        // Compose message — NO link in description (algo penalise les liens externes)
        $ctaText     = defined('FACEBOOK_CTA_TEXT') ? FACEBOOK_CTA_TEXT : 'Get the full recipe at';
        $title       = trim($post['title'] ?? $slug);
        $description = trim($post['description'] ?? '');
        if (mb_strlen($description) > 300) $description = mb_substr($description, 0, 297) . '...';
        $postUrl     = $siteBase . '/posts/' . $slug . '/';
        $parts = [$title];
        if ($description) $parts[] = $description;
        if ($hashtags)    $parts[] = $hashtags;
        $parts[]  = '👇 ' . $ctaText . ' (link in first comment)';
        $message  = implode("\n\n", array_filter($parts));

        // Post to Facebook immediately
        $ch = curl_init('https://graph.facebook.com/v19.0/' . $pageId . '/photos');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_POSTFIELDS     => [
                'url'          => $imgUrl,
                'message'      => $message,
                'published'    => 'true',
                'access_token' => $token,
            ],
        ]);
        $resp    = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            $results[] = ['slug' => $slug, 'ok' => false, 'msg' => 'cURL: ' . $curlErr];
            continue;
        }
        $data = json_decode($resp, true);
        if (!empty($data['id'])) {
            $photoId = $data['id'];

            // Post comment with the link (meilleur reach que lien dans description)
            $commentText = '👇 ' . $ctaText . "\n" . $postUrl;
            $chC = curl_init('https://graph.facebook.com/v19.0/' . $photoId . '/comments');
            curl_setopt_array($chC, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_POSTFIELDS     => ['message' => $commentText, 'access_token' => $token],
            ]);
            $commentResp = curl_exec($chC);
            curl_close($chC);
            $commentData = json_decode($commentResp, true);
            $commentId   = $commentData['id'] ?? null;

            // Log
            $logFile  = __DIR__ . '/fb_posted_log.json';
            $log      = file_exists($logFile) ? (json_decode(file_get_contents($logFile), true) ?? []) : [];
            $log[]    = ['slug' => $slug, 'photo_id' => $photoId, 'comment_id' => $commentId, 'posted_at' => date('Y-m-d H:i:s'), 'source' => 'manual_post'];
            file_put_contents($logFile, json_encode($log, JSON_PRETTY_PRINT));
            $msgOk = 'Posté — id=' . $photoId . ($commentId ? ' + comment ✅' : ' (comment ❌)');
            $results[] = ['slug' => $slug, 'ok' => true, 'msg' => $msgOk];
        } else {
            $errMsg = $data['error']['message'] ?? ('Réponse: ' . $resp);
            $results[] = ['slug' => $slug, 'ok' => false, 'msg' => $errMsg];
        }
    }

    $ok  = count(array_filter($results, fn($r) => $r['ok']));
    $ko  = count($results) - $ok;
    echo json_encode(['success' => $ok > 0, 'ok' => $ok, 'ko' => $ko, 'results' => $results]);
    exit;
}

// ========================================
// CHARGEMENT NORMAL DE LA PAGE
// ========================================

// Configuration
$postsBasePath = __DIR__ . '/posts';
$categoriesPath = __DIR__ . '/categories/index.json';
$itemsPerPage = isset($_GET['per_page']) ? max(5, min(50, intval($_GET['per_page']))) : 10;

require_once 'config.php';
require_once __DIR__ . '/auth.php';
auth_check();

// Read runtime settings (override config.php defaults)
$_settingsFile = __DIR__ . '/settings.json';
$_settings = file_exists($_settingsFile) ? (json_decode(file_get_contents($_settingsFile), true) ?? []) : [];
$linkPinActive     = isset($_settings['linkPinActive']) ? (bool)$_settings['linkPinActive'] : LINK_PIN_ACTIVE;
$lastDailyRun      = $_settings['lastDailyRun'] ?? '';
$pipelineLimitMin  = (int)($_settings['pipelineLimitMin'] ?? 5);
$pipelineLimitMax  = (int)($_settings['pipelineLimitMax'] ?? 15);

// Only generate posts index if not an AJAX request
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action'])) {
    generatepostsIndex();
}

if (isset($_GET['action']) && $_GET['action'] === 'generate_file_posts') {
    // Générer le fichier posts-liste.php
    $result = generatepostsIndex();
}
// Fonction pour générer RSS par catégorie depuis mn_pinrss
function generatePinterestRSSByCategory($pdo, $categorySlug = null) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = HOST_NAME;
    $siteUrl = $protocol . '://' . $host;

    // Si pas de catégorie spécifiée, générer pour toutes
    if ($categorySlug === null) {
        // Récupérer toutes les catégories distinctes
        $stmt = $pdo->query("SELECT DISTINCT CategorySlug FROM mn_pinrss WHERE CategorySlug IS NOT NULL AND CategorySlug != '' AND IsDelete = 0");
        $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $results = [];
        foreach ($categories as $cat) {
            $results[$cat] = generatePinterestRSSByCategory($pdo, $cat);
        }
        return $results;
    }

    // Récupérer les items de cette catégorie
    $stmt = $pdo->prepare("
        SELECT * FROM mn_pinrss
        WHERE CategorySlug = ? AND IsDelete = 0
        ORDER BY CreateAt DESC
        LIMIT 50
    ");
    $stmt->execute([$categorySlug]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        return ['success' => false, 'message' => 'Aucun item pour cette catégorie'];
    }

    // Construire le RSS
    $categoryName = $items[0]['category'] ?? $categorySlug;
    $currentDate = date('r');

    $rssXml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
    $rssXml .= '<rss version="2.0"
     xmlns:content="http://purl.org/rss/1.0/modules/content/"
     xmlns:media="http://search.yahoo.com/mrss/"
     xmlns:dc="http://purl.org/dc/elements/1.1/"
     xmlns:atom="http://www.w3.org/2005/Atom">' . PHP_EOL;

    $rssXml .= '  <channel>' . PHP_EOL;
    $rssXml .= '    <title>' . htmlspecialchars($categoryName . ' - Pinterest Feed', ENT_XML1, 'UTF-8') . '</title>' . PHP_EOL;
    $rssXml .= '    <link>' . htmlspecialchars($siteUrl, ENT_XML1, 'UTF-8') . '</link>' . PHP_EOL;
    $rssXml .= '    <description>' . htmlspecialchars('Latest ' . $categoryName . ' posts and content', ENT_XML1, 'UTF-8') . '</description>' . PHP_EOL;
    $rssXml .= '    <language>en-US</language>' . PHP_EOL;
    $rssXml .= '    <pubDate>' . $currentDate . '</pubDate>' . PHP_EOL;
    $rssXml .= '    <lastBuildDate>' . $currentDate . '</lastBuildDate>' . PHP_EOL;
    $rssXml .= '    <atom:link href="' . $siteUrl . '/rss/pinterest-' . $categorySlug . '.xml" rel="self" type="application/rss+xml" />' . PHP_EOL;

    foreach ($items as $item) {
        $pubDate = date('r', strtotime($item['CreateAt']));

        // Remplacer localhost par le domaine configuré dans les URLs
        // et enlever le chemin du projet local
        $itemLink = $item['link'];
        $itemImage = $item['image'];

        // Remplacer localhost par le domaine
        $itemLink = str_replace('localhost', HOST_NAME, $itemLink);
        $itemImage = str_replace('localhost', HOST_NAME, $itemImage);

        // Enlever le chemin du projet (ex: /SitePinterset/mollykitchendaily-main)
        // pour garder seulement /posts/... ou /images/...
        $itemLink = preg_replace('#^(https?://[^/]+)/.*?/(posts|categories|images)/#i', '$1/$2/', $itemLink);
        $itemImage = preg_replace('#^(https?://[^/]+)/.*?/(posts|categories|images)/#i', '$1/$2/', $itemImage);

        // Extraire les hashtags de la description
        $hashtags = [];
        if (!empty($item['description'])) {
            preg_match_all('/#(\w+)/u', $item['description'], $matches);
            if (!empty($matches[1])) {
                $hashtags = $matches[1];
            }
        }

        $rssXml .= '    <item>' . PHP_EOL;
        $rssXml .= '      <title>' . htmlspecialchars($item['title'], ENT_XML1, 'UTF-8') . '</title>' . PHP_EOL;
        $rssXml .= '      <link>' . htmlspecialchars($itemLink, ENT_XML1, 'UTF-8') . '</link>' . PHP_EOL;
        $rssXml .= '      <description>' . htmlspecialchars($item['description'], ENT_XML1, 'UTF-8') . '</description>' . PHP_EOL;
        $rssXml .= '      <pubDate>' . $pubDate . '</pubDate>' . PHP_EOL;
        $rssXml .= '      <dc:date>' . date('c', strtotime($item['CreateAt'])) . '</dc:date>' . PHP_EOL;
        $rssXml .= '      <dc:creator>' . htmlspecialchars(SITE_MANAGER, ENT_XML1, 'UTF-8') . '</dc:creator>' . PHP_EOL;
        $rssXml .= '      <guid isPermaLink="true">' . htmlspecialchars($itemLink, ENT_XML1, 'UTF-8') . '</guid>' . PHP_EOL;
        $rssXml .= '      <category>' . htmlspecialchars($item['category'], ENT_XML1, 'UTF-8') . '</category>' . PHP_EOL;

        // Ajouter les hashtags comme catégories supplémentaires
        foreach ($hashtags as $tag) {
            $rssXml .= '      <category>' . htmlspecialchars($tag, ENT_XML1, 'UTF-8') . '</category>' . PHP_EOL;
        }

        if (!empty($item['image'])) {
            $rssXml .= '      <enclosure url="' . htmlspecialchars($itemImage, ENT_XML1, 'UTF-8') . '" type="image/webp" />' . PHP_EOL;
            $rssXml .= '      <media:content url="' . htmlspecialchars($itemImage, ENT_XML1, 'UTF-8') . '" medium="image" type="image/webp">' . PHP_EOL;
            $rssXml .= '        <media:title>' . htmlspecialchars($item['title'], ENT_XML1, 'UTF-8') . '</media:title>' . PHP_EOL;
            $rssXml .= '        <media:description>' . htmlspecialchars($item['description'], ENT_XML1, 'UTF-8') . '</media:description>' . PHP_EOL;
            $rssXml .= '      </media:content>' . PHP_EOL;
        }

        $rssXml .= '    </item>' . PHP_EOL;
    }

    $rssXml .= '  </channel>' . PHP_EOL;
    $rssXml .= '</rss>' . PHP_EOL;

    // Sauvegarder dans 2 emplacements:
    // 1. Dossier /rss/ général
    // 2. Dossier de la catégorie /categories/{slug}/

    $savedPaths = [];
    $errors = [];

    // Emplacement 1: Dossier RSS général
    $rssDir = './rss';
    if (!is_dir($rssDir)) {
        mkdir($rssDir, 0755, true);
    }
    $rssPath1 = $rssDir . '/pinterest-' . $categorySlug . '.xml';

    // Supprimer l'ancien fichier s'il existe
    if (file_exists($rssPath1)) {
        @unlink($rssPath1);
    }
    if (file_put_contents($rssPath1, $rssXml) !== false) {
        @chmod($rssPath1, 0644);
        $savedPaths[] = $rssPath1;
    } else {
        $errors[] = 'Erreur écriture ' . $rssPath1;
    }

    // Emplacement 2: Dossier de la catégorie
    $categoryDir = './categories/' . $categorySlug;
    if (is_dir($categoryDir)) {
        $rssPath2 = $categoryDir . '/rss.xml';

        // Supprimer l'ancien fichier s'il existe
        if (file_exists($rssPath2)) {
            @unlink($rssPath2);
        }

        if (file_put_contents($rssPath2, $rssXml) !== false) {
            @chmod($rssPath2, 0644);
            $savedPaths[] = $rssPath2;
        } else {
            $errors[] = 'Erreur écriture ' . $rssPath2;
        }
    }

    return [
        'success' => count($savedPaths) > 0,
        'paths' => $savedPaths,
        'errors' => $errors,
        'itemsCount' => count($items),
        'category' => $categoryName,
        'categorySlug' => $categorySlug
    ];
}

// Handle AJAX request pour générer tous les RSS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_all_rss') {
    header('Content-Type: application/json');

    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Générer tous les RSS
        $results = generatePinterestRSSByCategory($pdo, null);

        $totalGenerated = 0;
        $details = [];

        foreach ($results as $slug => $result) {
            if ($result['success']) {
                $totalGenerated++;
                $details[] = $result['category'] . ' (' . $result['itemsCount'] . ' items)';
            }
        }

        echo json_encode([
            'success' => true,
            'message' => $totalGenerated . ' flux RSS générés avec succès!',
            'details' => $details,
            'results' => $results
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
    }
    exit;
}

// Handle AJAX request pour insérer dans mn_pinrss
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'insert_pinrss') {
    header('Content-Type: application/json');

    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $title = $_POST['title'] ?? '';
        $description = $_POST['description'] ?? '';
        $link = $_POST['link'] ?? '';
        $image = $_POST['image'] ?? '';
        $category = $_POST['category'] ?? '';
        $categorySlug = $_POST['category_slug'] ?? '';

        $stmt = $pdo->prepare("INSERT INTO mn_pinrss (title, description, link, image, category, CategorySlug, IsPublish, IsDelete, CreateAt) VALUES (?, ?, ?, ?, ?, ?, 0, 0, NOW())");
        $result = $stmt->execute([$title, $description, $link, $image, $category, $categorySlug]);

        if ($result) {
            // Générer automatiquement le RSS pour cette catégorie
            $rssResult = generatePinterestRSSByCategory($pdo, $categorySlug);

            $message = 'Données insérées avec succès!';
            if ($rssResult['success']) {
                $message .= ' RSS généré dans ' . count($rssResult['paths']) . ' emplacement(s)';
            }

            echo json_encode([
                'success' => true,
                'message' => $message,
                'rss_generated' => $rssResult
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Erreur lors de l\'insertion']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Erreur base de données: ' . $e->getMessage()]);
    }
    exit;
}



function createSlug($name) {
    $slug = strtolower($name);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug;
}

function generateUniquepostslug($name) {
    global $postsDir;
    
    $baseSlug = createSlug($name);
    $slug = $baseSlug;
    $counter = 1;
    
    while (is_dir($postsDir . '/' . $slug)) {
        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }
    
    return $slug;
}

function prepareStructuredContent($postData, $slug, $userImages) {
    $content = $postData['structured_content'] ?? [];
    
    $imageIndex = 0;
    foreach ($content as $key => &$section) {
        if (isset($section['upload']) && isset($userImages[$imageIndex])) {
            $section['upload']['url'] = $slug . '/images/' . $userImages[$imageIndex]['fileName'];
            $section['upload']['fileName'] = $userImages[$imageIndex]['fileName'];
            $section['upload']['type'] = $userImages[$imageIndex]['type'] ?? 'main';
            $imageIndex++;
        }
    }
    
    return $content;
}

function generatepostsIndex($postsDir = './posts') {
    if (!is_dir($postsDir)) {
        mkdir($postsDir, 0755, true);
    }
    
    $validFolders = [];
    
    $handle = opendir($postsDir);
    if ($handle) {
        while (($item = readdir($handle)) !== false) {
            if ($item === '.' || $item === '..') continue;
            
            $itemPath = $postsDir . '/' . $item;
            if (is_dir($itemPath) && file_exists($itemPath . '/post.json')) {
                $validFolders[] = $item;
            }
        }
        closedir($handle);
    }
    
    sort($validFolders);
    
    $indexData = [
        'generated' => date('Y-m-d H:i:s'),
        'count' => count($validFolders),
        'folders' => $validFolders
    ];
    
    $jsonContent = json_encode($indexData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $indexPath = $postsDir . '/index.json';
    
    return [
        'success' => file_put_contents($indexPath, $jsonContent) !== false,
        'count' => count($validFolders),
        'path' => $indexPath
    ];
}

// ===== 2. GÉNÉRATION SITEMAPS =====
function generateSitemaps($postsDir = './posts') {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = HOST_NAME;
    $basePage = '/base.html';
    $siteUrl = $protocol . '://' . $host . $basePage;
    $currentDate = date('Y-m-d');
    
    $validFolders = [];
    
    if (is_dir($postsDir)) {
        $handle = opendir($postsDir);
        if ($handle) {
            while (($item = readdir($handle)) !== false) {
                if ($item === '.' || $item === '..') continue;
                
                $itemPath = $postsDir . '/' . $item;
                if (is_dir($itemPath) && file_exists($itemPath . '/post.json')) {
                    $validFolders[] = $item;
                }
            }
            closedir($handle);
        }
    }
    
    sort($validFolders);
    
    // === SITEMAP PRINCIPAL (sitemap.xml) ===
    $sitemapXml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
    $sitemapXml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;
    
    // Page d'accueil
    $sitemapXml .= '  <url>' . PHP_EOL;
    $sitemapXml .= '    <loc>' . $siteUrl . '?page=home</loc>' . PHP_EOL;
    $sitemapXml .= '    <lastmod>' . $currentDate . '</lastmod>' . PHP_EOL;
    $sitemapXml .= '    <changefreq>weekly</changefreq>' . PHP_EOL;
    $sitemapXml .= '    <priority>1.0</priority>' . PHP_EOL;
    $sitemapXml .= '  </url>' . PHP_EOL;
    
    // Page index des posts
    $sitemapXml .= '  <url>' . PHP_EOL;
    $sitemapXml .= '    <loc>' . $siteUrl . '?page=posts</loc>' . PHP_EOL;
    $sitemapXml .= '    <lastmod>' . $currentDate . '</lastmod>' . PHP_EOL;
    $sitemapXml .= '    <changefreq>daily</changefreq>' . PHP_EOL;
    $sitemapXml .= '    <priority>0.9</priority>' . PHP_EOL;
    $sitemapXml .= '  </url>' . PHP_EOL;
    
    // Ajouter chaque post
    foreach ($validFolders as $folder) {
        $postJsonPath = $postsDir . '/' . $folder . '/post.json';

        if (file_exists($postJsonPath)) {
            $fileModTime = filemtime($postJsonPath);
            $lastmod = date('Y-m-d', $fileModTime);

            $sitemapXml .= '  <url>' . PHP_EOL;
            $sitemapXml .= '    <loc>' . $siteUrl . '/posts/' . htmlspecialchars($folder, ENT_XML1, 'UTF-8') . '/</loc>' . PHP_EOL;
            $sitemapXml .= '    <lastmod>' . $lastmod . '</lastmod>' . PHP_EOL;
            $sitemapXml .= '    <changefreq>monthly</changefreq>' . PHP_EOL;
            $sitemapXml .= '    <priority>0.8</priority>' . PHP_EOL;
            $sitemapXml .= '  </url>' . PHP_EOL;
        }
    }
    
    $sitemapXml .= '</urlset>' . PHP_EOL;
    
    // === SITEMAP RECETTES (sitemap-posts.xml) ===
    $postsSitemapXml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
    $postsSitemapXml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;
    
    foreach ($validFolders as $folder) {
        $postJsonPath = $postsDir . '/' . $folder . '/post.json';

        if (file_exists($postJsonPath)) {
            $fileModTime = filemtime($postJsonPath);
            $lastmod = date('Y-m-d', $fileModTime);

            $postsSitemapXml .= '  <url>' . PHP_EOL;
            $postsSitemapXml .= '    <loc>' . $siteUrl . '/posts/' . htmlspecialchars($folder, ENT_XML1, 'UTF-8') . '/</loc>' . PHP_EOL;
            $postsSitemapXml .= '    <lastmod>' . $lastmod . '</lastmod>' . PHP_EOL;
            $postsSitemapXml .= '    <changefreq>monthly</changefreq>' . PHP_EOL;
            $postsSitemapXml .= '    <priority>0.8</priority>' . PHP_EOL;
            $postsSitemapXml .= '  </url>' . PHP_EOL;
        }
    }
    
    $postsSitemapXml .= '</urlset>' . PHP_EOL;
    
    // Sauvegarder les fichiers
    $results = [
        'sitemap' => file_put_contents('./sitemap.xml', $sitemapXml) !== false,
        'sitemap_posts' => file_put_contents($postsDir . '/sitemap-posts.xml', $postsSitemapXml) !== false,
        'count' => count($validFolders) + 2
    ];
    
    return $results;
}

// ===== 3. GÉNÉRATION RSS FEEDS =====
function extractpostTags($post) {
    $tags = [];
    
    if (!empty($post['category_id'])) {
        $tags[] = str_replace(['-', '_', ' '], '', $post['category_id']);
    }
    
    if (!empty($post['difficulty'])) {
        $tags[] = $post['difficulty'] . 'post';
    }
    
    $totalTime = ($post['total_time'] ?? 0) ?: (($post['prep_time'] ?? 0) + ($post['cook_time'] ?? 0));
    if ($totalTime <= 30) {
        $tags[] = 'quickpost';
        $tags[] = '30minutemeals';
    } elseif ($totalTime <= 60) {
        $tags[] = '1hourmeals';
    }
    
    $tags = array_merge($tags, ['post', 'cooking', 'foodie', 'homemade', 'delicious']);
    
    if (!empty($post['ingredients']) && is_array($post['ingredients'])) {
        foreach (array_slice($post['ingredients'], 0, 3) as $ingredient) {
            $words = explode(' ', strtolower($ingredient));
            foreach ($words as $word) {
                $word = preg_replace('/[^a-z]/', '', $word);
                if (strlen($word) > 4 && !in_array($word, ['cups', 'tablespoons', 'teaspoons', 'ounces', 'pounds', 'grams'])) {
                    $tags[] = $word;
                    break;
                }
            }
        }
    }
    
    return array_unique(array_filter($tags));
}

function buildpostContentHTML($post, $mainImage, $tags) {
    $html = '<div class="post-content" style="font-family: Arial, sans-serif; max-width: 600px;">';
    
    if (!empty($mainImage)) {
        $html .= '<img src="' . htmlspecialchars($mainImage) . '" alt="' . htmlspecialchars($post['title']) . '" style="width:100%;max-width:600px;height:auto;border-radius:8px;margin-bottom:20px;">';
    }
    
    $html .= '<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin-bottom:20px;">';
    $html .= '<p style="margin:5px 0;"><strong>⏱️ Prep Time:</strong> ' . ($post['prep_time'] ?? 'N/A') . ' minutes</p>';
    $html .= '<p style="margin:5px 0;"><strong>🍳 Cook Time:</strong> ' . ($post['cook_time'] ?? 'N/A') . ' minutes</p>';
    $html .= '<p style="margin:5px 0;"><strong>⏰ Total Time:</strong> ' . ($post['total_time'] ?? 'N/A') . ' minutes</p>';
    $html .= '<p style="margin:5px 0;"><strong>🍽️ Servings:</strong> ' . ($post['servings'] ?? 'N/A') . '</p>';
    $html .= '<p style="margin:5px 0;"><strong>📊 Difficulty:</strong> ' . ucfirst($post['difficulty'] ?? 'medium') . '</p>';
    $html .= '</div>';
    
    if (!empty($post['ingredients'])) {
        $html .= '<div style="margin-bottom:30px;">';
        $html .= '<h3 style="color:#E60023;border-bottom:2px solid #E60023;padding-bottom:5px;">🥘 Ingredients</h3>';
        $html .= '<ul style="line-height:1.8;">';
        foreach ($post['ingredients'] as $ingredient) {
            $html .= '<li>' . htmlspecialchars($ingredient) . '</li>';
        }
        $html .= '</ul>';
        $html .= '</div>';
    }
    
    if (!empty($post['instructions'])) {
        $html .= '<div style="margin-bottom:30px;">';
        $html .= '<h3 style="color:#E60023;border-bottom:2px solid #E60023;padding-bottom:5px;">👩‍🍳 Instructions</h3>';
        $html .= '<ol style="line-height:1.8;">';
        foreach ($post['instructions'] as $instruction) {
            $html .= '<li>' . htmlspecialchars($instruction) . '</li>';
        }
        $html .= '</ol>';
        $html .= '</div>';
    }
    
    $html .= '<div style="background:#fff3f3;padding:15px;border-radius:8px;border-left:4px solid #E60023;">';
    $html .= '<p><strong>📌 Pinterest Tags:</strong> #' . implode(' #', $tags) . '</p>';
    $html .= '</div>';
    
    $html .= '<div style="text-align:center;margin-top:30px;padding:20px;background:#E60023;color:white;border-radius:8px;">';
    $html .= '<p style="margin:0;"><strong>📌 Save this post to Pinterest!</strong></p>';
    $html .= '<p style="margin:5px 0 0 0;">Perfect for meal planning and sharing with friends</p>';
    $html .= '</div>';
    
    $html .= '</div>';
    
    return $html;
}

function generatePinterestRSSFeed($postsDir = './posts') {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = HOST_NAME;
    $basePage = '/base.html';
    $siteUrl = $protocol . '://' . $host;
    
    $rssConfig = [
        'title' => 'Delicious posts Feed - Pinterest',
        'description' => 'Fresh posts and cooking inspiration for Pinterest discovery',
        'link' => $siteUrl . $basePage,
        'language' => 'en-US',
        'copyright' => '© ' . date('Y') . ' post Collection',
        'managingEditor' => '',
        'webMaster' => '',
        'category' => 'Food & Cooking',
        'generator' => 'post RSS Generator v2.0',
        'ttl' => 1440,
        'maxItems' => 50
    ];
    
    $validposts = [];
    
    if (is_dir($postsDir)) {
        $postDirs = array_filter(glob($postsDir . '/*'), 'is_dir');
        
        foreach ($postDirs as $postDir) {
            $folderName = basename($postDir);
            $postFile = $postDir . '/post.json';
            
            if (file_exists($postFile)) {
                $postData = json_decode(file_get_contents($postFile), true);
                
                if ($postData && isset($postData['title'])) {
                    $postData['folder'] = $folderName;
                    $postData['lastModified'] = filemtime($postFile);
                    $validposts[] = $postData;
                }
            }
        }
    }
    
    usort($validposts, function($a, $b) {
        return $b['lastModified'] - $a['lastModified'];
    });
    
    $validposts = array_slice($validposts, 0, $rssConfig['maxItems']);
    
    $currentDate = date('r');
    
    $rssXml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
    $rssXml .= '<rss version="2.0" 
     xmlns:content="http://purl.org/rss/1.0/modules/content/"
     xmlns:media="http://search.yahoo.com/mrss/"
     xmlns:dc="http://purl.org/dc/elements/1.1/"
     xmlns:atom="http://www.w3.org/2005/Atom">' . PHP_EOL;
    
    $rssXml .= '  <channel>' . PHP_EOL;
    $rssXml .= '    <title>' . htmlspecialchars($rssConfig['title'], ENT_XML1, 'UTF-8') . '</title>' . PHP_EOL;
    $rssXml .= '    <link>' . htmlspecialchars($rssConfig['link'], ENT_XML1, 'UTF-8') . '</link>' . PHP_EOL;
    $rssXml .= '    <description>' . htmlspecialchars($rssConfig['description'], ENT_XML1, 'UTF-8') . '</description>' . PHP_EOL;
    $rssXml .= '    <language>' . $rssConfig['language'] . '</language>' . PHP_EOL;
    $rssXml .= '    <copyright>' . htmlspecialchars($rssConfig['copyright'], ENT_XML1, 'UTF-8') . '</copyright>' . PHP_EOL;
    $rssXml .= '    <managingEditor>' . htmlspecialchars($rssConfig['managingEditor'], ENT_XML1, 'UTF-8') . '</managingEditor>' . PHP_EOL;
    $rssXml .= '    <webMaster>' . htmlspecialchars($rssConfig['webMaster'], ENT_XML1, 'UTF-8') . '</webMaster>' . PHP_EOL;
    $rssXml .= '    <pubDate>' . $currentDate . '</pubDate>' . PHP_EOL;
    $rssXml .= '    <lastBuildDate>' . $currentDate . '</lastBuildDate>' . PHP_EOL;
    $rssXml .= '    <category>' . htmlspecialchars($rssConfig['category'], ENT_XML1, 'UTF-8') . '</category>' . PHP_EOL;
    $rssXml .= '    <generator>' . htmlspecialchars($rssConfig['generator'], ENT_XML1, 'UTF-8') . '</generator>' . PHP_EOL;
    $rssXml .= '    <ttl>' . $rssConfig['ttl'] . '</ttl>' . PHP_EOL;
    $rssXml .= '    <atom:link href="' . $rssConfig['link'] . '/rss.xml" rel="self" type="application/rss+xml" />' . PHP_EOL;
    
    $rssXml .= '    <image>' . PHP_EOL;
    $rssXml .= '      <url>' . $rssConfig['link'] . '/images/logo.png</url>' . PHP_EOL;
    $rssXml .= '      <title>' . htmlspecialchars($rssConfig['title'], ENT_XML1, 'UTF-8') . '</title>' . PHP_EOL;
    $rssXml .= '      <link>' . htmlspecialchars($rssConfig['link'], ENT_XML1, 'UTF-8') . '</link>' . PHP_EOL;
    $rssXml .= '      <width>144</width>' . PHP_EOL;
    $rssXml .= '      <height>144</height>' . PHP_EOL;
    $rssXml .= '    </image>' . PHP_EOL;
    
    foreach ($validposts as $post) {
        $postUrl = $siteUrl . '/posts/' . $post['slug'] . '/';
        $pubDate = date('r', $post['lastModified']);
        
        $mainImage = '';
        if (!empty($post['images']) && isset($post['images'][0]['filePath'])) {
            $mainImage = $protocol . '://' . $host . '/' . ltrim($post['images'][0]['filePath'], './');
        } elseif (!empty($post['images']) && isset($post['images'][array_key_first($post['images'])]['filePath'])) {
            $mainImage = $protocol . '://' . $host . '/' . ltrim($post['images'][array_key_first($post['images'])]['filePath'], './');
        }
        
        $description = $post['description'] ?? 'Delicious post to try';
        
        $timeInfo = [];
        if (!empty($post['prep_time'])) $timeInfo[] = "Prep: {$post['prep_time']}min";
        if (!empty($post['cook_time'])) $timeInfo[] = "Cook: {$post['cook_time']}min";
        if (!empty($post['servings'])) $timeInfo[] = "Serves: {$post['servings']}";
        
        if (!empty($timeInfo)) {
            $description .= ' | ⏱️ ' . implode(' | ', $timeInfo);
        }
        
        $tags = extractpostTags($post);
        if (!empty($tags)) {
            $description .= ' | #' . implode(' #', array_slice($tags, 0, 5));
        }
        
        $contentHtml = buildpostContentHTML($post, $mainImage, $tags);
        
        $rssXml .= '    <item>' . PHP_EOL;
        $rssXml .= '      <title>' . htmlspecialchars($post['title'], ENT_XML1, 'UTF-8') . '</title>' . PHP_EOL;
        $rssXml .= '      <link>' . htmlspecialchars($rssConfig['link'] . '/posts/' . $post['slug'] . '/', ENT_XML1, 'UTF-8') . '</link>' . PHP_EOL;
        $rssXml .= '      <description>' . htmlspecialchars($description, ENT_XML1, 'UTF-8') . '</description>' . PHP_EOL;
        $rssXml .= '      <pubDate>' . $pubDate . '</pubDate>' . PHP_EOL;
        $rssXml .= '      <guid isPermaLink="true">' . htmlspecialchars($rssConfig['link'] . '/posts/' . $post['slug'] . '/', ENT_XML1, 'UTF-8') . '</guid>' . PHP_EOL;
        $rssXml .= '      <category>' . htmlspecialchars($post['category_id'] ?? 'posts', ENT_XML1, 'UTF-8') . '</category>' . PHP_EOL;
        $rssXml .= '      <dc:creator>' . htmlspecialchars($post['author_id'] ?? 'House Chef', ENT_XML1, 'UTF-8') . '</dc:creator>' . PHP_EOL;
        
        $rssXml .= '      <content:encoded><![CDATA[' . $contentHtml . ']]></content:encoded>' . PHP_EOL;
        
        if (!empty($mainImage)) {
            $rssXml .= '      <media:content url="' . htmlspecialchars($mainImage, ENT_XML1, 'UTF-8') . '" medium="image" type="image/webp">' . PHP_EOL;
            $rssXml .= '        <media:title>' . htmlspecialchars($post['title'], ENT_XML1, 'UTF-8') . '</media:title>' . PHP_EOL;
            $rssXml .= '        <media:description>' . htmlspecialchars($post['description'] ?? '', ENT_XML1, 'UTF-8') . '</media:description>' . PHP_EOL;
            $rssXml .= '      </media:content>' . PHP_EOL;
        }
        
        $rssXml .= '      <media:group>' . PHP_EOL;
        $rssXml .= '        <media:category>post</media:category>' . PHP_EOL;
        $rssXml .= '        <media:keywords>' . implode(', ', $tags) . '</media:keywords>' . PHP_EOL;
        $rssXml .= '      </media:group>' . PHP_EOL;
        
        $rssXml .= '    </item>' . PHP_EOL;
    }
    
    $rssXml .= '  </channel>' . PHP_EOL;
    $rssXml .= '</rss>' . PHP_EOL;
    
    // FIX: Vérifier les permissions avant d'essayer d'écrire
    $rssPaths = [
        './rss.xml',
        './pinterest-rss.xml',
        $postsDir . '/rss.xml'
    ];
    
    $savedCount = 0;
    $errors = [];
    
    foreach ($rssPaths as $path) {
        // Vérifier si le dossier parent existe et est accessible en écriture
        $directory = dirname($path);
        
        if (!is_dir($directory)) {
            $errors[$path] = "Directory doesn't exist: $directory";
            continue;
        }
        
        if (!is_writable($directory)) {
            $errors[$path] = "Directory not writable: $directory";
            continue;
        }
        
        // Tenter d'écrire le fichier
        if (@file_put_contents($path, $rssXml) !== false) {
            @chmod($path, 0644); // Assurer les bonnes permissions
            $savedCount++;
        } else {
            $errors[$path] = "Failed to write file (check permissions)";
        }
    }
    
    
    return [
        'success' => $savedCount > 0,
        'postsCount' => count($validposts),
        'filesCreated' => $savedCount,
        'paths' => array_filter($rssPaths, function($path) {
            return file_exists($path);
        }),
        'errors' => $errors // Ajouter les erreurs pour debug
    ];
}

function generateCategoryRSSFeed($categoryId, $categoryName, $postsDir = './posts') {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = HOST_NAME;
    $basePage = '/base.html';
    $siteUrl = $protocol . '://' . $host;
    
    $categoriesIndexPath = 'categories/index.json';
    if (file_exists($categoriesIndexPath)) {
        $categoriesData = json_decode(file_get_contents($categoriesIndexPath), true);
        $categoryName = array_search($categoryId, $categoriesData['folders']) ?: $categoryName;
    }

    $rssConfig = [
        'title' => $categoryName . ' posts - RSS Feed',
        'description' => 'Fresh ' . strtolower($categoryName) . ' posts and cooking inspiration',
        'link' => $siteUrl . $basePage,
        'language' => defined('SITE_LANGUAGE') ? SITE_LANGUAGE : 'en-US',
        'category' => $categoryName,
        'maxItems' => 50,
        'copyright' => '© ' . date('Y') . ' ' . HOST_NAME,
        'managingEditor' => defined('SITE_MANAGER') ? SITE_MANAGER : '',
        'webMaster' => defined('SITE_WEBMASTER') ? SITE_WEBMASTER : '',
        'generator' => 'posts - RSS Feed ' . HOST_NAME,
        'ttl' => 60
    ];
    
    $categoryposts = [];
    
    if (is_dir($postsDir)) {
        $postDirs = array_filter(glob($postsDir . '/*'), 'is_dir');
        
        foreach ($postDirs as $postDir) {
            $folderName = basename($postDir);
            $postFile = $postDir . '/post.json';
            
            if (file_exists($postFile)) {
                $postData = json_decode(file_get_contents($postFile), true);
                
                if ($postData && 
                    isset($postData['title']) && 
                    isset($postData['category_id']) && 
                    $postData['category_id'] === $categoryId &&
                    isset($postData['isOnline']) &&
                    $postData['isOnline'] === true) {
                    
                    $postData['folder'] = $folderName;
                    $postData['lastModified'] = filemtime($postFile);
                    $categoryposts[] = $postData;
                }
            }
        }
    }
    
    if (empty($categoryposts)) {
        return [
            'success' => false,
            'message' => 'Aucune post en ligne trouvée pour cette catégorie',
            'postsCount' => 0
        ];
    }
    
    usort($categoryposts, function($a, $b) {
        return $b['lastModified'] - $a['lastModified'];
    });
    
    $categoryposts = array_slice($categoryposts, 0, $rssConfig['maxItems']);
    
    $currentDate = date('r');
    
    $rssXml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
    $rssXml .= '<rss version="2.0" 
     xmlns:content="http://purl.org/rss/1.0/modules/content/"
     xmlns:media="http://search.yahoo.com/mrss/"
     xmlns:dc="http://purl.org/dc/elements/1.1/"
     xmlns:atom="http://www.w3.org/2005/Atom">' . PHP_EOL;
    
    $rssXml .= '  <channel>' . PHP_EOL;
    $rssXml .= '    <title>' . htmlspecialchars($rssConfig['title'], ENT_XML1, 'UTF-8') . '</title>' . PHP_EOL;
    $rssXml .= '    <link>' . htmlspecialchars($rssConfig['link'], ENT_XML1, 'UTF-8') . '</link>' . PHP_EOL;
    $rssXml .= '    <description>' . htmlspecialchars($rssConfig['description'], ENT_XML1, 'UTF-8') . '</description>' . PHP_EOL;
    $rssXml .= '    <language>' . $rssConfig['language'] . '</language>' . PHP_EOL;
    $rssXml .= '    <copyright>' . htmlspecialchars($rssConfig['copyright'], ENT_XML1, 'UTF-8') . '</copyright>' . PHP_EOL;
    $rssXml .= '    <managingEditor>' . htmlspecialchars($rssConfig['managingEditor'], ENT_XML1, 'UTF-8') . '</managingEditor>' . PHP_EOL;
    $rssXml .= '    <webMaster>' . htmlspecialchars($rssConfig['webMaster'], ENT_XML1, 'UTF-8') . '</webMaster>' . PHP_EOL;
    $rssXml .= '    <pubDate>' . $currentDate . '</pubDate>' . PHP_EOL;
    $rssXml .= '    <lastBuildDate>' . $currentDate . '</lastBuildDate>' . PHP_EOL;
    $rssXml .= '    <category>' . htmlspecialchars($rssConfig['category'], ENT_XML1, 'UTF-8') . '</category>' . PHP_EOL;
    $rssXml .= '    <generator>' . htmlspecialchars($rssConfig['generator'], ENT_XML1, 'UTF-8') . '</generator>' . PHP_EOL;
    $rssXml .= '    <ttl>' . $rssConfig['ttl'] . '</ttl>' . PHP_EOL;
    $rssXml .= '    <atom:link href="' . $rssConfig['link'] . '/rss.xml" rel="self" type="application/rss+xml" />' . PHP_EOL;
    
    $rssXml .= '    <image>' . PHP_EOL;
    $rssXml .= '      <url>' . $rssConfig['link'] . '/images/logo.png</url>' . PHP_EOL;
    $rssXml .= '      <title>' . htmlspecialchars($rssConfig['title'], ENT_XML1, 'UTF-8') . '</title>' . PHP_EOL;
    $rssXml .= '      <link>' . htmlspecialchars($rssConfig['link'], ENT_XML1, 'UTF-8') . '</link>' . PHP_EOL;
    $rssXml .= '      <width>144</width>' . PHP_EOL;
    $rssXml .= '      <height>144</height>' . PHP_EOL;
    $rssXml .= '    </image>' . PHP_EOL;

    foreach ($categoryposts as $post) {
        $postUrl = $siteUrl . '/posts/' . $post['slug'] . '/';
        $pubDate = date('r', $post['lastModified']);
        
        $mainImage = '';
        if (!empty($post['images'])) {
            if (isset($post['images'][array_key_last($post['images'])]['filePath'])) {
                $mainImage = $protocol . '://' . $host . '/' . ltrim($post['images'][array_key_last($post['images'])]['filePath'], './');
            } 
            elseif (isset($post['images'][array_key_first($post['images'])]['filePath'])) {
                $mainImage = $protocol . '://' . $host . '/' . ltrim($post['images'][array_key_first($post['images'])]['filePath'], './');
            }
        }
        elseif (!empty($post['image_path'])) {
            $mainImage = $protocol . '://' . $host . '/' . ltrim($post['image_path'], './');
        }
        
        $description = $post['description'] ?? 'Delicious post';
        $tags = extractpostTags($post);
        $contentHtml = buildpostContentHTML($post, $mainImage, $tags);
        
        $rssXml .= '    <item>' . PHP_EOL;
        $rssXml .= '      <title>' . htmlspecialchars($post['title'], ENT_XML1, 'UTF-8') . '</title>' . PHP_EOL;
        $rssXml .= '      <link>' . htmlspecialchars($rssConfig['link'] . '/posts/' . $post['slug'] . '/', ENT_XML1, 'UTF-8') . '</link>' . PHP_EOL;
        $rssXml .= '      <description>' . htmlspecialchars($description, ENT_XML1, 'UTF-8') . '</description>' . PHP_EOL;
        $rssXml .= '      <pubDate>' . $pubDate . '</pubDate>' . PHP_EOL;
        $rssXml .= '      <guid isPermaLink="true">' . htmlspecialchars($rssConfig['link'] . '/posts/' . $post['slug'] . '/', ENT_XML1, 'UTF-8') . '</guid>' . PHP_EOL;
        $rssXml .= '      <category>' . htmlspecialchars($post['category_id'] ?? 'posts', ENT_XML1, 'UTF-8') . '</category>' . PHP_EOL;
        $rssXml .= '      <dc:creator>' . htmlspecialchars($post['author_id'] ?? 'House Chef', ENT_XML1, 'UTF-8') . '</dc:creator>' . PHP_EOL;
        
        $rssXml .= '      <content:encoded><![CDATA[' . $contentHtml . ']]></content:encoded>' . PHP_EOL;
        
        if (!empty($mainImage)) {
            $rssXml .= '      <media:content url="' . htmlspecialchars($mainImage, ENT_XML1, 'UTF-8') . '" medium="image" type="image/webp">' . PHP_EOL;
            $rssXml .= '        <media:title>' . htmlspecialchars($post['title'], ENT_XML1, 'UTF-8') . '</media:title>' . PHP_EOL;
            $rssXml .= '        <media:description>' . htmlspecialchars($post['description'] ?? '', ENT_XML1, 'UTF-8') . '</media:description>' . PHP_EOL;
            $rssXml .= '      </media:content>' . PHP_EOL;
        }
        
        $rssXml .= '      <media:group>' . PHP_EOL;
        $rssXml .= '        <media:category>post</media:category>' . PHP_EOL;
        $rssXml .= '        <media:keywords>' . implode(', ', $tags) . '</media:keywords>' . PHP_EOL;
        $rssXml .= '      </media:group>' . PHP_EOL;
        
        $rssXml .= '    </item>' . PHP_EOL;
    }
    
    $rssXml .= '  </channel>' . PHP_EOL;
    $rssXml .= '</rss>' . PHP_EOL;
    
    return [
        'success' => true,
        'xml' => $rssXml,
        'postsCount' => count($categoryposts),
        'categoryName' => $categoryName,
        'categoryId' => $categoryId
    ];
}

function generateAllCategoryRSSFeeds($categoriesDir = './categories', $postsDir = './posts') {
    $results = [];
    
    if (!is_dir($categoriesDir)) {
        return ['success' => false, 'message' => 'Dossier categories introuvable'];
    }
    
    $categoryFolders = array_filter(glob($categoriesDir . '/*'), 'is_dir');
    
    foreach ($categoryFolders as $categoryFolder) {
        $categoryJsonFile = $categoryFolder . '/category.json';
        
        if (file_exists($categoryJsonFile)) {
            $categoryData = json_decode(file_get_contents($categoryJsonFile), true);
            
            if ($categoryData && isset($categoryData['id']) && isset($categoryData['name'])) {
                $rssResult = generateCategoryRSSFeed(
                    $categoryData['id'], 
                    $categoryData['name'], 
                    $postsDir
                );
                
                if ($rssResult['success']) {
                    $rssPath = $categoryFolder . '/rss.xml';
                    
                    if (file_put_contents($rssPath, $rssResult['xml']) !== false) {
                        $results[] = [
                            'category' => $categoryData['name'],
                            'path' => $rssPath,
                            'postsCount' => $rssResult['postsCount']
                        ];
                    }
                }
            }
        }
    }
    
    return [
        'success' => count($results) > 0,
        'categories' => $results,
        'totalCategories' => count($results)
    ];
}

// Function pour charger les catégories
function getCategories($categoriesPath) {
    if (file_exists($categoriesPath)) {
        $content = file_get_contents($categoriesPath);
        $data = json_decode($content, true);
        return $data['folders'] ?? [];
    }
    return [];
}

// Function bach n9raw post file
function getpost($filePath) {
    if (file_exists($filePath)) {
        $content = file_get_contents($filePath);
        return json_decode($content, true);
    }
    return null;
}

// Function bach nsawbo post file
function savepost($filePath, $data) {
    $jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return file_put_contents($filePath, $jsonData);
}

// Function bach n9ello l lista dial ga3 posts
function getAllposts($basePath) {
    $posts = [];
    
    if (!is_dir($basePath)) {
        return $posts;
    }
    
    $folders = scandir($basePath);
    
    foreach ($folders as $folder) {
        if ($folder === '.' || $folder === '..') continue;
        
        $folderPath = $basePath . '/' . $folder;
        
        if (is_dir($folderPath)) {
            $postPath = $folderPath . '/post.json';
        
            if (file_exists($postPath)) {
                $post = getpost($postPath);
                if ($post) {
                    // Récupérer la date de modification du fichier
                    $fileModTime = filemtime($postPath);
                    
                    $posts[] = [
                        'path' => $postPath,
                        'folder' => $folder,
                        'data' => $post,
                        'modified_time' => $fileModTime
                    ];
                }
            }
        }
    }
    
    return $posts;
}

// Charger les catégories
$categoriesData = getCategories($categoriesPath);

// ⚠️ HANDLER AJAX update_ajax DÉPLACÉ EN HAUT DU FICHIER (ligne ~28)

// Handle AJAX request pour update category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_category') {
    header('Content-Type: application/json');
    
    $postPath = $_POST['post_path'];
    $newCategoryId = $_POST['category_id'];
    
    $post = getpost($postPath);
    if ($post) {
        $post['category_id'] = $newCategoryId;
        $result = savepost($postPath, $post);
        
        if ($result !== false) {
            echo json_encode(['success' => true, 'message' => 'Catégorie mise à jour avec succès!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Erreur lors de la sauvegarde']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Post non trouvée']);
    }
    exit;
}

// Get all posts
$allposts = getAllposts($postsBasePath);

// Filtrage par statut (coché/non coché)
$filterStatus = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$filteredposts = $allposts;

if ($filterStatus === 'online') {
    $filteredposts = array_filter($allposts, function($r) {
        return $r['data']['isOnline'];
    });
} elseif ($filterStatus === 'offline') {
    $filteredposts = array_filter($allposts, function($r) {
        return !$r['data']['isOnline'];
    });
}

// Filtrage par nom / titre
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
if ($searchQuery !== '') {
    $searchLower = mb_strtolower($searchQuery);
    $filteredposts = array_filter($filteredposts, function($r) use ($searchLower) {
        $title  = mb_strtolower($r['data']['title'] ?? '');
        $folder = mb_strtolower($r['folder'] ?? '');
        return str_contains($title, $searchLower) || str_contains($folder, $searchLower);
    });
}

// Tri par date
$sortOrder = isset($_GET['sort']) ? $_GET['sort'] : 'desc';
usort($filteredposts, function($a, $b) use ($sortOrder) {
    if ($sortOrder === 'asc') {
        return $a['modified_time'] - $b['modified_time'];
    } else {
        return $b['modified_time'] - $a['modified_time'];
    }
});

// Pagination
$currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$totalposts = count($filteredposts);
$totalPages = ceil($totalposts / $itemsPerPage);
$currentPage = min($currentPage, max(1, $totalPages));
$offset = ($currentPage - 1) * $itemsPerPage;

// Slice posts for current page
$postsOnPage = array_slice($filteredposts, $offset, $itemsPerPage);

// Statistics (pour tous les posts, machi ghir li f page)
$onlineposts = count(array_filter($allposts, function($r) { return $r['data']['isOnline']; }));
$offlineposts = count($allposts) - $onlineposts;
?>


<?php



?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>post Manager - Filtré et Trié</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
            position: relative;
        }
        .header-user {
            position: absolute;
            top: 12px;
            right: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .header-user .role-badge {
            font-size: .78rem;
            background: rgba(255,255,255,.15);
            color: #fff;
            padding: 3px 10px;
            border-radius: 20px;
            border: 1px solid rgba(255,255,255,.3);
        }
        .header-user .logout-link {
            font-size: .78rem;
            color: #fca5a5;
            text-decoration: none;
        }
        .header-user .logout-link:hover { color: #fff; }
        
        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .stats {
            display: flex;
            justify-content: center;
            gap: 30px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        
        .stat-card {
            background: rgba(255,255,255,0.2);
            padding: 15px 30px;
            border-radius: 10px;
            backdrop-filter: blur(10px);
        }
        
        .stat-number {
            font-size: 2em;
            font-weight: bold;
        }
        
        .stat-label {
            font-size: 0.9em;
            opacity: 0.9;
        }
        
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 25px;
            border-radius: 8px;
            color: white;
            font-weight: 600;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            transform: translateX(400px);
            transition: transform 0.3s ease;
            z-index: 1000;
        }
        
        .notification.show {
            transform: translateX(0);
        }
        
        .notification.success {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .notification.error {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        .controls {
            padding: 30px;
            background: #f8f9fa;
            border-bottom: 2px solid #e9ecef;
        }
        
        .control-row {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .control-group {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .control-group label {
            font-weight: 600;
            color: #495057;
        }
        
        .filter-btn, .sort-btn {
            padding: 10px 20px;
            border: 2px solid #667eea;
            background: white;
            color: #667eea;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .filter-btn:hover, .sort-btn:hover {
            background: #667eea;
            color: white;
            transform: translateY(-2px);
        }
        
        .filter-btn.active, .sort-btn.active {
            background: #667eea;
            color: white;
        }
        
        select {
            padding: 10px 15px;
            border: 2px solid #667eea;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            color: #495057;
            cursor: pointer;
            background: white;
            transition: all 0.3s;
        }
        
        select:hover {
            border-color: #764ba2;
        }
        
        .content {
            padding: 30px;
        }
        
        .no-posts {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        
        .no-posts-icon {
            font-size: 4em;
            margin-bottom: 20px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-radius: 10px;
            overflow: hidden;
        }
        
        thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            font-size: 0.9em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        td {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
        }
        
        tr:last-child td {
            border-bottom: none;
        }
        
        tbody tr {
            transition: background-color 0.3s;
        }
        
        tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        .post-title {
            font-weight: 600;
            color: #212529;
            margin-bottom: 5px;
        }
        
        .folder-name {
            font-size: 0.85em;
            color: #6c757d;
        }
        
        .date-info {
            color: #495057;
            font-size: 0.9em;
        }
        
        .date-info small {
            color: #6c757d;
        }
        
        .checkbox-container {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .online-checkbox {
            width: 24px;
            height: 24px;
            cursor: pointer;
            accent-color: #667eea;
        }
        
        .loading-spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .actions {
            /* display: flex; */
            /* gap: 10px; */
        }

        .view-btn-container {
            margin: 4%;
            display: grid;
            align-content: center;
            justify-content: space-evenly;
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .pagination-container {
            padding: 30px;
            background: #f8f9fa;
            border-top: 2px solid #e9ecef;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .pagination a, .pagination span {
            padding: 10px 15px;
            border-radius: 8px;
            text-decoration: none;
            color: #495057;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .pagination a {
            background: white;
            border: 2px solid #dee2e6;
        }
        
        .pagination a:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
            transform: translateY(-2px);
        }
        
        .pagination .active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: 2px solid #667eea;
        }
        
        .pagination .disabled {
            background: #e9ecef;
            color: #adb5bd;
            cursor: not-allowed;
            border: 2px solid #dee2e6;
        }
        
        .pagination .dots {
            color: #6c757d;
            padding: 10px 5px;
        }
        
        .category-select {
            padding: 8px 12px;
            border: 2px solid #dee2e6;
            border-radius: 6px;
            font-size: 13px;
            color: #495057;
            cursor: pointer;
            background: white;
            transition: all 0.3s;
            min-width: 150px;
        }
        
        .category-select:hover {
            border-color: #667eea;
        }
        
        .category-select:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .category-loading {
            display: none;
            width: 16px;
            height: 16px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-left: 10px;
        }
        .actions a {
            width: max-content; 
            text-decoration: none;
        }

        a.gen_temp {
            background: #f093fb;
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            display: flex;
            text-decoration: none;
            width: max-content;
            margin: 2% 0%;
        }
    </style>

    <style>
        #pinImagesContainer img {
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid #dee2e6 !important;
        }
        
        #pinImagesContainer img:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2) !important;
        }
    </style>

    <script src="config.js"></script>
    <script>
        globalThis.linkPinActive = <?php echo $linkPinActive ? 'true' : 'false'; ?>;
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</head>
<body>
    <div id="notification" class="notification"></div>
    
    <div class="container">
        <iframe style="width: 100%; height: 250px;" src="push.php" frameborder="0"></iframe>
        <div class="header">
            <div class="header-user">
                <span class="role-badge">👤 <?= htmlspecialchars($_SESSION['role'] ?? '') ?></span>
                <a href="login.php?logout=1" class="logout-link">🚪 Déconnexion</a>
            </div>
            <h1>🍳 post Manager</h1>
            <div class="stats">
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($allposts); ?></div>
                    <div class="stat-label">Total Posts</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="online-count"><?php echo $onlineposts; ?></div>
                    <div class="stat-label">✅ En Ligne</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="offline-count"><?php echo $offlineposts; ?></div>
                    <div class="stat-label">❌ Hors Ligne</div>
                </div>
            </div>
        </div>
        
        <div class="controls">
            <div class="control-row">
                <div class="control-group">
                    <label>Recherche:</label>
                    <input type="text" id="searchInput"
                           value="<?php echo htmlspecialchars($searchQuery); ?>"
                           placeholder="Nom du post..."
                           style="padding:6px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; background:#f8fafc; color:#1e293b; height:36px; min-width:200px;"
                           onkeydown="if(event.key==='Enter') applySearch()"
                           oninput="clearTimeout(window._st); window._st = setTimeout(applySearch, 400)">
                </div>
                <div class="control-group">
                    <label>Filtre:</label>
                    <button class="filter-btn <?php echo $filterStatus === 'all' ? 'active' : ''; ?>"
                            onclick="applyFilter('all')">Tous</button>
                    <button class="filter-btn <?php echo $filterStatus === 'online' ? 'active' : ''; ?>"
                            onclick="applyFilter('online')">En Ligne</button>
                    <button class="filter-btn <?php echo $filterStatus === 'offline' ? 'active' : ''; ?>"
                            onclick="applyFilter('offline')">Hors Ligne</button>
                </div>
                
                <div class="control-group">
                    <label>Tri par date:</label>
                    <button class="sort-btn <?php echo $sortOrder === 'desc' ? 'active' : ''; ?>" 
                            onclick="changeSortOrder('desc')">Plus récent</button>
                    <button class="sort-btn <?php echo $sortOrder === 'asc' ? 'active' : ''; ?>" 
                            onclick="changeSortOrder('asc')">Plus ancien</button>
                </div>
                
                <div class="control-group">
                    <label>Par page:</label>
                    <select onchange="changeItemsPerPage(this.value)">
                        <option value="5" <?php echo $itemsPerPage == 5 ? 'selected' : ''; ?>>5</option>
                        <option value="10" <?php echo $itemsPerPage == 10 ? 'selected' : ''; ?>>10</option>
                        <option value="20" <?php echo $itemsPerPage == 20 ? 'selected' : ''; ?>>20</option>
                        <option value="30" <?php echo $itemsPerPage == 30 ? 'selected' : ''; ?>>30</option>
                        <option value="50" <?php echo $itemsPerPage == 50 ? 'selected' : ''; ?>>50</option>
                    </select>
                </div>

                <div class="control-group" style="margin-left: auto; display: contents; gap: 10px; align-items:center;">
                    <a href="config-ui.php" class="filter-btn" style="background:#1e293b;color:#fff;border:none;text-decoration:none;display:inline-flex;align-items:center;">
                        ⚙️ Config
                    </a>
                    <button id="generateAllRssBtn" class="filter-btn" style="background: linear-gradient(135deg, #E60023 0%, #bd081c 100%); color: white; border: none;">
                        📡 Générer tous les RSS
                    </button>
                    <?php if (auth_is_admin()): ?>
                    <input type="date" id="autoCsvDate" value="<?php echo date('Y-m-d'); ?>"
                        style="padding:6px 10px; border:1px solid #d97706; border-radius:8px; font-size:13px; color:#92400e; background:#fffbeb; cursor:pointer; height:36px;">
                    <button type="button" class="btn" id="autoDailyCsvBtn" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white;">
                        ⚡ Auto CSV
                    </button>
                    <button type="button" class="btn" id="rollbackDailyCsvBtn" style="background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%); color: white;">
                        ↩️ Rollback CSV
                    </button>
                    <?php endif; ?>
                    <button type="button" class="btn" id="generateCombinedCsvBtn" style="background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%); color: white;" title="Génère les CSV à partir de tous les articles en ligne avec templates">
                        🗂️ Grouper CSV
                    </button>
                    <button type="button" class="btn" id="csvSelectedBtn" disabled
                        style="background: linear-gradient(135deg, #059669 0%, #047857 100%); color: white; opacity:0.5;"
                        title="Générer CSV pour les articles sélectionnés">
                        📋 CSV sélection (<span id="selectedCount">0</span>)
                    </button>
                    <button type="button" class="btn" id="fbPostSelectedBtn" disabled
                        style="background: linear-gradient(135deg, #1877f2 0%, #0c5ec7 100%); color: white; opacity:0.5;"
                        title="Poster sur Facebook Page les articles sélectionnés">
                        📘 Post FB (<span id="fbSelectedCount">0</span>)
                    </button>
                    <div class="dropdown d-inline-block" id="csvDropdownWrap" style="display:none!important">
                      <button type="button" class="btn dropdown-toggle" id="downloadDailyCsvBtn" data-bs-toggle="dropdown" aria-expanded="false" style="background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%); color: white;">
                        📥 CSV prêts (<span id="csvFileCount">0</span>)
                      </button>
                      <ul class="dropdown-menu" id="csvFileList"></ul>
                    </div>
                    <button type="button" class="btn" id="exportQueueCsvBtn" style="background: linear-gradient(135deg, #10a37f 0%, #0d8566 100%); color: white;">
                    📊 Exporter la file CSV
                    </button>
                    <button type="button" id="runFbCrosspostBtn" class="btn" style="background: linear-gradient(135deg, #1877f2 0%, #0a5cc7 100%); color: white;" title="Générer templates + programmer sur Facebook">
                        📘 Post sur FB
                    </button>
                    <a href="?action=generate_file_posts" target="_blank" class="btn" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white;">
                        🗺️ generate file posts
                    </a>
                    <button id="generateMissingHtmlBtn" class="btn" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                        📄 Generate Missing HTML
                    </button>
                    <div style="display:flex; align-items:center; gap:6px; padding:4px 10px; background:#f0fdf4; border-radius:8px; border:1px solid #bbf7d0;" title="Articles générés par jour (random entre min et max)">
                        <span style="font-size:12px; color:#166534; white-space:nowrap;">📝 Art/jour</span>
                        <input type="number" id="pipelineLimitMin" min="1" max="50" value="<?php echo $pipelineLimitMin; ?>"
                            style="width:42px; padding:3px 5px; border:1px solid #86efac; border-radius:5px; font-size:13px; text-align:center; background:#fff;"
                            title="Minimum articles/jour">
                        <span style="font-size:11px; color:#555;">—</span>
                        <input type="number" id="pipelineLimitMax" min="1" max="50" value="<?php echo $pipelineLimitMax; ?>"
                            style="width:42px; padding:3px 5px; border:1px solid #86efac; border-radius:5px; font-size:13px; text-align:center; background:#fff;"
                            title="Maximum articles/jour">
                        <button id="savePipelineLimitsBtn" style="padding:3px 8px; background:#16a34a; color:#fff; border:none; border-radius:5px; font-size:12px; cursor:pointer;">✓</button>
                    </div>
                    <div style="display:flex; align-items:center; gap:6px; padding:4px 10px; background:#f8f9fa; border-radius:8px; border:1px solid #dee2e6;">
                        <span style="font-size:13px; color:#555;">🔗 Link Pin</span>
                        <label style="position:relative; display:inline-block; width:36px; height:20px; margin:0; cursor:pointer;">
                            <input type="checkbox" id="linkPinToggle" <?php echo $linkPinActive ? 'checked' : ''; ?> style="opacity:0; width:0; height:0;">
                            <span id="linkPinSlider" style="position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; border-radius:20px; transition:.3s; background:<?php echo $linkPinActive ? '#28a745' : '#ccc'; ?>;"></span>
                            <span style="position:absolute; content:''; height:14px; width:14px; left:<?php echo $linkPinActive ? '19px' : '3px'; ?>; bottom:3px; background:white; border-radius:50%; transition:.3s; pointer-events:none;" id="linkPinThumb"></span>
                        </label>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="content">
            <?php if (empty($postsOnPage)): ?>
                <div class="no-posts">
                    <div class="no-posts-icon">📭</div>
                    <h2>Aucune post trouvée</h2>
                    <p>Essayez de modifier vos filtres</p>
                </div>
            <?php else: ?>

                <table class="posts-table responsive-table striped highlight" style="overflow: scroll !important; display: block;">
                    <thead>
                        <tr>
                            <th style="width: 50px;">
                                <input type="checkbox" id="selectAllPageCheckbox" title="Sélectionner toute la page"
                                    style="width:18px;height:18px;cursor:pointer;accent-color:#7c3aed;">
                            </th>
                            <th style="width: 50px;">#</th>
                            <th>Titre</th>
                            <th style="width: 200px;">Catégorie</th>
                            <th style="width: 120px;">Date</th>
                            <th style="width: 100px;">En ligne</th>
                            <th style="width: 120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($postsOnPage as $index => $post): ?>

                            <tr>
                                <td style="width:40px; text-align:center; vertical-align:middle;">
                                    <input type="checkbox" class="page-select-checkbox"
                                        data-slug="<?php echo htmlspecialchars($post['folder']); ?>"
                                        style="width:18px;height:18px;cursor:pointer;accent-color:#7c3aed;">
                                </td>
                                <td style="width: 11%;">
                                    <?php  echo $globalIndex = $offset + $index + 1;
                                    ?>
                                    <?php if (!empty($post['data']['images'])): ?>
                                    <?php
                                        // $imagePath = isset($post['data']['images'][3]['filePath']) && $post['data']['images'][3]['filePath'] != ""
                                        //     ? $post['data']['images'][3]['filePath']
                                        //     : ($post['data']['images'][0]['filePath'] ?? '');

                                        $imagePath = '';
                                        if (isset($post['data']['images'][array_key_last($post['data']['images'])]['filePath']) && $post['data']['images'][array_key_last($post['data']['images'])]['filePath'] != "") {
                                            $imagePath = $post['data']['images'][array_key_last($post['data']['images'])]['filePath'];
                                        } elseif (isset($post['data']['images'][array_key_first($post['data']['images'])]['filePath'])) {
                                            $imagePath = $post['data']['images'][array_key_first($post['data']['images'])]['filePath'];
                                        }
                                    
                                    
                                    // Initialiser categoryName et categorySlugFolder ICI avant l'img
                                    $categoryName = '';
                                    $categorySlugFolder = '';
                                    foreach ($categoriesData as $name => $id) {
                                        if ($id === $post['data']['category_id']) {
                                            $categorySlugFolder = $name;
                                            break;
                                        }
                                    }
                                    $jsonFile = "./categories/$categorySlugFolder/category.json";
                                    if (file_exists($jsonFile)) {
                                        $_catData = json_decode(file_get_contents($jsonFile), true);
                                        $categoryName = $_catData['name'] ?? '';
                                    }
                                    ?>
                                    <?php
                                    // Override image_dir and slug with the real folder name (post.json slug may differ)
                                    $_pinData = $post['data'];
                                    $_pinData['image_dir'] = $post['folder'] . '/images';
                                    $_pinData['slug'] = $post['folder'];
                                    ?>
                                    <img width="100px" height="auto" src="<?php echo htmlspecialchars($imagePath); ?>" class="post-img gen_temp" data-frmID="<?php echo "frm_" . $globalIndex; ?>" data-categoryName="<?php echo htmlspecialchars($categoryName); ?>" data-categorySlug="<?php echo htmlspecialchars($categorySlugFolder); ?>" data-pin="<?php echo htmlspecialchars(json_encode($_pinData)) ?>"/>
                                    <?php else: ?>
                                        <div style="width:100px;height:100px;background:#ddd;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#999;">No Image</div>
                                    <?php endif; ?>

                                    <form class="frm_<?php echo $globalIndex; ?>" action="posts-api.php">
                                        <input type="hidden" name="uniqueSlug" value="<?php echo htmlspecialchars($post['folder']); ?>">
                                        <input type="hidden" name="action" value="generate_template">
                                        <button type="submit" class="btn gen_temp">GEN TEMP</button>
                                    </form>

                                        <!-- <a class="btn gen_temp" data-frmID="<?php echo "frm_" . $globalIndex; ?>" data-categoryName="<?php echo htmlspecialchars($categoryName); ?>" data-pin="<?php echo htmlspecialchars(json_encode($post['data'])) ?>"  >PIN</a> -->
                                        
                                </td>                    
                            
                                <td>
                                    <div class="post-title"><?php echo htmlspecialchars($post['data']['title']); ?></div>
                                    <div class="folder-name">📁 <?php echo htmlspecialchars($post['folder']); ?></div>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center;">
                                        <select class="category-select" 
                                                data-path="<?php echo htmlspecialchars($post['path']); ?>"
                                                data-current="<?php echo htmlspecialchars($post['data']['category_id']); ?>">
                                            <?php
                                            echo '<option value="">Aucune catégorie</option>';
                                            foreach ($categoriesData as $categoryName => $categoryId):
                                                $selected = ($post['data']['category_id'] === $categoryId) ? 'selected' : '';
                                            ?>
                                                <option value="<?php echo htmlspecialchars($categoryId); ?>" <?php echo $selected; ?>>
                                                    <?php echo htmlspecialchars($categoryName); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="category-loading"></div>
                                    </div>
                                 </td>
                                <td>
                                    <div class="date-info">
                                        <?php $date = new DateTime($post['data']['createdAt']) ?>
                                        <?php echo $date->format('d/m/Y') ?>
                                        <br>
                                        <small><?php echo $date->format('H:i') ?></small>
                                    </div>
                                </td>
                                <td>
                                    <div class="checkbox-container">
                                        <input type="checkbox" 
                                               class="online-checkbox"
                                               data-path="<?php echo htmlspecialchars($post['path']); ?>"
                                               <?php echo $post['data']['isOnline'] ? 'checked' : ''; ?>>
                                        <div class="loading-spinner"></div>
                                    </div>
                                </td>
                                <td>
                                    <div class="actions">
                                     
                                        <div class="view-btn-container">                                    
                                            <a href="<?php echo BASE_URL ?>posts/<?php echo htmlspecialchars($post['folder']); ?>" class="btn" target="_blanck" >
                                                👁️ Voir
                                            </a>
                                        </div>
                                        <div class="view-btn-container">                                    
                                            <?php if(!file_exists('posts/'. $post['folder'] . '/index.html')): ?>
                                            <a class="btn" href="generate-post.php?slug=<?php echo htmlspecialchars($post['folder']); ?>" target="_blank">
                                                🖨️ Générer Post
                                            </a>
                                            <?php else: ?>
                                            <a class="btn" style="background: gray;" href="generate-post.php?slug=<?php echo htmlspecialchars($post['folder']); ?>" target="_blank">
                                                🖨️ Regénérer Post
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="view-btn-container">
                                            <a  class="btn rewrite_post" href="posts-api.php?action=rewrite&slug=<?php echo htmlspecialchars($post['folder']); ?>&board_name=<?php echo urlencode($post['data']['board_name'] ?? ''); ?>">
                                            📝 Rewrite
                                            </a>
                                        </div>
                                        <div class="view-btn-container">
                                            <button class="btn delete-post-btn" data-slug="<?php echo htmlspecialchars($post['folder']); ?>" style="background:#dc2626;color:#fff;border:none;cursor:pointer;">
                                                🗑️ Supprimer
                                            </button>
                                        </div>


                                    </div>
                                    
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <?php if ($totalPages > 1): ?>
        <div class="pagination-container">
            <div class="pagination">
                <!-- Previous Button -->
                <?php if ($currentPage > 1): ?>
                    <a href="?page=<?php echo $currentPage - 1; ?>&filter=<?php echo $filterStatus; ?>&sort=<?php echo $sortOrder; ?>&per_page=<?php echo $itemsPerPage; ?>">← Précédent</a>
                <?php else: ?>
                    <span class="disabled">← Précédent</span>
                <?php endif; ?>
                
                <!-- Page Numbers -->
                <?php
                $range = 2;
                $start = max(1, $currentPage - $range);
                $end = min($totalPages, $currentPage + $range);
                
                if ($start > 1) {
                    echo '<a href="?page=1&filter=' . $filterStatus . '&sort=' . $sortOrder . '&per_page=' . $itemsPerPage . '">1</a>';
                    if ($start > 2) {
                        echo '<span class="dots">...</span>';
                    }
                }
                
                for ($i = $start; $i <= $end; $i++) {
                    if ($i == $currentPage) {
                        echo '<span class="active">' . $i . '</span>';
                    } else {
                        echo '<a href="?page=' . $i . '&filter=' . $filterStatus . '&sort=' . $sortOrder . '&per_page=' . $itemsPerPage . '">' . $i . '</a>';
                    }
                }
                
                if ($end < $totalPages) {
                    if ($end < $totalPages - 1) {
                        echo '<span class="dots">...</span>';
                    }
                    echo '<a href="?page=' . $totalPages . '&filter=' . $filterStatus . '&sort=' . $sortOrder . '&per_page=' . $itemsPerPage . '">' . $totalPages . '</a>';
                }
                ?>
                
                <!-- Next Button -->
                <?php if ($currentPage < $totalPages): ?>
                    <a href="?page=<?php echo $currentPage + 1; ?>&filter=<?php echo $filterStatus; ?>&sort=<?php echo $sortOrder; ?>&per_page=<?php echo $itemsPerPage; ?>">Suivant →</a>
                <?php else: ?>
                    <span class="disabled">Suivant →</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    

<!-- Modal -->
<div class="modal fade" id="pinModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">

      <div class="modal-header">
        <input style="width:100%" class="modal-title" id="pinTitle" />
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <strong>Description:</strong>                    
        <textarea id="pinDescription" style="width:100%" rows="5" class="mb-2"></textarea>
        <br>
        <div id="pinSlugRow">
        <strong>URL:</strong>
        <p><input style="width:100%" id="pinSlug" /></p>
        </div>
        <strong>Pinterest Board <small style="color:#999">(doit exister sur ton compte Pinterest)</small>:</strong>
        <p><input style="width:100%" id="pinBoardName" placeholder="ex: easy-posts" /></p>
        <input id="pinCategoryName" hidden />
        <hr>

        <div id="pinImagesContainer" class="d-flex flex-wrap gap-2"></div>

      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn" id="addToQueueBtn" style="background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%); color: white;">
          ➕ Ajouter à la file (<span id="queueCount">0</span>)
        </button>
        <button type="button" class="btn" id="insertPinRssBtn" style="background: linear-gradient(135deg, #E60023 0%, #bd081c 100%); color: white;">
          📌 Insérer dans Pinterest RSS
        </button>
      </div>

    </div>
  </div>
</div>


    <script>
let currentpostData = null;

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.gen_temp').forEach(btn => {
        btn.addEventListener('click', function () {

            let data = JSON.parse(this.getAttribute('data-pin'));
            let categoryName = this.getAttribute('data-categoryName');
            let categorySlug = this.getAttribute('data-categorySlug');
            let frmID = this.getAttribute('data-frmID');

            // Stocker les données actuelles pour l'insertion
            currentpostData = {
                title: data.title,
                description: data.title +"\n"+ data.description+"\n"+(data.hashtags !== undefined ? data.hashtags : ""),
                link: globalThis.siteUrl + "/posts/" + encodeURIComponent(data.slug),
                category: categoryName,
                category_slug: categorySlug,
                image: null,
                pin_variations: data.pin_variations || [],
                images: data.images || [],
                pinterest_boards: data.pinterest_boards || {},
            };

            // Fill modal info
            document.getElementById('pinTitle').value = currentpostData.title;
            document.getElementById('pinDescription').value = currentpostData.description;
            document.getElementById('pinSlug').value = currentpostData.link;
            document.getElementById('pinCategoryName').value = categoryName;
            // Default board: classic board or first board or categorySlug
            const _boards = data.pinterest_boards || {};
            document.getElementById('pinBoardName').value = _boards.classic || data.board_name || categorySlug;

            // Images container
            const container = document.getElementById('pinImagesContainer');
            container.innerHTML = "";

            // Store base URL (without fragment)
            currentpostData.baseLink = currentpostData.link;

            // Template images depuis post.json (type === 'template')
            const templateImages = currentpostData.images.filter(img => img.type === 'template');

            // Fetch images from PHP folder
            fetch(`posts-liste.php?dir=posts/${encodeURIComponent(data.image_dir)}`)
                .then(res => res.json())
                .then(images => {
                    images.forEach((src, index) => {
                        // MP4 reels — afficher une vignette vidéo au lieu d'une image cassée
                        if (src.toLowerCase().endsWith('.mp4')) {
                            const vidWrap = document.createElement('div');
                            vidWrap.style.cssText = 'display:inline-flex;align-items:center;justify-content:center;width:15%;aspect-ratio:9/16;background:#111;border-radius:6px;border:2px solid #444;color:#fff;font-size:28px;cursor:default;';
                            vidWrap.title = src.split('/').pop();
                            vidWrap.innerHTML = '🎬';
                            container.appendChild(vidWrap);
                            return;
                        }
                        let imgEl = document.createElement('img');
                        imgEl.src = src;
                        imgEl.dataset.pinIndex = index + 1;
                        imgEl.className = "rounded border";
                        imgEl.style.width = "15%";
                        imgEl.style.height = "auto";
                        imgEl.style.objectFit = "cover";

                        // Détecter si c'est une image template et lui assigner la variation
                        const filename = src.split('/').pop();
                        const tpl = templateImages.find(t => (t.fileName === filename) || (t.filePath || '').endsWith('/' + filename));
                        let wrapEl = imgEl;
                        if (tpl) {
                            const m = filename.match(/_image_(\d+)\.(webp|jpg)$/i);
                            if (m && currentpostData.pin_variations.length > 0) {
                                const idx = (parseInt(m[1]) - 1) % currentpostData.pin_variations.length;
                                imgEl.dataset.varIdx = idx;
                                imgEl.dataset.isTemplate = '1';
                                imgEl.dataset.template = tpl.template || '';
                                imgEl.title = currentpostData.pin_variations[idx].title;
                            }
                            // Wrap in a div with board badge
                            const tplName = tpl.template || '';
                            const boards = currentpostData.pinterest_boards || {};
                            const board = boards[tplName] || '';
                            const tplColors = {classic:'#93043d', header:'#1B4332', cinematic:'#0A0A0A'};
                            const tplColor = tplColors[tplName] || '#555';
                            wrapEl = document.createElement('div');
                            wrapEl.style.cssText = 'display:inline-block;text-align:center;width:15%';
                            imgEl.style.width = '100%';
                            if (board) {
                                const badge = document.createElement('div');
                                badge.style.cssText = `background:${tplColor};color:#fff;font-size:10px;padding:2px 4px;border-radius:0 0 4px 4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:pointer`;
                                badge.textContent = '📌 ' + board;
                                badge.title = tplName + ' → ' + board;
                                wrapEl.appendChild(imgEl);
                                wrapEl.appendChild(badge);
                            } else {
                                wrapEl.appendChild(imgEl);
                            }
                        }

                        container.appendChild(wrapEl);
                    });
                });

            // Show modal
            let myModal = new bootstrap.Modal(document.getElementById('pinModal'));
            myModal.show();

        });
    });

    // Handle Insert Pinterest RSS button click
    document.getElementById('insertPinRssBtn').addEventListener('click', function() {
        const selectedImage = window.getPinterestSelectedImage();

        if (!selectedImage) {
            showNotification('Veuillez sélectionner une image!', 'error');
            return;
        }

        if (!currentpostData) {
            showNotification('Données de post non disponibles!', 'error');
            return;
        }

        // Mettre à jour l'image sélectionnée
        currentpostData.image = replace(selectedImage, globalThis.siteUrl + '/', '');

        // Désactiver le bouton pendant l'envoi
        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '⏳ Insertion en cours...';

        // Créer FormData pour l'envoi
        const formData = new FormData();
        formData.append('action', 'insert_pinrss');
        formData.append('title', currentpostData.title);
        formData.append('description', currentpostData.description);
        formData.append('link', currentpostData.link);
        formData.append('image', currentpostData.image);
        formData.append('category', currentpostData.category);
        formData.append('category_slug', currentpostData.category_slug);

        // Envoyer la requête AJAX
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification(data.message, 'success');
                // Fermer le modal après 1 seconde
                setTimeout(() => {
                    bootstrap.Modal.getInstance(document.getElementById('pinModal')).hide();
                }, 1000);
            } else {
                showNotification(data.message, 'error');
            }
        })
        .catch(error => {
            showNotification('Erreur de connexion: ' + error.message, 'error');
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '📌 Insérer dans Pinterest RSS';
        });
    });

    // Export Queue Management
    function getExportQueue() {
        const queue = localStorage.getItem('pinterestExportQueue');
        return queue ? JSON.parse(queue) : [];
    }

    function saveExportQueue(queue) {
        localStorage.setItem('pinterestExportQueue', JSON.stringify(queue));
        updateQueueCounter();
    }

    function updateQueueCounter() {
        const queue = getExportQueue();
        document.getElementById('queueCount').textContent = queue.length;
    }

    // Initialiser le compteur au chargement
    updateQueueCounter();

    // Add to Queue button handler
    document.getElementById('addToQueueBtn').addEventListener('click', function() {
        console.log('🔵 Add to Queue clicked');
        console.log('🔵 currentpostData:', currentpostData);

        if (!currentpostData) {
            showNotification('Aucune post sélectionnée', 'error');
            return;
        }

        // Récupérer l'image sélectionnée via la fonction globale
        const selectedImage = window.getPinterestSelectedImage();
        console.log('🔵 selectedImage:', selectedImage);

        if (!selectedImage) {
            showNotification('Veuillez sélectionner une image', 'error');
            return;
        }

        const queue = getExportQueue();

        // Vérifier si déjà dans la file
        // const exists = queue.find(item => item.slug === currentpostData.uniqueSlug);
        // if (exists) {
        //     showNotification('⚠️ Cette post est déjà dans la file', 'warning');
        //     return;
        // }

        // Ajouter à la file avec timestamp pour scheduling
        const queueItem = {
            slug: globalThis.linkPinActive ? (document.getElementById('pinSlug').value || currentpostData.link) : '',
            title: document.getElementById('pinTitle').value || currentpostData.title,
            category: document.getElementById('pinBoardName').value || currentpostData.category_slug,
            image: selectedImage,
            description: document.getElementById('pinDescription').value || currentpostData.description || '',
            addedAt: new Date().toISOString(),
            scheduleTime: new Date(Date.now() + (queue.length * 3600000)).toISOString() // +1h par post
        };

        queue.push(queueItem);
        console.log('🔵 Queue after push:', queue);

        saveExportQueue(queue);
        console.log('🔵 Queue saved to localStorage');

        showNotification(`✅ Ajouté à la file! (${queue.length} posts)`, 'success');

        const modal = bootstrap.Modal.getInstance(document.getElementById('pinModal'));
        if (modal) modal.hide();
    });

    // Export Queue CSV button handler
document.getElementById('exportQueueCsvBtn').addEventListener('click', function() {
    const queue = getExportQueue();
    
    if (queue.length === 0) {
        showNotification('La file d\'export est vide', 'error');
        return;
    }
    
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '⏳ Export en cours...';
    
    fetch('export-queue-csv.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ queue: queue })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // 🔥 Décoder le CSV base64 et télécharger (Uint8Array pour préserver UTF-8)
            const bytes = atob(data.csvData);
            const arr = new Uint8Array(bytes.length);
            for (let j = 0; j < bytes.length; j++) arr[j] = bytes.charCodeAt(j);
            const blob = new Blob([arr], { type: 'text/csv;charset=utf-8;' });
            
            // Créer le téléchargement
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = data.filename;
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);
            
            // 🗑️ Vider la queue
            localStorage.removeItem('pinterestExportQueue');
            updateQueueCounter();
            
            const queueList = document.getElementById('exportQueueList');
            if (queueList) {
                queueList.innerHTML = '<p class="text-muted text-center">Aucune post dans la file</p>';
            }
            
            // Fermer le modal
            setTimeout(() => {
                const modal = bootstrap.Modal.getInstance(document.getElementById('pinModal'));
                if (modal) modal.hide();
            }, 500);
            
            showNotification(`✅ ${data.count} posts exportées!`, 'success');
        } else {
            throw new Error(data.message);
        }
    })
    .catch(error => {
        console.error('❌ Export error:', error);
        showNotification('Erreur: ' + error.message, 'error');
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '📊 Exporter la file CSV';
    });
});

    // Update counter on page load
    updateQueueCounter();

    // ── Select All page + CSV sélection ──────────────────────────────────────────
    function updateSelectedCount() {
        const checked = document.querySelectorAll('.page-select-checkbox:checked');
        const all     = document.querySelectorAll('.page-select-checkbox');
        const count   = checked.length;
        document.getElementById('selectedCount').textContent = count;
        document.getElementById('fbSelectedCount').textContent = count;
        const btn = document.getElementById('csvSelectedBtn');
        btn.disabled = count === 0;
        btn.style.opacity = count === 0 ? '0.5' : '1';
        const fbBtn = document.getElementById('fbPostSelectedBtn');
        fbBtn.disabled = count === 0;
        fbBtn.style.opacity = count === 0 ? '0.5' : '1';
        const selectAll = document.getElementById('selectAllPageCheckbox');
        if (selectAll) {
            selectAll.indeterminate = count > 0 && count < all.length;
            selectAll.checked = count > 0 && count === all.length;
        }
    }

    const _selectAll = document.getElementById('selectAllPageCheckbox');
    if (_selectAll) {
        _selectAll.addEventListener('change', function () {
            document.querySelectorAll('.page-select-checkbox').forEach(cb => cb.checked = this.checked);
            updateSelectedCount();
        });
    }
    document.querySelectorAll('.page-select-checkbox').forEach(cb => {
        cb.addEventListener('change', updateSelectedCount);
    });

    document.getElementById('csvSelectedBtn').addEventListener('click', function () {
        const slugs = [...document.querySelectorAll('.page-select-checkbox:checked')].map(cb => cb.dataset.slug);
        if (!slugs.length) return;

        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '⏳ Génération...';

        const fd = new FormData();
        slugs.forEach(s => fd.append('slugs[]', s));
        const csvDate = document.getElementById('autoCsvDate')?.value || '';
        if (csvDate) fd.append('csv_date', csvDate);
        const linkToggle = document.getElementById('linkPinToggle');
        fd.append('linkPinToggle', (linkToggle && linkToggle.checked) ? '1' : '0');

        fetch('generate-csv.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showNotification(`✅ ${data.message}`, 'success');
                } else {
                    showNotification('❌ ' + (data.message || 'Erreur'), 'error');
                }
            })
            .catch(e => showNotification('❌ ' + e.message, 'error'))
            .finally(() => {
                const c = document.querySelectorAll('.page-select-checkbox:checked').length;
                btn.disabled = c === 0;
                btn.style.opacity = c === 0 ? '0.5' : '1';
                btn.innerHTML = `📋 CSV sélection (<span id="selectedCount">${c}</span>)`;
            });
    });

    // ── Post Facebook sélection ───────────────────────────────────────────────────
    document.getElementById('fbPostSelectedBtn').addEventListener('click', function () {
        const slugs = [...document.querySelectorAll('.page-select-checkbox:checked')].map(cb => cb.dataset.slug);
        if (!slugs.length) return;

        if (!confirm(`Poster ${slugs.length} article(s) sur Facebook maintenant ?`)) return;

        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '⏳ Posting...';

        const fd = new FormData();
        fd.append('action', 'fb_post_slugs');
        slugs.forEach(s => fd.append('slugs[]', s));

        fetch('posts-liste.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const detail = data.results.map(r => (r.ok ? '✅' : '❌') + ' ' + r.slug + ' — ' + r.msg).join('\n');
                    showNotification(`✅ ${data.ok} posté(s)` + (data.ko ? `, ❌ ${data.ko} erreur(s)` : ''), 'success');
                    console.log('[FB Post]', detail);
                } else {
                    const errDetail = data.results ? data.results.map(r => r.slug + ': ' + r.msg).join('\n') : '';
                    showNotification('❌ ' + (data.error || 'Erreur Facebook') + (errDetail ? '\n' + errDetail : ''), 'error');
                }
            })
            .catch(e => showNotification('❌ ' + e.message, 'error'))
            .finally(() => {
                const c = document.querySelectorAll('.page-select-checkbox:checked').length;
                btn.disabled = c === 0;
                btn.style.opacity = c === 0 ? '0.5' : '1';
                btn.innerHTML = `📘 Post FB (<span id="fbSelectedCount">${c}</span>)`;
            });
    });

    // ── Générer CSV groupé — même logique que la fin de auto-daily-csv ──────────
    document.getElementById('generateCombinedCsvBtn').addEventListener('click', function () {
        const btn = this;
        const csvDate = document.getElementById('autoCsvDate')?.value || '';
        btn.disabled = true;
        btn.innerHTML = '⏳ Génération...';

        const fd = new FormData();
        if (csvDate) fd.append('csv_date', csvDate);
        const linkToggle = document.getElementById('linkPinToggle');
        fd.append('linkPinToggle', (linkToggle && linkToggle.checked) ? '1' : '0');

        fetch('generate-csv.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showNotification(`✅ ${data.message}`, 'success');

                    // Rafraîchir la liste des CSV disponibles
                    fetch('?action=check_daily_csv')
                        .then(r => r.json())
                        .then(d => {
                            if (d.exists && d.files && d.files.length > 0) {
                                const wrap = document.getElementById('csvDropdownWrap');
                                const list = document.getElementById('csvFileList');
                                const count = document.getElementById('csvFileCount');
                                if (count) count.textContent = d.files.length;
                                if (list) {
                                    list.innerHTML = '';
                                    d.files.forEach(f => {
                                        const li = document.createElement('li');
                                        li.innerHTML = `<a class="dropdown-item" href="?action=download_daily_csv&file=${encodeURIComponent(f.filename)}">📄 ${f.filename} <small class="text-muted">(${f.rows} pins)</small></a>`;
                                        list.appendChild(li);
                                    });
                                }
                                if (wrap) wrap.style.setProperty('display', 'inline-block', 'important');
                            }
                        });
                } else {
                    showNotification('❌ ' + (data.message || 'Erreur'), 'error');
                }
            })
            .catch(e => showNotification('❌ ' + e.message, 'error'))
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = '🗂️ Grouper CSV';
            });
    });

    // ── Auto Daily CSV ────────────────────────────────────────────────────────
    window.runAutoDailyCsv = function runAutoDailyCsv() {
        const btn   = document.getElementById('autoDailyCsvBtn');
        const limit = 10;
        btn.disabled = true;

        // ── Progress polling ──────────────────────────────────────────────────
        let pollInterval = setInterval(() => {
            fetch('downloads/progress.json?t=' + Date.now())
                .then(r => r.ok ? r.json() : null)
                .then(p => {
                    if (!p) return;
                    btn.innerHTML = p.icon + ' ' + p.message + (p.detail ? '<br><small style="font-weight:normal;opacity:.8">' + p.detail + '</small>' : '');
                })
                .catch(() => {});
        }, 1000);

        const fd = new FormData();
        fd.append('limit', limit);
        const linkToggle = document.getElementById('linkPinToggle');
        fd.append('linkPinToggle', (linkToggle && linkToggle.checked) ? '1' : '0');
        const csvDate = document.getElementById('autoCsvDate').value;
        fd.append('csv_date', csvDate);

        fetch('auto-daily-csv.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                clearInterval(pollInterval);
                if (!data.success) throw new Error(data.message);

                // Download all CSV files (one per template group)
                const files = data.files || [{ filename: data.filename, csvData: data.csvData }];
                files.forEach((f, i) => {
                    setTimeout(() => {
                        const bytes = atob(f.csvData);
                        const arr   = new Uint8Array(bytes.length);
                        for (let j = 0; j < bytes.length; j++) arr[j] = bytes.charCodeAt(j);
                        const blob = new Blob([arr], { type: 'text/csv;charset=utf-8;' });
                        const url  = URL.createObjectURL(blob);
                        const a    = document.createElement('a');
                        a.href = url; a.download = f.filename;
                        document.body.appendChild(a); a.click();
                        URL.revokeObjectURL(url); document.body.removeChild(a);
                    }, i * 400); // small delay between downloads
                });

                showNotification(`✅ ${data.count} articles — ${data.rows} pins — ${files.length} fichiers CSV`, 'success');
                setTimeout(() => location.reload(), 1500);
            })
            .catch(err => {
                clearInterval(pollInterval);
                console.error('Auto CSV error:', err);
                showNotification('❌ ' + err.message, 'error');
            })
            .finally(() => {
                clearInterval(pollInterval);
                btn.disabled = false;
                btn.innerHTML = '⚡ Auto CSV du jour';
            });
    }

    if (document.getElementById('autoDailyCsvBtn')) {
        document.getElementById('autoDailyCsvBtn').addEventListener('click', function () {
            runAutoDailyCsv();
        });
    }

    // ── Auto-trigger via URL param (?auto_csv=1) — pour .bat / Task Scheduler ──
    if (new URLSearchParams(window.location.search).get('auto_csv') === '1') {
        setTimeout(runAutoDailyCsv, 1200);
    }

    // ── Rollback Daily CSV ────────────────────────────────────────────────────
    document.getElementById('rollbackDailyCsvBtn') && document.getElementById('rollbackDailyCsvBtn').addEventListener('click', function () {
        if (!confirm('Annuler le dernier Auto CSV ?\nLes articles seront remis offline et les templates supprimés.')) return;
        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '⏳ Rollback...';
        fetch('rollback-daily-csv.php', { method: 'POST' })
            .then(r => r.json())
            .then(data => {
                if (!data.success) throw new Error(data.message);
                showNotification('↩️ ' + data.message, 'success');
                setTimeout(() => location.reload(), 1500);
            })
            .catch(err => showNotification('❌ ' + err.message, 'error'))
            .finally(() => { btn.disabled = false; btn.innerHTML = '↩️ Rollback CSV'; });
    });
});


</script>

    </script>



    <script>
        // Function pour afficher les notifications
        function showNotification(message, type = 'success') {
            const notification = document.getElementById('notification');
            notification.textContent = message;
            notification.className = `notification ${type}`;
            notification.classList.add('show');
            
            setTimeout(() => {
                notification.classList.remove('show');
            }, 3000);
        }
        
        // Function pour mettre à jour les statistiques
        function updateStats(change) {
            const onlineCountEl = document.getElementById('online-count');
            const offlineCountEl = document.getElementById('offline-count');
            
            let onlineCount = parseInt(onlineCountEl.textContent);
            let offlineCount = parseInt(offlineCountEl.textContent);
            
            if (change === 'online') {
                onlineCount++;
                offlineCount--;
            } else if (change === 'offline') {
                onlineCount--;
                offlineCount++;
            }
            
            onlineCountEl.textContent = onlineCount;
            offlineCountEl.textContent = offlineCount;
        }
        
        // Function pour appliquer le filtre
        function applySearch() {
            const q = document.getElementById('searchInput').value.trim();
            const urlParams = new URLSearchParams(window.location.search);
            if (q) urlParams.set('search', q);
            else urlParams.delete('search');
            urlParams.set('page', '1');
            window.location.href = '?' + urlParams.toString();
        }

        function applyFilter(status) {
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('filter', status);
            urlParams.set('page', '1'); // Reset à la page 1
            window.location.href = '?' + urlParams.toString();
        }
        
        // Function pour changer l'ordre de tri
        function changeSortOrder(order) {
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('sort', order);
            urlParams.set('page', '1'); // Reset à la page 1
            window.location.href = '?' + urlParams.toString();
        }
        
        // Function pour changer le nombre d'items par page
        function changeItemsPerPage(value) {
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('per_page', value);
            urlParams.set('page', '1'); // Reset à la page 1
            window.location.href = '?' + urlParams.toString();
        }
        
        // Event listener pour tous les checkboxes
        document.querySelectorAll('.online-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const path = this.getAttribute('data-path');
                const isOnline = this.checked;
                const spinner = this.nextElementSibling;
                
                this.disabled = true;
                spinner.style.display = 'inline-block';
                
                const formData = new FormData();
                formData.append('action', 'update_ajax');
                formData.append('post_path', path);
                formData.append('is_online', isOnline);
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Erreur HTTP: ' + response.status);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        showNotification(data.message, 'success');
                        updateStats(isOnline ? 'online' : 'offline');

                        // Régénérer l'index (erreur ici n'affecte pas l'update réussi)
                        fetch('posts-generater.php?action=posts_index', {
                            method: 'GET'
                        }).catch(indexError => {
                            console.error('Erreur lors de la régénération de l\'index:', indexError);
                            showNotification('Post mis à jour, mais erreur lors de la régénération de l\'index', 'warning');
                        });
                    } else {
                        showNotification(data.message || 'Erreur inconnue', 'error');
                        checkbox.checked = !isOnline;
                    }
                })
                .catch(error => {
                    console.error('Erreur update isOnline:', error);
                    showNotification('Erreur de connexion: ' + error.message, 'error');
                    checkbox.checked = !isOnline;
                })
                .finally(() => {
                    this.disabled = false;
                    spinner.style.display = 'none';
                });
            });
        });
        
        // Event listener pour tous les selectbox de catégorie
        document.querySelectorAll('.category-select').forEach(select => {
            select.addEventListener('change', function() {
                const path = this.getAttribute('data-path');
                const currentCategory = this.getAttribute('data-current');
                const newCategoryId = this.value;
                const spinner = this.nextElementSibling;
                
                // Si c'est la même catégorie, ne rien faire
                if (newCategoryId === currentCategory) {
                    return;
                }
                
                this.disabled = true;
                spinner.style.display = 'inline-block';
                
                const formData = new FormData();
                formData.append('action', 'update_category');
                formData.append('post_path', path);
                formData.append('category_id', newCategoryId);
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message, 'success');
                        // Mettre à jour l'attribut data-current
                        this.setAttribute('data-current', newCategoryId);
                        
                        // Appeler posts-generater.php pour regénérer l'index
                        return fetch('posts-generater.php?action=posts_index', {
                            method: 'GET'
                        });
                    } else {
                        showNotification(data.message, 'error');
                        // Remettre l'ancienne valeur
                        this.value = currentCategory;
                    }
                })
                .catch(error => {
                    showNotification('Erreur de connexion', 'error');
                    // Remettre l'ancienne valeur
                    this.value = currentCategory;
                })
                .finally(() => {
                    this.disabled = false;
                    spinner.style.display = 'none';
                });
            });
        });

        // Event listener pour le bouton "Générer tous les RSS"
        const generateMissingHtmlBtn = document.getElementById('generateMissingHtmlBtn');
        if (generateMissingHtmlBtn) {
            generateMissingHtmlBtn.addEventListener('click', function() {
                const btn = this;
                const originalText = btn.innerHTML;

                btn.disabled = true;
                btn.innerHTML = '⏳ Generating...';

                const formData = new FormData();
                formData.append('action', 'generate_missing_html');

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let msg = `✅ Regenerated: ${data.generated_count} files`;
                        if (data.generated.length > 0) {
                            msg += '\n\nGenerated:\n' + data.generated.join('\n');
                        }
                        if (data.errors.length > 0) {
                            msg += '\n\nErrors:\n' + data.errors.map(e => e.slug + ': ' + e.error).join('\n');
                        }
                        showNotification(msg, data.errors.length > 0 ? 'warning' : 'success');
                    } else {
                        showNotification('Error: ' + data.error, 'error');
                    }
                })
                .catch(error => {
                    showNotification('Error: ' + error.message, 'error');
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                });
            });
        }

        const generateAllRssBtn = document.getElementById('generateAllRssBtn');
        if (generateAllRssBtn) {
            generateAllRssBtn.addEventListener('click', function() {
                const btn = this;
                const originalText = btn.innerHTML;

                // Désactiver le bouton pendant le traitement
                btn.disabled = true;
                btn.innerHTML = '⏳ Génération en cours...';

                const formData = new FormData();
                formData.append('action', 'generate_all_rss');

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let detailsMsg = '';
                        if (data.details && data.details.length > 0) {
                            detailsMsg = '\n\n' + data.details.join('\n');
                        }
                        showNotification(data.message + detailsMsg, 'success');
                    } else {
                        showNotification(data.message, 'error');
                    }
                })
                .catch(error => {
                    showNotification('Erreur: ' + error.message, 'error');
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                });
            });
        }

            // document.querySelectorAll('.rewrite_post').forEach(select => {
            //     select.addEventListener('click', function() {
            //         const posts = this.getAttribute('data-post');
                    
            //         const spinner = this.nextElementSibling;                            
            //         console.log("posts: "+posts);
            //         this.disabled = true;
            //         spinner.style.display = 'inline-block';
                    
            //         const formData = new FormData();
            //         formData.append('action', 'rewrite_post');
            //         formData.append('posts', posts);
                    
                    
            //         // fetch(window.location.href, {
            //         //     method: 'POST',
            //         //     body: formData
            //         // })
            //         // .then(response => response.json())
            //         // .then(data => {
            //         //     if (data.success) {
            //         //         showNotification(data.message, 'success');
            //         //         // Mettre à jour l'attribut data-current
            //         //         this.setAttribute('data-current', newCategoryId);
                            
            //         //         // Appeler posts-generater.php pour regénérer l'index
            //         //         return fetch('posts-generater.php?action=posts_index', {
            //         //             method: 'GET'
            //         //         });
            //         //     } else {
            //         //         showNotification(data.message, 'error');
            //         //         // Remettre l'ancienne valeur
            //         //         this.value = currentCategory;
            //         //     }
            //         // })
            //         // .catch(error => {
            //         //     showNotification('Erreur de connexion', 'error');
            //         //     // Remettre l'ancienne valeur
            //         //     this.value = currentCategory;
            //         // })
            //         // .finally(() => {
            //         //     this.disabled = false;
            //         //     spinner.style.display = 'none';
            //         // });
            //     });
            // });

        
        
        function viewpost(path) {
            alert('Ouvrir la post: ' + path);
            // window.location.href = 'edit_post.php?path=' + encodeURIComponent(path);
        }
    </script>

<script>
// Pinterest Image Selection for Modal
(function() {
  console.log('🎯 Pinterest script initializing...');
  
  let selectedImage = null;
  
  // Wait for DOM to be fully loaded
  document.addEventListener('DOMContentLoaded', function() {
    console.log('✅ DOM loaded, setting up Pinterest image selection');
  });
  
  // Handle image clicks with delegation
  document.addEventListener('click', function(e) {
    // Check if clicked on an image inside pinImagesContainer
    const target = e.target;
    
    if (target.tagName === 'IMG' && target.closest('#pinImagesContainer')) {
      console.log('🖱️ Image clicked!', target.src);
      
      // Remove previous selection
      const container = document.getElementById('pinImagesContainer');
      if (container) {
        container.querySelectorAll('img').forEach(img => {
          img.style.border = '2px solid #dee2e6';
          img.style.transform = 'scale(1)';
          img.style.boxShadow = '';
        });
      }
      
      // Highlight selected image
      target.style.border = '4px solid #e60023';
      target.style.transform = 'scale(1.05)';
      target.style.boxShadow = '0 4px 8px rgba(230, 0, 35, 0.3)';
      
      // Store selected image
      selectedImage = target.src;

      // Update URL: src = title slugified (no special chars)
      const toSrcSlug = str => str.trim().replace(/\s+/g, '-').replace(/[^a-zA-Z0-9-]/g, '');

      if (typeof currentpostData !== 'undefined' && currentpostData && target.dataset.pinIndex) {
        const title = document.getElementById('pinTitle').value;
        const pinUrl = currentpostData.baseLink + '?src=' + toSrcSlug(title)+"-image-"+(target.dataset.pinIndex);
        document.getElementById('pinSlug').value = pinUrl;
      }

      // Si c'est un template → update title + description + board avec la variation correspondante
      if (target.dataset.isTemplate === '1' && typeof currentpostData !== 'undefined' && currentpostData) {
        const varIdx = parseInt(target.dataset.varIdx);
        const variation = (currentpostData.pin_variations || [])[varIdx];
        if (variation) {
          document.getElementById('pinTitle').value = variation.title;
          document.getElementById('pinSlug').value = currentpostData.baseLink + '?src=' + toSrcSlug(variation.title) + "-image-"+(target.dataset.pinIndex);
          document.getElementById('pinDescription').value = variation.description;
        }
        // Update board name based on template type
        const templateName = target.dataset.template; // 'classic', 'header', 'cinematic'
        const boards = currentpostData.pinterest_boards || {};
        const board = boards[templateName] || boards.classic || document.getElementById('pinBoardName').value;
        document.getElementById('pinBoardName').value = board;
      }
    }
    
    // Reset on modal close
    if (target.matches('[data-bs-dismiss="modal"]') || target.classList.contains('modal-backdrop')) {
      console.log('🔄 Modal closed, resetting selection');
      selectedImage = null;
      const container = document.getElementById('pinImagesContainer');
      if (container) {
        container.querySelectorAll('img').forEach(img => {
          img.style.border = '2px solid #dee2e6';
          img.style.transform = 'scale(1)';
          img.style.boxShadow = '';
        });
      }
    }
  });
  
  // Make selectedImage accessible globally
  window.getPinterestSelectedImage = function() {
    console.log('📤 Extension requested image:', selectedImage);
    return selectedImage;
  };
  
  console.log('✅ Pinterest Image Selection loaded and ready!');
})();
</script>

<script>
// ── CSV du jour (Task Scheduler) — vérification au chargement ────
(function () {
    document.addEventListener('DOMContentLoaded', function () {

        // Lister tous les CSV disponibles
        fetch('?action=check_daily_csv')
            .then(r => r.json())
            .then(d => {
                if (d.exists && d.files && d.files.length > 0) {
                    const wrap = document.getElementById('csvDropdownWrap');
                    const list = document.getElementById('csvFileList');
                    const count = document.getElementById('csvFileCount');
                    if (count) count.textContent = d.files.length;
                    if (list) {
                        list.innerHTML = '';
                        d.files.forEach(f => {
                            const li = document.createElement('li');
                            li.innerHTML = `<a class="dropdown-item" href="?action=download_daily_csv&file=${encodeURIComponent(f.filename)}">
                                📄 ${f.filename} <small class="text-muted">(${f.rows} pins)</small>
                            </a>`;
                            list.appendChild(li);
                        });
                    }
                    if (wrap) wrap.style.setProperty('display', 'inline-block', 'important');
                }
            });
    });
})();

// ── Link Pin toggle ──────────────────────────────────────────────
(function () {
    function applyLinkPinState(active) {
        globalThis.linkPinActive = active;
        const row = document.getElementById('pinSlugRow');
        if (row) row.style.display = active ? '' : 'none';
        const slider = document.getElementById('linkPinSlider');
        const thumb  = document.getElementById('linkPinThumb');
        if (slider) slider.style.background = active ? '#28a745' : '#ccc';
        if (thumb)  thumb.style.left = active ? '19px' : '3px';
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Apply initial state
        applyLinkPinState(globalThis.linkPinActive);

        const toggle = document.getElementById('linkPinToggle');
        if (!toggle) return;

        toggle.addEventListener('change', function () {
            const newVal = this.checked;
            applyLinkPinState(newVal);
            const fd = new FormData();
            fd.append('action', 'toggle_link_pin');
            fd.append('value', newVal ? 'true' : 'false');
            fetch(window.location.pathname, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => console.log('🔗 linkPinActive =', d.linkPinActive))
                .catch(e => console.warn('toggle_link_pin error', e));
        });

        // Delete post buttons
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.delete-post-btn');
            if (!btn) return;
            const slug = btn.dataset.slug;
            if (!confirm('Supprimer "' + slug + '" ? Cette action est irréversible.')) return;
            btn.disabled = true;
            btn.textContent = '⏳';
            const fd = new FormData();
            fd.append('action', 'delete_post');
            fd.append('slug', slug);
            fetch(window.location.pathname, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        const row = btn.closest('tr');
                        if (row) row.remove();
                    } else {
                        alert('Erreur: ' + d.error);
                        btn.disabled = false;
                        btn.textContent = '🗑️ Supprimer';
                    }
                })
                .catch(() => { btn.disabled = false; btn.textContent = '🗑️ Supprimer'; });
        });

        const saveBtn = document.getElementById('savePipelineLimitsBtn');
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                const min = parseInt(document.getElementById('pipelineLimitMin').value) || 5;
                const max = parseInt(document.getElementById('pipelineLimitMax').value) || 15;
                const fd = new FormData();
                fd.append('action', 'save_pipeline_limits');
                fd.append('min', min);
                fd.append('max', max);
                fetch(window.location.pathname, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(d => {
                        saveBtn.textContent = '✅';
                        setTimeout(() => saveBtn.textContent = '✓', 1500);
                    })
                    .catch(e => console.warn('save_pipeline_limits error', e));
            });
        }
    });
})();
</script>

</body>
</html>
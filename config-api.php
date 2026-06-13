<?php
header('Content-Type: application/json');

require_once __DIR__ . '/auth.php';
auth_check();
require_once __DIR__ . '/pinterest-boards-helpers.php';

$action     = $_GET['action'] ?? ($_POST['action'] ?? '');
$configFile = __DIR__ . '/site-config.json';

// ── Upload Pinterest Trends CSV ───────────────────────────────────────────────
if ($action === 'upload_pinterest_trends') {
    if (!isset($_FILES['trends_csv']) || $_FILES['trends_csv']['error'] !== UPLOAD_ERR_OK) {
        $errCode = $_FILES['trends_csv']['error'] ?? -1;
        echo json_encode(['success' => false, 'error' => "Aucun fichier reçu (code $errCode)"]);
        exit;
    }
    $tmp = $_FILES['trends_csv']['tmp_name'];
    // Valider que le CSV contient une colonne "Trend" ou "Keyword"
    // Le CSV Pinterest Trends a plusieurs lignes de métadonnées avant l'en-tête réel
    $fh = fopen($tmp, 'r');
    $dataHeader = null;
    $maxScan = 15; $scanned = 0;
    while ($fh && ($row = fgetcsv($fh)) !== false && $scanned < $maxScan) {
        $scanned++;
        $normalized = array_map('strtolower', array_map('trim', $row));
        if (in_array('trend', $normalized) || in_array('keyword', $normalized)) {
            $dataHeader = $normalized; break;
        }
    }
    if ($fh) fclose($fh);
    if (!$dataHeader) {
        echo json_encode(['success' => false, 'error' => 'CSV invalide — colonne "Trend" introuvable. Vérifie que c\'est bien un export Pinterest Trends.']);
        exit;
    }
    $uploadDir  = __DIR__ . '/downloads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $targetFile = $uploadDir . 'pinterest-trends-import.csv';
    $lineCount  = max(0, count(file($tmp)) - 1);
    if (!move_uploaded_file($tmp, $targetFile)) {
        echo json_encode(['success' => false, 'error' => 'Erreur lors de la sauvegarde du fichier']);
        exit;
    }
    echo json_encode(['success' => true, 'count' => $lineCount]);
    exit;
}

// ── Save satellites ───────────────────────────────────────────────────────────
if ($action === 'save_satellites') {
    if (!auth_is_admin()) {
        echo json_encode(['success' => false, 'message' => 'Accès refusé']);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        echo json_encode(['success' => false, 'message' => 'JSON invalide']);
        exit;
    }
    $satellites = [];
    foreach ($input as $sat) {
        $path = trim($sat['path'] ?? '');
        $url  = trim($sat['url']  ?? '');
        if ($path !== '' && $url !== '') {
            $satellites[] = ['path' => $path, 'url' => $url];
        }
    }
    $raw = file_exists($configFile) ? file_get_contents($configFile) : '';
    $cfg = json_decode($raw, true);
    if (!is_array($cfg)) {
        echo json_encode(['success' => false, 'message' => 'site-config.json invalide — sauvegardez d\'abord la config principale']);
        exit;
    }
    $cfg['SATELLITE_PROJECTS'] = $satellites;
    if (file_put_contents($configFile, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
        echo json_encode(['success' => true, 'count' => count($satellites), 'satellites' => $satellites]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Impossible d\'écrire site-config.json']);
    }
    exit;
}

// ── Reset site-config.json ────────────────────────────────────────────────────
if ($action === 'reset') {
    if (file_exists($configFile)) {
        unlink($configFile);
        echo json_encode(['success' => true, 'message' => 'site-config.json supprimé']);
    } else {
        echo json_encode(['success' => true, 'message' => 'Rien à supprimer']);
    }
    exit;
}

// ── Save ads config → regenerate ads.js ──────────────────────────────────────
if ($action === 'save_ads') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'JSON invalide']);
        exit;
    }

    $adsJsPath = __DIR__ . '/ads.js';
    $adsJs     = file_get_contents($adsJsPath);

    // Find engine marker — everything from it onwards is kept intact
    $marker    = '    // ==================== ENGINE';
    $enginePos = strpos($adsJs, $marker);
    if ($enginePos === false) {
        echo json_encode(['success' => false, 'message' => 'Marker ENGINE introuvable dans ads.js']);
        exit;
    }
    $enginePart = substr($adsJs, $enginePos);

    // Build indented JSON config (4-space indent to match JS style)
    $json = json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $json = preg_replace('/^/m', '        ', $json);   // 8 spaces for IIFE indent
    $json = ltrim($json);                               // remove leading spaces on first line

    $newJs = <<<JS
/**
 * AdSense Auto-Injection System
 * 100% Config-driven — edit via config-ui.php
 */

(function() {
    'use strict';

    // ==================== CONFIGURATION ====================
    const ADS_CONFIG = $json;

    $enginePart
JS;

    // Save ads-config.json + regenerate ads.js
    file_put_contents(__DIR__ . '/ads-config.json', json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents($adsJsPath, $newJs);

    echo json_encode(['success' => true, 'message' => 'ads.js régénéré']);
    exit;
}

// ── Sync page-content code files ─────────────────────────────────────────────
if ($action === 'sync_page_code') {
    require_once __DIR__ . '/config.php';

    $filesToSync = [
        // Core app
        'config-ui.php',
        'config-api.php',
        'git-init-api.php',
        'auth.php',
        'login.php',
        // Frontend
        'router.js',
        'main.js',
        'post-interactive.js',
        'ads.js',
        // Page content
        'page-content-api.php',
        'pages/home-content.html',
        'pages/about-content.html',
        'pages/contact-content.html',
        'pages/privacy-policy-content.html',
        'pages/discover-content.html',
        // Generation & pipeline
        'config.php',
        'rating-helpers.php',
        'rate-post.php',
        'regen-pages-api.php',
        'generate-post.php',
        'generate-single-post.php',
        'generate_pinterest.php',
        'generate-pinterest-rss.php',
        'auto-pipeline.php',
        'auto-daily-csv.php',
        'posts-generater.php',
        'posts-api.php',
        'posts-async.php',
        'posts-worker.php',
        'posts-job-status.php',
        'posts-liste.php',
        'posts-table.php',
        'sync-satellites.php',
        'push.php',
        'tasks-api.php',
    ];

    // Helper: sync files to one target directory
    $syncToPath = function(string $targetPath) use ($filesToSync): array {
        $satLog = [];
        foreach ($filesToSync as $rel) {
            $src = __DIR__ . '/' . $rel;
            $dst = $targetPath . '/' . $rel;
            if (!file_exists($src)) { $satLog[] = '⚠ source manquante: ' . $rel; continue; }
            $dstDir = dirname($dst);
            if (!is_dir($dstDir)) mkdir($dstDir, 0755, true);
            $satLog[] = copy($src, $dst) ? '✓ ' . $rel : '✗ échec: ' . $rel;
        }
        // page-content.json — copier seulement si absent (préserver personnalisation)
        $pcSrc = __DIR__ . '/pages/page-content.json';
        $pcDst = $targetPath . '/pages/page-content.json';
        if (file_exists($pcSrc) && !file_exists($pcDst)) {
            copy($pcSrc, $pcDst);
            $satLog[] = '✓ pages/page-content.json (nouveau)';
        } elseif (file_exists($pcDst)) {
            $satLog[] = '– pages/page-content.json conservé (déjà présent)';
        }
        return $satLog;
    };

    $log = [];

    // Cas 1 : chemin custom fourni (main site ou autre)
    $customPath = trim($input['target_path'] ?? $_GET['target_path'] ?? '');
    if ($customPath !== '') {
        // Accepter chemin absolu ou relatif depuis __DIR__/../
        $resolved = realpath($customPath) ?: realpath(__DIR__ . '/../' . $customPath);
        if (!$resolved || !is_dir($resolved)) {
            echo json_encode(['success' => false, 'message' => 'Dossier introuvable: ' . $customPath]);
            exit;
        }
        $log[] = ['sat' => basename($resolved), 'status' => 'ok', 'files' => $syncToPath($resolved)];
        echo json_encode(['success' => true, 'log' => $log]);
        exit;
    }

    // Cas 2 : sync vers tous les satellites enregistrés
    $satellites = SATELLITE_PROJECTS;
    if (empty($satellites)) {
        echo json_encode(['success' => true, 'message' => 'Aucun satellite configuré.', 'log' => []]);
        exit;
    }
    foreach ($satellites as $sat) {
        $satPath = realpath(__DIR__ . '/' . $sat['path']);
        if (!$satPath || !is_dir($satPath)) {
            $log[] = ['sat' => $sat['path'], 'status' => 'error', 'msg' => 'Dossier introuvable'];
            continue;
        }
        $log[] = ['sat' => basename($satPath), 'status' => 'ok', 'files' => $syncToPath($satPath)];
    }

    echo json_encode(['success' => true, 'log' => $log]);
    exit;
}

// ── Regenerate all post HTML pages ───────────────────────────────────────────
if ($action === 'generate_pages') {
    require_once __DIR__ . '/config.php';
    if (!defined('POST_HTML_FUNCTIONS_ONLY')) define('POST_HTML_FUNCTIONS_ONLY', true);
    require_once __DIR__ . '/generate-single-post.php'; // classe PostHTMLGenerator (router neutralisé)

    $postsDir = __DIR__ . '/posts';
    $count    = 0;
    $lines    = [];
    foreach (glob($postsDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $slug     = basename($dir);
        $jsonPath = $dir . '/post.json';
        if (!file_exists($jsonPath)) continue;
        try {
            if ((new PostHTMLGenerator($jsonPath))->saveFile($dir . '/index.html')) {
                $count++; $lines[] = "✓ $slug";
            } else {
                $lines[] = "✗ $slug";
            }
        } catch (Throwable $e) {
            $lines[] = "✗ $slug — " . $e->getMessage();
        }
    }
    echo json_encode(['success' => true, 'count' => $count, 'output' => implode("\n", $lines)]);
    exit;
}

// ── Get Pinterest boards master list ─────────────────────────────────────────
if ($action === 'get_boards') {
    $boardsFile = __DIR__ . '/pinterest-boards.json';
    $data       = file_exists($boardsFile) ? (json_decode(file_get_contents($boardsFile), true) ?: []) : [];
    $boards     = $data['boards'] ?? ['classic' => [], 'header' => [], 'cinematic' => []];

    // Compute usage counts from all post.json files
    $postsDir = __DIR__ . '/posts';
    $usage    = ['classic' => [], 'header' => [], 'cinematic' => []];
    foreach (glob($postsDir . '/*/post.json') ?: [] as $postFile) {
        $post = json_decode(file_get_contents($postFile), true);
        if (!$post) continue;
        $pb = $post['pinterest_boards'] ?? [];
        foreach (['classic', 'header', 'cinematic'] as $key) {
            $b = trim($pb[$key] ?? '');
            if ($b === '') continue;
            $lower = strtolower($b);
            $usage[$key][$lower] = ($usage[$key][$lower] ?? 0) + 1;
        }
    }

    echo json_encode([
        'success'    => true,
        'boards'     => $boards,
        'updated_at' => $data['updated_at'] ?? null,
        'usage'      => $usage,
    ]);
    exit;
}

// ── Save Pinterest boards master list ────────────────────────────────────────
if ($action === 'save_boards') {
    if (!auth_is_admin()) {
        echo json_encode(['success' => false, 'message' => 'Accès refusé']);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['boards']) || !is_array($input['boards'])) {
        echo json_encode(['success' => false, 'message' => 'Format invalide — boards requis']);
        exit;
    }
    $ok = savePinterestBoards($input['boards']);
    if ($ok) {
        $saved = json_decode(file_get_contents(__DIR__ . '/pinterest-boards.json'), true);
        echo json_encode(['success' => true, 'boards' => $saved['boards'], 'updated_at' => $saved['updated_at']]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Erreur écriture pinterest-boards.json']);
    }
    exit;
}

// ── Rebuild Pinterest boards from all existing post.json ─────────────────────
if ($action === 'rebuild_boards_from_posts') {
    if (!auth_is_admin()) {
        echo json_encode(['success' => false, 'message' => 'Accès refusé']);
        exit;
    }
    $postsDir  = __DIR__ . '/posts';
    $collected = ['classic' => [], 'header' => [], 'cinematic' => []];
    $postCount = 0;
    foreach (glob($postsDir . '/*/post.json') ?: [] as $postFile) {
        $post = json_decode(file_get_contents($postFile), true);
        if (!$post) continue;
        $postCount++;
        $pb = $post['pinterest_boards'] ?? [];
        // Fallback: posts that only have board_name (legacy) → put in all 3 slots
        if (empty($pb['classic']) && empty($pb['header']) && empty($pb['cinematic'])) {
            $fallback = trim($post['board_name'] ?? '');
            if ($fallback !== '') {
                $pb = ['classic' => $fallback, 'header' => $fallback, 'cinematic' => $fallback];
            }
        }
        foreach (['classic', 'header', 'cinematic'] as $key) {
            $b = trim($pb[$key] ?? '');
            if ($b === '') continue;
            $lower    = strtolower($b);
            $existing = array_map('strtolower', $collected[$key]);
            if (!in_array($lower, $existing, true)) {
                $collected[$key][] = $b;
            }
        }
    }
    $ok = savePinterestBoards($collected);
    if ($ok) {
        $saved = json_decode(file_get_contents(__DIR__ . '/pinterest-boards.json'), true);
        $counts = [
            'classic'   => count($saved['boards']['classic']),
            'header'    => count($saved['boards']['header']),
            'cinematic' => count($saved['boards']['cinematic']),
        ];
        echo json_encode([
            'success'       => true,
            'posts_scanned' => $postCount,
            'boards'        => $saved['boards'],
            'counts'        => $counts,
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Erreur écriture pinterest-boards.json']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Action inconnue']);

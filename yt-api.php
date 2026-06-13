<?php
/**
 * yt-api.php — API endpoint pour YouTube (utilisé par index-facebook-tools.php)
 * Actions: post_single, run_auto, check_credentials
 */
require_once __DIR__ . '/auth.php';
auth_check();
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
set_time_limit(300);

$data   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $data['action'] ?? $_GET['action'] ?? '';
$slug   = trim($data['slug'] ?? $_GET['slug'] ?? '');

$clientId     = defined('YOUTUBE_CLIENT_ID')     ? YOUTUBE_CLIENT_ID     : '';
$clientSecret = defined('YOUTUBE_CLIENT_SECRET') ? YOUTUBE_CLIENT_SECRET : '';
$refreshToken = defined('YOUTUBE_REFRESH_TOKEN') ? YOUTUBE_REFRESH_TOKEN : '';
$siteUrl      = 'https://' . (defined('HOST_NAME') ? HOST_NAME : '');
$ctaText      = defined('YOUTUBE_CTA_TEXT')       ? YOUTUBE_CTA_TEXT       : 'Get the full recipe at';
$ytHashtags   = defined('YOUTUBE_HASHTAGS')       ? YOUTUBE_HASHTAGS       : '#Shorts #recipes #food';
$titleSuffix  = defined('YOUTUBE_TITLE_SUFFIX')   ? YOUTUBE_TITLE_SUFFIX   : '#Shorts';
$categoryId   = defined('YOUTUBE_CATEGORY_ID')    ? YOUTUBE_CATEGORY_ID    : '26';
$privacy      = defined('YOUTUBE_PRIVACY_STATUS') ? YOUTUBE_PRIVACY_STATUS : 'public';
$postedFile   = __DIR__ . '/yt_posted_log.json';

// ── Helpers ───────────────────────────────────────────────────────────────────
function yt_api_get_access_token($clientId, $clientSecret, $refreshToken) {
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $res  = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res, true);
    if (empty($data['access_token'])) {
        error_log('[yt-api] Token error: ' . $res);
    }
    return $data['access_token'] ?? null;
}

function yt_api_get_token_error($clientId, $clientSecret, $refreshToken) {
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

function yt_api_upload($videoPath, $title, $description, $tags, $categoryId, $privacy, $accessToken) {
    $fileSize = filesize($videoPath);
    $metadata = json_encode([
        'snippet' => [
            'title'       => $title,
            'description' => $description,
            'tags'        => $tags,
            'categoryId'  => $categoryId,
        ],
        'status' => [
            'privacyStatus'           => $privacy,
            'selfDeclaredMadeForKids' => false,
        ],
    ]);

    // Initier l'upload résumable
    $ch = curl_init('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $metadata,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json; charset=UTF-8',
            'X-Upload-Content-Type: video/mp4',
            'X-Upload-Content-Length: ' . $fileSize,
        ],
        CURLOPT_TIMEOUT => 60,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return ['ok' => false, 'error' => 'Initiation HTTP ' . $httpCode . ': ' . substr($response, 0, 200)];
    }

    $uploadUrl = null;
    foreach (explode("\r\n", $response) as $line) {
        if (stripos($line, 'Location:') === 0) {
            $uploadUrl = trim(substr($line, 9));
            break;
        }
    }
    if (!$uploadUrl) return ['ok' => false, 'error' => 'Upload URL introuvable'];

    $fp = fopen($videoPath, 'rb');
    if (!$fp) return ['ok' => false, 'error' => 'Impossible d\'ouvrir la vidéo'];

    $ch = curl_init($uploadUrl);
    curl_setopt_array($ch, [
        CURLOPT_PUT            => true,
        CURLOPT_INFILE         => $fp,
        CURLOPT_INFILESIZE     => $fileSize,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: video/mp4',
            'Content-Length: ' . $fileSize,
        ],
        CURLOPT_TIMEOUT => 600,
    ]);
    $uploadResponse = curl_exec($ch);
    $uploadCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    $result = json_decode($uploadResponse, true);
    if (in_array($uploadCode, [200, 201]) && !empty($result['id'])) {
        return ['ok' => true, 'video_id' => $result['id']];
    }
    return ['ok' => false, 'error' => $result['error']['message'] ?? ('HTTP ' . $uploadCode)];
}

function yt_api_build_title($post, $titleSuffix) {
    $base = $post['title'] ?? 'Easy Recipe';
    return mb_substr(trim($base . ' ' . $titleSuffix), 0, 100);
}

function yt_api_build_description($post, $slug, $siteUrl, $ctaText, $ytHashtags) {
    $title        = $post['title']       ?? $slug;
    $description  = $post['description'] ?? '';
    $ingredients  = $post['ingredients'] ?? [];
    $postHashtags = $post['hashtags']    ?? '';
    $recipeUrl    = rtrim($siteUrl, '/') . '/posts/' . $slug . '/';

    $ingShort   = array_slice($ingredients, 0, 5);
    $ingSnippet = !empty($ingShort) ? implode(' · ', array_map('trim', $ingShort)) : '';

    $desc = $title . "\n\n";
    if ($description)  $desc .= $description . "\n\n";
    if ($ingSnippet)   $desc .= "🛒 Ingredients: " . $ingSnippet . "\n\n";
    $desc .= $ctaText . ': ' . $recipeUrl . "\n\n";
    $allTags = trim($postHashtags . ' ' . $ytHashtags);
    if ($allTags) $desc .= $allTags;
    return trim($desc);
}

function yt_api_build_tags($post) {
    $tags = [];
    foreach (explode(' ', $post['hashtags'] ?? '') as $tag) {
        $tag = trim($tag, "#\t\n\r ");
        if ($tag) $tags[] = $tag;
    }
    foreach ($post['seo']['secondary_keywords'] ?? [] as $kw) {
        $kw = trim($kw);
        if ($kw) $tags[] = $kw;
    }
    return array_unique(array_slice($tags, 0, 500));
}

function yt_api_load_log($file) {
    return file_exists($file) ? (json_decode(file_get_contents($file), true) ?? []) : [];
}

function yt_api_save_log($file, $log) {
    file_put_contents($file, json_encode($log, JSON_PRETTY_PRINT));
}

function yt_api_already_posted($slug, $file) {
    foreach (yt_api_load_log($file) as $e) {
        if ($e['slug'] === $slug) return true;
    }
    return false;
}

// ── Router ────────────────────────────────────────────────────────────────────
switch ($action) {

    // ── Vérifier les credentials ──────────────────────────────────────────────
    case 'check_credentials':
        $ok = $clientId && $clientSecret && $refreshToken;
        if (!$ok) { echo json_encode(['ok' => false, 'error' => 'Credentials manquants']); exit; }
        $result = yt_api_get_token_error($clientId, $clientSecret, $refreshToken);
        if (!empty($result['access_token'])) {
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'unknown', 'detail' => $result['error_description'] ?? '']);
        }
        exit;

    // ── Uploader une seule vidéo ──────────────────────────────────────────────
    case 'post_single':
        if (!$slug) { echo json_encode(['ok' => false, 'error' => 'slug manquant']); exit; }
        if (!$clientId || !$clientSecret || !$refreshToken) {
            echo json_encode(['ok' => false, 'error' => 'Credentials YouTube non configurés — lance php yt-auth.php']); exit;
        }

        $videoPath = __DIR__ . '/posts/' . $slug . '/images/' . $slug . '_reel.mp4';
        if (!file_exists($videoPath)) {
            echo json_encode(['ok' => false, 'error' => 'Vidéo absente : ' . $slug . '_reel.mp4']); exit;
        }

        $postFile = __DIR__ . '/posts/' . $slug . '/post.json';
        $post     = file_exists($postFile) ? (json_decode(file_get_contents($postFile), true) ?? []) : [];

        $accessToken = yt_api_get_access_token($clientId, $clientSecret, $refreshToken);
        if (!$accessToken) { echo json_encode(['ok' => false, 'error' => 'Impossible d\'obtenir l\'access token']); exit; }

        $title  = yt_api_build_title($post, $titleSuffix);
        $desc   = yt_api_build_description($post, $slug, $siteUrl, $ctaText, $ytHashtags);
        $tags   = yt_api_build_tags($post);

        $res = yt_api_upload($videoPath, $title, $desc, $tags, $categoryId, $privacy, $accessToken);

        if ($res['ok']) {
            $log   = yt_api_load_log($postedFile);
            $log[] = ['slug' => $slug, 'video_id' => $res['video_id'], 'posted_at' => date('Y-m-d H:i:s'), 'url' => 'https://youtu.be/' . $res['video_id']];
            yt_api_save_log($postedFile, $log);
            echo json_encode(['ok' => true, 'video_id' => $res['video_id'], 'url' => 'https://youtu.be/' . $res['video_id']]);
        } else {
            echo json_encode(['ok' => false, 'error' => $res['error']]);
        }
        exit;

    // ── Lancer yt-auto-post.php en arrière-plan ───────────────────────────────
    case 'run_auto':
        if (!$clientId || !$clientSecret || !$refreshToken) {
            echo json_encode(['ok' => false, 'error' => 'Credentials YouTube non configurés']); exit;
        }
        $phpExe = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'C:/xampp/php/php.exe';
        $script = __DIR__ . '/yt-auto-post.php';
        $logFile = __DIR__ . '/yt_auto_post.log';
        $cmd = '"' . $phpExe . '" "' . $script . '" >> "' . $logFile . '" 2>&1';
        pclose(popen('start /B ' . $cmd, 'r'));
        echo json_encode(['ok' => true, 'message' => 'yt-auto-post.php lancé en arrière-plan. Consulte yt_auto_post.log']);
        exit;

    default:
        echo json_encode(['ok' => false, 'error' => 'Action inconnue']);
        exit;
}

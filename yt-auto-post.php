<?php
/**
 * yt-auto-post.php — Auto-posting YouTube Shorts/Reels
 * Usage CLI : php yt-auto-post.php
 * Réutilise les vidéos _reel.mp4 générées pour Facebook et les uploade sur YouTube.
 *
 * Setup requis (une seule fois) : php yt-auth.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);

define('CLI_RUN', true);
require_once __DIR__ . '/config.php';

// ── Config ────────────────────────────────────────────────────────────────────
$clientId     = defined('YOUTUBE_CLIENT_ID')       ? YOUTUBE_CLIENT_ID       : '';
$clientSecret = defined('YOUTUBE_CLIENT_SECRET')   ? YOUTUBE_CLIENT_SECRET   : '';
$refreshToken = defined('YOUTUBE_REFRESH_TOKEN')   ? YOUTUBE_REFRESH_TOKEN   : '';
$channelId    = defined('YOUTUBE_CHANNEL_ID')      ? YOUTUBE_CHANNEL_ID      : '';
$hourStart    = defined('YOUTUBE_POST_HOUR_START') ? (int)YOUTUBE_POST_HOUR_START : 10;
$hourEnd      = defined('YOUTUBE_POST_HOUR_END')   ? (int)YOUTUBE_POST_HOUR_END   : 20;
$dailyCount   = defined('YOUTUBE_DAILY_COUNT')     ? (int)YOUTUBE_DAILY_COUNT     : 3;
$minGapMin    = defined('YOUTUBE_MIN_GAP_MINUTES') ? (int)YOUTUBE_MIN_GAP_MINUTES : 60;
$privacy      = defined('YOUTUBE_PRIVACY_STATUS')  ? YOUTUBE_PRIVACY_STATUS  : 'public';
$categoryId   = defined('YOUTUBE_CATEGORY_ID')     ? YOUTUBE_CATEGORY_ID     : '26';
$ctaText      = defined('YOUTUBE_CTA_TEXT')        ? YOUTUBE_CTA_TEXT        : 'Full recipe at';
$ytHashtags   = defined('YOUTUBE_HASHTAGS')        ? YOUTUBE_HASHTAGS        : '#recipes #food #easyrecipes';
$titleSuffix  = defined('YOUTUBE_TITLE_SUFFIX')    ? YOUTUBE_TITLE_SUFFIX    : '| Easy Recipe';
$siteUrl      = 'https://' . (defined('HOST_NAME') ? HOST_NAME : '');

$logFile    = __DIR__ . '/yt_auto_post.log';
$postedFile = __DIR__ . '/yt_posted_log.json';

if (!$clientId || !$clientSecret || !$refreshToken) {
    yt_log("❌ YOUTUBE_CLIENT_ID, YOUTUBE_CLIENT_SECRET ou YOUTUBE_REFRESH_TOKEN manquant.");
    yt_log("   Lance d'abord: php yt-auth.php");
    exit(1);
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function yt_log($msg) {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . "\n";
    file_put_contents($logFile, $line . "\n", FILE_APPEND);
}

function yt_load_posted_log() {
    global $postedFile;
    return file_exists($postedFile) ? (json_decode(file_get_contents($postedFile), true) ?? []) : [];
}

function yt_save_posted_log($log) {
    global $postedFile;
    file_put_contents($postedFile, json_encode($log, JSON_PRETTY_PRINT));
}

function yt_already_posted_today($slug) {
    $log   = yt_load_posted_log();
    $today = date('Y-m-d');
    foreach ($log as $entry) {
        if ($entry['slug'] === $slug && substr($entry['posted_at'], 0, 10) === $today) return true;
    }
    return false;
}

function yt_mark_posted($slug, $videoId) {
    $log   = yt_load_posted_log();
    $log[] = [
        'slug'      => $slug,
        'video_id'  => $videoId,
        'posted_at' => date('Y-m-d H:i:s'),
        'url'       => 'https://www.youtube.com/watch?v=' . $videoId,
    ];
    yt_save_posted_log($log);
}

/**
 * Génère N timestamps aléatoires dans le range horaire (même logique que fb-auto-post).
 */
function yt_generate_schedule_times($count, $hourStart, $hourEnd, $minGapSec) {
    $now          = time();
    $todayMidnight = mktime(0, 0, 0);
    $tsStart      = $todayMidnight + $hourStart * 3600;

    if ($hourEnd <= $hourStart) {
        $tsEnd = $todayMidnight + 86400 + $hourEnd * 3600;
    } else {
        $tsEnd = $todayMidnight + $hourEnd * 3600;
    }

    if ($now > $tsEnd) {
        $tsStart += 86400;
        $tsEnd   += 86400;
    }
    if ($now > $tsStart) {
        $tsStart = $now + 900;
    }

    $range = $tsEnd - $tsStart;
    if ($range < $count * $minGapSec) {
        $times = [];
        for ($i = 0; $i < $count; $i++) $times[] = $tsStart + $i * $minGapSec;
        return $times;
    }

    $candidates = [];
    $attempts   = 0;
    while (count($candidates) < $count && $attempts < 1000) {
        $attempts++;
        $ts = $tsStart + rand(0, $range);
        $ok = true;
        foreach ($candidates as $c) {
            if (abs($c - $ts) < $minGapSec) { $ok = false; break; }
        }
        if ($ok) $candidates[] = $ts;
    }
    sort($candidates);
    return $candidates;
}

/**
 * Obtenir un access_token frais via le refresh_token.
 */
function yt_get_access_token($clientId, $clientSecret, $refreshToken) {
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
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    if (!empty($data['access_token'])) return $data['access_token'];

    yt_log("  ❌ Impossible d'obtenir access_token: " . ($data['error_description'] ?? $response));
    return null;
}

/**
 * Construire le titre YouTube (max 100 chars).
 */
function yt_build_title($post, $titleSuffix) {
    $base  = $post['title'] ?? 'Easy Recipe';
    $full  = trim($base . ' ' . $titleSuffix);
    return mb_substr($full, 0, 100);
}

/**
 * Construire la description YouTube.
 */
function yt_build_description($post, $slug, $siteUrl, $ctaText, $ytHashtags) {
    $title       = $post['title']       ?? $slug;
    $description = $post['description'] ?? '';
    $ingredients = $post['ingredients'] ?? [];
    $postHashtags = $post['hashtags']   ?? '';
    $recipeUrl   = rtrim($siteUrl, '/') . '/posts/' . $slug . '/';

    $ingShort   = array_slice($ingredients, 0, 5);
    $ingSnippet = !empty($ingShort) ? implode(' · ', array_map('trim', $ingShort)) : '';

    $desc = $title . "\n\n";
    if ($description) $desc .= $description . "\n\n";
    if ($ingSnippet)  $desc .= "🛒 Ingredients: " . $ingSnippet . "\n\n";
    $desc .= $ctaText . ': ' . $recipeUrl . "\n\n";

    $allTags = trim($postHashtags . ' ' . $ytHashtags);
    if ($allTags) $desc .= $allTags;

    return trim($desc);
}

/**
 * Extraire les tags depuis les hashtags et les seo.secondary_keywords.
 */
function yt_build_tags($post) {
    $tags = [];

    // Depuis post.hashtags
    $hashtags = $post['hashtags'] ?? '';
    foreach (explode(' ', $hashtags) as $tag) {
        $tag = trim($tag, "#\t\n\r ");
        if ($tag) $tags[] = $tag;
    }

    // Depuis seo.secondary_keywords
    $secondaryKeywords = $post['seo']['secondary_keywords'] ?? [];
    foreach ($secondaryKeywords as $kw) {
        $kw = trim($kw);
        if ($kw) $tags[] = $kw;
    }

    return array_unique(array_slice($tags, 0, 500)); // YouTube max 500 tags
}

/**
 * Upload la vidéo sur YouTube via Resumable Upload.
 * Retourne ['ok' => true, 'video_id' => '...'] ou ['ok' => false, 'error' => '...']
 */
function yt_upload_video($videoPath, $title, $description, $tags, $categoryId, $privacy, $accessToken) {
    if (!file_exists($videoPath)) {
        return ['ok' => false, 'error' => 'Fichier vidéo absent: ' . $videoPath];
    }

    $fileSize = filesize($videoPath);

    // Métadonnées vidéo
    $metadata = json_encode([
        'snippet' => [
            'title'      => $title,
            'description'=> $description,
            'tags'       => $tags,
            'categoryId' => $categoryId,
        ],
        'status' => [
            'privacyStatus'          => $privacy,
            'selfDeclaredMadeForKids'=> false,
        ],
    ]);

    // Étape 1 : Initier l'upload résumable
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
        CURLOPT_TIMEOUT        => 60,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return ['ok' => false, 'error' => 'Initiation upload échouée HTTP ' . $httpCode . ': ' . substr($response, 0, 300)];
    }

    // Extraire l'URL d'upload depuis les headers
    $uploadUrl = null;
    foreach (explode("\r\n", $response) as $line) {
        if (stripos($line, 'Location:') === 0) {
            $uploadUrl = trim(substr($line, 9));
            break;
        }
    }
    if (!$uploadUrl) {
        return ['ok' => false, 'error' => 'Upload URL introuvable dans la réponse'];
    }

    // Étape 2 : Uploader le fichier vidéo
    $fp = fopen($videoPath, 'rb');
    if (!$fp) return ['ok' => false, 'error' => 'Impossible d\'ouvrir le fichier vidéo'];

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
        CURLOPT_TIMEOUT        => 600, // 10 min pour gros fichiers
    ]);
    $uploadResponse = curl_exec($ch);
    $uploadCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    $result = json_decode($uploadResponse, true);
    if (in_array($uploadCode, [200, 201]) && !empty($result['id'])) {
        return ['ok' => true, 'video_id' => $result['id']];
    }

    $errMsg = $result['error']['message'] ?? ('HTTP ' . $uploadCode . ': ' . substr($uploadResponse, 0, 300));
    return ['ok' => false, 'error' => $errMsg];
}

// ── Main ──────────────────────────────────────────────────────────────────────
yt_log("=== yt-auto-post démarré ===");
yt_log("Range horaire : {$hourStart}h → {$hourEnd}h | Posts/jour : {$dailyCount} | Gap min : {$minGapMin} min");

// 1. Charger la liste des posts
$indexFile = __DIR__ . '/posts/index.json';
$indexData = file_exists($indexFile) ? json_decode(file_get_contents($indexFile), true) : [];
$slugs     = $indexData['folders'] ?? [];

// 2. Filtrer : online + vidéo disponible + pas encore posté aujourd'hui sur YT
$eligible = [];
foreach ($slugs as $slug) {
    if (strpos($slug, 'BCP') === 0) continue;

    $postFile  = __DIR__ . '/posts/' . $slug . '/post.json';
    $videoFile = __DIR__ . '/posts/' . $slug . '/images/' . $slug . '_reel.mp4';

    if (!file_exists($postFile) || !file_exists($videoFile)) continue;

    $post = json_decode(file_get_contents($postFile), true);
    if (empty($post['isOnline'])) continue;
    if (yt_already_posted_today($slug)) continue;

    $eligible[] = ['slug' => $slug, 'post' => $post];
}

yt_log("Articles éligibles : " . count($eligible));

if (empty($eligible)) {
    yt_log("Rien à poster aujourd'hui sur YouTube.");
    exit(0);
}

// 3. Mélanger et prendre N articles
shuffle($eligible);
$toPost = array_slice($eligible, 0, $dailyCount);
yt_log("Articles sélectionnés (" . count($toPost) . ") : " . implode(', ', array_column($toPost, 'slug')));

// 4. Obtenir l'access token (une fois pour tous)
yt_log("Obtention de l'access token...");
$accessToken = yt_get_access_token($clientId, $clientSecret, $refreshToken);
if (!$accessToken) {
    yt_log("❌ Impossible d'obtenir l'access token. Vérifier les credentials.");
    exit(1);
}
yt_log("✅ Access token OK");

// 5. Pour chaque article : uploader
$minGapSec      = $minGapMin * 60;
$scheduledTimes = yt_generate_schedule_times(count($toPost), $hourStart, $hourEnd, $minGapSec);

foreach ($toPost as $i => $item) {
    $slug        = $item['slug'];
    $post        = $item['post'];
    $scheduledTs = $scheduledTimes[$i] ?? time();

    yt_log("--- [{$slug}] prévu vers " . date('H:i', $scheduledTs) . " ---");

    $videoFile  = __DIR__ . '/posts/' . $slug . '/images/' . $slug . '_reel.mp4';
    $title      = yt_build_title($post, $titleSuffix);
    $descText   = yt_build_description($post, $slug, $siteUrl, $ctaText, $ytHashtags);
    $tags       = yt_build_tags($post);

    yt_log("  Titre : $title");
    yt_log("  Tags  : " . implode(', ', array_slice($tags, 0, 5)) . (count($tags) > 5 ? '...' : ''));

    // Rafraîchir l'access token avant chaque upload (expire après 1h)
    $accessToken = yt_get_access_token($clientId, $clientSecret, $refreshToken);
    if (!$accessToken) {
        yt_log("  ❌ Impossible de rafraîchir le token — on passe");
        continue;
    }

    $res = yt_upload_video($videoFile, $title, $descText, $tags, $categoryId, $privacy, $accessToken);

    if ($res['ok']) {
        yt_log("  ✅ Uploadé → video_id: {$res['video_id']} | https://youtu.be/{$res['video_id']}");
        yt_mark_posted($slug, $res['video_id']);
    } else {
        yt_log("  ❌ Erreur YouTube : " . ($res['error'] ?? 'inconnue'));
    }

    // Pause entre uploads
    if ($i < count($toPost) - 1) sleep(5);
}

yt_log("=== yt-auto-post terminé ===");

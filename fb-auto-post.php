<?php
/**
 * fb-auto-post.php — Auto posting Facebook
 * Usage CLI : php fb-auto-post.php
 * Lance automatiquement : génération vidéo + posting planifié pour N articles/jour
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);

define('CLI_RUN', true);
require_once __DIR__ . '/config.php';

// ── Config ────────────────────────────────────────────────────────────────────
$pageId      = defined('FACEBOOK_PAGE_ID')         ? FACEBOOK_PAGE_ID         : '';
$token       = defined('FACEBOOK_ACCESS_TOKEN')    ? FACEBOOK_ACCESS_TOKEN    : '';
$hourStart   = defined('FACEBOOK_POST_HOUR_START') ? (int)FACEBOOK_POST_HOUR_START : 16;
$hourEnd     = defined('FACEBOOK_POST_HOUR_END')   ? (int)FACEBOOK_POST_HOUR_END   : 4;
$dailyCount  = defined('FACEBOOK_DAILY_COUNT')     ? (int)FACEBOOK_DAILY_COUNT     : 5;
$ffmpeg      = defined('FACEBOOK_FFMPEG_PATH')     ? FACEBOOK_FFMPEG_PATH     : 'ffmpeg';
$siteUrl     = 'https://' . (defined('HOST_NAME') ? HOST_NAME : '');
$ctaText     = defined('FACEBOOK_CTA_TEXT')        ? FACEBOOK_CTA_TEXT        : 'Get the full recipe at';
$hashtags    = defined('FACEBOOK_HASHTAGS')        ? FACEBOOK_HASHTAGS        : '';

$postType    = defined('FACEBOOK_POST_TYPE') ? FACEBOOK_POST_TYPE : 'photo';

$logFile     = __DIR__ . '/fb_auto_post.log';
$postedFile  = __DIR__ . '/fb_posted_log.json';

if (!$pageId || !$token) {
    log_msg("❌ FACEBOOK_PAGE_ID ou FACEBOOK_ACCESS_TOKEN manquant dans config.");
    exit(1);
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function log_msg($msg) {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . "\n";
    file_put_contents($logFile, $line . "\n", FILE_APPEND);
}

function load_posted_log() {
    global $postedFile;
    return file_exists($postedFile) ? (json_decode(file_get_contents($postedFile), true) ?? []) : [];
}

function save_posted_log($log) {
    global $postedFile;
    file_put_contents($postedFile, json_encode($log, JSON_PRETTY_PRINT));
}

function already_posted_today($slug) {
    $log   = load_posted_log();
    $today = date('Y-m-d');
    foreach ($log as $entry) {
        if ($entry['slug'] === $slug && substr($entry['posted_at'], 0, 10) === $today) return true;
    }
    return false;
}

function mark_posted($slug, $videoId, $scheduledTs) {
    $log   = load_posted_log();
    $log[] = [
        'slug'         => $slug,
        'video_id'     => $videoId,
        'posted_at'    => date('Y-m-d H:i:s'),
        'scheduled_ts' => $scheduledTs,
    ];
    save_posted_log($log);
}

/**
 * Génère N timestamps aléatoires dans le range horaire.
 * Si hourEnd < hourStart → le range chevauche minuit.
 * Ex: start=16, end=4 → timestamps entre aujourd'hui 16h et demain 04h
 */
function generate_schedule_times($count, $hourStart, $hourEnd) {
    $now   = time();
    $times = [];

    // Calculer les bornes en timestamps
    $todayMidnight = mktime(0, 0, 0);
    $tsStart = $todayMidnight + $hourStart * 3600;

    if ($hourEnd <= $hourStart) {
        // range traverse minuit : fin = lendemain
        $tsEnd = $todayMidnight + 86400 + $hourEnd * 3600;
    } else {
        $tsEnd = $todayMidnight + $hourEnd * 3600;
    }

    // Si on est déjà après la fin d'aujourd'hui, on décale tout au lendemain
    if ($now > $tsEnd) {
        $tsStart += 86400;
        $tsEnd   += 86400;
    }
    // Si on est entre start et end, start = maintenant + 15 min (marge upload)
    if ($now > $tsStart) {
        $tsStart = $now + 900;
    }

    $minGap = 2400; // 40 minutes minimum entre chaque post

    $range = $tsEnd - $tsStart;
    if ($range < $count * $minGap) {
        // Pas assez de place → espace régulier
        for ($i = 0; $i < $count; $i++) {
            $times[] = $tsStart + $i * $minGap;
        }
        return $times;
    }

    // Générer $count timestamps aléatoires avec au moins 40 min d'écart
    $candidates = [];
    $attempts   = 0;
    while (count($candidates) < $count && $attempts < 1000) {
        $attempts++;
        $ts = $tsStart + rand(0, $range);
        $ok = true;
        foreach ($candidates as $c) {
            if (abs($c - $ts) < $minGap) { $ok = false; break; }
        }
        if ($ok) $candidates[] = $ts;
    }
    sort($candidates);
    return $candidates;
}

// ── Fonctions Facebook (copiées de generate-facebook-reel.php) ────────────────

function fb_hexToRgb_cli($hex) {
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
}

function fb_generate_hook_cli() {
    $hooks = [
        "Stop scrolling — this one's worth it 👀",
        "One bite and you'll never go back 😍",
        "This recipe broke the internet for a reason 🔥",
        "You need to make this tonight 🤤",
        "The easiest dinner you'll ever make 👌",
        "Crispy, juicy, and ready in minutes ⏱️",
        "Your family will ask for this every week 💯",
        "Warning: extremely addictive 🚨",
        "Save this before you forget it 📌",
        "The secret ingredient changes everything 🤫",
        "Made this once, now it's on repeat 🔄",
        "This is what weeknight dinners should look like ✨",
        "Trust the process — the result is insane 😤",
        "Nobody believes it's this simple 😅",
        "Restaurant quality, home kitchen price 💸",
        "3 ingredients, zero excuses 🙌",
        "The smell alone will bring everyone to the kitchen 👃",
        "Looks hard, tastes incredible, takes 20 min 🕐",
        "I make this every single week 🗓️",
        "Once you try this, everything else tastes bland 😬",
        "Dinner just got a serious upgrade 🚀",
        "This hit different and I can't explain why 😭",
        "Golden, crispy, perfection 🏆",
        "The recipe everyone's sharing right now 📲",
        "Your new favorite comfort food 🫶",
    ];
    return $hooks[array_rand($hooks)];
}

function api_call_local($action, $slug, $extra = []) {
    $json   = json_encode(array_merge(['action' => $action, 'slug' => $slug], $extra));
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'generate-facebook-reel.php';
    $phpExe = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'C:/xampp/php/php.exe';

    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    // JSON passé en argv[1] — plus fiable que stdin sur Windows
    $cmd  = [$phpExe, $script, $json];
    $proc = proc_open($cmd, $desc, $pipes);
    if (!is_resource($proc)) return ['ok' => false, 'error' => 'proc_open failed'];

    fclose($pipes[0]); // stdin non utilisé

    $output = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    // Log stderr si présent (aide au debug)
    if ($stderr) log_msg("    [stderr] " . trim(substr($stderr, 0, 300)));

    // Extraire le JSON (ignorer les warnings PHP avant le {)
    $jsonStart = strpos($output, '{');
    if ($jsonStart !== false) $output = substr($output, $jsonStart);

    $data = json_decode($output, true);
    if ($data === null) {
        log_msg("    [raw output] " . substr($output, 0, 300));
        return ['ok' => false, 'error' => 'bad response'];
    }
    return $data;
}

function pick_random_music() {
    $musicDir = __DIR__ . '/music/';
    if (!is_dir($musicDir)) return '';
    $allowed = ['mp3', 'm4a', 'aac', 'wav', 'ogg'];
    $files   = [];
    foreach (glob($musicDir . '*') as $f) {
        if (in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $allowed)) {
            $files[] = basename($f);
        }
    }
    if (empty($files)) return '';
    return $files[array_rand($files)];
}

function generate_frames_and_video($slug) {
    // Étape 1 : frames
    log_msg("    → Génération frames...");
    $r1 = api_call_local('generate_frames', $slug);
    if (empty($r1['ok'])) {
        log_msg("    ❌ Frames échouées : " . ($r1['error'] ?? 'erreur inconnue'));
        return false;
    }
    log_msg("    ✅ Frames OK");

    // Étape 2 : choisir une musique aléatoire
    $music = pick_random_music();
    log_msg("    → Génération vidéo FFmpeg" . ($music ? " + musique : $music" : " (sans musique)") . "...");

    // Étape 3 : vidéo FFmpeg
    $r2 = api_call_local('generate_video', $slug, ['music' => $music]);
    if (empty($r2['ok'])) {
        log_msg("    ❌ Vidéo échouée : " . ($r2['error'] ?? $r2['log'] ?? 'erreur inconnue'));
        return false;
    }
    log_msg("    ✅ Vidéo OK (" . ($r2['size_kb'] ?? '?') . " KB) — musique : " . ($music ?: 'aucune'));
    return true;
}

function build_fb_message($post, $slug, $siteUrl, $ctaText, $hashtags) {
    $title       = $post['title']       ?? $slug;
    $description = trim($post['description'] ?? '');

    $message = $title;
    if ($description) $message .= "\n\n" . $description;
    if ($hashtags) $message .= "\n\n" . $hashtags;
    $message .= "\n\n👇 " . $ctaText . " (link in first comment)";
    return $message;
}

function _fb_post_comment_auto(string $postId, string $text, string $token): ?string {
    $ch = curl_init('https://graph.facebook.com/v19.0/' . $postId . '/comments');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_POSTFIELDS     => ['message' => $text, 'access_token' => $token],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    return $data['id'] ?? null;
}

function _fb_pin_comment_auto(string $commentId, string $token): bool {
    $ch = curl_init('https://graph.facebook.com/v19.0/' . $commentId);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_POSTFIELDS     => http_build_query(['is_pinned' => 'true', 'access_token' => $token]),
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    return !empty($data['success']);
}

function get_principal_image($post, $slug) {
    // Try images array first (type=main or order=1)
    foreach ($post['images'] ?? [] as $img) {
        if (($img['type'] ?? '') === 'main' || ($img['order'] ?? 0) === 1) {
            $path = __DIR__ . '/posts/' . $slug . '/images/' . ($img['fileName'] ?? '');
            if (file_exists($path)) return $path;
        }
    }
    // Fallback: image_path from root
    if (!empty($post['image_path'])) {
        $path = __DIR__ . '/' . ltrim($post['image_path'], '/');
        if (file_exists($path)) return $path;
    }
    // Fallback: first image in array
    foreach ($post['images'] ?? [] as $img) {
        $path = __DIR__ . '/posts/' . $slug . '/images/' . ($img['fileName'] ?? '');
        if (file_exists($path)) return $path;
    }
    return '';
}

function post_photo_to_fb($slug, $scheduledTs, $pageId, $token, $siteUrl, $ctaText, $hashtags) {
    $postJson = __DIR__ . '/posts/' . $slug . '/post.json';
    $post     = file_exists($postJson) ? (json_decode(file_get_contents($postJson), true) ?? []) : [];

    $imagePath = get_principal_image($post, $slug);
    if (!$imagePath) return ['ok' => false, 'error' => 'Image principale absente'];

    $message = build_fb_message($post, $slug, $siteUrl, $ctaText, $hashtags);
    $mime    = mime_content_type($imagePath) ?: 'image/webp';

    // Étape 1 — Upload photo sans la publier (pas de scheduled_publish_time)
    $ch = curl_init('https://graph.facebook.com/v19.0/' . $pageId . '/photos');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_POSTFIELDS     => [
            'source'       => new CURLFile($imagePath, $mime, basename($imagePath)),
            'published'    => 'false',
            'access_token' => $token,
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response, true);
    if ($httpCode !== 200 || empty($result['id'])) {
        return ['ok' => false, 'error' => 'Upload photo: ' . ($result['error']['message'] ?? 'HTTP ' . $httpCode)];
    }
    $photoId = $result['id'];

    // Étape 2 — Créer le feed post programmé avec la photo (apparaît dans l'agenda Meta)
    $ch2 = curl_init('https://graph.facebook.com/v19.0/' . $pageId . '/feed');
    curl_setopt_array($ch2, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POSTFIELDS     => http_build_query([
            'attached_media'         => json_encode([['media_fbid' => $photoId]]),
            'message'                => $message,
            'published'              => 'false',
            'scheduled_publish_time' => (string)$scheduledTs,
            'access_token'           => $token,
        ]),
    ]);
    $response2 = curl_exec($ch2);
    $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    $result2 = json_decode($response2, true);
    if ($httpCode2 === 200 && isset($result2['id'])) {
        $postId    = $result2['id'];
        $recipeUrl = rtrim($siteUrl, '/') . '/posts/' . $slug . '/';
        $commentId = _fb_post_comment_auto($postId, $recipeUrl, $token);
        if ($commentId) {
            log_msg("  💬 Comment URL: $commentId");
            if (_fb_pin_comment_auto($commentId, $token)) {
                log_msg("  📌 Comment épinglé");
            }
        }
        return ['ok' => true, 'video_id' => $postId];
    }
    return ['ok' => false, 'error' => 'Feed post: ' . ($result2['error']['message'] ?? 'HTTP ' . $httpCode2)];
}

function _auto_generate_reel(string $slug): bool {
    if (!defined('FB_REEL_FUNCTIONS_ONLY')) define('FB_REEL_FUNCTIONS_ONLY', true);
    require_once __DIR__ . '/generate-facebook-reel.php';

    ob_start();
    action_generate_frames($slug);
    $r1 = json_decode(ob_get_clean(), true);
    if (empty($r1['ok'])) {
        log_msg("  ⚠️  Frames generation failed: " . ($r1['error'] ?? 'unknown'));
        return false;
    }

    $dur = defined('FACEBOOK_FRAME_DURATION') ? (int)FACEBOOK_FRAME_DURATION : 4;
    ob_start();
    action_generate_video($slug, $dur, '');
    $r2 = json_decode(ob_get_clean(), true);
    if (empty($r2['ok'])) {
        log_msg("  ⚠️  Video generation failed: " . ($r2['error'] ?? 'unknown'));
        return false;
    }
    return true;
}

function post_video_to_fb($slug, $scheduledTs, $pageId, $token, $siteUrl, $ctaText, $hashtags) {
    $videoPath = __DIR__ . '/posts/' . $slug . '/images/' . $slug . '_reel.mp4';
    if (!file_exists($videoPath)) {
        log_msg("  🎬 Pas de vidéo — génération automatique du reel...");
        if (!_auto_generate_reel($slug)) {
            return ['ok' => false, 'error' => 'Génération reel échouée'];
        }
        log_msg("  ✅ Reel généré");
    }

    $postJson = __DIR__ . '/posts/' . $slug . '/post.json';
    $post     = file_exists($postJson) ? (json_decode(file_get_contents($postJson), true) ?? []) : [];

    $message = build_fb_message($post, $slug, $siteUrl, $ctaText, $hashtags);

    $postFields = [
        'access_token'           => $token,
        'description'            => $message,
        'source'                 => new CURLFile($videoPath, 'video/mp4', $slug . '_reel.mp4'),
        'published'              => 'false',
        'scheduled_publish_time' => (string)$scheduledTs,
    ];

    $ch = curl_init('https://graph-video.facebook.com/v19.0/' . $pageId . '/videos');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 180,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response, true);
    if ($httpCode === 200 && isset($result['id'])) {
        $postId    = $result['id'];
        $recipeUrl = rtrim($siteUrl, '/') . '/posts/' . $slug . '/';
        $commentId = _fb_post_comment_auto($postId, $recipeUrl, $token);
        if ($commentId) {
            log_msg("  💬 Comment URL: $commentId");
            if (_fb_pin_comment_auto($commentId, $token)) {
                log_msg("  📌 Comment épinglé");
            }
        }
        return ['ok' => true, 'video_id' => $postId];
    }
    return ['ok' => false, 'error' => $result['error']['message'] ?? ('HTTP ' . $httpCode)];
}

// ── Main ──────────────────────────────────────────────────────────────────────
log_msg("=== fb-auto-post démarré ===");
log_msg("Range horaire : {$hourStart}h → {$hourEnd}h | Posts/jour : {$dailyCount}");

// 1. Charger la liste des posts
$indexFile = __DIR__ . '/posts/index.json';
$indexData = file_exists($indexFile) ? json_decode(file_get_contents($indexFile), true) : [];
$slugs     = $indexData['folders'] ?? [];

// 2. Filtrer : online + vidéo disponible + pas encore posté aujourd'hui
$eligible = [];
foreach ($slugs as $slug) {
    if (strpos($slug, 'BCP') === 0) continue;

    $postFile  = __DIR__ . '/posts/' . $slug . '/post.json';
    $videoFile = __DIR__ . '/posts/' . $slug . '/images/' . $slug . '_reel.mp4';

    if (!file_exists($postFile)) continue;

    $post = json_decode(file_get_contents($postFile), true);
    if (empty($post['isOnline'])) continue;
    if (already_posted_today($slug)) continue;

    $eligible[] = $slug;
}

log_msg("Articles éligibles : " . count($eligible));

if (empty($eligible)) {
    log_msg("Rien à poster aujourd'hui.");
    exit(0);
}

// 3. Mélanger et prendre N articles
shuffle($eligible);
$toPost = array_slice($eligible, 0, $dailyCount);
log_msg("Articles sélectionnés (" . count($toPost) . ") : " . implode(', ', $toPost));

// 4. Générer les timestamps planifiés
$scheduledTimes = generate_schedule_times(count($toPost), $hourStart, $hourEnd);

// 5. Pour chaque article : générer vidéo si absente + poster
foreach ($toPost as $i => $slug) {
    $scheduledTs  = $scheduledTimes[$i];
    $scheduledStr = date('Y-m-d H:i:s', $scheduledTs);

    log_msg("--- [{$slug}] planifié pour {$scheduledStr} ---");

    // Poster sur Facebook
    if ($postType === 'video') {
        $videoFile = __DIR__ . '/posts/' . $slug . '/images/' . $slug . '_reel.mp4';
        if (!file_exists($videoFile)) {
            log_msg("  📹 Vidéo absente — génération en cours...");
            if (!generate_frames_and_video($slug)) {
                log_msg("  ❌ Génération échouée — on passe");
                continue;
            }
        } else {
            log_msg("  ✅ Vidéo déjà disponible");
        }
        $res = post_video_to_fb($slug, $scheduledTs, $pageId, $token, $siteUrl, $ctaText, $hashtags);
    } else {
        log_msg("  🖼️ Post photo — image principale...");
        $res = post_photo_to_fb($slug, $scheduledTs, $pageId, $token, $siteUrl, $ctaText, $hashtags);
    }

    if ($res['ok']) {
        log_msg("  ✅ Planifié → video_id: {$res['video_id']}");
        mark_posted($slug, $res['video_id'], $scheduledTs);
    } else {
        log_msg("  ❌ Erreur Facebook : " . ($res['error'] ?? 'inconnue'));
    }

    // Pause entre uploads
    sleep(5);
}

log_msg("=== fb-auto-post terminé ===");

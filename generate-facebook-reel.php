<?php
/**
 * Facebook Reels Generator
 * Actions : check | generate_frames | generate_video | download_zip
 */
ob_start(); // capture any stray PHP notices/warnings
error_reporting(0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { ob_end_clean(); exit(0); }

require_once __DIR__ . '/config.php';

// ── Helpers ──────────────────────────────────────────────────────────────────

if (!function_exists('fb_hexToRgb')) {
    function fb_hexToRgb($hex) {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
    }
}

if (!function_exists('fb_loadImage')) {
    function fb_loadImage($path) {
        if (!file_exists($path)) return false;
        $info = @getimagesize($path);
        if (!$info) return false;
        switch ($info['mime']) {
            case 'image/jpeg': return @imagecreatefromjpeg($path);
            case 'image/png':  return @imagecreatefrompng($path);
            case 'image/webp': return @imagecreatefromwebp($path);
            default:           return false;
        }
    }
}

if (!function_exists('fb_drawImageCover')) {
    function fb_drawImageCover($canvas, $img, $x, $y, $tw, $th) {
        $iw = imagesx($img); $ih = imagesy($img);
        if ($iw / $ih > $tw / $th) {
            $nh = $th; $nw = $iw * ($th / $ih); $ox = ($tw - $nw) / 2; $oy = 0;
        } else {
            $nw = $tw; $nh = $ih * ($tw / $iw); $ox = 0; $oy = ($th - $nh) / 2;
        }
        imagecopyresampled($canvas, $img, (int)($x+$ox), (int)($y+$oy), 0, 0, (int)$nw, (int)$nh, $iw, $ih);
    }
}

if (!function_exists('fb_wrapText')) {
    function fb_wrapText($text, $fontFile, $fontSize, $maxWidth) {
        $words = explode(' ', $text);
        $lines = []; $cur = '';
        foreach ($words as $word) {
            $test = $cur . ($cur ? ' ' : '') . $word;
            $bbox = imagettfbbox($fontSize, 0, $fontFile, $test);
            if (($bbox[2] - $bbox[0]) > $maxWidth && $cur !== '') {
                $lines[] = trim($cur); $cur = $word;
            } else { $cur = $test; }
        }
        if ($cur !== '') $lines[] = trim($cur);
        return $lines;
    }
}

function fb_drawOverlay($img, $w, $h, $alpha) {
    $overlay = imagecolorallocatealpha($img, 0, 0, 0, $alpha);
    imagefilledrectangle($img, 0, 0, $w, $h, $overlay);
}

function fb_drawGradient($img, $w, $startY, $height, $maxAlpha) {
    for ($i = 0; $i < $height; $i++) {
        $a = (int)(127 - ($maxAlpha * (1 - $i / $height)));
        $c = imagecolorallocatealpha($img, 0, 0, 0, $a);
        imagefilledrectangle($img, 0, $startY + $i, $w, $startY + $i, $c);
    }
}

function fb_drawCenteredText($img, $text, $fontFile, $fontSize, $color, $y, $w, $maxW) {
    $lines = fb_wrapText($text, $fontFile, $fontSize, $maxW);
    $lh = (int)($fontSize * 1.25);
    $totalH = count($lines) * $lh;
    $startY = (int)($y - $totalH / 2 + $fontSize);
    $shadow = imagecolorallocatealpha($img, 0, 0, 0, 85);
    foreach ($lines as $i => $line) {
        $bbox = imagettfbbox($fontSize, 0, $fontFile, $line);
        $lw = $bbox[2] - $bbox[0];
        $x = (int)(($w - $lw) / 2);
        $cy = $startY + $i * $lh;
        imagettftext($img, $fontSize, 0, $x + 3, $cy + 3, $shadow, $fontFile, $line);
        imagettftext($img, $fontSize, 0, $x, $cy, $color, $fontFile, $line);
    }
    return $startY + count($lines) * $lh;
}

function fb_drawLeftText($img, $text, $fontFile, $fontSize, $color, $x, $y) {
    $shadow = imagecolorallocatealpha($img, 0, 0, 0, 85);
    imagettftext($img, $fontSize, 0, $x + 2, $y + 2, $shadow, $fontFile, $text);
    imagettftext($img, $fontSize, 0, $x, $y, $color, $fontFile, $text);
}

/**
 * Resolve template font — same font as Pinterest template (TEMPLATE_CONFIG).
 * Tries: cached font file → download from Google Fonts → system Arial → any ttf.
 */
function fb_resolveFont() {
    // Read font URL from TEMPLATE_CONFIG (same source as Pinterest template)
    $fontUrl = '';
    if (defined('TEMPLATE_CONFIG')) {
        $cfg = TEMPLATE_CONFIG;
        $fontUrl = $cfg['text']['fontUrl'] ?? '';
    }
    if ($fontUrl) {
        preg_match('/family=([^&:]+)/', $fontUrl, $m);
        if ($m) {
            $name    = str_replace(['+', ' '], '', $m[1]);
            $fontDir = __DIR__ . '/fonts/';
            // Check cached versions
            foreach ([$name . '_400.ttf', $name . '_700.ttf', $name . '.ttf'] as $f) {
                if (file_exists($fontDir . $f)) return $fontDir . $f;
            }
            // Try to download
            if (!is_dir($fontDir)) mkdir($fontDir, 0777, true);
            $ctx     = stream_context_create(['http' => ['header' => 'User-Agent: Mozilla/5.0']]);
            $baseUrl = preg_replace('/family=([^&:]+).*/', 'family=$1', $fontUrl);
            $css     = @file_get_contents($baseUrl, false, $ctx);
            if ($css && preg_match('/url\((https:\/\/[^)]+\.ttf)\)/', $css, $ttfM)) {
                $fc = @file_get_contents($ttfM[1], false, $ctx);
                if ($fc) {
                    $dest = $fontDir . $name . '_400.ttf';
                    file_put_contents($dest, $fc);
                    return $dest;
                }
            }
        }
    }
    // Fallbacks
    if (file_exists('C:/Windows/Fonts/arial.ttf')) return 'C:/Windows/Fonts/arial.ttf';
    $ttfs = glob(__DIR__ . '/fonts/*.ttf');
    return $ttfs ? $ttfs[0] : false;
}

/**
 * Get template brand colors from TEMPLATE_CONFIG (same source as Pinterest template).
 */
function fb_colors($img) {
    $bannerHex = '#1a1a2e';
    $textHex   = '#ffffff';
    if (defined('TEMPLATE_CONFIG')) {
        $cfg       = TEMPLATE_CONFIG;
        $bannerHex = substr($cfg['banner']['color']  ?? '#1a1a2e', 0, 7);
        $textHex   = substr($cfg['text']['color']    ?? '#ffffff', 0, 7);
    }
    $b = fb_hexToRgb($bannerHex);
    $t = fb_hexToRgb($textHex);
    return [
        'banner'  => imagecolorallocate($img, $b[0], $b[1], $b[2]),
        'bannerA' => imagecolorallocatealpha($img, $b[0], $b[1], $b[2], 25),
        'bannerD' => imagecolorallocatealpha($img, $b[0], $b[1], $b[2], 55),
        'text'    => imagecolorallocate($img, $t[0], $t[1], $t[2]),
        'textA'   => imagecolorallocatealpha($img, $t[0], $t[1], $t[2], 50),
        'white'   => imagecolorallocate($img, 255, 255, 255),
        'whiteA'  => imagecolorallocatealpha($img, 255, 255, 255, 60),
        'shadow'  => imagecolorallocatealpha($img, 0, 0, 0, 80),
    ];
}

function fb_bannerStrip($img, $w, $y, $h, $color) {
    imagefilledrectangle($img, 0, $y, $w, $y + $h, $color);
}

function fb_decoLines($img, $w, $cy, $color) {
    imagesetthickness($img, 2);
    imageline($img, 60, $cy, 180, $cy, $color);
    imageline($img, $w - 180, $cy, $w - 60, $cy, $color);
    imagesetthickness($img, 1);
}

/**
 * Resolve all image paths from post.json images array.
 * Returns [img1, img2, img3, overlay_list_path, recipe_card_path]
 */
function fb_resolveImages($post, $slug) {
    $base   = __DIR__ . '/posts/' . $slug . '/images/';
    $images = $post['images'] ?? [];

    // Article images (non-template), sorted by order
    $articles = array_values(array_filter($images, fn($i) => ($i['type'] ?? '') === ''));
    if (empty($articles)) {
        $articles = array_values(array_filter($images, fn($i) => !in_array($i['type'] ?? '', ['template', 'recipe_card', 'overlay_list'])));
    }
    usort($articles, fn($a, $b) => ($a['order'] ?? 99) - ($b['order'] ?? 99));

    $img1 = isset($articles[0]) ? $base . $articles[0]['fileName'] : null;
    $img2 = isset($articles[1]) ? $base . $articles[1]['fileName'] : $img1;
    $img3 = isset($articles[2]) ? $base . $articles[2]['fileName'] : $img2;

    // Template images — chercher par type explicite d'abord
    $overlayList = null;
    $recipeCard  = null;
    foreach ($images as $img) {
        $t = $img['type'] ?? '';
        if ($t === 'overlay_list' && !$overlayList) $overlayList = $base . $img['fileName'];
        if ($t === 'recipe_card'  && !$recipeCard)  $recipeCard  = $base . $img['fileName'];
    }

    // Fallback : les templates génériques (type="template") — prendre les 2 derniers
    if (!$overlayList || !$recipeCard) {
        $tpls = array_values(array_filter($images, fn($i) => ($i['type'] ?? '') === 'template'));
        usort($tpls, fn($a, $b) => ($a['order'] ?? 99) - ($b['order'] ?? 99));
        if (!$recipeCard  && isset($tpls[count($tpls)-2])) $recipeCard  = $base . $tpls[count($tpls)-2]['fileName'];
        if (!$overlayList && isset($tpls[count($tpls)-1])) $overlayList = $base . $tpls[count($tpls)-1]['fileName'];
    }

    return [$img1, $img2, $img3, $overlayList, $recipeCard];
}

function fb_newCanvas($w, $h) {
    $img = imagecreatetruecolor($w, $h);
    imagesavealpha($img, true);
    imagealphablending($img, true);
    return $img;
}

function fb_saveWebp($img, $path) {
    imagewebp($img, $path, 85);
    imagedestroy($img);
}

// ── Frame generators (template-branded) ──────────────────────────────────────

/**
 * Dessine un loading spinner sur un canvas existant.
 * $rotation = angle de départ en degrés (0-360), change à chaque frame pour simuler la rotation.
 */
function fb_drawSpinner($img, $cx, $cy, $rotation) {
    $white      = imagecolorallocate($img, 255, 255, 255);
    $whiteTrack = imagecolorallocatealpha($img, 255, 255, 255, 100);
    $r = 88;
    $arcSpan = 270; // 3/4 du cercle

    // Track (cercle complet fin)
    imagesetthickness($img, 7);
    imagearc($img, $cx, $cy, $r * 2, $r * 2, 0, 360, $whiteTrack);

    // Arc actif (270°) avec rotation
    $startDeg = $rotation;
    $endDeg   = $rotation + $arcSpan;
    imagesetthickness($img, 13);
    imagearc($img, $cx, $cy, $r * 2, $r * 2, (int)$startDeg, (int)$endDeg, $white);

    // Bouts arrondis
    foreach ([$startDeg, $endDeg] as $deg) {
        $rad = deg2rad($deg);
        $px  = (int)($cx + $r * cos($rad));
        $py  = (int)($cy + $r * sin($rad));
        imagefilledellipse($img, $px, $py, 15, 15, $white);
    }
    imagesetthickness($img, 1);
}

/**
 * Génère une frame spinner avec l'image de fond + spinner à un angle donné.
 * Sauvegardée comme WebP temporaire pour FFmpeg.
 */
function fb_makeSpinnerFrame($img1Path, $fontFile, $w, $h, $rotation) {
    $img = fb_newCanvas($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 10, 10, 10));
    if ($img1Path) {
        $s = fb_loadImage($img1Path);
        if ($s) { fb_drawImageCover($img, $s, 0, 0, $w, $h); imagedestroy($s); }
    }
    // Assombrissement centre doux
    fb_drawGradient($img, $w, $h - 280, 280, 85);

    $cx = (int)($w / 2);
    $cy = (int)($h / 2);
    fb_drawSpinner($img, $cx, $cy, $rotation);

    // Site name
    $siteName = defined('HOMEPAGE_TITLE') ? HOMEPAGE_TITLE : '';
    if ($siteName && $fontFile) {
        $wA = imagecolorallocatealpha($img, 255, 255, 255, 60);
        $bbox = imagettfbbox(30, 0, $fontFile, $siteName);
        $x = (int)(($w - ($bbox[2] - $bbox[0])) / 2);
        imagettftext($img, 30, 0, $x, $h - 55, $wA, $fontFile, $siteName);
    }
    return $img;
}

// Frame 1 — utilisée comme dernière frame du spinner (rotation fixe) et premier contenu
function fb_frame1($post, $img1Path, $fontFile, $w, $h) {
    // Utilise la dernière position du spinner (sans animation — juste pour compatibilité)
    return fb_makeSpinnerFrame($img1Path, $fontFile, $w, $h, 315);
}

// Frame 2 — Template image (overlay_list ou recipe_card) affiché tel quel, portrait crop
function fb_frame_template($templatePath, $fallbackImg, $w, $h) {
    $img = fb_newCanvas($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 10, 10, 10));
    $src = $templatePath ? fb_loadImage($templatePath) : null;
    if (!$src && $fallbackImg) $src = fb_loadImage($fallbackImg);
    if ($src) { fb_drawImageCover($img, $src, 0, 0, $w, $h); imagedestroy($src); }
    return $img;
}

// Frame 3/4 — Article image propre (sans overlay ni texte) + site name discret
function fb_frame_clean($imgPath, $fontFile, $w, $h) {
    $img = fb_newCanvas($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 10, 10, 10));
    if ($imgPath) { $s = fb_loadImage($imgPath); if ($s) { fb_drawImageCover($img, $s, 0, 0, $w, $h); imagedestroy($s); } }
    // Gradient bas discret
    fb_drawGradient($img, $w, $h - 200, 200, 80);
    // Site name
    $c = fb_colors($img);
    $siteName = defined('HOMEPAGE_TITLE') ? HOMEPAGE_TITLE : '';
    if ($siteName && $fontFile) {
        $wA = imagecolorallocatealpha($img, 255, 255, 255, 55);
        $bbox = imagettfbbox(30, 0, $fontFile, $siteName);
        $x = (int)(($w - ($bbox[2] - $bbox[0])) / 2);
        imagettftext($img, 30, 0, $x, $h - 55, $wA, $fontFile, $siteName);
    }
    return $img;
}

// Frame 2 (ancien) — Ingrédients : photo + overlay + banner haut + liste
function fb_frame2($post, $img1Path, $fontFile, $w, $h) {
    $img = fb_newCanvas($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 10, 10, 10));
    if ($img1Path) { $s = fb_loadImage($img1Path); if ($s) { fb_drawImageCover($img, $s, 0, 0, $w, $h); imagedestroy($s); } }
    fb_drawOverlay($img, $w, $h, 72);

    $c = fb_colors($img);
    $labels = defined('POST_SECTION_LABELS') ? POST_SECTION_LABELS : [];
    $secTitle = $labels['ingredients'] ?? "What You'll Need";

    // Top banner
    $bh = 175;
    fb_bannerStrip($img, $w, 0, $bh, $c['banner']);
    fb_decoLines($img, $w, (int)($bh / 2), $c['textA']);
    if ($fontFile) fb_drawCenteredText($img, $secTitle, $fontFile, 58, $c['text'], (int)($bh / 2) + 22, $w, (int)($w * 0.85));

    $y = $bh + 55;
    $items = $post['ingredients'] ?? [];
    if (empty($items) && isset($post['description']))
        $items = array_slice(explode('. ', $post['description']), 0, 7);
    $items = array_slice($items, 0, 8);

    if ($fontFile) {
        foreach ($items as $item) {
            $t = '  ▸  ' . trim(is_array($item) ? ($item['name'] ?? $item[0] ?? '') : $item);
            $t = mb_substr(html_entity_decode($t, ENT_QUOTES, 'UTF-8'), 0, 55);
            fb_drawLeftText($img, $t, $fontFile, 36, $c['white'], 70, $y);
            $y += 66;
            if ($y > $h - 80) break;
        }
    }
    // Bottom deco line
    fb_bannerStrip($img, $w, $h - 8, 8, $c['banner']);
    return $img;
}

// Frame 3 — Étapes : photo2 + overlay + banner haut + steps numérotés
function fb_frame3($post, $img2Path, $fontFile, $w, $h) {
    $img = fb_newCanvas($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 10, 10, 10));
    if ($img2Path) { $s = fb_loadImage($img2Path); if ($s) { fb_drawImageCover($img, $s, 0, 0, $w, $h); imagedestroy($s); } }
    fb_drawOverlay($img, $w, $h, 72);

    $c = fb_colors($img);
    $labels = defined('POST_SECTION_LABELS') ? POST_SECTION_LABELS : [];
    $secTitle = $labels['instructions'] ?? 'How To Make It';

    $bh = 175;
    fb_bannerStrip($img, $w, 0, $bh, $c['banner']);
    fb_decoLines($img, $w, (int)($bh / 2), $c['textA']);
    if ($fontFile) fb_drawCenteredText($img, $secTitle, $fontFile, 58, $c['text'], (int)($bh / 2) + 22, $w, (int)($w * 0.85));

    $y = $bh + 60;
    $steps = $post['instructions'] ?? ($post['pro_tips'] ?? []);
    $steps = array_slice($steps, 0, 5);

    if ($fontFile) {
        foreach ($steps as $i => $step) {
            $text = is_array($step) ? ($step['instruction'] ?? $step['tip'] ?? $step['step'] ?? '') : $step;
            $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
            // Number badge
            $num = (string)($i + 1);
            $bbox = imagettfbbox(32, 0, $fontFile, $num);
            $bw = max(58, $bbox[2] - $bbox[0] + 24); $bxS = 55; $byS = $y - 42;
            imagefilledellipse($img, $bxS + (int)($bw/2), $byS + 28, 52, 52, $c['banner']);
            imagettftext($img, 32, 0, $bxS + (int)(($bw - ($bbox[2]-$bbox[0]))/2), $y - 2, $c['text'], $fontFile, $num);
            // Step text
            $lines = array_slice(fb_wrapText($text, $fontFile, 32, $w - 180), 0, 2);
            foreach ($lines as $li => $line) {
                fb_drawLeftText($img, $line, $fontFile, 32, $c['white'], 128, $y - 8 + $li * 44);
            }
            $y += 44 * count($lines) + 32;
            if ($y > $h - 80) break;
        }
    }
    fb_bannerStrip($img, $w, $h - 8, 8, $c['banner']);
    return $img;
}

// Frame 4 — Résultat : photo clean + banner bas minimal
function fb_frame4($post, $img2Path, $fontFile, $w, $h) {
    $img = fb_newCanvas($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 10, 10, 10));
    if ($img2Path) { $s = fb_loadImage($img2Path); if ($s) { fb_drawImageCover($img, $s, 0, 0, $w, $h); imagedestroy($s); } }

    $c = fb_colors($img);
    $bh = 200; $bannerY = $h - $bh;
    fb_drawGradient($img, $w, $bannerY - 150, 150, 110);
    fb_bannerStrip($img, $w, $bannerY, $bh, $c['banner']);
    fb_decoLines($img, $w, $bannerY + 50, $c['textA']);
    if ($fontFile) fb_drawCenteredText($img, 'The Result', $fontFile, 76, $c['text'], $bannerY + 128, $w, (int)($w * 0.85));
    fb_decoLines($img, $w, $bannerY + $bh - 30, $c['textA']);
    return $img;
}

// Frame 5 — CTA : photo + overlay fort + bloc central brandé
function fb_frame5($post, $slug, $img1Path, $fontFile, $w, $h) {
    $img = fb_newCanvas($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 10, 10, 10));
    if ($img1Path) { $s = fb_loadImage($img1Path); if ($s) { fb_drawImageCover($img, $s, 0, 0, $w, $h); imagedestroy($s); } }
    fb_drawOverlay($img, $w, $h, 85);

    $c = fb_colors($img);
    $ctaText  = defined('FACEBOOK_CTA_TEXT') ? FACEBOOK_CTA_TEXT : 'Get the full recipe at';
    $host     = defined('HOST_NAME') ? HOST_NAME : '';
    $hashtags = defined('FACEBOOK_HASHTAGS') ? FACEBOOK_HASHTAGS : '';
    $siteName = defined('HOMEPAGE_TITLE') ? HOMEPAGE_TITLE : '';
    $url      = $host;

    // Central banner block
    $blockY = 460; $blockH = 500;
    fb_bannerStrip($img, $w, $blockY, $blockH, $c['bannerA']);
    // Top deco bar
    fb_bannerStrip($img, $w, $blockY, 6, $c['banner']);
    fb_bannerStrip($img, $w, $blockY + $blockH - 6, 6, $c['banner']);

    if ($fontFile) {
        $cy = $blockY + 90;
        fb_drawCenteredText($img, $ctaText, $fontFile, 46, $c['white'], $cy, $w, (int)($w * 0.82));
        $cy += 110;
        fb_decoLines($img, $w, $cy, $c['textA']);
        $cy += 55;
        fb_drawCenteredText($img, $url, $fontFile, 40, $c['text'], $cy, $w, (int)($w * 0.88));
        $cy += 95;
        fb_decoLines($img, $w, $cy, $c['textA']);

        if ($hashtags) {
            $cy += 60;
            fb_drawCenteredText($img, $hashtags, $fontFile, 28, $c['whiteA'], $cy, $w, (int)($w * 0.85));
        }
        if ($siteName) {
            $bbox = imagettfbbox(30, 0, $fontFile, $siteName);
            $x = (int)(($w - ($bbox[2] - $bbox[0])) / 2);
            imagettftext($img, 30, 0, $x, $h - 55, $c['whiteA'], $fontFile, $siteName);
        }
    }
    // Bottom banner strip
    fb_bannerStrip($img, $w, $h - 12, 12, $c['banner']);
    return $img;
}

// ── Action: check ─────────────────────────────────────────────────────────────
function action_check() {
    $disabled = array_map('trim', explode(',', ini_get('disable_functions')));
    if (!function_exists('exec') || in_array('exec', $disabled)) {
        echo json_encode(['ffmpeg' => false, 'version' => null, 'reason' => 'exec() désactivé dans php.ini']);
        return;
    }
    $ffmpegPath = defined('FACEBOOK_FFMPEG_PATH') ? FACEBOOK_FFMPEG_PATH : 'ffmpeg';
    $cmd = '"' . $ffmpegPath . '" -version 2>&1';
    $out = []; $code = -1;
    exec($cmd, $out, $code);
    $available = ($code === 0);
    $version = $available ? (preg_match('/ffmpeg version ([^\s]+)/', implode(' ', $out), $m) ? $m[1] : 'ok') : null;
    echo json_encode(['ffmpeg' => $available, 'version' => $version]);
}

// ── Action: generate_frames ───────────────────────────────────────────────────
function action_generate_frames($slug) {
    echo json_encode(fb_buildFrames($slug));
}

/**
 * Génère les frames du reel (sans echo). Retourne array de résultat.
 * @return array{ok?:bool, frames?:array, slug?:string, error?:string}
 */
function fb_buildFrames($slug) {
    if (!$slug) { return ['error' => 'slug required']; }

    $postFile = __DIR__ . '/posts/' . $slug . '/post.json';
    if (!file_exists($postFile)) { return ['error' => 'post not found']; }
    $post = json_decode(file_get_contents($postFile), true);

    $fontFile = fb_resolveFont();
    if (!$fontFile) { return ['error' => 'no font file found']; }

    [$img1, $img2, $img3, $overlayList, $recipeCard] = fb_resolveImages($post, $slug);
    if (!$img2) $img2 = $img1;
    if (!$img3) $img3 = $img2;

    $imgDir = __DIR__ . '/posts/' . $slug . '/images/';
    $w = 1080; $h = 1920;

    // ── Spinner frames (8 frames, chaque 45°) ────────────────────────────────
    $nSpinner   = 8;
    $spinnerDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
    for ($s = 0; $s < $nSpinner; $s++) {
        $rotation   = ($s / $nSpinner) * 360;
        $spinFrame  = fb_makeSpinnerFrame($img1, $fontFile, $w, $h, $rotation);
        $spinPath   = $spinnerDir . 'fb_spin_' . $slug . '_' . $s . '.webp';
        fb_saveWebp($spinFrame, $spinPath);
    }

    // ── Main content frames ────────────────────────────────────────────────────
    $frames = [];
    $defs = [
        // 1 — Overlay List template (as-is)
        ['fn' => fn() => fb_frame_template($overlayList, $img1, $w, $h)],
        // 2 — Image article 2 (propre)
        ['fn' => fn() => fb_frame_clean($img2, $fontFile, $w, $h)],
        // 3 — Image article 3 (propre)
        ['fn' => fn() => fb_frame_clean($img3, $fontFile, $w, $h)],
        // 4 — Instructions (steps texte sur img2)
        ['fn' => fn() => fb_frame3($post, $img2, $fontFile, $w, $h)],
        // 5 — Recipe Card template (as-is)
        ['fn' => fn() => fb_frame_template($recipeCard, $img1, $w, $h)],
    ];

    foreach ($defs as $i => $def) {
        $frame     = ($def['fn'])();
        $framePath = $imgDir . $slug . '_fb_frame_' . ($i + 1) . '.webp';
        fb_saveWebp($frame, $framePath);
        $frames[] = 'posts/' . $slug . '/images/' . $slug . '_fb_frame_' . ($i + 1) . '.webp';
    }
    // Supprimer frame 6 si elle existe encore (ancien montage)
    @unlink($imgDir . $slug . '_fb_frame_6.webp');

    return ['ok' => true, 'frames' => $frames, 'slug' => $slug];
}

/**
 * Construit le reel complet (frames + MP4) en une passe. Pour le pipeline.
 * Skip si le MP4 existe déjà et est récent (< 30 jours).
 * @return array{ok?:bool, video?:string, skipped?:bool, error?:string}
 */
function fb_buildReelComplete($slug, $duration = null, $music = '') {
    $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));
    if (!$slug) return ['error' => 'slug required'];

    $mp4 = __DIR__ . '/posts/' . $slug . '/images/' . $slug . '_reel.mp4';
    if (file_exists($mp4) && (time() - filemtime($mp4)) < 30 * 86400) {
        return ['ok' => true, 'video' => 'posts/' . $slug . '/images/' . $slug . '_reel.mp4', 'skipped' => true];
    }

    $dur = $duration !== null ? (int)$duration
         : (defined('FACEBOOK_FRAME_DURATION') ? (int)FACEBOOK_FRAME_DURATION : 3);
    $dur = max(1, min(15, $dur));

    $framesRes = fb_buildFrames($slug);
    if (!empty($framesRes['error'])) return ['error' => 'frames: ' . $framesRes['error']];

    $res = fb_buildReel($slug, $dur, $music);

    // Cleanup : supprimer les frames intermédiaires (_fb_frame_*.webp) après le MP4.
    // On garde les 3 images source + les templates générés. Allège disque + git.
    if (!empty($res['ok'])) {
        $imgDir = __DIR__ . '/posts/' . $slug . '/images/';
        foreach (glob($imgDir . $slug . '_fb_frame_*.webp') ?: [] as $frameFile) {
            @unlink($frameFile);
        }
        // Marquer has_reel dans post.json — permet au schema VideoObject d'être généré
        // même si le MP4 est absent de git (gitignored)
        $postJsonPath = __DIR__ . '/posts/' . $slug . '/post.json';
        if (file_exists($postJsonPath)) {
            $pd = json_decode(file_get_contents($postJsonPath), true);
            if (is_array($pd)) {
                $pd['has_reel'] = true;
                file_put_contents($postJsonPath, json_encode($pd, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }
    }

    return $res;
}

// ── Action: generate_video ────────────────────────────────────────────────────
function action_generate_video($slug, $duration, $music = '') {
    echo json_encode(fb_buildReel($slug, $duration, $music));
}

/**
 * Construit le reel MP4 (1080×1920) et retourne un array de résultat (sans echo).
 * Réutilisable depuis le pipeline (auto-daily-csv.php) et le router HTTP.
 * @return array{ok?:bool, video?:string, size_kb?:int, error?:string}
 */
function fb_buildReel($slug, $duration, $music = '') {
    set_time_limit(300); // 5 minutes max pour FFmpeg
    if (!$slug) { return ['error' => 'slug required']; }

    $imgDir = __DIR__ . '/posts/' . $slug . '/images/';
    // Détecter le nombre de frames disponibles (5 ou 6)
    $nFrames = 6;
    if (!file_exists($imgDir . $slug . '_fb_frame_6.webp')) $nFrames = 5;
    for ($i = 1; $i <= $nFrames; $i++) {
        $f = $imgDir . $slug . '_fb_frame_' . $i . '.webp';
        if (!file_exists($f)) {
            return ['error' => 'frame ' . $i . ' not found — run generate_frames first'];
        }
    }

    $disabled = array_map('trim', explode(',', ini_get('disable_functions')));
    if (!function_exists('exec') || in_array('exec', $disabled)) {
        return ['error' => 'exec() désactivé dans php.ini — FFmpeg ne peut pas être utilisé.'];
    }

    $ffmpegPath = defined('FACEBOOK_FFMPEG_PATH') ? FACEBOOK_FFMPEG_PATH : 'ffmpeg';
    $chkOut = []; $chkCode = -1;
    exec('"' . $ffmpegPath . '" -version 2>&1', $chkOut, $chkCode);
    if ($chkCode !== 0) {
        return ['error' => 'FFmpeg non installé — installe FFmpeg et configure son chemin dans Config UI → Facebook Reels', 'ffmpeg_path' => $ffmpegPath];
    }

    $outPath      = $imgDir . $slug . '_reel.mp4';
    $tmpDir       = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
    $fcScriptPath = $tmpDir . 'fb_fc_' . $slug . '.txt';

    $dur      = (int)$duration;
    $fade     = 0.5;
    $nSpinner = 8;    // 1 rotation complète
    $spinDur  = 0.12; // → ~1s total
    $contentN = $nFrames;

    // ── Regénérer les spinner frames (peuvent avoir été supprimées) ───────────
    $postFile2 = __DIR__ . '/posts/' . $slug . '/post.json';
    $post2     = file_exists($postFile2) ? json_decode(file_get_contents($postFile2), true) : [];
    $fontFile2 = fb_resolveFont();
    [$sImg1]   = fb_resolveImages($post2, $slug);
    for ($s = 0; $s < $nSpinner; $s++) {
        $spinPath = $tmpDir . 'fb_spin_' . $slug . '_' . $s . '.webp';
        if (!file_exists($spinPath)) {
            $rotation  = ($s / $nSpinner) * 360;
            $spinFrame = fb_makeSpinnerFrame($sImg1, $fontFile2, 1080, 1920, $rotation);
            fb_saveWebp($spinFrame, $spinPath);
        }
    }

    // ── Inputs : spinner + content ────────────────────────────────────────────
    $inputs = '';
    for ($s = 0; $s < $nSpinner; $s++) {
        $inputs .= ' -loop 1 -t ' . $spinDur . ' -i "' . $tmpDir . 'fb_spin_' . $slug . '_' . $s . '.webp"';
    }
    for ($i = 1; $i <= $contentN; $i++) {
        $inputs .= ' -loop 1 -t ' . $dur . ' -i "' . $imgDir . $slug . '_fb_frame_' . $i . '.webp"';
    }
    $totalInputs     = $nSpinner + $contentN;
    $spinnerTotalDur = round($nSpinner * $spinDur, 3);

    // ── Filter complex ────────────────────────────────────────────────────────
    $fc = '';
    for ($i = 0; $i < $totalInputs; $i++) {
        $fc .= '[' . $i . ':v]scale=1080:1920:force_original_aspect_ratio=decrease,'
             . 'pad=1080:1920:(ow-iw)/2:(oh-ih)/2,setsar=1,fps=25,'
             . 'format=yuv420p[v' . $i . '];' . "\n";
    }

    // Spinner concat (rapide, sans xfade) + settb pour matcher timebase 1/25
    $fc .= '[v0]';
    for ($s = 1; $s < $nSpinner; $s++) $fc .= '[v' . $s . ']';
    $fc .= 'concat=n=' . $nSpinner . ':v=1:a=0,settb=expr=1/25,fps=25[vspinner];' . "\n";

    // Content frames avec xfade — offset = (spinnerDur - fade) + i*(dur - fade)
    $prev = 'vspinner';
    for ($i = 0; $i < $contentN; $i++) {
        $vi      = $nSpinner + $i;
        $offset  = round(($spinnerTotalDur - $fade) + $i * ($dur - $fade), 3);
        if ($offset < 0) $offset = 0;
        $out_tag = ($i === $contentN - 1) ? 'vout' : 'xc' . $i;
        $fc .= '[' . $prev . '][v' . $vi . ']xfade=transition=fade:duration=' . $fade . ':offset=' . $offset . '[' . $out_tag . '];' . "\n";
        $prev = $out_tag;
    }
    file_put_contents($fcScriptPath, rtrim($fc, ";\n"));

    // Audio
    $musicPath = '';
    if ($music) {
        $musicFile = __DIR__ . '/music/' . basename($music);
        if (file_exists($musicFile)) $musicPath = $musicFile;
    }
    $totalDuration = round($spinnerTotalDur + $dur * $contentN - $fade * ($contentN - 1), 2);

    $audioInput = $musicPath ? ' -stream_loop -1 -i "' . $musicPath . '"' : '';
    $audioMap   = $musicPath ? ' -map ' . $totalInputs . ':a' : '';
    $audioCodec = $musicPath ? ' -c:a aac -b:a 128k -t ' . $totalDuration : '';

    $cmd = '"' . $ffmpegPath . '"'
         . ' -y' . $inputs . $audioInput
         . ' -filter_complex_script "' . $fcScriptPath . '"'
         . ' -map "[vout]"' . $audioMap
         . ' -c:v libx264 -profile:v main -level 4.0 -pix_fmt yuv420p -preset fast -crf 23 -movflags +faststart'
         . $audioCodec
         . ' "' . $outPath . '"'
         . ' 2>&1';

    $out = []; $code = -1;
    exec($cmd, $out, $code);
    @unlink($fcScriptPath);
    // Cleanup spinner temp files
    for ($s = 0; $s < 8; $s++) @unlink($tmpDir . 'fb_spin_' . $slug . '_' . $s . '.webp');

    if ($code !== 0) {
        // Écrire le filter_complex dans le log pour debug
        $fcContent = file_exists($fcScriptPath) ? file_get_contents($fcScriptPath) : '(deleted)';
        return [
            'error'   => 'ffmpeg failed',
            'log'     => implode("\n", array_slice($out, -20)),
            'cmd'     => $cmd,
            'fc'      => $fcContent,
        ];
    }

    $sizeKb = file_exists($outPath) ? (int)(filesize($outPath) / 1024) : 0;
    return [
        'ok' => true,
        'video' => 'posts/' . $slug . '/images/' . $slug . '_reel.mp4',
        'size_kb' => $sizeKb,
    ];
}

// ── Action: download_zip ──────────────────────────────────────────────────────
function action_download_zip($slug) {
    if (!$slug) { echo json_encode(['error' => 'slug required']); return; }

    $imgDir = __DIR__ . '/posts/' . $slug . '/images/';

    if (!class_exists('ZipArchive')) {
        echo json_encode(['error' => 'ZipArchive not available']);
        return;
    }

    $zipPath = sys_get_temp_dir() . '/fb_reel_' . $slug . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        echo json_encode(['error' => 'Cannot create zip']);
        return;
    }
    $found = 0;
    for ($i = 1; $i <= 6; $i++) {
        $f = $imgDir . $slug . '_fb_frame_' . $i . '.webp';
        if (file_exists($f)) { $zip->addFile($f, 'frame_' . $i . '.webp'); $found++; }
    }
    // Also add MP4 if exists
    $mp4 = $imgDir . $slug . '_reel.mp4';
    if (file_exists($mp4)) $zip->addFile($mp4, $slug . '_reel.mp4');
    $zip->close();

    if ($found === 0) {
        echo json_encode(['error' => 'No frames found for ' . $slug]);
        return;
    }

    ob_end_clean();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $slug . '_facebook_reel.zip"');
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: no-cache');
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

// ── Action: list_music ────────────────────────────────────────────────────────
function action_list_music() {
    $musicDir = __DIR__ . '/music/';
    if (!is_dir($musicDir)) { echo json_encode(['ok' => true, 'files' => []]); return; }
    $allowed = ['mp3','m4a','aac','wav','ogg'];
    $files = [];
    foreach (glob($musicDir . '*') as $f) {
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $files[] = [
                'name'   => basename($f),
                'size_kb'=> (int)(filesize($f) / 1024),
            ];
        }
    }
    usort($files, fn($a,$b) => strcmp($a['name'], $b['name']));
    echo json_encode(['ok' => true, 'files' => $files]);
}

// ── Action: upload_music ───────────────────────────────────────────────────────
function action_upload_music() {
    $musicDir = __DIR__ . '/music/';
    if (!is_dir($musicDir)) mkdir($musicDir, 0755, true);

    if (empty($_FILES['file']['tmp_name'])) {
        echo json_encode(['error' => 'Aucun fichier reçu']); return;
    }
    $allowed = ['mp3','m4a','aac','wav','ogg'];
    $origName = $_FILES['file']['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) {
        echo json_encode(['error' => 'Format non supporté — MP3, M4A, AAC, WAV, OGG uniquement']); return;
    }
    // Sanitize name
    $safeName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $origName);
    $dest = $musicDir . $safeName;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
        echo json_encode(['error' => 'Erreur lors de l\'upload']); return;
    }
    echo json_encode(['ok' => true, 'name' => $safeName, 'size_kb' => (int)(filesize($dest)/1024)]);
}

// ── Action: delete_music ───────────────────────────────────────────────────────
function action_delete_music($name) {
    $name = basename($name); // sécurité : pas de path traversal
    $path = __DIR__ . '/music/' . $name;
    if (!file_exists($path)) { echo json_encode(['error' => 'Fichier non trouvé']); return; }
    unlink($path);
    echo json_encode(['ok' => true]);
}

// ── Helper: poster un commentaire puis l'épingler ────────────────────────────
function fb_post_and_pin_comment($videoId, $token, $message) {
    // 1. Poster le commentaire
    $chC = curl_init('https://graph.facebook.com/v19.0/' . $videoId . '/comments');
    curl_setopt_array($chC, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['access_token' => $token, 'message' => $message],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $cResult = json_decode(curl_exec($chC), true);
    curl_close($chC);

    if (!isset($cResult['id'])) {
        return ['ok' => false, 'error' => $cResult['error']['message'] ?? 'Comment API error'];
    }
    $commentId = $cResult['id'];

    // 2. Épingler le commentaire
    $chP = curl_init('https://graph.facebook.com/v19.0/' . $commentId);
    curl_setopt_array($chP, [
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => ['access_token' => $token, 'is_pinned' => 'true'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $pResult = json_decode(curl_exec($chP), true);
    curl_close($chP);

    return [
        'ok'         => true,
        'comment_id' => $commentId,
        'pinned'     => !empty($pResult['success']),
        'pin_error'  => empty($pResult['success']) ? ($pResult['error']['message'] ?? 'Pin failed') : null,
    ];
}

// ── Helper: pending comments queue ───────────────────────────────────────────
function fb_pending_file() { return __DIR__ . '/fb_pending_comments.json'; }
function fb_pending_load() {
    $f = fb_pending_file();
    return file_exists($f) ? (json_decode(file_get_contents($f), true) ?? []) : [];
}
function fb_pending_save($queue) {
    file_put_contents(fb_pending_file(), json_encode(array_values($queue), JSON_PRETTY_PRINT));
}
function fb_pending_add($videoId, $comment, $scheduledTs, $slug) {
    $queue   = fb_pending_load();
    $queue[] = ['video_id' => $videoId, 'comment' => $comment, 'scheduled_ts' => $scheduledTs, 'slug' => $slug, 'retries' => 0];
    fb_pending_save($queue);
}

// ── Action: process_pending_comments ─────────────────────────────────────────
function action_process_pending_comments() {
    $token = defined('FACEBOOK_ACCESS_TOKEN') ? FACEBOOK_ACCESS_TOKEN : '';
    if (!$token) { echo json_encode(['ok' => true, 'processed' => 0]); return; }

    $queue = fb_pending_load();
    $now   = time();
    $done  = []; $kept = []; $errors = [];

    foreach ($queue as $item) {
        // Attendre 3 min après l'heure planifiée pour que FB ait publié la vidéo
        if ($now < ($item['scheduled_ts'] + 180)) { $kept[] = $item; continue; }
        if (($item['retries'] ?? 0) >= 5) continue; // abandon après 5 échecs

        $res = fb_post_and_pin_comment($item['video_id'], $token, $item['comment']);
        if ($res['ok']) {
            $done[] = ['slug' => $item['slug'], 'comment_id' => $res['comment_id'], 'pinned' => $res['pinned']];
        } else {
            $item['retries']    = ($item['retries'] ?? 0) + 1;
            $item['last_error'] = $res['error'];
            $kept[]             = $item;
            $errors[]           = $item['slug'] . ': ' . $res['error'];
        }
    }

    fb_pending_save($kept);
    echo json_encode(['ok' => true, 'processed' => count($done), 'done' => $done, 'errors' => $errors, 'remaining' => count($kept)]);
}

// ── Action: get_page_tokens ──────────────────────────────────────────────────
function action_get_page_tokens($appId, $appSecret, $userToken) {
    if (!$appId || !$appSecret || !$userToken) {
        echo json_encode(['error' => 'App ID, App Secret et User Token requis']); return;
    }

    // 1. Échanger short-lived → long-lived token
    $url = 'https://graph.facebook.com/v19.0/oauth/access_token?grant_type=fb_exchange_token'
         . '&client_id='          . urlencode($appId)
         . '&client_secret='      . urlencode($appSecret)
         . '&fb_exchange_token='  . urlencode($userToken);

    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (isset($res['error'])) {
        echo json_encode(['error' => $res['error']['message'] ?? 'Erreur exchange token']); return;
    }
    $longLivedToken = $res['access_token'];

    // 2. Récupérer les Page Access Tokens
    $url2 = 'https://graph.facebook.com/v19.0/me/accounts?access_token=' . urlencode($longLivedToken);
    $ch2  = curl_init($url2);
    curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $res2 = json_decode(curl_exec($ch2), true);
    curl_close($ch2);

    if (isset($res2['error'])) {
        echo json_encode(['error' => $res2['error']['message'] ?? 'Erreur récupération pages']); return;
    }

    $pages = [];
    foreach ($res2['data'] ?? [] as $p) {
        // Vérifier l'expiry via debug_token
        $debugUrl = 'https://graph.facebook.com/v19.0/debug_token?input_token=' . urlencode($p['access_token'])
                  . '&access_token=' . urlencode($appId . '|' . $appSecret);
        $chD = curl_init($debugUrl);
        curl_setopt_array($chD, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $debugRes = json_decode(curl_exec($chD), true);
        curl_close($chD);

        $expiry = $debugRes['data']['expires_at'] ?? 0;
        $pages[] = [
            'id'           => $p['id'],
            'name'         => $p['name'],
            'access_token' => $p['access_token'],
            'expires'      => $expiry === 0 ? 'permanent' : date('d/m/Y', $expiry),
        ];
    }

    echo json_encode(['ok' => true, 'pages' => $pages]);
}

// ── Helper: hook aléatoire depuis liste statique ──────────────────────────────
function fb_generate_hook() {
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

// ── Action: post_to_facebook ──────────────────────────────────────────────────
function action_post_to_facebook($slug, $scheduledTime) {
    $pageId = defined('FACEBOOK_PAGE_ID')      ? FACEBOOK_PAGE_ID      : '';
    $token  = defined('FACEBOOK_ACCESS_TOKEN') ? FACEBOOK_ACCESS_TOKEN : '';

    if (!$pageId || !$token) {
        echo json_encode(['error' => 'Configure FACEBOOK_PAGE_ID et FACEBOOK_ACCESS_TOKEN dans Config UI → Facebook Reels']); return;
    }

    $videoPath = __DIR__ . '/posts/' . $slug . '/images/' . $slug . '_reel.mp4';
    if (!file_exists($videoPath)) {
        echo json_encode(['error' => 'Vidéo non trouvée — génère la vidéo d\'abord']); return;
    }

    $postJson = __DIR__ . ‘/posts/’ . $slug . ‘/post.json’;
    $post     = file_exists($postJson) ? (json_decode(file_get_contents($postJson), true) ?? []) : [];

    $title       = $post['title']       ?? $slug;
    $ingredients = $post['ingredients'] ?? [];
    $hashtags    = defined('FACEBOOK_HASHTAGS') ? FACEBOOK_HASHTAGS : '';
    $ctaText     = defined('FACEBOOK_CTA_TEXT') ? FACEBOOK_CTA_TEXT : 'Get the full recipe at';
    $siteUrl     = 'https://' . (defined('HOST_NAME') ? HOST_NAME : '');
    $recipeUrl   = rtrim($siteUrl, '/') . '/posts/' . $slug . '/';

    // ── Hook aléatoire ────────────────────────────────────────────────────────
    $hook = fb_generate_hook();

    // ── Quelques ingrédients clés (3-5 premiers) ──────────────────────────────
    $ingSnippet = '';
    $ingShort   = array_slice($ingredients, 0, 5);
    if (!empty($ingShort)) {
        $ingSnippet = implode(' · ', array_map(fn($i) => trim($i), $ingShort));
    }

    // ── Description de la vidéo : hook + ingrédients + lien + hashtags ────────
    $descriptionOnly = $title . "\n\n" . $hook;
    if ($ingSnippet) $descriptionOnly .= "\n\n🛒 " . $ingSnippet;
    $descriptionOnly .= "\n\n" . $ctaText . "\n" . $recipeUrl;
    if ($hashtags) $descriptionOnly .= "\n\n" . $hashtags;

    $scheduledTs = $scheduledTime ? strtotime($scheduledTime) : 0;
    $isScheduled = $scheduledTs > (time() + 600);

    $postFields = [
        'access_token' => $token,
        'description'  => $descriptionOnly,
        'source'       => new CURLFile($videoPath, 'video/mp4', $slug . '_reel.mp4'),
    ];
    if ($isScheduled) {
        $postFields['published']              = 'false';
        $postFields['scheduled_publish_time'] = (string)$scheduledTs;
    } else {
        $postFields['published'] = 'true';
    }

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
    if (!($httpCode === 200 && isset($result['id']))) {
        echo json_encode(['error' => $result['error']['message'] ?? ('HTTP ' . $httpCode)]);
        return;
    }

    $videoId = $result['id'];

    echo json_encode([
        'ok'        => true,
        'video_id'  => $videoId,
        'scheduled' => $isScheduled,
        'time'      => $isScheduled ? date('d/m/Y H:i', $scheduledTs) : 'maintenant',
    ]);
}

// ── Router ────────────────────────────────────────────────────────────────────
// Guard : si FB_REEL_FUNCTIONS_ONLY est défini, on n'exécute pas le router
// (permet d'inclure ce fichier depuis fb-auto-post.php pour appeler les fonctions directement)
if (!defined('FB_REEL_FUNCTIONS_ONLY')):

// CLI : json passé en argv[1], HTTP : php://input ou POST/GET
if (PHP_SAPI === 'cli') {
    $input = isset($argv[1]) ? (json_decode($argv[1], true) ?? []) : [];
} else {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
}
$action = $input['action'] ?? $_GET['action'] ?? $_POST['action'] ?? '';
$slug   = preg_replace('/[^a-z0-9\-]/', '', strtolower($input['slug'] ?? $_POST['slug'] ?? $_GET['slug'] ?? ''));
$dur    = max(1, min(15, (int)($input['duration'] ?? $_POST['duration'] ?? FACEBOOK_FRAME_DURATION)));

ob_end_clean(); // discard any stray output before JSON

try {
    switch ($action) {
        case 'check':            action_check();                       break;
        case 'generate_frames':  action_generate_frames($slug);        break;
        case 'generate_video':   action_generate_video($slug, $dur, $input['music'] ?? ''); break;
        case 'download_zip':     action_download_zip($slug);                                          break;
        case 'get_page_tokens':          action_get_page_tokens($input['app_id'] ?? '', $input['app_secret'] ?? '', $input['user_token'] ?? ''); break;
        case 'post_to_facebook':         action_post_to_facebook($slug, $input['scheduled_time'] ?? ''); break;
        case 'process_pending_comments': action_process_pending_comments();                              break;
        case 'list_music':               action_list_music();                                            break;
        case 'upload_music':     action_upload_music();                                            break;
        case 'delete_music':     action_delete_music($input['name'] ?? $_POST['name'] ?? '');     break;
        default:
            echo json_encode(['error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()]);
}

endif; // FB_REEL_FUNCTIONS_ONLY

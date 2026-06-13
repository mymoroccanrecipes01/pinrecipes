<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once 'config.php';

// Nettoyer le texte pour rendu GD — remplace les caractères que la plupart des polices TTF ne contiennent pas
function gd_safe_text(string $text): string {
    $text = strtr($text, [
        "\u{2018}" => "'",  "\u{2019}" => "'",  "\u{201A}" => "'",
        "\u{201C}" => '"',  "\u{201D}" => '"',  "\u{201E}" => '"',
        "\u{2013}" => '-',  "\u{2014}" => '-',  "\u{2026}" => '...',
        "\u{00AB}" => '"',  "\u{00BB}" => '"',
        "\u{2022}" => '-',  "\u{00B7}" => '.',
        "\u{00A0}" => ' ',
    ]);
    $text = preg_replace('/[^\x{0000}-\x{FFFF}]/u', '', $text);
    $text = preg_replace('/[\x{2000}-\x{2BFF}][\x{FE00}-\x{FEFF}]*/u', '', $text);
    return trim($text);
}

// Normalise les quotes/tirets mais garde les symboles BMP (↓ ↑ → ← etc.) pour le fallback font
function gd_normalize_text(string $text): string {
    $text = strtr($text, [
        "\u{2018}" => "'",  "\u{2019}" => "'",  "\u{201A}" => "'",
        "\u{201C}" => '"',  "\u{201D}" => '"',  "\u{201E}" => '"',
        "\u{2013}" => '-',  "\u{2014}" => '-',  "\u{2026}" => '...',
        "\u{00AB}" => '"',  "\u{00BB}" => '"',
        "\u{2022}" => '-',  "\u{00B7}" => '.',
        "\u{00A0}" => ' ',
    ]);
    return trim(preg_replace('/[^\x{0000}-\x{FFFF}]/u', '', $text));
}

// Trouve une police système avec large couverture Unicode (DejaVu, Liberation, Noto)
function _gd_fallback_font(): ?string {
    static $cache = false;
    if ($cache !== false) return $cache;
    foreach ([
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/TTF/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        '/usr/share/fonts/liberation/LiberationSans-Regular.ttf',
        '/usr/share/fonts/truetype/noto/NotoSans-Regular.ttf',
    ] as $f) {
        if (file_exists($f)) return ($cache = $f);
    }
    return ($cache = null);
}

// Mesure la largeur d'un texte avec font fallback pour les caractères non-ASCII
function _gd_mixed_width(float $size, string $primaryFont, string $text): int {
    $fallback = _gd_fallback_font();
    $w = 0;
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $char) {
        $useFont = ($fallback && mb_ord($char) > 0x00FF) ? $fallback : $primaryFont;
        $bbox = imagettfbbox($size, 0, $useFont, $char);
        $w += abs($bbox[2] - $bbox[0]);
    }
    return $w;
}

// Rend un texte avec font fallback pour les caractères non-ASCII
function _gd_mixed_render($image, float $size, int $x, int $y, $color, string $primaryFont, string $text, $shadow = null): void {
    $fallback = _gd_fallback_font();
    $curX = $x;
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $char) {
        $useFont = ($fallback && mb_ord($char) > 0x00FF) ? $fallback : $primaryFont;
        $bbox = imagettfbbox($size, 0, $useFont, $char);
        $charW = abs($bbox[2] - $bbox[0]);
        if ($shadow !== null) {
            imagettftext($image, $size, 0, $curX + 2, $y + 2, $shadow, $useFont, $char);
        }
        imagettftext($image, $size, 0, $curX, $y, $color, $useFont, $char);
        $curX += $charW;
    }
}

// Détecter le protocole
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
    || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";

$host        = $_SERVER['HTTP_HOST'];
$projectPath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/';

// Use TEMPLATE_CONFIG (reads image1/image2/title/slug/etc. from $_POST)
$config = TEMPLATE_CONFIG;

if (!$config) {
    http_response_code(400);
    echo json_encode(['error' => 'Config missing']);
    exit;
}

try {
// ── EXTRA TEMPLATES: recipe_card + overlay_list (driven by TEMPLATE_PRESETS) ──
$tplType    = $_POST['template'] ?? '';
$extraTypes = array_keys(array_filter(TEMPLATE_PRESETS, fn($p) => isset($p['layout']) && in_array($p['layout'], ['recipe_card', 'overlay_list'])));
if (in_array($tplType, $extraTypes)) {

    $p = TEMPLATE_PRESETS[$tplType]; // preset config

    // ── Hériter couleurs + font du template principal actif ───────────────────
    // no_inherit=1 → utilise les couleurs propres du preset (recipe_card/overlay_list)
    $noInherit = !empty($_POST['no_inherit']);
    $parentKey = $_POST['parent_template'] ?? ACTIVE_TEMPLATE;
    $parent    = (!$noInherit) ? (TEMPLATE_PRESETS[$parentKey] ?? TEMPLATE_PRESETS[ACTIVE_TEMPLATE] ?? []) : [];
    if (!empty($parent['TEMPLATE_BANNER_COLOR'])) {
        // Retirer le canal alpha si présent (#rrggbbaa → #rrggbb)
        $bannerHex = '#' . substr(ltrim($parent['TEMPLATE_BANNER_COLOR'], '#'), 0, 6);
        $p['SEP_COLOR']           = $bannerHex;
        $p['LABEL_COLOR']         = $bannerHex;
        $p['CIRCLE_BORDER_COLOR'] = $bannerHex;
        $p['URL_COLOR']           = $bannerHex;
        $p['ACCENT_COLOR']        = $bannerHex;
        $p['OVERLAY_COLOR']       = $bannerHex;
    }
    if (!empty($parent['TEMPLATE_BG_COLOR'])) {
        $p['BG_COLOR'] = $parent['TEMPLATE_BG_COLOR'];
    }
    if (!empty($parent['TEMPLATE_TEXT_COLOR'])) {
        $textHex = '#' . substr(ltrim($parent['TEMPLATE_TEXT_COLOR'], '#'), 0, 6);
        $p['TITLE_COLOR'] = $textHex;
        $p['ING_COLOR']   = $textHex;
    }
    if (!empty($parent['TEMPLATE_FONT_URL'])) {
        $p['TITLE_FONT_URL'] = $parent['TEMPLATE_FONT_URL'];
        $p['BODY_FONT_URL']  = $parent['TEMPLATE_FONT_URL'];
    }
    // ─────────────────────────────────────────────────────────────────────────

    // ── Overrides explicites depuis site-config.json (config-ui → champs couleur extra templates) ──
    // Ces valeurs ont priorité sur l'héritage du template parent
    $bgKey = ($p['layout'] === 'overlay_list') ? 'OVERLAY_COLOR' : 'BG_COLOR';
    foreach ([
        $bgKey         => $bgKey,
        'TITLE_COLOR'  => 'TITLE_COLOR',
        'ING_COLOR'    => 'TITLE_COLOR',   // ING_COLOR hérite de TITLE_COLOR si pas de champ séparé
        'LABEL_COLOR'  => 'LABEL_COLOR',
        'SEP_COLOR'    => 'LABEL_COLOR',
        'CIRCLE_BORDER_COLOR' => 'LABEL_COLOR',
        'ACCENT_COLOR' => 'LABEL_COLOR',
        'URL_COLOR'    => 'LABEL_COLOR',
    ] as $paramKey => $cfgSuffix) {
        $val = _cfg($tplType . '_' . $cfgSuffix, null);
        if ($val !== null && $val !== '') $p[$paramKey] = $val;
    }
    // ─────────────────────────────────────────────────────────────────────────

    $W       = 1000; $H = 2000;
    $title   = $_POST['title']  ?? '';
    $img1Url = $_POST['image1'] ?? '';

    $rawIng      = $_POST['ingredients'] ?? '[]';
    $ingredients = json_decode($rawIng, true);
    if (!is_array($ingredients)) $ingredients = [];
    $ingredients = array_values(array_filter(array_map(function($i) {
        if (is_string($i)) return trim($i);
        if (is_array($i)) return trim($i['name'] ?? $i['ingredient'] ?? $i['item'] ?? implode(' ', array_filter($i, 'is_string')));
        return '';
    }, $ingredients)));
    $ingredients = array_slice($ingredients, 0, (int)($p['ING_MAX_ITEMS'] ?? 14));

    $titleFont = downloadFont($p['TITLE_FONT_URL'] ?? 'https://fonts.googleapis.com/css2?family=Bebas+Neue&display=swap', 400);
    $bodyFont  = downloadFont($p['BODY_FONT_URL']  ?? 'https://fonts.googleapis.com/css2?family=Oswald:wght@400&display=swap', 400);
    if (!$titleFont) $titleFont = $bodyFont;
    if (!$bodyFont)  $bodyFont  = $titleFont;

    $canvas = imagecreatetruecolor($W, $H);
    imagealphablending($canvas, true);
    imagesavealpha($canvas, true);

    // ── Helper: word-wrap + render text centered ───────────────────────────────
    $renderCenteredText = function($text, $size, $font, $color, $maxW, $startY, $lineRatio = 1.15) use ($canvas, $W) {
        $words = explode(' ', $text); $lines = []; $line = '';
        foreach ($words as $w) {
            $test = $line ? $line . ' ' . $w : $w;
            $bb = imagettfbbox($size, 0, $font, $test);
            if (($bb[2] - $bb[0]) > $maxW && $line) { $lines[] = $line; $line = $w; }
            else $line = $test;
        }
        if ($line) $lines[] = $line;
        $lh = (int)($size * $lineRatio);
        foreach ($lines as $li => $l) {
            $bb = imagettfbbox($size, 0, $font, $l);
            $lx = (int)(($W - ($bb[2] - $bb[0])) / 2);
            imagettftext($canvas, $size, 0, $lx, $startY + $li * $lh, $color, $font, $l);
        }
        return $startY + count($lines) * $lh;
    };

    // ── Helper: render ingredient bullet list ──────────────────────────────────
    $renderIngList = function($ingredients, $size, $font, $color, $maxW, $startX, $startY, $H) use ($canvas) {
        $lh = (int)($size * 1.6);
        $y  = $startY;
        foreach ($ingredients as $ing) {
            $words2 = explode(' ', '• ' . $ing); $cl = ''; $iLines = [];
            foreach ($words2 as $w2) {
                $t2 = $cl ? $cl . ' ' . $w2 : $w2;
                $b2 = imagettfbbox($size, 0, $font, $t2);
                if (($b2[2] - $b2[0]) > $maxW && $cl) { $iLines[] = $cl; $cl = '  ' . $w2; }
                else $cl = $t2;
            }
            if ($cl) $iLines[] = $cl;
            foreach ($iLines as $il) {
                if ($y > $H - 100) break 2;
                imagettftext($canvas, $size, 0, $startX, $y, $color, $font, $il);
                $y += $lh;
            }
        }
    };

    if ($p['layout'] === 'recipe_card') {
        // ── Fond ──────────────────────────────────────────────────────────────
        $bgRgb = hexToRgb($p['BG_COLOR'] ?? '#FFF8F0');
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, $bgRgb[0], $bgRgb[1], $bgRgb[2]));

        // ── Photo circulaire ──────────────────────────────────────────────────
        $diam = (int)($p['CIRCLE_DIAM'] ?? 560); $cx = 500; $cy = 360; $r = (int)($diam / 2);
        $img1 = loadImage($img1Url);
        if ($img1) {
            $iw = imagesx($img1); $ih = imagesy($img1);
            $ratio = max($diam / $iw, $diam / $ih);
            $nw = (int)($iw * $ratio); $nh = (int)($ih * $ratio);
            $ox = (int)(($diam - $nw) / 2); $oy = (int)(($diam - $nh) / 2);
            $layer = imagecreatetruecolor($diam, $diam);
            imagealphablending($layer, false); imagesavealpha($layer, true);
            $transp = imagecolorallocatealpha($layer, 0, 0, 0, 127);
            imagefill($layer, 0, 0, $transp);
            imagealphablending($layer, true);
            imagecopyresampled($layer, $img1, $ox, $oy, 0, 0, $nw, $nh, $iw, $ih);
            imagealphablending($layer, false);
            for ($px = 0; $px < $diam; $px++) {
                for ($py = 0; $py < $diam; $py++) {
                    $dx = $px - $r; $dy = $py - $r;
                    if (($dx*$dx + $dy*$dy) > ($r*$r)) imagesetpixel($layer, $px, $py, $transp);
                }
            }
            imagealphablending($canvas, true);
            imagecopy($canvas, $layer, $cx - $r, $cy - $r, 0, 0, $diam, $diam);
            imagedestroy($layer); imagedestroy($img1);
        }
        $borderRgb = hexToRgb($p['CIRCLE_BORDER_COLOR'] ?? '#8B4513');
        $ringC = imagecolorallocate($canvas, $borderRgb[0], $borderRgb[1], $borderRgb[2]);
        imagesetthickness($canvas, (int)($p['CIRCLE_BORDER_WIDTH'] ?? 8));
        imagearc($canvas, $cx, $cy, $diam + 10, $diam + 10, 0, 360, $ringC);
        imagesetthickness($canvas, 1);

        // ── Titre ─────────────────────────────────────────────────────────────
        $tRgb = hexToRgb($p['TITLE_COLOR'] ?? '#2C1810');
        $tColor = imagecolorallocate($canvas, $tRgb[0], $tRgb[1], $tRgb[2]);
        $afterTitle = $renderCenteredText(mb_strtoupper($title), (int)($p['TITLE_FONT_SIZE'] ?? 82), $titleFont, $tColor, (int)($p['TITLE_MAX_WIDTH'] ?? 880), 730) + 20;

        // ── Séparateur ────────────────────────────────────────────────────────
        $sepRgb = hexToRgb($p['SEP_COLOR'] ?? '#8B4513');
        $sepC = imagecolorallocate($canvas, $sepRgb[0], $sepRgb[1], $sepRgb[2]);
        imagesetthickness($canvas, (int)($p['SEP_WIDTH'] ?? 3));
        imageline($canvas, 200, $afterTitle + 20, 800, $afterTitle + 20, $sepC);
        imagesetthickness($canvas, 1);

        // ── Label INGREDIENTS ─────────────────────────────────────────────────
        $lbRgb = hexToRgb($p['LABEL_COLOR'] ?? '#8B4513');
        $lbColor = imagecolorallocate($canvas, $lbRgb[0], $lbRgb[1], $lbRgb[2]);
        $lbSize = (int)($p['LABEL_FONT_SIZE'] ?? 52);
        $lbText = $p['LABEL_TEXT'] ?? 'INGREDIENTS'; $lbY = $afterTitle + 90;
        $bb = imagettfbbox($lbSize, 0, $bodyFont, $lbText);
        imagettftext($canvas, $lbSize, 0, (int)(($W - ($bb[2] - $bb[0])) / 2), $lbY, $lbColor, $bodyFont, $lbText);

        // ── Liste ingrédients ─────────────────────────────────────────────────
        $iRgb = hexToRgb($p['ING_COLOR'] ?? '#2C1810');
        $iColor = imagecolorallocate($canvas, $iRgb[0], $iRgb[1], $iRgb[2]);
        $renderIngList($ingredients, (int)($p['ING_FONT_SIZE'] ?? 36), $bodyFont, $iColor, (int)($p['ING_MAX_WIDTH'] ?? 840), 80, $lbY + 60, $H);

        // ── URL ───────────────────────────────────────────────────────────────
        $uRgb = hexToRgb($p['URL_COLOR'] ?? '#8B4513');
        $uColor = imagecolorallocate($canvas, $uRgb[0], $uRgb[1], $uRgb[2]);
        $uSize = (int)($p['URL_FONT_SIZE'] ?? 28);
        $bb = imagettfbbox($uSize, 0, $bodyFont, HOST_NAME);
        imagettftext($canvas, $uSize, 0, (int)(($W - ($bb[2] - $bb[0])) / 2), $H - 40, $uColor, $bodyFont, HOST_NAME);

    } else { // overlay_list

        // ── Photo plein format ────────────────────────────────────────────────
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 10, 10, 10));
        $img1 = loadImage($img1Url);
        if ($img1) { drawImageCover($canvas, $img1, 0, 0, $W, $H); imagedestroy($img1); }

        // ── Gradient overlay sombre ───────────────────────────────────────────
        $ovRgb  = hexToRgb($p['OVERLAY_COLOR'] ?? '#120800');
        $ovStart = (int)($p['OVERLAY_START'] ?? 880);
        $fadeZ   = (int)($p['OVERLAY_FADE_ZONE'] ?? 200);
        for ($oy2 = $ovStart; $oy2 < $H; $oy2++) {
            $alpha = ($oy2 < $ovStart + $fadeZ) ? (int)(127 - 127 * (($oy2 - $ovStart) / $fadeZ)) : 0;
            $oc = imagecolorallocatealpha($canvas, $ovRgb[0], $ovRgb[1], $ovRgb[2], $alpha);
            imagefilledrectangle($canvas, 0, $oy2, $W - 1, $oy2, $oc);
        }

        // ── Titre ─────────────────────────────────────────────────────────────
        $tRgb = hexToRgb($p['TITLE_COLOR'] ?? '#ffffff');
        $tColor = imagecolorallocate($canvas, $tRgb[0], $tRgb[1], $tRgb[2]);
        $tSize  = (int)($p['TITLE_FONT_SIZE'] ?? 90);
        $tMaxW  = (int)($p['TITLE_MAX_WIDTH'] ?? 900);
        $tY     = (int)($p['TITLE_Y'] ?? 1070);
        // render with shadow
        $words = explode(' ', mb_strtoupper($title)); $lines = []; $line = '';
        foreach ($words as $w) {
            $test = $line ? $line . ' ' . $w : $w;
            $bb = imagettfbbox($tSize, 0, $titleFont, $test);
            if (($bb[2] - $bb[0]) > $tMaxW && $line) { $lines[] = $line; $line = $w; }
            else $line = $test;
        }
        if ($line) $lines[] = $line;
        $lh = (int)($tSize * 1.1);
        foreach ($lines as $li => $l) {
            $bb = imagettfbbox($tSize, 0, $titleFont, $l);
            $lx = (int)(($W - ($bb[2] - $bb[0])) / 2);
            $shad = imagecolorallocatealpha($canvas, 0, 0, 0, 60);
            imagettftext($canvas, $tSize, 0, $lx + 3, $tY + $li * $lh + 3, $shad, $titleFont, $l);
            imagettftext($canvas, $tSize, 0, $lx, $tY + $li * $lh, $tColor, $titleFont, $l);
        }
        $afterTitle = $tY + count($lines) * $lh + 15;

        // ── Accent line ───────────────────────────────────────────────────────
        $acRgb = hexToRgb($p['ACCENT_COLOR'] ?? '#FFD700');
        $acC = imagecolorallocate($canvas, $acRgb[0], $acRgb[1], $acRgb[2]);
        imagesetthickness($canvas, (int)($p['ACCENT_WIDTH'] ?? 4));
        imageline($canvas, 80, $afterTitle, 920, $afterTitle, $acC);
        imagesetthickness($canvas, 1);

        // ── Label INGREDIENTS: ────────────────────────────────────────────────
        $lbRgb = hexToRgb($p['LABEL_COLOR'] ?? '#FFD700');
        $lbColor = imagecolorallocate($canvas, $lbRgb[0], $lbRgb[1], $lbRgb[2]);
        $lbSize = (int)($p['LABEL_FONT_SIZE'] ?? 48); $lbY = $afterTitle + 60;
        imagettftext($canvas, $lbSize, 0, 80, $lbY, $lbColor, $bodyFont, $p['LABEL_TEXT'] ?? 'INGREDIENTS:');

        // ── Liste ingrédients ─────────────────────────────────────────────────
        $iRgb = hexToRgb($p['ING_COLOR'] ?? '#ffffff');
        $iColor = imagecolorallocate($canvas, $iRgb[0], $iRgb[1], $iRgb[2]);
        $renderIngList($ingredients, (int)($p['ING_FONT_SIZE'] ?? 34), $bodyFont, $iColor, (int)($p['ING_MAX_WIDTH'] ?? 860), 80, $lbY + 55, $H);

        // ── URL ───────────────────────────────────────────────────────────────
        $uAlpha = (int)($p['URL_ALPHA'] ?? 50);
        $uRgb   = hexToRgb($p['URL_COLOR'] ?? '#ffffff');
        $uColor = imagecolorallocatealpha($canvas, $uRgb[0], $uRgb[1], $uRgb[2], $uAlpha);
        $uSize  = (int)($p['URL_FONT_SIZE'] ?? 28);
        $bb = imagettfbbox($uSize, 0, $bodyFont, HOST_NAME);
        imagettftext($canvas, $uSize, 0, (int)(($W - ($bb[2] - $bb[0])) / 2), $H - 40, $uColor, $bodyFont, HOST_NAME);
    }

    // ── Sauvegarde fichier ─────────────────────────────────────────────────
    $folder2   = ($config['slug'] === 'test') ? $config['slug'] : ($config['folder'] ?? 'posts');
    $outputDir = __DIR__ . '/' . $folder2 . '/' . $config['slug'] . '/images/';
    if (!file_exists($outputDir)) mkdir($outputDir, 0777, true);
    $filename  = $config['slug'] . '_' . SITE_FOLDER . '_image_' . $config['index'] . '.webp';
    $filepath  = $outputDir . $filename;
    imagewebp($canvas, $filepath, 92);
    imagedestroy($canvas);
    $relativePath = $config['slug'] . '/images/' . $filename;
    $fullPath     = $folder2 . '/' . $config['slug'] . '/images/' . $filename;
    $fullUrl      = ($config['slug'] === 'test')
        ? $protocol . $host . $projectPath . $relativePath
        : $protocol . $host . $projectPath . $fullPath;
    echo json_encode(['success' => true, 'filename' => $filename, 'pathrelative' => $relativePath, 'url' => $fullUrl, 'path' => $fullPath]);
    exit;
}
// ── END NEW TEMPLATES ─────────────────────────────────────────────────────────

    // Créer l'image
    $width = $config['dimensions']['width'];
    $height = $config['dimensions']['height'];
    $image = imagecreatetruecolor($width, $height);
    
    // Couleur de fond
    $bgColor = hexToRgb($config['colors']['backgroundColor']);
    $background = imagecolorallocate($image, $bgColor[0], $bgColor[1], $bgColor[2]);
    imagefill($image, 0, 0, $background);
    
    // Image de fond (optionnelle)
    if (!empty($config['images']['background']['url'])) {
        $bgImage = loadImage($config['images']['background']['url']);
        if ($bgImage) {
            imagecopymerge($image, $bgImage, 0, 0, 0, 0, $width, $height, 30);
            imagedestroy($bgImage);
        }
    }
    
    // Définir les zones
    $bannerY = $config['banner']['y']; // 750
    $bannerHeight = $config['banner']['height']; // 200
    $bannerEndY = $bannerY + $bannerHeight; // 850
    
    // Image 1 (top) - 750px de hauteur
    $image1Height = $config['images']['image1']['height']; // 675
    if (!empty($config['images']['image1']['url'])) {
        $img1 = loadImage($config['images']['image1']['url']);
        if ($img1) {
            drawImageCover($image, $img1, 0, 0, $width, $image1Height);
            imagedestroy($img1);
        }
    } else {
        // Placeholder
        $placeholderColor = imagecolorallocate($image, 229, 212, 193);
        imagefilledrectangle($image, 0, 0, $width, $image1Height, $placeholderColor);
    }
    
    // Image 2 (bottom) - Commence à 750px avec clip pour éviter le banner
    $image2Y = $config['images']['image2']['y']; // 750
    $image2Height = $config['images']['image2']['height']; // 675
    
    if ($image2Height > 0 && !empty($config['images']['image2']['url'])) {
        $img2 = loadImage($config['images']['image2']['url']);
        if ($img2) {
            $img2Layer = imagecreatetruecolor($width, $image2Height);
            drawImageCover($img2Layer, $img2, 0, 0, $width, $image2Height);

            if ($bannerEndY <= $image2Y) {
                // Banner is entirely ABOVE image2 → no clipping needed, draw image2 as-is
                imagecopy($image, $img2Layer, 0, $image2Y, 0, 0, $width, $image2Height);
            } else {
                // Banner overlaps image2 → clip around the banner zone
                if ($image2Y < $bannerY) {
                    // Part above the banner
                    $topHeight = $bannerY - $image2Y;
                    imagecopy($image, $img2Layer, 0, $image2Y, 0, 0, $width, $topHeight);
                }
                // Part below the banner
                $bottomSrcY = $bannerEndY - $image2Y;
                $bottomHeight = $image2Height - $bottomSrcY;
                if ($bottomSrcY >= 0 && $bottomHeight > 0) {
                    imagecopy($image, $img2Layer, 0, $bannerEndY, 0, $bottomSrcY, $width, $bottomHeight);
                }
            }

            imagedestroy($img2Layer);
            imagedestroy($img2);
        }
    } else if ($image2Height > 0) {
        // Placeholder — only draw outside the banner zone
        $placeholderColor = imagecolorallocate($image, 229, 212, 193);
        if ($bannerEndY <= $image2Y) {
            imagefilledrectangle($image, 0, $image2Y, $width, $image2Y + $image2Height, $placeholderColor);
        } else {
            if ($image2Y < $bannerY) {
                imagefilledrectangle($image, 0, $image2Y, $width, $bannerY, $placeholderColor);
            }
            $belowEnd = $image2Y + $image2Height;
            if ($belowEnd > $bannerEndY) {
                imagefilledrectangle($image, 0, $bannerEndY, $width, $belowEnd, $placeholderColor);
            }
        }
    }
    
    // Banner - Gradient fade in/out
    if ($config['banner']['type'] === 'color') {
        $bannerColorRgb = hexToRgb($config['banner']['color']);
        $r = $bannerColorRgb[0]; $g = $bannerColorRgb[1]; $b = $bannerColorRgb[2];

        $fadeZone = (int)($bannerHeight * 0.22); // 22% fade top and bottom
        for ($i = 0; $i < $bannerHeight; $i++) {
            if ($i < $fadeZone) {
                $alpha = (int)(115 - (115 * ($i / $fadeZone)));
            } elseif ($i > $bannerHeight - $fadeZone) {
                $alpha = (int)(115 * (($i - ($bannerHeight - $fadeZone)) / $fadeZone));
            } else {
                $alpha = 0;
            }
            $lineColor = imagecolorallocatealpha($image, $r, $g, $b, $alpha);
            imagefilledRectangle($image, 0, $bannerY + $i, $width, $bannerY + $i + 1, $lineColor);
        }

    } elseif ($config['banner']['type'] === 'image' && !empty($config['banner']['imageUrl'])) {
        $bannerImg = loadImage($config['banner']['imageUrl']);
        if ($bannerImg) {
            // Créer un layer pour le banner
            $bannerLayer = imagecreatetruecolor($width, $bannerHeight);
            drawImageCover($bannerLayer, $bannerImg, 0, 0, $width, $bannerHeight);
            
            // Appliquer l'overlay sombre
            $overlay = imagecolorallocatealpha($bannerLayer, 0, 0, 0, 50);
            imagefilledrectangle($bannerLayer, 0, 0, $width, $bannerHeight, $overlay);
            
            imagecopy($image, $bannerLayer, 0, $bannerY, 0, 0, $width, $bannerHeight);
            imagedestroy($bannerLayer);
            imagedestroy($bannerImg);
        }
    }
    
// Texte
$text = gd_safe_text($config['text']['content']);
$textColorRgb = hexToRgb($config['text']['color']);
$textColor = imagecolorallocate($image, $textColorRgb[0], $textColorRgb[1], $textColorRgb[2]);

// Charger la police avec le poids spécifié
$fontWeight = isset($config['text']['fontWeight']) ? $config['text']['fontWeight'] : 400;
$fontFile = downloadFont($config['text']['fontUrl'], $fontWeight);

if ($fontFile && file_exists($fontFile)) {
    $maxWidth = isset($config['text']['maxWidth']) ? $config['text']['maxWidth'] : 850;
    $lines = [];
    
    // Vérifier si une taille de police fixe est spécifiée
    if (isset($config['text']['fontSize']) && $config['text']['fontSize'] > 0) {
        // Utiliser la taille de police fixe
        $fontSize = $config['text']['fontSize'];
        $lines = wrapText($text, $fontFile, $fontSize, $maxWidth);
        
        // Vérifier si le texte dépasse la hauteur du banner
        $lineHeight = $fontSize * 1.2;
        $totalHeight = count($lines) * $lineHeight;
        
        if ($totalHeight > $bannerHeight) {
            // Si ça dépasse, on peut soit :
            // Option 1 : Garder la taille et couper le texte
            // Option 2 : Réduire automatiquement la taille
            
            // Option 2 (recommandé) : réduction automatique
            do {
                $fontSize -= 2;
                $lines = wrapText($text, $fontFile, $fontSize, $maxWidth);
                $lineHeight = $fontSize * 1.2;
                $totalHeight = count($lines) * $lineHeight;
            } while ($totalHeight > $bannerHeight && $fontSize > 24);
        }
    } else {
        // Calculer automatiquement la taille de police optimale
        $maxFontSize = isset($config['text']['maxFontSize']) ? $config['text']['maxFontSize'] : 72;
        $minFontSize = isset($config['text']['minFontSize']) ? $config['text']['minFontSize'] : 24;
        
        $fontSize = $maxFontSize;
        
        do {
            $lines = wrapText($text, $fontFile, $fontSize, $maxWidth);
            $lineHeight = $fontSize * 1.2;
            $totalHeight = count($lines) * $lineHeight;
            
            if ($totalHeight <= $bannerHeight && $fontSize <= $maxFontSize) {
                break;
            }
            $fontSize -= 2;
        } while ($fontSize > $minFontSize);
    }
    
    // Dessiner le texte
    $lineHeight = $fontSize * 1.2;
    $totalTextHeight = count($lines) * $lineHeight;
    $startY = $bannerY + (($bannerHeight - $totalTextHeight) / 2) + ($fontSize * 0.8);

    // Calculer la largeur max du bloc texte pour les lignes décoratives
    $maxLineWidth = 0;
    foreach ($lines as $line) {
        $bbox = imagettfbbox($fontSize, 0, $fontFile, $line);
        $lw = $bbox[2] - $bbox[0];
        if ($lw > $maxLineWidth) $maxLineWidth = $lw;
    }
    $textBlockStartX = ($width - $maxLineWidth) / 2;
    $textBlockEndX   = $textBlockStartX + $maxLineWidth;
    $bannerCenterY   = (int)($bannerY + ($bannerHeight / 2));

    // Lignes décoratives flanquant le texte
    $decoConf = $config['decorLines'] ?? [];
    if (!empty($decoConf['enabled'])) {
        $decoMargin = $decoConf['margin']    ?? 28;
        $decoEdge   = $decoConf['edge']      ?? 40;
        $decoAlpha  = $decoConf['alpha']     ?? 50;
        $decoThick  = $decoConf['thickness'] ?? 2;
        $decoColor  = imagecolorallocatealpha($image, 255, 255, 255, $decoAlpha);
        imagesetthickness($image, $decoThick);
        imageline($image, $decoEdge, $bannerCenterY, (int)($textBlockStartX - $decoMargin), $bannerCenterY, $decoColor);
        imageline($image, (int)($textBlockEndX + $decoMargin), $bannerCenterY, $width - $decoEdge, $bannerCenterY, $decoColor);
        imagesetthickness($image, 1);
    }

    // Couleur ombre portée
    $shadowConf  = $config['shadow'] ?? [];
    $shadowAlpha = $shadowConf['alpha'] ?? 85;
    $shadowOx    = $shadowConf['offsetX'] ?? 3;
    $shadowOy    = $shadowConf['offsetY'] ?? 3;
    $shadowEnabled = !isset($shadowConf['enabled']) || $shadowConf['enabled'];
    $shadowColor = imagecolorallocatealpha($image, 0, 0, 0, $shadowAlpha);

    foreach ($lines as $i => $line) {
        $bbox = imagettfbbox($fontSize, 0, $fontFile, $line);
        $textWidth = $bbox[2] - $bbox[0];
        $x = ($width - $textWidth) / 2;
        $y = $startY + ($i * $lineHeight);

        // Drop shadow
        if ($shadowEnabled) {
            imagettftext($image, $fontSize, 0, (int)($x + $shadowOx), (int)($y + $shadowOy), $shadowColor, $fontFile, $line);
        }

        // Simuler différents poids de police
        $strokeWidth = 0;

        if ($fontWeight >= 700) {
            $strokeWidth = 2; // Bold
        } elseif ($fontWeight >= 600) {
            $strokeWidth = 1.5; // Semi-bold
        } elseif ($fontWeight >= 500) {
            $strokeWidth = 1; // Medium
        } elseif ($fontWeight >= 300) {
            $strokeWidth = 0.5; // Light avec légère épaisseur
        } elseif ($fontWeight < 300) {
            $strokeWidth = 0; // Thin - texte normal
        }

        // Dessiner le texte avec l'épaisseur appropriée
        if ($strokeWidth > 0) {
            for ($ox = 0; $ox <= $strokeWidth; $ox += 0.5) {
                for ($oy = 0; $oy <= $strokeWidth; $oy += 0.5) {
                    imagettftext($image, $fontSize, 0, (int)($x + $ox), (int)($y + $oy), $textColor, $fontFile, $line);
                }
            }
        } else {
            imagettftext($image, $fontSize, 0, (int)$x, (int)$y, $textColor, $fontFile, $line);
        }
    }

    // ── Engagement text ─────────────────────────────────────────────
    $engConf = $config['engagementText'] ?? [];
    if (!empty($engConf['enabled']) && !empty($engConf['text']) && $fontFile && file_exists($fontFile)) {

        $engRaw      = $engConf['text'];
        $engFontSize = $engConf['fontSize']      ?? 28;
        $engGap      = $engConf['gap']            ?? 42;
        $engStyle    = $engConf['style']          ?? 'pill';   // pill | lines | plain
        $engUpper    = !empty($engConf['uppercase']);           // default false
        $engSpacing  = (int)($engConf['letterSpacing'] ?? 3);  // spaces between chars

        // Normalise quotes/tirets mais garde les symboles (↓ etc.) — rendu via font fallback
        $engRaw = gd_normalize_text($engRaw);

        // Text transform
        if ($engUpper) $engRaw = mb_strtoupper($engRaw, 'UTF-8');
        if ($engSpacing > 0) {
            $chars  = preg_split('//u', $engRaw, -1, PREG_SPLIT_NO_EMPTY);
            $engRaw = implode(str_repeat(' ', $engSpacing), $chars);
        }

        $engW = _gd_mixed_width($engFontSize, $fontFile, $engRaw);
        $engY    = $bannerEndY + $engGap + $engFontSize;
        $engX    = (int)(($width - $engW) / 2);
        $engMidY = $engY - (int)($engFontSize * 0.4);   // vertical center of the line

        // Text color
        $engColorRgb = hexToRgb($engConf['color'] ?? '#ffffff');
        $engColor    = imagecolorallocate($image, $engColorRgb[0], $engColorRgb[1], $engColorRgb[2]);

        // ── Background commun (pill / lines / plain) ──────────────────
        $bgAlpha = $engConf['bgAlpha'] ?? 65;
        if (!empty($engConf['bgColor'])) {
            $bgRgb = hexToRgb($engConf['bgColor']);
        } else {
            $bgRgb = hexToRgb($config['banner']['color'] ?? '#000000');
        }
        $padH = $engConf['paddingH'] ?? 28;
        $padV = $engConf['paddingV'] ?? 10;
        $rx1  = $engX - $padH;
        $ry1  = $engY - $engFontSize - $padV;
        $rx2  = $engX + $engW + $padH;
        $ry2  = $engY + $padV + 4;

        $halfH = (int)(($ry2 - $ry1) / 2);
        if ($engStyle === 'pill') {
            $rad = isset($engConf['borderRadius']) ? (int)$engConf['borderRadius'] : $halfH;
            drawRoundedRectLayer($image, $rx1, $ry1, $rx2, $ry2, $rad, $bgRgb, $bgAlpha);
        } elseif ($engStyle === 'lines') {
            $rad = isset($engConf['borderRadius']) ? (int)$engConf['borderRadius'] : 12;
            drawRoundedRectLayer($image, $rx1, $ry1, $rx2, $ry2, $rad, $bgRgb, $bgAlpha);
        } else {
            // Plain : bande pleine largeur, borderRadius optionnel
            $rad = isset($engConf['borderRadius']) ? (int)$engConf['borderRadius'] : 0;
            drawRoundedRectLayer($image, 0, $ry1, $width, $ry2, $rad, $bgRgb, $bgAlpha);
        }

        // Thin border (tous styles)
        if (!empty($engConf['border'])) {
            $borderRgb   = hexToRgb($engConf['borderColor'] ?? '#ffffff');
            $borderColor = imagecolorallocatealpha($image, $borderRgb[0], $borderRgb[1], $borderRgb[2], 80);
            imagesetthickness($image, 1);
            imagerectangle($image, $rx1, $ry1, $rx2, $ry2, $borderColor);
        }

        // ── Style: lines ─────────────────────────────────────────────
        if ($engStyle === 'lines' || !empty($engConf['decorLines'])) {
            $lineAlpha = $engConf['lineAlpha'] ?? 55;
            $lineColor = imagecolorallocatealpha($image,
                $engColorRgb[0], $engColorRgb[1], $engColorRgb[2], $lineAlpha);
            $lineGap  = $engConf['lineGap']  ?? 16;
            $lineEdge = $engConf['lineEdge'] ?? 45;
            imagesetthickness($image, 1);
            imageline($image, $lineEdge, $engMidY, $engX - $lineGap, $engMidY, $lineColor);
            imageline($image, $engX + $engW + $lineGap, $engMidY, $width - $lineEdge, $engMidY, $lineColor);
        }

        // ── Drop shadow + Text (avec font fallback pour symboles non supportés) ──
        $engShadow = imagecolorallocatealpha($image, 0, 0, 0, 90);
        _gd_mixed_render($image, $engFontSize, $engX, $engY, $engColor, $fontFile, $engRaw, $engShadow);
    }
    // ── End engagement text ──────────────────────────────────────────

    // URL branding en bas de l'image
    $urlConf = $config['urlBranding'] ?? [];
    if (!isset($urlConf['enabled']) || $urlConf['enabled']) {
        $urlText     = $urlConf['text']      ?? HOST_NAME;
        $urlFontSize = $urlConf['fontSize']  ?? 30;
        $urlBgAlpha  = $urlConf['bgAlpha']   ?? 60;
        $urlTxtAlpha = $urlConf['textAlpha'] ?? 10;
        $urlGap      = $urlConf['bottomGap'] ?? 30;
        $urlBbox     = imagettfbbox($urlFontSize, 0, $fontFile, $urlText);
        $urlW        = $urlBbox[2] - $urlBbox[0];
        $urlX        = (int)(($width - $urlW) / 2);
        $urlY        = $height - $urlGap;
        $urlBgColor  = imagecolorallocatealpha($image, 0, 0, 0, $urlBgAlpha);
        imagefilledrectangle($image, $urlX - 20, $urlY - $urlFontSize - 6, $urlX + $urlW + 20, $urlY + 10, $urlBgColor);
        $urlColor = imagecolorallocatealpha($image, 255, 255, 255, $urlTxtAlpha);
        imagettftext($image, $urlFontSize, 0, $urlX, $urlY, $urlColor, $fontFile, $urlText);
    }

} else {
    // Fallback: utiliser une police système
    $fontSize = 5; // GD font size
    $x = ($width - (strlen($text) * imagefontwidth($fontSize))) / 2;
    $y = $bannerY + ($bannerHeight / 2);
    imagestring($image, $fontSize, $x, $y, $text, $textColor);
}
    
    if($config['slug']=="test"){
        $folder = $config['slug'];
    }else{
        $folder = $config['folder'] ?? 'posts';
    }
    $outputDir = __DIR__ . '/' . $folder . '/' . $config['slug'] . '/images/';
    if (!file_exists($outputDir)) {
        mkdir($outputDir, 0777, true);
    }

    $filename = $config['slug'].'_'.SITE_FOLDER.'_image_'.$config['index'].'.webp';
    $filepath = $outputDir . $filename;

    imagewebp($image, $filepath, 90);
    imagedestroy($image);

    $relativePath = $config['slug']."/images/".$filename;
    $fullPath     = $folder."/".$config['slug']."/images/".$filename;
    $fullUrl      = ($config['slug']==="test")
        ? $protocol.$host.$projectPath.$relativePath
        : $protocol.$host.$projectPath.$fullPath;

    echo json_encode([
        'success'      => true,
        'filename'     => $filename,
        'pathrelative' => $relativePath,
        'url'          => $fullUrl,
        'path'         => $fullPath
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Generation failed: ' . $e->getMessage()
    ]);
}

// Fonctions utilitaires

/**
 * Dessine un rectangle aux coins arrondis avec transparence correcte.
 * Utilise un layer off-screen pour éviter le double-rendu alpha aux coins.
 *
 * @param resource $dst    Canvas destination
 * @param int      $x1,$y1 Coin haut-gauche
 * @param int      $x2,$y2 Coin bas-droite
 * @param int      $r      Rayon des coins (0 = rectangle)
 * @param array    $rgb    [r, g, b]
 * @param int      $alpha  0=opaque … 127=transparent (échelle GD)
 */
function drawRoundedRectLayer($dst, $x1, $y1, $x2, $y2, $r, $rgb, $alpha) {
    $w = max(1, $x2 - $x1);
    $h = max(1, $y2 - $y1);
    $r = min($r, (int)($w / 2), (int)($h / 2));

    // Layer off-screen — blending OFF pour écriture directe pixel (pas de double-blend aux coins)
    $layer = imagecreatetruecolor($w, $h);
    imagealphablending($layer, false);
    imagesavealpha($layer, true);

    // Fond 100% transparent
    $transparent = imagecolorallocatealpha($layer, 0, 0, 0, 127);
    imagefill($layer, 0, 0, $transparent);

    // Couleur avec l'alpha voulu — écrite directement (blending=false → pas de cumul)
    $color = imagecolorallocatealpha($layer, $rgb[0], $rgb[1], $rgb[2], $alpha);

    if ($r > 0) {
        imagefilledrectangle($layer, $r,      0,      $w - $r, $h,      $color);
        imagefilledrectangle($layer, 0,       $r,     $w,      $h - $r, $color);
        imagefilledellipse(  $layer, $r,      $r,      $r * 2,  $r * 2,  $color);
        imagefilledellipse(  $layer, $w - $r, $r,      $r * 2,  $r * 2,  $color);
        imagefilledellipse(  $layer, $r,      $h - $r, $r * 2,  $r * 2,  $color);
        imagefilledellipse(  $layer, $w - $r, $h - $r, $r * 2,  $r * 2,  $color);
    } else {
        imagefilledrectangle($layer, 0, 0, $w, $h, $color);
    }

    // Copier sur le canvas — imagecopy respecte le canal alpha pixel par pixel
    imagealphablending($dst, true);
    imagecopy($dst, $layer, $x1, $y1, 0, 0, $w, $h);
    imagedestroy($layer);
}

function hexToRgb($hex) {
    $hex = ltrim($hex, '#');
    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2))
    ];
}

function loadImage($url) {
    if (empty($url)) return false;

    // Chemin local — lecture directe
    if (file_exists($url)) {
        $data = file_get_contents($url);
    } else {
        // URL distante — téléchargement via curl binary (évite allow_url_fopen)
        $curlBin = trim(shell_exec('which curl 2>/dev/null') ?: '/usr/bin/curl');
        $cmd  = sprintf('%s -s --max-time 30 --connect-timeout 10 -L --http1.1 %s 2>/dev/null',
            $curlBin, escapeshellarg($url));
        $data = shell_exec($cmd);
    }

    if (empty($data)) return false;
    $img = @imagecreatefromstring($data);
    return $img ?: false;
}

function drawImageCover($canvas, $img, $x, $y, $targetWidth, $targetHeight) {
    $imgWidth = imagesx($img);
    $imgHeight = imagesy($img);
    
    $imgRatio = $imgWidth / $imgHeight;
    $targetRatio = $targetWidth / $targetHeight;
    
    if ($imgRatio > $targetRatio) {
        $newHeight = $targetHeight;
        $newWidth = $imgWidth * ($targetHeight / $imgHeight);
        $offsetX = ($targetWidth - $newWidth) / 2;
        $offsetY = 0;
    } else {
        $newWidth = $targetWidth;
        $newHeight = $imgHeight * ($targetWidth / $imgWidth);
        $offsetX = 0;
        $offsetY = ($targetHeight - $newHeight) / 2;
    }
    
    imagecopyresampled(
        $canvas, $img,
        $x + $offsetX, $y + $offsetY,
        0, 0,
        $newWidth, $newHeight,
        $imgWidth, $imgHeight
    );
}

function downloadFont($fontUrl, $fontWeight = 400) {
    if (empty($fontUrl)) {
        return false;
    }
    
    // Extraire le nom de la police
    preg_match('/family=([^&:]+)/', $fontUrl, $matches);
    if (!$matches) {
        return false;
    }
    
    $fontName = str_replace('+', '', $matches[1]);
    $fontDir = __DIR__ . '/fonts/';
    
    if (!file_exists($fontDir)) {
        mkdir($fontDir, 0777, true);
    }
    
    // Si le poids n'est pas standard, utiliser 400 par défaut
    $validWeights = [100, 200, 300, 400, 500, 600, 700, 800, 900];
    if (!in_array($fontWeight, $validWeights)) {
        $fontWeight = 400;
    }
    
    // Créer un nom de fichier unique pour chaque poids
    $fontFile = $fontDir . $fontName . '_' . $fontWeight . '.ttf';
    
    // Si la police existe déjà, la retourner
    if (file_exists($fontFile)) {
        return $fontFile;
    }
    
    // Sinon, essayer de télécharger d'abord la version 400 (regular)
    $fontFileRegular = $fontDir . $fontName . '_400.ttf';
    
    // Télécharger la version regular si elle n'existe pas
    if (!file_exists($fontFileRegular)) {
        try {
            $baseUrl = preg_replace('/family=([^&:]+).*/', 'family=$1', $fontUrl);
            
            $context = stream_context_create([
                'http' => [
                    'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
                ]
            ]);
            
            $css = @file_get_contents($baseUrl, false, $context);
            
            if ($css && preg_match('/url\((https:\/\/[^)]+\.ttf)\)/', $css, $ttfMatches)) {
                $fontContent = @file_get_contents($ttfMatches[1], false, $context);
                if ($fontContent) {
                    file_put_contents($fontFileRegular, $fontContent);
                }
            }
        } catch (Exception $e) {
            // Ignorer les erreurs
        }
    }
    
    // Retourner la police regular (poids 400) si disponible
    if (file_exists($fontFileRegular)) {
        return $fontFileRegular;
    }
    
    return false;
}

function wrapText($text, $fontFile, $fontSize, $maxWidth) {
    $words = explode(' ', $text);
    $lines = [];
    $currentLine = '';
    
    foreach ($words as $word) {
        $testLine = $currentLine . ($currentLine ? ' ' : '') . $word;
        $bbox = imagettfbbox($fontSize, 0, $fontFile, $testLine);
        $width = $bbox[2] - $bbox[0];
        
        if ($width > $maxWidth && $currentLine !== '') {
            $lines[] = trim($currentLine);
            $currentLine = $word;
        } else {
            $currentLine = $testLine;
        }
    }
    
    if ($currentLine) {
        $lines[] = trim($currentLine);
    }
    
    return $lines;
}
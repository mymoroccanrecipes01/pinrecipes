<?php
// generate-csv.php — même logique CSV que la fin de auto-daily-csv.php (5 fichiers, 1 par groupe template)
require_once 'config.php';
require_once __DIR__ . '/auth.php';
auth_check();

header('Content-Type: application/json');

$csvDateInput = isset($_POST['csv_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['csv_date'])
    ? $_POST['csv_date'] : date('Y-m-d');

$_pSettings = file_exists(__DIR__ . '/settings.json') ? (json_decode(file_get_contents(__DIR__ . '/settings.json'), true) ?? []) : [];
$_linkPinDefault = isset($_pSettings['linkPinActive']) ? (bool)$_pSettings['linkPinActive'] : (defined('LINK_PIN_ACTIVE') && LINK_PIN_ACTIVE);
$linkActive = isset($_POST['linkPinToggle']) ? ($_POST['linkPinToggle'] === '1') : $_linkPinDefault;

// Base URL images — raw GitHub si disponible, sinon HOST_NAME
$rawImageBase = 'https://' . HOST_NAME;
if (defined('GITHUB_REPO') && defined('BRANCH')) {
    $_branch      = BRANCH ?: 'main';
    $_repo        = preg_replace('#^https://github\.com/#', '', rtrim(GITHUB_REPO, '/'));
    $_repo        = preg_replace('/\.git$/', '', $_repo);
    $rawImageBase = 'https://raw.githubusercontent.com/' . $_repo . '/refs/heads/' . $_branch;
}
$imageBase = $linkActive ? ('https://' . HOST_NAME) : $rawImageBase;

// ── Catégories ────────────────────────────────────────────────────────────────
$catIndexPath = __DIR__ . '/categories/index.json';
$catIndex     = file_exists($catIndexPath) ? (json_decode(file_get_contents($catIndexPath), true) ?? []) : [];
$catIdToSlug  = [];
if (!empty($catIndex['folders'])) {
    foreach ($catIndex['folders'] as $slug => $id) $catIdToSlug[$id] = $slug;
}

// ── Slugs filtrés (optionnel — depuis checkbox sélection page) ───────────────
$filterSlugs = [];
if (!empty($_POST['slugs']) && is_array($_POST['slugs'])) {
    $filterSlugs = array_filter(array_map(
        fn($s) => preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($s))),
        $_POST['slugs']
    ));
}

// ── Charger les articles (filtrés si slugs fournis, sinon tous online) ────────
$postsDir = __DIR__ . '/posts';
$selected = [];
foreach (glob($postsDir . '/*/post.json') as $jsonPath) {
    $post = json_decode(file_get_contents($jsonPath), true);
    if (!$post) continue;

    $slug = basename(dirname($jsonPath));

    // Si slugs fournis : prendre uniquement ceux-là (pas besoin d'isOnline)
    // Sinon : prendre tous les articles en ligne
    if (!empty($filterSlugs)) {
        if (!in_array($slug, $filterSlugs)) continue;
    } else {
        if (!($post['isOnline'] ?? false)) continue;
    }

    $templates = array_values(array_filter(
        $post['images'] ?? [],
        fn($i) => in_array($i['type'] ?? '', ['template', 'recipe_card', 'overlay_list'])
    ));
    if (count($templates) < 4) continue;

    shuffle($templates);
    $post['_slug']      = $slug;
    $post['_templates'] = array_values($templates);
    $selected[]         = $post;
}

if (empty($selected)) {
    $msg = !empty($filterSlugs)
        ? 'Aucun article sélectionné n\'a 4 templates générés'
        : 'Aucun article en ligne avec templates';
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

if (empty($filterSlugs)) shuffle($selected);

// ── CSV helpers (identiques à auto-daily-csv) ─────────────────────────────────
function csvField($value) {
    $value = preg_replace('/[\r\n\v\f\x{0085}\x{2028}\x{2029}]+/u', ' ', (string)$value);
    $value = str_replace('"', '""', $value);
    return '"' . trim($value) . '"';
}
function csvText($value) {
    $value = preg_replace('/[\r\n\v\f\x{0085}\x{2028}\x{2029}]+/u', ' ', (string)$value);
    $value = str_replace(['"', ','], '', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return '"' . trim($value) . '"';
}

// ── Même boucle que la fin de auto-daily-csv : 5 fichiers, 1 par groupe template ──
$today    = new DateTime($csvDateInput);
$header   = 'Title,Media URL,Pinterest board,Thumbnail,Description,Link,Publish date,Keywords';
$csvFiles = [];

foreach ([0, 1, 2, 3, 4] as $weekIdx) {
    $groupDate    = clone $today;
    $groupDate->modify('+' . ($weekIdx * CSV_PUBLISH_SPACING_DAYS) . ' days');
    $groupDateStr = $groupDate->format('Y-m-d');
    $lines        = [$header];       // image pins
    $videoLines   = [$header];       // video pins — fichier séparé
    $currentMinute = 16 * 60;
    $seenTitles   = [];

    foreach ($selected as $post) {
        $templates = $post['_templates'];
        if (!isset($templates[$weekIdx])) continue;
        $template = $templates[$weekIdx];
        $slug     = $post['_slug'];

        // Title + Description depuis pin_variations si disponible
        $variations = $post['pin_variations'] ?? [];
        if (!empty($variations[$weekIdx])) {
            $title       = $variations[$weekIdx]['title']       ?? $post['title'] ?? '';
            $description = $variations[$weekIdx]['description'] ?? $post['description'] ?? '';
        } else {
            $title       = $post['title']       ?? '';
            $description = $post['description'] ?? '';
        }

        // Strip engagement CTAs avant extraction hashtags (évite #FamilyDinnerSAVE etc.)
        $description = trim(preg_replace('/\b(SAVE FOR LATER|FOR LATER|PIN IT|PIN THIS|BOOKMARK)\b\.?/i', '', $description));
        $description = trim(preg_replace('/\s{2,}/', ' ', $description));

        // Keywords depuis hashtags
        $keywords = '';
        if (preg_match_all('/#(\w+)/', $description, $matches)) {
            $rawTags     = $matches[1];
            $description = preg_replace('/#\w+/', '', $description);
            $description = trim(preg_replace('/\s+/', ' ', $description));
            $cleanTags   = array_filter(array_map(fn($t) => preg_replace('/(SAVE|LATER|PINIT|CLICK|BOOKMARK|TRYIT|MAKEIT|GRABIT)$/i', '', $t), $rawTags), fn($t) => strlen($t) >= 3);
            $keywords    = implode(', ', $cleanTags);
        } else {
            $rawHashtags = is_array($post['hashtags'] ?? null)
                ? implode(' ', $post['hashtags'])
                : ($post['hashtags'] ?? '');
            if (preg_match_all('/#(\w+)/', $rawHashtags, $hm)) {
                $cleanTags = array_filter(array_map(fn($t) => preg_replace('/(SAVE|LATER|PINIT|CLICK|BOOKMARK|TRYIT|MAKEIT|GRABIT)$/i', '', $t), $hm[1]), fn($t) => strlen($t) >= 3);
                $keywords  = implode(', ', $cleanTags);
            }
        }

        // Limites + déduplication titre
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

        // Media URL — raw GitHub (image doit être publique)
        $mediaUrl = $rawImageBase . '/posts/' . $slug . '/images/' . $template['fileName'];

        // Board
        $templateKey     = $template['template'] ?? 'classic';
        $pinterestBoards = $post['pinterest_boards'] ?? [];
        $rawBoard = $pinterestBoards[$templateKey]
            ?? $pinterestBoards['classic']
            ?? $post['board_name']
            ?? '';
        $boardName = '';
        if (!empty($rawBoard)) {
            $boardName = trim($rawBoard); // nom exact Pinterest (espaces + casse préservés)
        } elseif (!empty($post['category_id'])) {
            $catSlug   = $catIdToSlug[$post['category_id']] ?? '';
            $boardName = ucwords(str_replace('-', ' ', $catSlug));
        }
        if (empty($boardName)) $boardName = 'posts';

        // Link
        preg_match('/_image_(\d+)/', $template['fileName'] ?? '', $imgMatch);
        $imgNum   = $imgMatch[1] ?? ($weekIdx + 1);
        $tplType  = $template['type'] ?? 'template';
        $isNoLink = ($tplType === 'recipe_card'  && !_cfg('recipe_card_LINK_ACTIVE',  false))
                 || ($tplType === 'overlay_list' && !_cfg('overlay_list_LINK_ACTIVE', false))
                 || (!in_array($tplType, ['recipe_card', 'overlay_list']) && !empty($template['no_link']));
        $link     = ($linkActive && !$isNoLink)
            ? $imageBase . '/posts/' . $slug . '/?src=' . $slug . '-image-' . $imgNum
            : '';

        // Publish date — ~1h entre chaque pin depuis 16:00 (identique à auto)
        $minutesFromMidnight = $currentMinute + rand(0, 15);
        $currentMinute      += 60 + rand(-10, 10);
        $isNextDay           = $minutesFromMidnight >= 1440;
        $actualDate          = clone $groupDate;
        if ($isNextDay) $actualDate->modify('+1 day');
        $h           = (int)(($minutesFromMidnight % 1440) / 60);
        $m           = $minutesFromMidnight % 60;
        $publishDate = $actualDate->format('Y-m-d') . 'T' . sprintf('%02d:%02d:00', $h, $m);

        $lines[] = implode(',', [
            csvText($title),
            csvField($mediaUrl),
            csvField($boardName),
            '',              // Thumbnail vide pour image pin (Media URL = l'image)
            csvText($description),
            csvField($link),
            csvField($publishDate),
            csvField($keywords),
        ]);

        // ── Video pin : uniquement sur le 1er groupe, si reel MP4 existe ─────────
        if ($weekIdx === 0 && defined('PINTEREST_VIDEO_PINS_ACTIVE') && PINTEREST_VIDEO_PINS_ACTIVE) {
            $_reelPath = $postsDir . '/' . $slug . '/images/' . $slug . '_reel.mp4';
            if (file_exists($_reelPath)) {
                // Lire l'URL depuis constante ou site-config.json directement
                if (defined('PINTEREST_VIDEO_BASE_URL') && PINTEREST_VIDEO_BASE_URL) {
                    $_vBase = PINTEREST_VIDEO_BASE_URL;
                } else {
                    $_sc    = json_decode(@file_get_contents(__DIR__ . '/site-config.json'), true) ?: [];
                    $_vBase = rtrim(trim($_sc['PINTEREST_VIDEO_BASE_URL'] ?? ''), '/') ?: ('https://' . HOST_NAME);
                }
                // IP brute → forcer HTTP (pas de cert SSL valide sur IP)
                if (preg_match('/^https?:\/\/\d+\.\d+\.\d+\.\d+/', $_vBase)) {
                    $_vBase = preg_replace('/^https:\/\//', 'http://', $_vBase);
                }
                $videoUrl  = rtrim($_vBase, '/') . '/posts/' . $slug . '/images/' . $slug . '_reel.mp4';
                $vMin      = $currentMinute + rand(5, 20);
                $currentMinute += 60 + rand(-10, 10);
                $vDate     = clone $groupDate;
                if ($vMin >= 1440) $vDate->modify('+1 day');
                $vh = (int)(($vMin % 1440) / 60); $vm_ = $vMin % 60;
                $videoDate = $vDate->format('Y-m-d') . 'T' . sprintf('%02d:%02d:00', $vh, $vm_);

                $videoLines[] = implode(',', [
                    csvText($title),
                    csvField($videoUrl),   // Media URL = MP4
                    csvField($boardName),
                    '',                    // Thumbnail vide — Pinterest auto-génère depuis 1ère frame
                    csvText($description),
                    csvField($link),
                    csvField($videoDate),
                    csvField($keywords),
                ]);
            }
        }
    }

    if (count($lines) > 1) {
        $csvFiles[] = [
            'filename' => 'pinterest_' . $groupDateStr . '.csv',
            'content'  => implode("\r\n", $lines),
            'rows'     => count($lines) - 1,
        ];
    }
    if ($weekIdx === 0 && count($videoLines) > 1) {
        $vMax = defined('PINTEREST_VIDEO_DAILY_MAX') ? (int)PINTEREST_VIDEO_DAILY_MAX : 5;
        if ($vMax > 0 && count($videoLines) - 1 > $vMax) {
            $videoLines = array_merge([$videoLines[0]], array_slice($videoLines, 1, $vMax));
        }
        $csvFiles[] = [
            'filename' => 'pinterest_' . $groupDateStr . '_reels.csv',
            'content'  => implode("\r\n", $videoLines),
            'rows'     => count($videoLines) - 1,
        ];
    }
}

// ── Sauvegarder dans downloads/ sans écraser les fichiers existants ───────────
$outDir = __DIR__ . '/downloads';
if (!is_dir($outDir)) mkdir($outDir, 0755, true);
foreach ($csvFiles as &$f) {
    $base = pathinfo($f['filename'], PATHINFO_FILENAME);
    $dest = $outDir . '/' . $f['filename'];
    $n    = 2;
    while (file_exists($dest)) {
        $dest = $outDir . '/' . $base . '_' . $n . '.csv';
        $n++;
    }
    file_put_contents($dest, $f['content']);
    $f['filename'] = basename($dest);
}
unset($f);

$totalRows = array_sum(array_column($csvFiles, 'rows'));

echo json_encode([
    'success'   => true,
    'articles'  => count($selected),
    'files'     => array_map(fn($f) => [
        'filename' => $f['filename'],
        'rows'     => $f['rows'],
    ], $csvFiles),
    'totalRows' => $totalRows,
    'filenames' => array_column($csvFiles, 'filename'),
    'message'   => count($selected) . ' articles → ' . $totalRows . ' pins dans ' . count($csvFiles) . ' fichiers CSV',
]);

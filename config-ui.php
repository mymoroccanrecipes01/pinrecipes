<?php
require_once 'config.php';
require_once __DIR__ . '/auth.php';
auth_check();

$configFile   = __DIR__ . '/site-config.json';
$promptsFile  = __DIR__ . '/prompts.json';
$settingsFile = __DIR__ . '/settings.json';
$saved = isset($_GET['saved']);
$error = '';
$cssUploadMsg = '';

// Keys currently overridden in site-config.json
$_ovr = file_exists($configFile) ? (json_decode(file_get_contents($configFile), true) ?? []) : [];

// ── Upload ads.txt + Pinterest claim HTML ─────────────────────────────────────
$uploadFileMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_verify_files'])) {

    // --- ads.txt ---
    $adsFile = $_FILES['ads_txt_file'] ?? null;
    if ($adsFile && $adsFile['error'] === UPLOAD_ERR_OK) {
        // Extension check
        if (strtolower(pathinfo($adsFile['name'], PATHINFO_EXTENSION)) !== 'txt') {
            $uploadFileMsg .= '<div style="color:#dc2626">❌ ads.txt : extension invalide (seul .txt accepté)</div>';
        } elseif ($adsFile['size'] > 512 * 1024) {
            $uploadFileMsg .= '<div style="color:#dc2626">❌ ads.txt : fichier trop grand (max 512 Ko)</div>';
        } else {
            $content = file_get_contents($adsFile['tmp_name']);
            // Strip tout code PHP/script — sécurité
            $content = preg_replace('/<\?.*?\?>/s', '', $content);
            $content = preg_replace('/<script.*?<\/script>/si', '', $content);
            file_put_contents(__DIR__ . '/ads.txt', $content);
            $uploadFileMsg .= '<div style="color:#16a34a">✅ ads.txt uploadé</div>';
        }
    }

    // --- Pinterest claim HTML ---
    $pinFile = $_FILES['pinterest_html_file'] ?? null;
    if ($pinFile && $pinFile['error'] === UPLOAD_ERR_OK) {
        $ext      = strtolower(pathinfo($pinFile['name'], PATHINFO_EXTENSION));
        $basename = pathinfo($pinFile['name'], PATHINFO_BASENAME);
        // Filename doit matcher pinterest-XXXXX.html
        if ($ext !== 'html' || !preg_match('/^pinterest-[a-zA-Z0-9]+\.html$/', $basename)) {
            $uploadFileMsg .= '<div style="color:#dc2626">❌ Pinterest HTML : nom invalide — doit être <b>pinterest-XXXXX.html</b></div>';
        } elseif ($pinFile['size'] > 64 * 1024) {
            $uploadFileMsg .= '<div style="color:#dc2626">❌ Pinterest HTML : fichier trop grand (max 64 Ko)</div>';
        } else {
            $content = file_get_contents($pinFile['tmp_name']);
            // Interdire tout code PHP et scripts
            if (preg_match('/<\?php|<\?=/i', $content)) {
                $uploadFileMsg .= '<div style="color:#dc2626">❌ Pinterest HTML : contenu PHP interdit</div>';
            } else {
                $content = preg_replace('/<script(?!.*application\/ld\+json).*?<\/script>/si', '', $content);
                $dest = __DIR__ . '/' . $basename;
                file_put_contents($dest, $content);
                $uploadFileMsg .= '<div style="color:#16a34a">✅ ' . htmlspecialchars($basename) . ' uploadé</div>';
            }
        }
    }
}

// ── Upload CSS ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_css'])) {
    $f = $_FILES['custom_css_file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        $cssUploadMsg = '<span style="color:#dc2626">Erreur upload (code ' . ($f['error'] ?? '?') . ')</span>';
    } elseif (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'css') {
        $cssUploadMsg = '<span style="color:#dc2626">Fichier invalide — seuls les .css sont acceptés.</span>';
    } else {
        $destName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($f['name'], PATHINFO_FILENAME)) . '.css';
        $dest = __DIR__ . '/' . $destName;
        if (move_uploaded_file($f['tmp_name'], $dest)) {
            // Auto-apply: update site-config.json + base.html
            $cfg = file_exists($configFile) ? (json_decode(file_get_contents($configFile), true) ?? []) : [];
            $cfg['SITE_CSS'] = $destName;
            file_put_contents($configFile, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $baseHtml = __DIR__ . '/base.html';
            if (file_exists($baseHtml)) {
                $bc = file_get_contents($baseHtml);
                $bc = preg_replace(
                    '/(<link[^>]+rel=["\']stylesheet["\'][^>]+href=["\'])[^"\']+\.css(["\'][^>]*>)/i',
                    '${1}' . $destName . '${2}', $bc
                );
                file_put_contents($baseHtml, $bc);
            }
            header('Location: ' . $_SERVER['PHP_SELF'] . '?saved=1&css_uploaded=' . urlencode($destName));
            exit;
        } else {
            $cssUploadMsg = '<span style="color:#dc2626">Impossible d\'écrire le fichier sur le serveur.</span>';
        }
    }
}

// ── Upload logo / favicon / author image + nav labels ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_identity'])) {
    $baseHtml = __DIR__ . '/base.html';
    $bh = file_exists($baseHtml) ? file_get_contents($baseHtml) : '';

    // Logo
    if (!empty($_FILES['site_logo']['tmp_name']) && $_FILES['site_logo']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['site_logo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['svg', 'png', 'jpg', 'jpeg', 'webp'])) {
            $dest = __DIR__ . '/assets/logo.' . $ext;
            move_uploaded_file($_FILES['site_logo']['tmp_name'], $dest);
            $bh = preg_replace('/(src="assets\/logo\.)[^"]+(")/i', '${1}' . $ext . '${2}', $bh);
        }
    }

    // Favicon
    if (!empty($_FILES['site_favicon']['tmp_name']) && $_FILES['site_favicon']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['site_favicon']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['png', 'svg', 'ico', 'jpg', 'jpeg', 'webp'])) {
            if ($ext === 'svg') {
                move_uploaded_file($_FILES['site_favicon']['tmp_name'], __DIR__ . '/assets/favicons/icon.svg');
            } elseif ($ext === 'ico') {
                move_uploaded_file($_FILES['site_favicon']['tmp_name'], __DIR__ . '/favicon.ico');
            } else {
                $src = $_FILES['site_favicon']['tmp_name'];
                copy($src, __DIR__ . '/assets/favicons/favicon-196x196.png');
                copy($src, __DIR__ . '/assets/favicons/favicon-48x48.png');
            }
        }
    }

    // Author image + name + description
    $authorsFile = __DIR__ . '/authors/authors.json';
    $authors = file_exists($authorsFile) ? (json_decode(file_get_contents($authorsFile), true) ?? []) : [];
    if (empty($authors)) $authors = [['id' => 'author_001', 'name' => '', 'description' => '', 'imagePath' => 'authors/images/author.jpg', 'active' => true]];

    if (!empty($_FILES['author_image']['tmp_name']) && $_FILES['author_image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['author_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            if (!is_dir(__DIR__ . '/authors/images')) @mkdir(__DIR__ . '/authors/images', 0755, true);
            $dest = __DIR__ . '/authors/images/author.' . $ext;
            move_uploaded_file($_FILES['author_image']['tmp_name'], $dest);
            foreach ($authors as &$a) { $a['imagePath'] = 'authors/images/author.' . $ext; }
            unset($a);
        }
    }
    $authorName = trim($_POST['author_name'] ?? '');
    $authorDesc = trim($_POST['author_description'] ?? '');
    foreach ($authors as &$a) {
        if ($authorName !== '') $a['name'] = $authorName;
        if ($authorDesc !== '') $a['description'] = $authorDesc;
    }
    unset($a);
    file_put_contents($authorsFile, json_encode($authors, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    // Nav labels — header nav: text after </svg>
    $headerNav = [
        'posts'           => trim($_POST['nav_h_posts']    ?? ''),
        'posts-category'  => trim($_POST['nav_h_category'] ?? ''),
        'about'           => trim($_POST['nav_h_about']    ?? ''),
        'contact'         => trim($_POST['nav_h_contact']  ?? ''),
    ];
    foreach ($headerNav as $page => $newLabel) {
        if ($newLabel === '') continue;
        $newLabel = htmlspecialchars(strip_tags($newLabel), ENT_QUOTES);
        $bh = preg_replace(
            '/(<a href="\?page=' . preg_quote($page, '/') . '">[^<]*(?:<[^>]*>)*<\/svg>)\s*[^<]+\s*(<\/a>)/',
            '$1 ' . $newLabel . '$2',
            $bh
        );
    }

    // Footer nav labels — identified by alt attribute
    $footerNav = [
        'Home'     => trim($_POST['nav_f_home']     ?? ''),
        'Writers'  => trim($_POST['nav_f_writers']  ?? ''),
        'Topics'   => trim($_POST['nav_f_topics']   ?? ''),
        'Keywords' => trim($_POST['nav_f_keywords'] ?? ''),
        'Favorites'=> trim($_POST['nav_f_favorites']?? ''),
        'Discover' => trim($_POST['nav_f_discover'] ?? ''),
        'Posts'    => trim($_POST['nav_f_posts']    ?? ''),
        'Recipes'  => trim($_POST['nav_f_recipes']  ?? ''),
        'About Us' => trim($_POST['nav_f_about']    ?? ''),
        'Privacy'  => trim($_POST['nav_f_privacy']  ?? ''),
    ];
    foreach ($footerNav as $alt => $newLabel) {
        if ($newLabel === '') continue;
        $newLabel = htmlspecialchars(strip_tags($newLabel), ENT_QUOTES);
        $bh = preg_replace(
            '/(alt="' . preg_quote($alt, '/') . '">)[^<]*(<\/a>)/',
            '${1}' . $newLabel . '${2}',
            $bh
        );
    }

    if ($bh) file_put_contents($baseHtml, $bh);
    header('Location: ' . $_SERVER['PHP_SELF'] . '?saved=1&identity=1');
    exit;
}

// ── Save Pinterest Profiles ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profiles'])) {
    $profilesFile = __DIR__ . '/profiles.json';  // local au site — un seul profil
    $existing     = file_exists($profilesFile) ? (json_decode(file_get_contents($profilesFile), true) ?? []) : [];

    $thisEntry = [
        'label'             => trim($_POST['pf_label']       ?? ''),
        'profile'           => trim($_POST['pf_profile']     ?? ''),
        'adspower_id'       => trim($_POST['pf_adspower_id'] ?? ''),
        'pin_url'           => trim($_POST['pf_pin_url']     ?? 'https://www.pinterest.com/settings/bulk-create-pins/'),
        'site_url'          => 'https://' . (defined('HOST_NAME') ? HOST_NAME : ''),
        'browser'           => trim($_POST['pf_browser']      ?? ($existing['browser']      ?? 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe')),
        'browser_type'      => trim($_POST['pf_browser_type'] ?? ($existing['browser_type'] ?? 'edge')),
        'delay_seconds'     => max(0, (int)($_POST['pf_delay']   ?? ($existing['delay_seconds']   ?? 20))),
        'max_wait_seconds'  => max(0, (int)($_POST['pf_maxwait'] ?? ($existing['max_wait_seconds'] ?? 50))),
        'post_upload_delay' => max(0, (int)($_POST['pf_postdel'] ?? ($existing['post_upload_delay'] ?? 15))),
    ];

    $pfError = '';
    $ok = file_put_contents($profilesFile, json_encode($thisEntry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    if ($ok === false) {
        $pfError = 'Impossible d\'écrire profiles.json — ' . $profilesFile;
    } else {
        // Auto-régénérer le .bat
        $genScript = dirname(__DIR__) . '/publish-pinterest-gen.php';
        if (file_exists($genScript)) {
            ob_start(); include $genScript; $batContent = ob_get_clean();
            $batContent = ltrim($batContent, "\xEF\xBB\xBF"); // strip BOM
            file_put_contents(dirname(__DIR__) . '/publish-pinterest-generated.bat', $batContent);
        }
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '?pf_saved=1' . ($pfError ? '&pf_error=' . urlencode($pfError) : '') . '#section-profiles');
    exit;
}

// ── Save ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    // Satellites are now saved via dedicated AJAX (config-api.php?action=save_satellites)
    // — do not touch SATELLITE_PROJECTS here to avoid accidental overwrites.

    // Helper: keep existing sensitive value if new one is empty.
    // Priority: POST value → site-config.json → current constant (config.php default)
    $keepIfEmpty = function(string $key, string $postVal): string {
        $v = trim($postVal);
        if ($v !== '') return $v;
        if (!empty($_ovr[$key])) return $_ovr[$key];
        return defined($key) ? (string)constant($key) : '';
    };

    $newConfig = [
        'GIT_MODE'                  => ($_POST['GIT_MODE'] ?? 'https') === 'ssh' ? 'ssh' : 'https',
        'GITHUB_USER'               => trim($_POST['GITHUB_USER']   ?? ''),
        'GITHUB_PASSWORD'           => $keepIfEmpty('GITHUB_PASSWORD', $_POST['GITHUB_PASSWORD'] ?? ''),
        'BRANCH'                    => trim($_POST['BRANCH']      ?? 'main'),
        'GITHUB_REPO'               => trim($_POST['GITHUB_REPO'] ?? ''),
        'SSH_KEY'                   => $keepIfEmpty('SSH_KEY', $_POST['SSH_KEY'] ?? ''),
        'ANTHROPIC_API_KEY'         => $keepIfEmpty('ANTHROPIC_API_KEY', $_POST['ANTHROPIC_API_KEY'] ?? ''),
        'OPENAI_API_KEY'            => $keepIfEmpty('OPENAI_API_KEY', $_POST['OPENAI_API_KEY'] ?? ''),
        'GENERATION_API'            => trim($_POST['GENERATION_API'] ?? 'openai'),
        'ANTHROPIC_MODEL'           => trim($_POST['ANTHROPIC_MODEL'] ?? ''),
        'OPENAI_CONTENT_MODEL'      => trim($_POST['OPENAI_CONTENT_MODEL'] ?? ''),
        'OPENAI_CONTENT_MAX_TOKENS' => (int)($_POST['OPENAI_CONTENT_MAX_TOKENS'] ?? 12000),
        'OPENAI_IMAGE_MODEL'        => trim($_POST['OPENAI_IMAGE_MODEL'] ?? ''),
        'OPENAI_IMAGE_QUALITY'      => trim($_POST['OPENAI_IMAGE_QUALITY'] ?? 'medium'),
        'OPENAI_IMAGE_SIZE'         => trim($_POST['OPENAI_IMAGE_SIZE'] ?? '1024x1536'),
        'OPENAI_IMAGE_COST'         => (float)($_POST['OPENAI_IMAGE_COST'] ?? 0.015),
        'HOST_NAME'                 => trim($_POST['HOST_NAME']        ?? ''),
        'BASE_URL'                  => trim($_POST['BASE_URL']         ?? ''),
        'HOMEPAGE_TITLE'            => trim($_POST['HOMEPAGE_TITLE']   ?? 'Pin Posts'),
        'HOMEPAGE_TAGLINE'          => trim($_POST['HOMEPAGE_TAGLINE'] ?? ''),
        'SITE_LANGUAGE'             => trim($_POST['SITE_LANGUAGE']    ?? 'en-US'),
        'SITE_CSS'                  => trim($_POST['SITE_CSS']      ?? 'style.css'),
        // SATELLITE_PROJECTS managed via config-api.php?action=save_satellites (AJAX, not form POST)
        'FLIP'                      => isset($_POST['FLIP']),
        'IMG1'                      => (int)($_POST['IMG1'] ?? 1),
        'IMG2'                      => (int)($_POST['IMG2'] ?? 2),
        'ZOOM'                      => trim($_POST['ZOOM'] ?? '1'),
        'POST_PROMPT'               => $_POST['POST_PROMPT']         ?? '',
        'REWRITE_POST_PROMPT'       => $_POST['REWRITE_POST_PROMPT'] ?? '',
        'IMG_PROMPT_1'              => $_POST['IMG_PROMPT_1']        ?? '',
        'IMG_PROMPT_2'              => $_POST['IMG_PROMPT_2']        ?? '',
        'IMG_PROMPT_3'              => $_POST['IMG_PROMPT_3']        ?? '',
        'PINTEREST_CSV_PROMPT'      => $_POST['PINTEREST_CSV_PROMPT']  ?? '',
        // Template
        'ACTIVE_TEMPLATE'           => trim($_POST['ACTIVE_TEMPLATE'] ?? 'classic'),
        'TEMPLATE_CANVAS_HEIGHT'    => (int)($_POST['TEMPLATE_CANVAS_HEIGHT'] ?? 2000),
        'TEMPLATE_BANNER_Y'         => (int)($_POST['TEMPLATE_BANNER_Y']      ?? 850),
        'TEMPLATE_BANNER_HEIGHT'    => (int)($_POST['TEMPLATE_BANNER_HEIGHT'] ?? 270),
        'TEMPLATE_BANNER_COLOR'     => trim($_POST['TEMPLATE_BANNER_COLOR']   ?? '#93043dff'),
        'TEMPLATE_TEXT_COLOR'       => trim($_POST['TEMPLATE_TEXT_COLOR']     ?? '#ffffffff'),
        'TEMPLATE_FONT_SIZE'        => (int)($_POST['TEMPLATE_FONT_SIZE']     ?? 95),
        'TEMPLATE_FONT_FAMILY'      => trim($_POST['TEMPLATE_FONT_FAMILY']    ?? '"Akaya Kanadaka", system-ui'),
        'TEMPLATE_FONT_URL'         => trim($_POST['TEMPLATE_FONT_URL']       ?? 'https://fonts.googleapis.com/css2?family=Akaya+Kanadaka&display=swap'),
        'TEMPLATE_IMG1_HEIGHT'      => (int)($_POST['TEMPLATE_IMG1_HEIGHT']   ?? 1120),
        'TEMPLATE_IMG2_Y'           => (int)($_POST['TEMPLATE_IMG2_Y']        ?? 1000),
        'TEMPLATE_IMG2_HEIGHT'      => (int)($_POST['TEMPLATE_IMG2_HEIGHT']   ?? 1000),
        'TEMPLATE_BG_COLOR'         => trim($_POST['TEMPLATE_BG_COLOR']       ?? '#F5E6D3'),
        'TEMPLATE_DECOR_LINES'      => isset($_POST['TEMPLATE_DECOR_LINES']),
        // Engagement text
        'TEMPLATE_ENGAGEMENT_ENABLED'        => isset($_POST['TEMPLATE_ENGAGEMENT_ENABLED']),
        'TEMPLATE_ENGAGEMENT_TEXT'           => trim($_POST['TEMPLATE_ENGAGEMENT_TEXT']           ?? 'Save this post'),
        'TEMPLATE_ENGAGEMENT_STYLE'          => trim($_POST['TEMPLATE_ENGAGEMENT_STYLE']          ?? 'pill'),
        'TEMPLATE_ENGAGEMENT_FONT_SIZE'      => (int)($_POST['TEMPLATE_ENGAGEMENT_FONT_SIZE']     ?? 28),
        'TEMPLATE_ENGAGEMENT_COLOR'          => trim($_POST['TEMPLATE_ENGAGEMENT_COLOR']          ?? '#ffffff'),
        'TEMPLATE_ENGAGEMENT_UPPERCASE'      => isset($_POST['TEMPLATE_ENGAGEMENT_UPPERCASE']),
        'TEMPLATE_ENGAGEMENT_LETTER_SPACING' => (int)($_POST['TEMPLATE_ENGAGEMENT_LETTER_SPACING'] ?? 3),
        'TEMPLATE_ENGAGEMENT_GAP'            => (int)($_POST['TEMPLATE_ENGAGEMENT_GAP']           ?? 42),
        'TEMPLATE_ENGAGEMENT_BG_COLOR'       => trim($_POST['TEMPLATE_ENGAGEMENT_BG_COLOR']       ?? ''),
        'TEMPLATE_ENGAGEMENT_BG_ALPHA'       => (int)($_POST['TEMPLATE_ENGAGEMENT_BG_ALPHA']      ?? 60),
        'TEMPLATE_ENGAGEMENT_RADIUS'         => (int)($_POST['TEMPLATE_ENGAGEMENT_RADIUS']        ?? 50),
        'TEMPLATE_ENGAGEMENT_LINES'          => isset($_POST['TEMPLATE_ENGAGEMENT_LINES']),
        'TEMPLATE_ENGAGEMENT_LINE_ALPHA'     => (int)($_POST['TEMPLATE_ENGAGEMENT_LINE_ALPHA']    ?? 55),
        // Extra templates (recipe_card, overlay_list) — couleurs propres + link toggle
        'recipe_card_BG_COLOR'      => trim($_POST['recipe_card_BG_COLOR']      ?? ''),
        'recipe_card_TITLE_COLOR'   => trim($_POST['recipe_card_TITLE_COLOR']   ?? ''),
        'recipe_card_LABEL_COLOR'   => trim($_POST['recipe_card_LABEL_COLOR']   ?? ''),
        'recipe_card_LINK_ACTIVE'   => isset($_POST['recipe_card_LINK_ACTIVE']),
        'overlay_list_OVERLAY_COLOR'=> trim($_POST['overlay_list_OVERLAY_COLOR']?? ''),
        'overlay_list_TITLE_COLOR'  => trim($_POST['overlay_list_TITLE_COLOR']  ?? ''),
        'overlay_list_LABEL_COLOR'  => trim($_POST['overlay_list_LABEL_COLOR']  ?? ''),
        'overlay_list_LINK_ACTIVE'  => isset($_POST['overlay_list_LINK_ACTIVE']),
        // CSV publish spacing
        'CSV_PUBLISH_SPACING_DAYS' => max(1, (int)($_POST['CSV_PUBLISH_SPACING_DAYS'] ?? 7)),
        'CSV_GUARD_MIN_ROWS'       => max(0, (int)($_POST['CSV_GUARD_MIN_ROWS']       ?? 5)),
        'PINTEREST_VIDEO_PINS_ACTIVE' => isset($_POST['PINTEREST_VIDEO_PINS_ACTIVE']),
        'PINTEREST_VIDEO_BASE_URL'    => rtrim(trim($_POST['PINTEREST_VIDEO_BASE_URL'] ?? ''), '/'),
        'PINTEREST_RECYCLE_ACTIVE'    => isset($_POST['PINTEREST_RECYCLE_ACTIVE']),
        'PINTEREST_RECYCLE_COUNT'     => max(0, (int)($_POST['PINTEREST_RECYCLE_COUNT']    ?? 10)),
        'PINTEREST_RECYCLE_MIN_DAYS'  => max(1, (int)($_POST['PINTEREST_RECYCLE_MIN_DAYS'] ?? 7)),
        'PIN_SCHEDULE_START'       => (int)($_POST['PIN_SCHEDULE_START'] ?? 16),
        'PIN_SCHEDULE_END'         => (int)($_POST['PIN_SCHEDULE_END']   ?? 4),
        // Google Trends keywords CSV directory
        'KEYWORDS_PIN_DIR'      => trim($_POST['KEYWORDS_PIN_DIR'] ?? ''),
        'KEYWORD_SOURCE'            => trim($_POST['KEYWORD_SOURCE'] ?? 'pinterest_trends'),
        'KEYWORD_SUGGEST_SEEDS'     => trim($_POST['KEYWORD_SUGGEST_SEEDS'] ?? ''),
        'PINTEREST_TRENDS_TYPE'     => trim($_POST['PINTEREST_TRENDS_TYPE']     ?? 'growing'),
        'PINTEREST_TRENDS_INTEREST' => trim($_POST['PINTEREST_TRENDS_INTEREST'] ?? 'FOOD_AND_DRINKS'),
        'PINTEREST_TRENDS_GENDER'   => trim($_POST['PINTEREST_TRENDS_GENDER']   ?? 'female'),
        'PINTEREST_TRENDS_AGES'            => trim($_POST['PINTEREST_TRENDS_AGES']            ?? '50-54,55-64,65+'),
        'PINTEREST_TRENDS_COUNTRY'         => trim($_POST['PINTEREST_TRENDS_COUNTRY']         ?? 'US'),
        'PINTEREST_TRENDS_MOMENTS'         => trim(implode(',', array_filter(array_map('trim', (array)($_POST['PINTEREST_TRENDS_MOMENTS_CB'] ?? []))))),
        'PINTEREST_TRENDS_INCLUDE_KEYWORD' => trim($_POST['PINTEREST_TRENDS_INCLUDE_KEYWORD'] ?? ''),
        'PINTEREST_TRENDS_IMPORT_MAX_DAYS' => max(1, (int)($_POST['PINTEREST_TRENDS_IMPORT_MAX_DAYS'] ?? 7)),
        // Niche config
        'NICHE' => trim($_POST['NICHE'] ?? 'general'),
        // Facebook Reels
        'FACEBOOK_PAGE_URL'       => trim($_POST['FACEBOOK_PAGE_URL']       ?? ''),
        'FACEBOOK_CTA_TEXT'       => trim($_POST['FACEBOOK_CTA_TEXT']       ?? 'Get the full recipe at'),
        'FACEBOOK_HASHTAGS'       => trim($_POST['FACEBOOK_HASHTAGS']       ?? ''),
        'FACEBOOK_FRAME_DURATION' => (int)($_POST['FACEBOOK_FRAME_DURATION'] ?? 4),
        'FACEBOOK_FFMPEG_PATH'    => trim($_POST['FACEBOOK_FFMPEG_PATH']    ?? 'ffmpeg'),
        'FACEBOOK_APP_ID'         => trim($_POST['FACEBOOK_APP_ID']         ?? ''),
        'FACEBOOK_APP_SECRET'     => $keepIfEmpty('FACEBOOK_APP_SECRET', $_POST['FACEBOOK_APP_SECRET'] ?? ''),
        'FACEBOOK_PAGE_ID'         => trim($_POST['FACEBOOK_PAGE_ID']         ?? ''),
        'FACEBOOK_ACCESS_TOKEN'    => $keepIfEmpty('FACEBOOK_ACCESS_TOKEN', $_POST['FACEBOOK_ACCESS_TOKEN'] ?? ''),
        'FACEBOOK_POST_HOUR_START' => (int)($_POST['FACEBOOK_POST_HOUR_START'] ?? 16),
        'FACEBOOK_POST_HOUR_END'   => (int)($_POST['FACEBOOK_POST_HOUR_END']   ?? 4),
        'FACEBOOK_DAILY_COUNT'       => (int)($_POST['FACEBOOK_DAILY_COUNT']     ?? 5),
        'FACEBOOK_CROSSPOST_ACTIVE'  => isset($_POST['FACEBOOK_CROSSPOST_ACTIVE']),
        'FACEBOOK_POST_TYPE'         => in_array($_POST['FACEBOOK_POST_TYPE'] ?? '', ['photo','video']) ? $_POST['FACEBOOK_POST_TYPE'] : 'photo',
    ];
    // POST_META_STATS — from textarea JSON or preserve existing
    if (!empty($_POST['POST_META_STATS'])) {
        $parsed = json_decode(trim($_POST['POST_META_STATS']), true);
        if (is_array($parsed)) $newConfig['POST_META_STATS'] = $parsed;
        elseif (isset($_ovr['POST_META_STATS'])) $newConfig['POST_META_STATS'] = $_ovr['POST_META_STATS'];
    } elseif (isset($_ovr['POST_META_STATS'])) {
        $newConfig['POST_META_STATS'] = $_ovr['POST_META_STATS'];
    }
    // POST_SECTION_LABELS — from textarea JSON or preserve existing
    if (!empty($_POST['POST_SECTION_LABELS'])) {
        $parsed = json_decode(trim($_POST['POST_SECTION_LABELS']), true);
        if (is_array($parsed)) $newConfig['POST_SECTION_LABELS'] = $parsed;
        elseif (isset($_ovr['POST_SECTION_LABELS'])) $newConfig['POST_SECTION_LABELS'] = $_ovr['POST_SECTION_LABELS'];
    } elseif (isset($_ovr['POST_SECTION_LABELS'])) {
        $newConfig['POST_SECTION_LABELS'] = $_ovr['POST_SECTION_LABELS'];
    }
    // POST_LAYOUT — from hidden input JSON or preserve existing
    if (!empty($_POST['POST_LAYOUT'])) {
        $parsed = json_decode(trim($_POST['POST_LAYOUT']), true);
        if (is_array($parsed)) $newConfig['POST_LAYOUT'] = $parsed;
        elseif (isset($_ovr['POST_LAYOUT'])) $newConfig['POST_LAYOUT'] = $_ovr['POST_LAYOUT'];
    } elseif (isset($_ovr['POST_LAYOUT'])) {
        $newConfig['POST_LAYOUT'] = $_ovr['POST_LAYOUT'];
    }
    // ── Credentials (hash passwords, save admin/user credentials) ────────────
    if (!empty(trim($_POST['new_admin_email'] ?? ''))) {
        $newConfig['ADMIN_EMAIL'] = trim($_POST['new_admin_email']);
    } elseif (isset($_ovr['ADMIN_EMAIL'])) {
        $newConfig['ADMIN_EMAIL'] = $_ovr['ADMIN_EMAIL'];
    }
    if (!empty(trim($_POST['new_admin_password'] ?? ''))) {
        $newConfig['ADMIN_PASSWORD'] = password_hash(trim($_POST['new_admin_password']), PASSWORD_BCRYPT);
    } elseif (isset($_ovr['ADMIN_PASSWORD'])) {
        $newConfig['ADMIN_PASSWORD'] = $_ovr['ADMIN_PASSWORD'];
    }
    if (!empty(trim($_POST['new_user_email'] ?? ''))) {
        $newConfig['USER_EMAIL'] = trim($_POST['new_user_email']);
    } elseif (isset($_ovr['USER_EMAIL'])) {
        $newConfig['USER_EMAIL'] = $_ovr['USER_EMAIL'];
    }
    if (!empty(trim($_POST['new_user_password'] ?? ''))) {
        $newConfig['USER_PASSWORD'] = password_hash(trim($_POST['new_user_password']), PASSWORD_BCRYPT);
    } elseif (isset($_ovr['USER_PASSWORD'])) {
        $newConfig['USER_PASSWORD'] = $_ovr['USER_PASSWORD'];
    }

    $newConfig = array_filter($newConfig, fn($v) => $v !== null);

    // ── Sauvegarder prompts → prompts.json (source dédiée, jamais effacée par reset) ──
    $promptKeys = ['POST_PROMPT', 'REWRITE_POST_PROMPT', 'IMG_PROMPT_1', 'IMG_PROMPT_2', 'IMG_PROMPT_3', 'PINTEREST_CSV_PROMPT'];
    $promptsData = file_exists($promptsFile) ? (json_decode(file_get_contents($promptsFile), true) ?? []) : [];
    foreach ($promptKeys as $pk) {
        if (isset($_POST[$pk])) {
            $promptsData[$pk] = $_POST[$pk];
        }
        unset($newConfig[$pk]); // ne pas mettre dans site-config.json
    }
    file_put_contents($promptsFile, json_encode($promptsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    // profiles.json sauvegardé via handler séparé (save_profiles) — pas ici

    // SATELLITE_PROJECTS is managed by dedicated AJAX endpoint — preserve existing value.
    if (isset($_ovr['SATELLITE_PROJECTS'])) {
        $newConfig['SATELLITE_PROJECTS'] = $_ovr['SATELLITE_PROJECTS'];
    }

    // Remove keys marked for reset → they fall back to config.php defaults
    foreach (array_keys($newConfig) as $k) {
        if (($_POST['reset_' . $k] ?? '0') === '1') {
            unset($newConfig[$k]);
        }
    }

    if (file_put_contents($configFile, json_encode($newConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
        // Update base.html CSS link to match the selected theme
        $cssFile   = $newConfig['SITE_CSS'] ?? 'style.css';
        $baseHtml  = __DIR__ . '/base.html';
        if (file_exists($baseHtml)) {
            $baseContent = file_get_contents($baseHtml);
            $baseContent = preg_replace(
                '/(<link[^>]+rel=["\']stylesheet["\'][^>]+href=["\'])[^"\']+\.css(["\'][^>]*>)/i',
                '${1}' . $cssFile . '${2}',
                $baseContent
            );
            // Inject/update Pinterest domain verify tag
            $verifyCode = $newConfig['PINTEREST_DOMAIN_VERIFY'] ?? '';
            $verifyTag  = $verifyCode
                ? '<meta name="p:domain_verify" content="' . htmlspecialchars($verifyCode, ENT_QUOTES) . '">'
                : '';
            if (strpos($baseContent, 'p:domain_verify') !== false) {
                // Already exists — update or remove
                $baseContent = preg_replace('/<meta name="p:domain_verify"[^>]*>(\s*\n)?/', $verifyTag ? $verifyTag . "\n    " : '', $baseContent);
            } elseif ($verifyTag) {
                // Insert after pinterest-rich-pin tag
                $baseContent = str_replace(
                    '<meta name="pinterest-rich-pin" content="true">',
                    '<meta name="pinterest-rich-pin" content="true">' . "\n    " . $verifyTag,
                    $baseContent
                );
            }
            file_put_contents($baseHtml, $baseContent);
        }
        // Regénérer config.js avec toutes les valeurs dynamiques
        $cfgJs = __DIR__ . '/config.js';
        if (file_exists($cfgJs)) {
            $jsContent = file_get_contents($cfgJs);
            // Site identity
            $jsContent = preg_replace('/^globalThis\.homepageTitle\s*=.+;$/m',    "globalThis.homepageTitle = " . json_encode($newConfig['HOMEPAGE_TITLE']   ?? 'Pin Posts', JSON_UNESCAPED_UNICODE) . ";",   $jsContent);
            $jsContent = preg_replace('/^globalThis\.homepageTagline\s*=.+;$/m',  "globalThis.homepageTagline = " . json_encode($newConfig['HOMEPAGE_TAGLINE'] ?? '', JSON_UNESCAPED_UNICODE) . ";", $jsContent);
            $jsContent = preg_replace('/^globalThis\.siteUrl\s*=.+;$/m',          "globalThis.siteUrl = " . json_encode('https://' . ($newConfig['HOST_NAME'] ?? ''), JSON_UNESCAPED_UNICODE) . ";", $jsContent);
            $siteName = $newConfig['HOMEPAGE_TITLE'] ?? 'Pin Posts';
            $jsContent = preg_replace('/^globalThis\.copyright\s*=.+;$/m',        "globalThis.copyright = " . json_encode('2025 - ' . date('Y') . ' ' . $siteName . '. All rights reserved.', JSON_UNESCAPED_UNICODE) . ";", $jsContent);
            // Niche config
            $jsContent = preg_replace('/^globalThis\.postNiche\s*=.+;$/m',        "globalThis.postNiche = " . json_encode($newConfig['NICHE'] ?? 'general', JSON_UNESCAPED_UNICODE) . ";",          $jsContent);
            $jsContent = preg_replace('/^globalThis\.postMetaStats\s*=.+;$/m',    "globalThis.postMetaStats = " . json_encode($newConfig['POST_META_STATS']     ?? [], JSON_UNESCAPED_UNICODE) . ";", $jsContent);
            $jsContent = preg_replace('/^globalThis\.postSectionLabels\s*=.+;$/m',"globalThis.postSectionLabels = " . json_encode($newConfig['POST_SECTION_LABELS'] ?? [], JSON_UNESCAPED_UNICODE) . ";", $jsContent);
            file_put_contents($cfgJs, $jsContent);
        }
        // ── Sauvegarder aussi les profils Pinterest (fusionné dans save_config) ──
        if (isset($_POST['pf_label']) || isset($_POST['pf_profile'])) {
            $pfFile  = __DIR__ . '/profiles.json';  // local au site — un seul profil
            $pfExist = file_exists($pfFile) ? (json_decode(file_get_contents($pfFile), true) ?? []) : [];
            $pfEntry = [
                'label'             => trim($_POST['pf_label']       ?? ''),
                'profile'           => trim($_POST['pf_profile']     ?? ''),
                'adspower_id'       => trim($_POST['pf_adspower_id'] ?? ''),
                'pin_url'           => trim($_POST['pf_pin_url']     ?? 'https://www.pinterest.com/settings/bulk-create-pins/'),
                'site_url'          => 'https://' . ($newConfig['HOST_NAME'] ?? (defined('HOST_NAME') ? HOST_NAME : '')),
                'browser'           => trim($_POST['pf_browser']      ?? ($pfExist['browser']      ?? 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe')),
                'browser_type'      => trim($_POST['pf_browser_type'] ?? ($pfExist['browser_type'] ?? 'edge')),
                'delay_seconds'     => max(0, (int)($_POST['pf_delay']   ?? ($pfExist['delay_seconds']   ?? 20))),
                'max_wait_seconds'  => max(0, (int)($_POST['pf_maxwait'] ?? ($pfExist['max_wait_seconds'] ?? 50))),
                'post_upload_delay' => max(0, (int)($_POST['pf_postdel'] ?? ($pfExist['post_upload_delay'] ?? 15))),
            ];
            file_put_contents($pfFile, json_encode($pfEntry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $pfGen = dirname(__DIR__) . '/publish-pinterest-gen.php';
            if (file_exists($pfGen)) {
                ob_start(); include $pfGen; $pfBat = ob_get_clean();
                file_put_contents(dirname(__DIR__) . '/publish-pinterest-generated.bat', ltrim($pfBat, "\xEF\xBB\xBF"));
            }
        }

        header('Location: ' . $_SERVER['PHP_SELF'] . '?saved=1');
        exit;
    } else {
        $cfgPerms = file_exists($configFile) ? decoct(fileperms($configFile) & 0777) : '—';
        $cfgOwner = (file_exists($configFile) && function_exists('posix_getpwuid')) ? (posix_getpwuid(fileowner($configFile))['name'] ?? '?') : '?';
        $phpUsr   = function_exists('posix_geteuid') && function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'www-data') : trim(shell_exec('whoami 2>/dev/null') ?: 'www-data');
        $error = 'Erreur écriture site-config.json — fichier owner: <b>' . htmlspecialchars($cfgOwner) . '</b>, PHP user: <b>' . htmlspecialchars($phpUsr) . '</b>, perms: <b>' . $cfgPerms . '</b>. Cliquer <b>🔧 Fix app perms</b> dans la section Git ci-dessous.';
    }
}

// Current runtime values
$_settings   = file_exists($settingsFile) ? (json_decode(file_get_contents($settingsFile), true) ?? []) : [];
$linkPinActive = isset($_settings['linkPinActive']) ? (bool)$_settings['linkPinActive'] : LINK_PIN_ACTIVE;

// ── Helpers ──────────────────────────────────────────────────────────────────
// Renders label row with optional "overridden" badge + reset button
// ── Parse current nav labels + assets from base.html ─────────────────────────
$_bh = file_exists(__DIR__ . '/base.html') ? file_get_contents(__DIR__ . '/base.html') : '';
$_navH = [];
foreach (['posts' => 'Topics', 'posts-category' => 'Discover', 'about' => 'About Us', 'contact' => 'Contact'] as $page => $def) {
    if (preg_match('/<a href="\?page=' . preg_quote($page) . '">[^<]*(?:<[^>]*>)*<\/svg>\s*([^<]+)\s*<\/a>/', $_bh, $m)) {
        $_navH[$page] = trim($m[1]);
    } else {
        $_navH[$page] = $def;
    }
}
$_navF = [];
foreach (['Home','Writers','Topics','Keywords','Favorites','Discover','Posts','Recipes','About Us','Privacy'] as $alt) {
    if (preg_match('/alt="' . preg_quote($alt) . '">([^<]+)<\/a>/', $_bh, $m)) {
        $_navF[$alt] = trim($m[1]);
    } else {
        $_navF[$alt] = $alt;
    }
}
$_logoFile  = preg_match('/src="assets\/logo\.([^"]+)"/', $_bh, $m) ? 'assets/logo.' . $m[1] : 'assets/logo.svg';
$_authorData = file_exists(__DIR__ . '/authors/authors.json')
    ? (json_decode(file_get_contents(__DIR__ . '/authors/authors.json'), true)[0] ?? [])
    : [];
$_authorImg  = $_authorData['imagePath']    ?? 'authors/images/author.jpg';
$_authorName = $_authorData['name']         ?? '';
$_authorDesc = $_authorData['description']  ?? '';

// ── Profiles Pinterest ────────────────────────────────────────────────────────
// Chaque site a son propre profiles.json local — un seul profil (objet plat)
$_profilesFile = __DIR__ . '/profiles.json';
$_siteProfile  = file_exists($_profilesFile) ? (json_decode(file_get_contents($_profilesFile), true) ?? []) : [];
$_thisSiteUrl  = 'https://' . (defined('HOST_NAME') ? HOST_NAME : '');
if (empty($_siteProfile)) {
    $_siteProfile = ['label' => basename(__DIR__), 'profile' => '', 'adspower_id' => '', 'pin_url' => '', 'site_url' => $_thisSiteUrl];
}

$_pfBrowser     = $_siteProfile['browser']           ?? 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
$_pfBrowserType = $_siteProfile['browser_type']      ?? 'edge';
$_pfDelay       = $_siteProfile['delay_seconds']     ?? 20;
$_pfMaxWait     = $_siteProfile['max_wait_seconds']  ?? 50;
$_pfPostDel     = $_siteProfile['post_upload_delay'] ?? 15;

function lbl(string $key, string $text, string $hint = ''): void {
    global $_ovr;
    $ovr = array_key_exists($key, $_ovr);
    echo '<div class="lbl-row">';
    echo '<label>' . htmlspecialchars($text);
    if ($hint) echo ' <small>' . htmlspecialchars($hint) . '</small>';
    echo '</label>';
    if ($ovr) {
        echo '<span class="badge-ovr" id="badge_' . $key . '">modifié</span>';
        echo '<button type="button" class="reset-btn" id="rbtn_' . $key . '" onclick="resetField(\'' . $key . '\')" title="Revenir au défaut config.php">↺ défaut</button>';
        echo '<input type="hidden" name="reset_' . $key . '" id="hid_' . $key . '" value="0">';
    }
    echo '</div>';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Configuration — <?= htmlspecialchars(SITE_FOLDER) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f1f5f9;color:#1e293b;min-height:100vh}
.topbar{background:#1e293b;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:16px}
.topbar a{color:#94a3b8;text-decoration:none;font-size:14px}.topbar a:hover{color:#fff}
.topbar h1{font-size:18px;font-weight:600;flex:1}
.container{max-width:900px;margin:32px auto;padding:0 16px 100px}
.section{background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:20px;overflow:hidden}
.section-header{padding:14px 20px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:10px;cursor:pointer;user-select:none}
.section-header h2{font-size:15px;font-weight:600;color:#1e293b;flex:1}
.section-header .icon{font-size:18px}
.section-header .chevron{color:#94a3b8;transition:.2s}
.section-header.collapsed .chevron{transform:rotate(-90deg)}
.section-body{padding:20px}.section-body.hidden{display:none}
.field{margin-bottom:18px}.field:last-child{margin-bottom:0}
/* Label row with badge + reset btn */
.lbl-row{display:flex;align-items:center;gap:6px;margin-bottom:6px}
.lbl-row label{font-size:13px;font-weight:500;color:#374151;flex:1}
.lbl-row label small{font-weight:400;color:#9ca3af}
.badge-ovr{font-size:11px;background:#fef3c7;color:#92400e;border-radius:4px;padding:2px 6px;white-space:nowrap}
.badge-ovr.reset{background:#dcfce7;color:#166534}
.reset-btn{background:none;border:none;font-size:11px;color:#6366f1;cursor:pointer;padding:2px 5px;border-radius:4px;white-space:nowrap}
.reset-btn:hover{background:#ede9fe}
.field-reset input,.field-reset select,.field-reset textarea{opacity:.45;pointer-events:none}
.field input[type=text],.field input[type=number],.field input[type=password],.field select,.field textarea{
    width:100%;padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:14px;color:#1e293b;background:#fff;transition:border-color .15s}
.field input:focus,.field select:focus,.field textarea:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.1)}
.field textarea{resize:vertical;font-family:'SFMono-Regular',Consolas,monospace;font-size:12.5px;line-height:1.5}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.toggle-row{display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f8fafc}
.toggle-row:last-child{border-bottom:none}
.toggle-row .info label{font-size:14px;font-weight:500;color:#374151;display:block}
.toggle-row .info small{font-size:12px;color:#9ca3af}
.toggle{position:relative;width:44px;height:24px;flex-shrink:0}
.toggle input{opacity:0;width:0;height:0}
.toggle .slider{position:absolute;inset:0;background:#cbd5e1;border-radius:24px;cursor:pointer;transition:.2s}
.toggle .slider:before{content:'';position:absolute;width:18px;height:18px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s}
.toggle input:checked+.slider{background:#6366f1}
.toggle input:checked+.slider:before{transform:translateX(20px)}
.satellite-item{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px;margin-bottom:10px;position:relative}
.satellite-item .remove-sat{position:absolute;top:10px;right:10px;background:none;border:none;color:#ef4444;cursor:pointer;font-size:20px;line-height:1}
.sat-grid{display:grid;grid-template-columns:1fr 1.5fr;gap:12px}
.btn-add{background:#f1f5f9;border:1px dashed #cbd5e1;border-radius:8px;padding:10px;width:100%;font-size:13px;color:#64748b;cursor:pointer;transition:.15s}
.btn-add:hover{background:#e2e8f0}
.sticky-bar{position:fixed;bottom:0;left:0;right:0;background:#fff;border-top:1px solid #e2e8f0;padding:14px 24px;display:flex;align-items:center;gap:12px;z-index:100;box-shadow:0 -2px 8px rgba(0,0,0,.06)}
.btn-save{background:#6366f1;color:#fff;border:none;border-radius:8px;padding:10px 28px;font-size:15px;font-weight:600;cursor:pointer}
.btn-save:hover{background:#4f46e5}
.btn-back{background:#f1f5f9;color:#374151;border:none;border-radius:8px;padding:10px 20px;font-size:14px;cursor:pointer;text-decoration:none;display:inline-block}
.alert{padding:12px 16px;border-radius:8px;font-size:14px;font-weight:500;margin-bottom:20px}
.alert-success{background:#dcfce7;color:#16a34a;border:1px solid #bbf7d0}
.alert-error{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
.hint{font-size:12px;color:#9ca3af;margin-top:5px}
.info-box{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 14px;font-size:13px;color:#1d4ed8}
.prompt-tabs{display:flex;gap:4px;margin-bottom:12px}
.prompt-tab{padding:6px 14px;border-radius:6px;border:1px solid #e2e8f0;font-size:13px;cursor:pointer;background:#f8fafc;color:#64748b}
.prompt-tab.active{background:#6366f1;color:#fff;border-color:#6366f1}
.prompt-panel{display:none}.prompt-panel.active{display:block}
.tpl-cards{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:20px}
.tpl-card{display:flex;align-items:center;gap:14px;padding:14px 18px;border:2px solid #e2e8f0;border-radius:12px;cursor:pointer;background:#fff;transition:border-color .15s,box-shadow .15s,transform .1s;user-select:none;min-width:210px}
.tpl-card:hover{border-color:#6366f1;box-shadow:0 2px 12px rgba(99,102,241,.15);transform:translateY(-2px)}
.tpl-card.active{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.2);background:#f5f3ff}
.tpl-diagram{flex-shrink:0;border-radius:4px;overflow:hidden;box-shadow:0 1px 4px #0002}
.tpl-card span{font-size:13px;font-weight:600;color:#374151}
.tpl-card.active span{color:#4f46e5}
/* CSS theme cards */
.css-theme-card{display:flex;flex-direction:column;align-items:center;gap:4px;padding:10px 18px;border:2px solid #e5e7eb;border-radius:10px;cursor:pointer;transition:.2s;background:#fff;min-width:100px}
.css-theme-card:hover{border-color:#6366f1;background:#f5f3ff}
.css-theme-card.active{border-color:#6366f1;background:#eef2ff}
.css-theme-card input[type=radio]{display:none}
.css-theme-name{font-size:14px;font-weight:700;color:#1e293b;text-transform:capitalize}
.css-theme-file{font-size:11px;color:#9ca3af;font-family:monospace}
.css-theme-card.active .css-theme-name{color:#4f46e5}
</style>
</head>
<body>

<div class="topbar">
    <a href="posts-liste.php">← Retour</a>
    <h1>⚙️ Configuration — <?= htmlspecialchars(SITE_FOLDER) ?></h1>
    <div style="display:flex;align-items:center;gap:10px;margin-left:auto">
        <span style="font-size:12px;color:<?= file_exists($configFile) ? '#34d399' : '#94a3b8' ?>">
            <?= file_exists($configFile) ? '● site-config.json actif (' . count($_ovr) . ' params)' : '○ Défauts config.php' ?>
        </span>
        <span style="font-size:.78rem;background:#1e293b;color:#94a3b8;padding:3px 10px;border-radius:20px;border:1px solid #334155;">
            👤 <?= htmlspecialchars($_SESSION['role'] ?? '') ?>
        </span>
        <a href="login.php?logout=1" style="font-size:.78rem;color:#f87171;text-decoration:none;">🚪 Déconnexion</a>
    </div>
</div>

<div class="container">
<?php if ($saved): ?><div class="alert alert-success">✅ Configuration sauvegardée</div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error">❌ <?= $error ?></div><?php endif; ?>

<form method="POST" novalidate id="form-save-config">
<input type="hidden" name="save_config" value="1">

<!-- ── Git ── -->
<div class="section">
    <div class="section-header" onclick="toggleSection(this)">
        <span class="icon">🔐</span><h2>Git & Déploiement</h2><span class="chevron">▾</span>
    </div>
    <div class="section-body">
        <div class="row2">
            <div class="field">
                <?php lbl('GITHUB_USER', 'GitHub Username') ?>
                <input type="text" name="GITHUB_USER" value="<?= htmlspecialchars(GITHUB_USER) ?>" placeholder="myusername">
            </div>
            <div class="field">
                <?php lbl('GITHUB_PASSWORD', 'GitHub Token') ?>
                <input type="password" name="GITHUB_PASSWORD" value="" placeholder="<?= GITHUB_PASSWORD ? '••••••• (inchangé)' : 'ghp_xxxx...' ?>">
            </div>
        </div>
        <div class="row2">
            <div class="field">
                <?php lbl('BRANCH', 'Branche') ?>
                <input type="text" name="BRANCH" value="<?= htmlspecialchars(BRANCH) ?>">
            </div>
            <div class="field">
                <?php lbl('GITHUB_REPO', 'GitHub Repo URL') ?>
                <input type="text" name="GITHUB_REPO" value="<?= htmlspecialchars(GITHUB_REPO) ?>"
                    placeholder="https://github.com/user/repo.git">
            </div>
        </div>
    </div>
</div>

<!-- ── API Keys ── -->
<div class="section">
    <div class="section-header" onclick="toggleSection(this)">
        <span class="icon">🔑</span><h2>Clés API</h2><span class="chevron">▾</span>
    </div>
    <div class="section-body">
        <div class="field">
            <?php lbl('OPENAI_API_KEY', 'OpenAI API Key') ?>
            <input type="password" name="OPENAI_API_KEY" value="" placeholder="<?= OPENAI_API_KEY ? '••••••• (inchangé)' : 'sk-...' ?>">
        </div>
        <div class="field">
            <?php lbl('ANTHROPIC_API_KEY', 'Anthropic API Key') ?>
            <input type="password" name="ANTHROPIC_API_KEY" value="" placeholder="<?= ANTHROPIC_API_KEY ? '••••••• (inchangé)' : 'sk-ant-...' ?>">
        </div>
    </div>
</div>

<!-- ── Modèles ── -->
<div class="section">
    <div class="section-header" onclick="toggleSection(this)">
        <span class="icon">🤖</span><h2>Modèles IA</h2><span class="chevron">▾</span>
    </div>
    <div class="section-body">
        <div class="field">
            <?php lbl('GENERATION_API', 'API de génération', 'images toujours OpenAI') ?>
            <select name="GENERATION_API">
                <option value="openai"    <?= GENERATION_API === 'openai'    ? 'selected' : '' ?>>OpenAI</option>
                <option value="anthropic" <?= GENERATION_API === 'anthropic' ? 'selected' : '' ?>>Anthropic</option>
            </select>
        </div>
        <div class="row2">
            <div class="field">
                <?php lbl('OPENAI_CONTENT_MODEL', 'Modèle contenu OpenAI') ?>
                <input type="text" name="OPENAI_CONTENT_MODEL" value="<?= htmlspecialchars(OPENAI_CONTENT_MODEL) ?>" placeholder="gpt-4.1-mini">
                <div class="hint">gpt-4.1-mini · gpt-4o-mini · gpt-4o</div>
            </div>
            <div class="field">
                <?php lbl('OPENAI_CONTENT_MAX_TOKENS', 'Max tokens') ?>
                <input type="number" name="OPENAI_CONTENT_MAX_TOKENS" value="<?= OPENAI_CONTENT_MAX_TOKENS ?>" min="1000" max="128000">
            </div>
        </div>
        <div class="row2">
            <div class="field">
                <?php lbl('OPENAI_IMAGE_MODEL', 'Modèle image') ?>
                <input type="text" name="OPENAI_IMAGE_MODEL" value="<?= htmlspecialchars(OPENAI_IMAGE_MODEL) ?>" placeholder="gpt-image-1-mini">
            </div>
            <div class="field">
                <?php lbl('OPENAI_IMAGE_QUALITY', 'Qualité image') ?>
                <select name="OPENAI_IMAGE_QUALITY">
                    <option value="low"    <?= OPENAI_IMAGE_QUALITY === 'low'    ? 'selected' : '' ?>>low</option>
                    <option value="medium" <?= OPENAI_IMAGE_QUALITY === 'medium' ? 'selected' : '' ?>>medium</option>
                    <option value="high"   <?= OPENAI_IMAGE_QUALITY === 'high'   ? 'selected' : '' ?>>high</option>
                </select>
            </div>
        </div>
        <div class="row2">
            <div class="field">
                <?php lbl('OPENAI_IMAGE_SIZE', 'Taille image') ?>
                <select name="OPENAI_IMAGE_SIZE">
                    <option value="1024x1536" <?= OPENAI_IMAGE_SIZE === '1024x1536' ? 'selected' : '' ?>>1024×1536 (Portrait)</option>
                    <option value="1536x1024" <?= OPENAI_IMAGE_SIZE === '1536x1024' ? 'selected' : '' ?>>1536×1024 (Paysage)</option>
                    <option value="1024x1024" <?= OPENAI_IMAGE_SIZE === '1024x1024' ? 'selected' : '' ?>>1024×1024 (Carré)</option>
                </select>
            </div>
            <div class="field">
                <?php lbl('OPENAI_IMAGE_COST', 'Coût par image ($)') ?>
                <input type="number" name="OPENAI_IMAGE_COST" value="<?= OPENAI_IMAGE_COST ?>" step="0.001" min="0">
            </div>
        </div>
        <div id="anthropic-fields" style="<?= GENERATION_API !== 'anthropic' ? 'opacity:.4;pointer-events:none' : '' ?>">
            <div class="field">
                <?php lbl('ANTHROPIC_MODEL', 'Modèle Anthropic') ?>
                <input type="text" name="ANTHROPIC_MODEL" value="<?= htmlspecialchars(ANTHROPIC_MODEL) ?>">
            </div>
        </div>
    </div>
</div>

<!-- ── Site ── -->
<div class="section">
    <div class="section-header" onclick="toggleSection(this)">
        <span class="icon">🌐</span><h2>Site</h2><span class="chevron">▾</span>
    </div>
    <div class="section-body">
        <div class="row2">
            <div class="field">
                <?php lbl('HOST_NAME', 'Nom de domaine', 'HOST_NAME') ?>
                <input type="text" name="HOST_NAME" value="<?= htmlspecialchars(HOST_NAME) ?>" placeholder="www.monsite.com">
            </div>
            <div class="field">
                <?php lbl('BASE_URL', 'Base URL (CLI/Cron)', 'URL utilisée par le pipeline en mode CLI. Laisser vide pour auto-détection.') ?>
                <input type="text" name="BASE_URL" value="<?= htmlspecialchars(defined('BASE_URL') ? BASE_URL : '') ?>" placeholder="http://127.0.0.1/sites-moad/demo/">
            </div>
            <div class="field">
                <div class="lbl-row"><label>Dossier <small>auto</small></label></div>
                <input type="text" value="<?= htmlspecialchars(SITE_FOLDER) ?>" disabled style="background:#f8fafc;color:#9ca3af">
            </div>
        </div>
        <div class="field">
            <?php lbl('HOMEPAGE_TITLE', 'Nom du site', 'Affiché dans le titre, footer, et partage social') ?>
            <input type="text" name="HOMEPAGE_TITLE" value="<?= htmlspecialchars(defined('HOMEPAGE_TITLE') ? HOMEPAGE_TITLE : '') ?>" placeholder="Mon Site">
        </div>
        <div class="field">
            <?php lbl('HOMEPAGE_TAGLINE', 'Tagline du site', 'Description courte pour les méta-tags SEO') ?>
            <input type="text" name="HOMEPAGE_TAGLINE" value="<?= htmlspecialchars(defined('HOMEPAGE_TAGLINE') ? HOMEPAGE_TAGLINE : '') ?>" placeholder="Découvrez du contenu pour toutes les occasions">
        </div>
        <div class="field">
            <?php lbl('SITE_LANGUAGE', 'Langue', 'ex: en-US, fr-FR') ?>
            <input type="text" name="SITE_LANGUAGE" value="<?= htmlspecialchars(SITE_LANGUAGE) ?>">
        </div>
        <div class="field">
            <?php lbl('NICHE', 'Niche du site', 'ex: general, recipes, travel, diy, finance') ?>
            <input type="text" name="NICHE" value="<?= htmlspecialchars(NICHE) ?>" placeholder="general">
        </div>
        <div class="field">
            <?php lbl('POST_SECTION_LABELS', 'Labels des sections (JSON)', 'Titre affiché pour chaque section — laisser vide pour masquer') ?>
            <textarea name="POST_SECTION_LABELS" rows="13" style="font-family:monospace;font-size:0.82em"><?= htmlspecialchars(json_encode(POST_SECTION_LABELS, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></textarea>
            <p style="margin-top:4px;font-size:0.78em;color:#6b7280">Label vide <code>""</code> = section masquée. Champs: why_this_works, ingredients, instructions, pro_tips, common_mistakes, variations, nutrition, storage, faq, conclusion, introduction</p>
        </div>
        <div class="field">
            <?php lbl('POST_META_STATS', 'Stats du post (JSON)', 'Stats affichées dans le header — seuls les champs présents dans post.json s\'affichent') ?>
            <textarea name="POST_META_STATS" rows="12" style="font-family:monospace;font-size:0.82em"><?= htmlspecialchars(json_encode(POST_META_STATS, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></textarea>
            <p style="margin-top:4px;font-size:0.78em;color:#6b7280">Chaque entrée: <code>{"field": "prep_time", "label": "Prep", "suffix": "min", "icon": "⏱"}</code></p>
        </div>
        <div class="field">
            <?php lbl('POST_LAYOUT', 'Ordre des blocs de la page post', 'Glisser-déposer pour réorganiser — cliquer ↑↓ sur mobile') ?>
            <?php
            $blockLabels = [
                'breadcrumb'      => '🔗 Fil d\'Ariane',
                'header'          => '📝 Titre + Auteur + Stats',
                'rating'          => '⭐ Note / Étoiles',
                'main_image'      => '🖼️ Image principale + PIN',
                'image_1'         => '📷 Image 1',
                'image_2'         => '📷 Image 2',
                'image_3'         => '📷 Image 3',
                'description'     => '📄 Description courte',
                'introduction'    => '👋 Introduction',
                'story'           => '📖 Contenu structuré',
                'why_this_works'  => '✨ Pourquoi ça marche',
                'ingredients'     => '🥕 Ingrédients',
                'instructions'    => '📋 Instructions / Steps',
                'pro_tips'        => '💪 Pro Tips',
                'common_mistakes' => '⚠️ Erreurs communes',
                'variations'      => '🔄 Variations',
                'nutrition'       => '🥗 Nutrition',
                'storage'         => '🗄️ Conservation',
                'faq'             => '❓ FAQ',
                'conclusion'      => '🎯 Conclusion',
                'tips'            => '💡 Tips & Notes',
            ];
            // Ajouter dynamiquement les blocs ads depuis ads-config.json
            $_adsFile = __DIR__ . '/ads-config.json';
            $_adsCfg  = file_exists($_adsFile) ? (json_decode(file_get_contents($_adsFile), true) ?? []) : [];
            foreach ($_adsCfg['placements'] ?? [] as $_ai => $_apl) {
                $blockLabels['ad_' . ($_ai + 1)] = '📢 Pub ' . ($_ai + 1) . ' — ' . ($_apl['className'] ?? ($_apl['format'] ?? 'auto'));
            }
            $currentLayout = defined('POST_LAYOUT') ? POST_LAYOUT : array_keys($blockLabels);
            // Ensure all known blocks appear (append any missing ones at the end)
            foreach (array_keys($blockLabels) as $k) {
                if (!in_array($k, $currentLayout)) $currentLayout[] = $k;
            }
            ?>
            <input type="hidden" name="POST_LAYOUT" id="post_layout_input" value="<?= htmlspecialchars(json_encode($currentLayout)) ?>">
            <div id="layout-sortable" style="display:flex;flex-direction:column;gap:6px;margin-top:8px;max-width:420px;">
                <?php foreach ($currentLayout as $key): ?>
                <?php if (!isset($blockLabels[$key])) continue; ?>
                <div class="layout-block" draggable="true" data-key="<?= htmlspecialchars($key) ?>"
                     style="display:flex;align-items:center;justify-content:space-between;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:8px 12px;cursor:grab;user-select:none;gap:8px;">
                    <span style="display:flex;align-items:center;gap:8px;">
                        <span style="color:#9ca3af;font-size:14px;">⠿</span>
                        <span style="font-size:0.9em;"><?= htmlspecialchars($blockLabels[$key]) ?></span>
                    </span>
                    <span style="display:flex;gap:4px;">
                        <button type="button" onclick="layoutMove(this,-1)" style="background:none;border:1px solid #d1d5db;border-radius:4px;padding:2px 7px;cursor:pointer;font-size:12px;line-height:1;">↑</button>
                        <button type="button" onclick="layoutMove(this,1)"  style="background:none;border:1px solid #d1d5db;border-radius:4px;padding:2px 7px;cursor:pointer;font-size:12px;line-height:1;">↓</button>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <div style="margin-top:10px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <button type="button" id="btn-regen-pages" onclick="regenPages()"
                        style="background:#1d4ed8;color:#fff;border:none;border-radius:6px;padding:8px 16px;font-size:0.88em;cursor:pointer;display:flex;align-items:center;gap:6px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.5"/></svg>
                    Regenerate HTML posts
                </button>
                <span id="regen-status" style="font-size:0.82em;color:#6b7280;"></span>
            </div>
            <script>
            function regenPages() {
                var btn = document.getElementById('btn-regen-pages');
                var status = document.getElementById('regen-status');
                btn.disabled = true;
                btn.style.opacity = '0.6';
                status.style.color = '#6b7280';
                status.textContent = 'Génération en cours…';

                var formData = new FormData();
                formData.append('action', 'generate_missing_html');
                var layoutInput = document.getElementById('post_layout_input');
                if (layoutInput) formData.append('OVERRIDE_LAYOUT', layoutInput.value);

                fetch('posts-liste.php', { method: 'POST', body: formData })
                    .then(function(r){ return r.json(); })
                    .then(function(d){
                        if (d.success) {
                            status.style.color = '#16a34a';
                            status.textContent = '✓ ' + d.generated_count + ' page(s) générée(s)';
                        } else {
                            status.style.color = '#dc2626';
                            status.textContent = '✗ Erreur: ' + (d.message || 'inconnue');
                        }
                    })
                    .catch(function(){ status.style.color='#dc2626'; status.textContent='✗ Requête échouée'; })
                    .finally(function(){ btn.disabled=false; btn.style.opacity='1'; });
            }
            </script>
            <p style="margin-top:6px;font-size:0.78em;color:#6b7280">Sauvegarder d'abord, puis cliquer <b>Regenerate</b> pour appliquer le nouvel ordre aux pages existantes.</p>
        </div>
        <hr style="border:none;border-top:1px solid #f1f5f9;margin:20px 0">
        <div style="margin-top:6px;padding:8px 10px;background:#f0f9ff;border-radius:6px;font-size:0.78em;color:#0369a1">
            💡 Les blocs <b>📢 Pub N</b> correspondent aux placements dans <code>ads-config.json</code>. Ajouter un placement → nouveau bloc disponible automatiquement.
        </div>
            <script>
            (function(){
                var list = document.getElementById('layout-sortable');
                var inp  = document.getElementById('post_layout_input');
                var drag = null;

                function syncInput() {
                    var keys = Array.from(list.children).map(function(el){ return el.dataset.key; });
                    inp.value = JSON.stringify(keys);
                }

                list.addEventListener('dragstart', function(e) {
                    drag = e.target.closest('.layout-block');
                    if (drag) { setTimeout(function(){ drag.style.opacity='0.4'; }, 0); }
                });
                list.addEventListener('dragend', function(e) {
                    if (drag) { drag.style.opacity=''; drag = null; }
                    syncInput();
                });
                list.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    var target = e.target.closest('.layout-block');
                    if (target && drag && target !== drag) {
                        var rect = target.getBoundingClientRect();
                        var mid  = rect.top + rect.height / 2;
                        if (e.clientY < mid) list.insertBefore(drag, target);
                        else list.insertBefore(drag, target.nextSibling);
                    }
                });

                window.layoutMove = function(btn, dir) {
                    var item = btn.closest('.layout-block');
                    if (dir === -1 && item.previousElementSibling) {
                        list.insertBefore(item, item.previousElementSibling);
                    } else if (dir === 1 && item.nextElementSibling) {
                        list.insertBefore(item.nextElementSibling, item);
                    }
                    syncInput();
                };
            })();
            </script>
        </div>
        <div class="field">
            <?php lbl('SITE_CSS', 'Style du site (CSS)', 'Appliqué à base.html et aux pages de détail générées') ?>
            <?php
                // List all .css files in project root
                $cssFiles = glob(__DIR__ . '/*.css') ?: [];
                $cssOptions = array_map('basename', $cssFiles);
                $currentCss = defined('SITE_CSS') ? SITE_CSS : 'style.css';
            ?>
            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:4px">
            <?php foreach ($cssOptions as $cssOpt): ?>
                <label class="css-theme-card <?= $currentCss === $cssOpt ? 'active' : '' ?>">
                    <input type="radio" name="SITE_CSS" value="<?= htmlspecialchars($cssOpt) ?>"
                           <?= $currentCss === $cssOpt ? 'checked' : '' ?>>
                    <span class="css-theme-name"><?= htmlspecialchars(pathinfo($cssOpt, PATHINFO_FILENAME)) ?></span>
                    <span class="css-theme-file"><?= htmlspecialchars($cssOpt) ?></span>
                </label>
            <?php endforeach; ?>
            </div>
            <p style="margin-top:6px;font-size:0.82em;color:#6b7280">
                Sélectionner un style met à jour <code>base.html</code> et est utilisé pour générer les pages de détail.
            </p>
            <!-- Upload CSS personnalisé (AJAX — hors form principal) -->
            <div style="margin-top:14px;padding:12px 16px;background:#f8fafc;border:1.5px dashed #cbd5e1;border-radius:10px">
                <div style="font-size:0.84em;font-weight:700;color:#374151;margin-bottom:8px">⬆️ Uploader un CSS personnalisé</div>
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                    <input type="file" id="cssUploadInput" accept=".css"
                           style="font-size:0.83em;border:1px solid #d1d5db;border-radius:6px;padding:5px 8px;background:#fff;color:#374151">
                    <button type="button" id="cssUploadBtn"
                            style="background:#6366f1;color:#fff;border:none;border-radius:7px;padding:7px 16px;font-size:0.83em;font-weight:700;cursor:pointer;white-space:nowrap">
                        Uploader &amp; Appliquer
                    </button>
                    <span id="cssUploadMsg" style="font-size:0.82em"></span>
                    <?php if (isset($_GET['css_uploaded'])): ?>
                        <span style="color:#16a34a;font-size:0.82em">✔ <?= htmlspecialchars($_GET['css_uploaded']) ?> appliqué</span>
                    <?php endif; ?>
                </div>
                <p style="margin:6px 0 0;font-size:0.78em;color:#9ca3af">Le fichier sera copié à la racine du site et appliqué immédiatement comme style actif.</p>
            </div>
        </div>
    </div>
</div>

<!-- ── Contenu des Pages ── -->
<div class="section">
    <div class="section-header" onclick="toggleSection(this)">
        <span class="icon">📝</span><h2>Contenu des Pages</h2><span class="chevron">▾</span>
    </div>
    <div class="section-body">
        <?php
        $pcFile = __DIR__ . '/pages/page-content.json';
        $pcData = file_exists($pcFile) ? json_decode(file_get_contents($pcFile), true) : [];
        $pcNiche = $pcData['niche'] ?? '—';
        $pcDate  = $pcData['generated_at'] ?? '—';
        ?>
        <div class="field">
            <p style="margin:0 0 8px;">Niche actuelle : <strong><?= htmlspecialchars(NICHE) ?></strong> &nbsp;|&nbsp; Dernier contenu généré pour : <strong><?= htmlspecialchars($pcNiche) ?></strong> le <strong><?= htmlspecialchars($pcDate) ?></strong></p>
            <p style="margin:0 0 12px;color:#888;font-size:13px;">Génère automatiquement le contenu des pages home, about, contact et privacy adapté à la niche courante via IA.</p>
            <button type="button" id="btn-gen-pages" onclick="generatePageContent()" style="background:#2563eb;color:#fff;border:none;padding:9px 20px;border-radius:6px;cursor:pointer;font-size:14px;">
                ✨ Générer le contenu avec IA
            </button>
            <span id="page-gen-spinner" style="display:none;margin-left:12px;color:#888;">⏳ Génération en cours...</span>
            <span id="page-gen-status" style="margin-left:12px;font-size:13px;"></span>
        </div>

        <div id="page-content-preview" style="display:none;margin-top:16px;">
            <h3 style="margin:0 0 12px;font-size:15px;">Aperçu — modifiable avant sauvegarde</h3>

            <div class="field">
                <label>Home — Tagline hero</label>
                <input type="text" id="pc-home-tagline" style="width:100%">
            </div>
            <div class="field">
                <label>Home — Texte de bienvenue</label>
                <textarea id="pc-home-welcome" rows="3" style="width:100%"></textarea>
            </div>

            <div class="field">
                <label>About — Sous-titre héro</label>
                <textarea id="pc-about-hero" rows="2" style="width:100%"></textarea>
            </div>
            <div class="field">
                <label>About — Nom du fondateur</label>
                <input type="text" id="pc-about-founder-name" style="width:100%">
            </div>
            <div class="field">
                <label>About — Rôle du fondateur</label>
                <input type="text" id="pc-about-founder-role" style="width:100%">
            </div>
            <div class="field">
                <label>About — Introduction fondateur</label>
                <textarea id="pc-about-founder-intro" rows="3" style="width:100%"></textarea>
            </div>

            <div class="field">
                <label>Contact — Sous-titre héro</label>
                <input type="text" id="pc-contact-hero" style="width:100%">
            </div>
            <div class="field">
                <label>Contact — Texte d'introduction</label>
                <textarea id="pc-contact-intro" rows="3" style="width:100%"></textarea>
            </div>

            <div class="field">
                <label>Privacy — Texte d'accueil</label>
                <textarea id="pc-privacy-welcome" rows="3" style="width:100%"></textarea>
            </div>
            <div class="field">
                <label>Privacy — Conclusion</label>
                <textarea id="pc-privacy-conclusion" rows="2" style="width:100%"></textarea>
            </div>

            <div style="margin-top:12px;">
                <button type="button" onclick="savePageContent()" style="background:#16a34a;color:#fff;border:none;padding:9px 20px;border-radius:6px;cursor:pointer;font-size:14px;">
                    💾 Sauvegarder le contenu
                </button>
                <span id="page-save-status" style="margin-left:12px;font-size:13px;"></span>
            </div>
        </div>
    </div>
</div>

<script>
let _pcGenerated = null;

async function generatePageContent() {
    document.getElementById('btn-gen-pages').disabled = true;
    document.getElementById('page-gen-spinner').style.display = 'inline';
    document.getElementById('page-gen-status').textContent = '';
    document.getElementById('page-content-preview').style.display = 'none';

    try {
        const res = await fetch('page-content-api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'generate'})
        });
        const json = await res.json();

        if (!json.success) {
            document.getElementById('page-gen-status').textContent = '❌ ' + (json.message || 'Erreur inconnue');
            return;
        }

        _pcGenerated = json.data;
        _fillPageContentPreview(_pcGenerated);
        document.getElementById('page-content-preview').style.display = 'block';
        document.getElementById('page-gen-status').textContent = '✅ Contenu généré ! Vérifiez et sauvegardez.';
    } catch (e) {
        document.getElementById('page-gen-status').textContent = '❌ Erreur réseau: ' + e.message;
    } finally {
        document.getElementById('btn-gen-pages').disabled = false;
        document.getElementById('page-gen-spinner').style.display = 'none';
    }
}

function _fillPageContentPreview(d) {
    document.getElementById('pc-home-tagline').value        = d.home?.hero_tagline || '';
    document.getElementById('pc-home-welcome').value        = d.home?.welcome_text || '';
    document.getElementById('pc-about-hero').value          = d.about?.hero_subtitle || '';
    document.getElementById('pc-about-founder-name').value  = d.about?.founder_name || '';
    document.getElementById('pc-about-founder-role').value  = d.about?.founder_role || '';
    document.getElementById('pc-about-founder-intro').value = d.about?.founder_intro || '';
    document.getElementById('pc-contact-hero').value        = d.contact?.hero_subtitle || '';
    document.getElementById('pc-contact-intro').value       = d.contact?.intro || '';
    document.getElementById('pc-privacy-welcome').value     = d.privacy?.welcome_text || '';
    document.getElementById('pc-privacy-conclusion').value  = d.privacy?.conclusion_text || '';
}

async function savePageContent() {
    if (!_pcGenerated) return;

    // Merge edits back into generated data
    _pcGenerated.home  = _pcGenerated.home  || {};
    _pcGenerated.about = _pcGenerated.about || {};
    _pcGenerated.contact = _pcGenerated.contact || {};
    _pcGenerated.privacy = _pcGenerated.privacy || {};

    _pcGenerated.home.hero_tagline        = document.getElementById('pc-home-tagline').value;
    _pcGenerated.home.welcome_text        = document.getElementById('pc-home-welcome').value;
    _pcGenerated.about.hero_subtitle      = document.getElementById('pc-about-hero').value;
    _pcGenerated.about.founder_name       = document.getElementById('pc-about-founder-name').value;
    _pcGenerated.about.founder_role       = document.getElementById('pc-about-founder-role').value;
    _pcGenerated.about.founder_intro      = document.getElementById('pc-about-founder-intro').value;
    _pcGenerated.contact.hero_subtitle    = document.getElementById('pc-contact-hero').value;
    _pcGenerated.contact.intro            = document.getElementById('pc-contact-intro').value;
    _pcGenerated.privacy.welcome_text     = document.getElementById('pc-privacy-welcome').value;
    _pcGenerated.privacy.conclusion_text  = document.getElementById('pc-privacy-conclusion').value;

    const statusEl = document.getElementById('page-save-status');
    statusEl.textContent = '⏳ Sauvegarde...';

    try {
        const res = await fetch('page-content-api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'save', data: _pcGenerated})
        });
        const json = await res.json();
        statusEl.textContent = json.success ? '✅ Sauvegardé !' : '❌ ' + json.message;
        if (json.success) setTimeout(() => location.reload(), 1200);
    } catch (e) {
        statusEl.textContent = '❌ Erreur: ' + e.message;
    }
}
</script>

<!-- ── Satellites (admin only) ── -->
<?php if (auth_is_admin()): ?>
<div class="section">
    <div class="section-header" onclick="toggleSection(this)">
        <span class="icon">📡</span><h2>Satellites</h2><span class="chevron">▾</span>
    </div>
    <div class="section-body">
        <div id="satellites-list">
        <?php foreach (SATELLITE_PROJECTS as $sat): ?>
        <div class="satellite-item">
            <button type="button" class="remove-sat" onclick="this.closest('.satellite-item').remove()">×</button>
            <div class="sat-grid">
                <div class="field" style="margin-bottom:0">
                    <label style="font-size:13px;font-weight:500;color:#374151">Path local</label>
                    <input type="text" class="sat-path" value="<?= htmlspecialchars($sat['path']) ?>" placeholder="../NomSatellite">
                </div>
                <div class="field" style="margin-bottom:0">
                    <label style="font-size:13px;font-weight:500;color:#374151">URL</label>
                    <input type="text" class="sat-url" value="<?= htmlspecialchars($sat['url']) ?>" placeholder="http://localhost/...">
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:8px;align-items:center;margin-top:10px;flex-wrap:wrap;">
            <button type="button" class="btn-add" onclick="addSatellite()" style="flex:1">+ Ajouter un satellite</button>
            <button type="button" id="btn-save-satellites" onclick="saveSatellites()"
                style="background:#0f766e;color:#fff;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;white-space:nowrap;">
                💾 Sauvegarder satellites
            </button>
        </div>
        <div id="sat-save-msg" style="display:none;margin-top:8px;padding:8px 12px;border-radius:6px;font-size:13px;"></div>

        <div style="margin-top:18px;padding-top:16px;border-top:1px solid #e2e8f0;">
            <p style="margin:0 0 8px;font-size:13px;color:#555;">Synchronise les fichiers de code depuis <strong>Demo</strong> (template principal) vers les satellites ou un autre site main.<br>
            <em style="color:#888;">pages/page-content.json est copié uniquement si absent sur la cible. config.php et site-config.json ne sont jamais écrasés.</em></p>

            <!-- Sync vers tous les satellites -->
            <button type="button" id="btn-sync-code" onclick="syncCodeToSatellites(null)" style="background:#7c3aed;color:#fff;border:none;padding:9px 20px;border-radius:6px;cursor:pointer;font-size:14px;">
                🔄 Sync code → tous les satellites
            </button>

            <!-- Sync vers un site main custom -->
            <div style="margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="text" id="sync-custom-path" placeholder="ex: SmartBudgetStart ou C:/xampp/htdocs/SitePinterset/SmartBudgetStart"
                    style="flex:1;min-width:260px;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                <button type="button" onclick="syncCodeToCustomPath()" style="background:#0891b2;color:#fff;border:none;padding:9px 16px;border-radius:6px;cursor:pointer;font-size:14px;white-space:nowrap;">
                    🔄 Sync → ce site
                </button>
            </div>

            <span id="sync-code-spinner" style="display:none;margin-left:10px;color:#888;">⏳ Sync en cours...</span>
            <div id="sync-code-log" style="display:none;margin-top:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:12px;font-size:13px;font-family:monospace;white-space:pre-wrap;max-height:200px;overflow-y:auto;"></div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
async function syncCodeToSatellites() {
    await _doSyncCode({});
}

async function syncCodeToCustomPath() {
    const path = document.getElementById('sync-custom-path').value.trim();
    if (!path) { alert('Entre le nom du dossier ou le chemin complet.'); return; }
    await _doSyncCode({ target_path: path });
}

async function _doSyncCode(body) {
    document.getElementById('btn-sync-code').disabled = true;
    document.getElementById('sync-code-spinner').style.display = 'inline';
    const logEl = document.getElementById('sync-code-log');
    logEl.style.display = 'none';

    try {
        const res = await fetch('config-api.php?action=sync_page_code', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(body)
        });
        const json = await res.json();
        logEl.style.display = 'block';

        if (!json.success) {
            logEl.textContent = '❌ ' + (json.message || 'Erreur inconnue');
            return;
        }
        if (!json.log || !json.log.length) {
            logEl.textContent = json.message || 'Aucun site à synchroniser.';
            return;
        }
        let out = '';
        for (const entry of json.log) {
            out += `\n📁 ${entry.sat} [${entry.status}]\n`;
            if (entry.msg) out += `   ${entry.msg}\n`;
            if (entry.files) entry.files.forEach(f => out += `   ${f}\n`);
        }
        logEl.textContent = out.trim();
    } catch (e) {
        logEl.style.display = 'block';
        logEl.textContent = '❌ Erreur réseau: ' + e.message;
    } finally {
        document.getElementById('btn-sync-code').disabled = false;
        document.getElementById('sync-code-spinner').style.display = 'none';
    }
}
</script>

<!-- ── Images ── -->
<div class="section">
    <div class="section-header" onclick="toggleSection(this)">
        <span class="icon">🖼️</span><h2>Traitement Images</h2><span class="chevron">▾</span>
    </div>
    <div class="section-body">
        <div class="row2">
            <div class="field">
                <?php lbl('IMG1', 'IMG1', 'index image principale (0-based)') ?>
                <input type="number" name="IMG1" value="<?= IMG1 ?>" min="0" max="10">
            </div>
            <div class="field">
                <?php lbl('IMG2', 'IMG2', 'index image secondaire') ?>
                <input type="number" name="IMG2" value="<?= IMG2 ?>" min="0" max="10">
            </div>
        </div>
        <div class="field">
            <?php lbl('ZOOM', 'ZOOM') ?>
            <input type="text" name="ZOOM" value="<?= htmlspecialchars(ZOOM) ?>">
        </div>
        <div class="toggle-row">
            <div class="info">
                <label>FLIP — Retourner les images horizontalement
                    <?php if (array_key_exists('FLIP', $_ovr)): ?>
                        <span class="badge-ovr" style="margin-left:6px" id="badge_FLIP">modifié</span>
                        <button type="button" class="reset-btn" onclick="resetFlip()" title="Revenir au défaut">↺ défaut</button>
                        <input type="hidden" name="reset_FLIP" id="hid_FLIP" value="0">
                    <?php endif; ?>
                </label>
            </div>
            <label class="toggle">
                <input type="checkbox" name="FLIP" id="flipCheck" <?= FLIP ? 'checked' : '' ?>>
                <span class="slider"></span>
            </label>
        </div>
    </div>
</div>

<!-- ── Pinterest ── -->
<div class="section">
    <div class="section-header" onclick="toggleSection(this)">
        <span class="icon">📌</span><h2>Pinterest — Link Pin</h2><span class="chevron">▾</span>
    </div>
    <div class="section-body">
        <div class="info-box">
            ℹ️ Le toggle <strong>🔗 Link Pin</strong> est géré depuis la liste articles.
            &nbsp;<a href="posts-liste.php" style="color:#1d4ed8;font-weight:600">Ouvrir →</a>
        </div>
        <div style="margin-top:12px;padding:10px 14px;background:#f8fafc;border-radius:8px;font-size:14px;color:#374151">
            État actuel :
            <strong style="color:<?= $linkPinActive ? '#16a34a' : '#dc2626' ?>">
                <?= $linkPinActive ? '✅ Actif — liens inclus dans le CSV' : '❌ Inactif — liens vides dans le CSV' ?>
            </strong>
        </div>


        <hr style="border:none;border-top:1px solid #f1f5f9;margin:20px 0">
        <div class="row2">
            <div class="field">
                <?php lbl('CSV_PUBLISH_SPACING_DAYS', 'Espacement CSV (jours)', 'nombre de jours entre chaque groupe de publications dans le CSV') ?>
                <input type="number" name="CSV_PUBLISH_SPACING_DAYS"
                    value="<?= (int)_cfg('CSV_PUBLISH_SPACING_DAYS', 7) ?>"
                    min="1" max="30" step="1" style="width:100px">
                <small style="color:#64748b;margin-top:4px;display:block">Espacement entre groupes d'articles dans le CSV (ex: 7 = 1 semaine)</small>
            </div>
            <div class="field">
                <?php lbl('CSV_GUARD_MIN_ROWS', 'Seuil lignes CSV (guard pipeline)', 'Si le CSV du jour contient plus de N lignes, la pipeline ne se relance pas') ?>
                <input type="number" name="CSV_GUARD_MIN_ROWS"
                    value="<?= (int)_cfg('CSV_GUARD_MIN_ROWS', 5) ?>"
                    min="0" max="100" step="1" style="width:100px">
                <small style="color:#64748b;margin-top:4px;display:block">CSV du jour &gt; N lignes → pipeline bloquée (défaut : 5) — <b>0 = guard désactivé</b></small>
            </div>
        </div>

        <div class="toggle-row" style="margin-top:12px">
            <div class="info">
                <label>🎬 Video Pins Pinterest</label>
                <span class="hint">Génère un reel MP4 (9:16) par post et l'ajoute au CSV (Media URL = .mp4, Thumbnail = cover). Video pins = 2-3x reach. <b>Nécessite FFmpeg</b> (config Facebook Reels).</span>
            </div>
            <label class="switch">
                <input type="checkbox" name="PINTEREST_VIDEO_PINS_ACTIVE" value="1"
                    <?= (defined('PINTEREST_VIDEO_PINS_ACTIVE') && PINTEREST_VIDEO_PINS_ACTIVE) ? 'checked' : '' ?>>
                <span class="slider"></span>
            </label>
        </div>
        <div class="field" style="margin-top:8px">
            <?php lbl('PINTEREST_VIDEO_BASE_URL', 'URL serveur des videos', 'Les MP4 sont servis DIRECTEMENT par le serveur (jamais committés dans git → push rapide)') ?>
            <input type="text" name="PINTEREST_VIDEO_BASE_URL"
                value="<?= htmlspecialchars(defined('PINTEREST_VIDEO_BASE_URL') ? PINTEREST_VIDEO_BASE_URL : '') ?>"
                placeholder="https://39.113.162.145/sites-moad/pinrecipes" style="width:100%">
            <small style="color:#64748b;margin-top:4px;display:block">
                URL publique du serveur Linux où le site tourne. Vide = <code>https://<?= defined('HOST_NAME') ? HOST_NAME : 'HOST_NAME' ?></code>.
                Les videos ne passent <b>pas</b> par git/GitHub.
            </small>
        </div>

        <div class="toggle-row" style="margin-top:12px">
            <div class="info">
                <label>♻️ Fresh-Pin Recycling</label>
                <span class="hint">Re-pin d'anciens posts publiés avec un design frais à chaque run (sans toucher leur date de publication). Pinterest récompense les pins frais → multiplie les impressions.</span>
            </div>
            <label class="switch">
                <input type="checkbox" name="PINTEREST_RECYCLE_ACTIVE" value="1"
                    <?= (defined('PINTEREST_RECYCLE_ACTIVE') && PINTEREST_RECYCLE_ACTIVE) ? 'checked' : '' ?>>
                <span class="slider"></span>
            </label>
        </div>
        <div class="row2" style="margin-top:8px">
            <div class="field">
                <?php lbl('PINTEREST_RECYCLE_COUNT', 'Posts recyclés / run', 'Combien d\'anciens posts re-pinner à chaque exécution') ?>
                <input type="number" name="PINTEREST_RECYCLE_COUNT" min="0" max="100"
                    value="<?= (int)_cfg('PINTEREST_RECYCLE_COUNT', 10) ?>" style="width:100px">
            </div>
            <div class="field">
                <?php lbl('PINTEREST_RECYCLE_MIN_DAYS', 'Délai min (jours)', 'Ne pas re-pinner un post recyclé il y a moins de N jours') ?>
                <input type="number" name="PINTEREST_RECYCLE_MIN_DAYS" min="1" max="90"
                    value="<?= (int)_cfg('PINTEREST_RECYCLE_MIN_DAYS', 7) ?>" style="width:100px">
            </div>
        </div>

        <hr style="border:none;border-top:1px solid #f1f5f9;margin:20px 0">
        <div class="row2">
            <div class="field">
                <?php lbl('PIN_SCHEDULE_START', 'Heure début publication (h)', 'Heure de début pour les pins Pinterest (0-23) — ex: 16 = 16:00') ?>
                <input type="number" name="PIN_SCHEDULE_START"
                    value="<?= (int)_cfg('PIN_SCHEDULE_START', 16) ?>"
                    min="0" max="23" step="1" style="width:100px">
                <small style="color:#64748b;margin-top:4px;display:block">Heure de début (incluse)</small>
            </div>
            <div class="field">
                <?php lbl('PIN_SCHEDULE_END', 'Heure fin publication (h)', 'Heure de fin pour les pins Pinterest (0-23) — ex: 4 = 04:00 AM') ?>
                <input type="number" name="PIN_SCHEDULE_END"
                    value="<?= (int)_cfg('PIN_SCHEDULE_END', 4) ?>"
                    min="0" max="23" step="1" style="width:100px">
                <small style="color:#64748b;margin-top:4px;display:block">Heure de fin (peut dépasser minuit)</small>
            </div>
        </div>

        <hr style="border:none;border-top:1px solid #f1f5f9;margin:20px 0">
        <div class="field">
            <?php lbl('KEYWORDS_PIN_DIR', 'Dossier keywordsPIN (Google Trends CSV)', 'Chemin absolu vers le dossier contenant les CSV Google Trends') ?>
            <input type="text" name="KEYWORDS_PIN_DIR"
                value="<?= htmlspecialchars(defined('KEYWORDS_PIN_DIR') ? KEYWORDS_PIN_DIR : '') ?>"
                placeholder="<?= htmlspecialchars(realpath(__DIR__ . '/keywordsPIN') ?: __DIR__ . '/keywordsPIN') ?>"
                style="width:100%">
            <small style="color:#64748b;margin-top:4px;display:block">Laisser vide = dossier <code>keywordsPIN/</code> dans le site (défaut). Remplir pour pointer vers un autre chemin.</small>
        </div>

        <div class="field">
            <?php lbl('KEYWORD_SOURCE', 'Source des keywords', 'Comment générer les keywords/titres pour le pipeline') ?>
            <select name="KEYWORD_SOURCE" id="keyword_source_select" onchange="toggleSeedsField()">
                <?php
                $kwSrc = defined('KEYWORD_SOURCE') ? KEYWORD_SOURCE : 'pinterest_trends';
                $kwOpts = [
                    'pinterest_import' => '📥 Pinterest Trends Import (CSV manuel — données réelles)',
                    'google_suggest'   => '🔍 Google Suggest (gratuit, sans API)',
                    'prompt'           => '🤖 Prompt IA (OpenAI / Anthropic)',
                ];
                foreach ($kwOpts as $val => $label):
                ?>
                <option value="<?= $val ?>" <?= $kwSrc === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <small style="color:#64748b;margin-top:4px;display:block">
                <b>📥 Import CSV</b> — export manuel depuis trends.pinterest.com → données réelles Pinterest, valable N jours. Fallback auto Google Suggest si expiré.<br>
                <b>🔍 Google Suggest</b> — autocomplétion Google sur les seeds ci-dessous (gratuit, zéro coût API).<br>
                <b>🤖 Prompt IA</b> — génère des titres créatifs avec contexte saisonnier + Google Trends (coût API).
            </small>
        </div>

        <?php
        $kwSrcCurrent = defined('KEYWORD_SOURCE') ? KEYWORD_SOURCE : 'pinterest_import';
        $showSeeds    = ($kwSrcCurrent === 'google_suggest');
        ?>
        <?php
        // ── Bloc import CSV Pinterest Trends ─────────────────────────────────
        $importFile = defined('PINTEREST_TRENDS_IMPORT_PATH')
            ? PINTEREST_TRENDS_IMPORT_PATH
            : (__DIR__ . '/downloads/pinterest-trends-import.csv');
        $importMaxDays = defined('PINTEREST_TRENDS_IMPORT_MAX_DAYS') ? (int)PINTEREST_TRENDS_IMPORT_MAX_DAYS : 7;
        $showImport = ($kwSrcCurrent === 'pinterest_import');
        ?>
        <div class="field" id="pinterest_import_block" style="<?= $showImport ? '' : 'display:none' ?>">
            <label style="font-weight:600;color:#374151;display:block;margin-bottom:8px">📥 Import Pinterest Trends CSV</label>
            <?php if (file_exists($importFile)): ?>
                <?php
                $age     = floor((time() - filemtime($importFile)) / 86400);
                $kwCount = max(0, count(file($importFile)) - 1);
                $expires = $importMaxDays - $age;
                $bgColor = $expires > 2 ? '#f0fdf4' : ($expires > 0 ? '#fffbeb' : '#fef2f2');
                $bdColor = $expires > 2 ? '#86efac' : ($expires > 0 ? '#fcd34d' : '#fca5a5');
                $txColor = $expires > 2 ? '#16a34a' : ($expires > 0 ? '#d97706' : '#dc2626');
                $icon    = $expires > 0 ? '✅' : '⚠️';
                ?>
                <div style="background:<?= $bgColor ?>;border:1px solid <?= $bdColor ?>;border-radius:8px;padding:10px;margin-bottom:10px;font-size:13px">
                    <b style="color:<?= $txColor ?>"><?= $icon ?> Import actif</b> —
                    <b><?= $kwCount ?></b> keywords |
                    importé il y a <b><?= $age ?> jour(s)</b> |
                    <?= $expires > 0 ? "expire dans <b>$expires jour(s)</b>" : "<b>expiré</b> — pipeline utilise le fallback" ?>
                </div>
            <?php else: ?>
                <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:10px;margin-bottom:10px;font-size:13px">
                    ⚠️ Aucun fichier importé — le pipeline utilise le fallback automatique
                </div>
            <?php endif; ?>
            <div style="border:2px dashed #cbd5e1;border-radius:8px;padding:14px;text-align:center">
                <div style="font-weight:600;margin-bottom:6px">Comment importer :</div>
                <ol style="text-align:left;display:inline-block;font-size:13px;color:#475569;margin:0 0 10px">
                    <li>Ouvrir <b>trends.pinterest.com</b> (compte Business)</li>
                    <li>Configurer tes filtres (Growing, Food & Drinks, Female, 65+...)</li>
                    <li>Cliquer <b>Export</b> → télécharger le CSV</li>
                    <li>Glisser le fichier ici ↓</li>
                </ol>
                <br>
                <input type="file" id="pinterest_trends_upload" accept=".csv" style="display:none">
                <button type="button" onclick="document.getElementById('pinterest_trends_upload').click()"
                    style="background:#e11d48;color:#fff;border:none;padding:8px 18px;border-radius:6px;cursor:pointer;font-size:14px">
                    📂 Choisir le CSV Pinterest Trends
                </button>
                <span id="pinterest_upload_status" style="display:block;margin-top:8px;font-size:13px;color:#64748b"></span>
            </div>
            <div style="margin-top:8px;display:flex;align-items:center;gap:10px">
                <?php lbl('PINTEREST_TRENDS_IMPORT_MAX_DAYS', 'Expiration (jours)', 'Durée de validité du fichier avant fallback automatique') ?>
                <input type="number" name="PINTEREST_TRENDS_IMPORT_MAX_DAYS" min="1" max="30"
                    value="<?= $importMaxDays ?>" style="width:70px">
                <small style="color:#64748b">jours avant que le pipeline bascule sur le fallback</small>
            </div>
        </div>


        <div class="field" id="suggest_seeds_field" style="<?= $showSeeds ? '' : 'display:none' ?>">
            <?php lbl('KEYWORD_SUGGEST_SEEDS', 'Seeds Google Suggest', 'Un mot-clé par ligne — Google Suggest sera appelé pour chacun') ?>
            <textarea name="KEYWORD_SUGGEST_SEEDS" rows="8" style="width:100%;font-family:monospace;font-size:13px"
                placeholder="chicken&#10;pasta&#10;salad&#10;soup&#10;cake&#10;..."
            ><?= htmlspecialchars(defined('KEYWORD_SUGGEST_SEEDS') ? KEYWORD_SUGGEST_SEEDS : '') ?></textarea>
            <small style="color:#64748b;margin-top:4px;display:block">
                Un seed par ligne. Les ingrédients saisonniers sont ajoutés automatiquement.
                Google retourne ~10 suggestions par seed → plus de seeds = plus de variété.
            </small>
        </div>

        <hr style="border:none;border-top:1px solid #f1f5f9;margin:20px 0">
        <div class="toggle-row">
            <div class="info">
                <label>📘 Cross-post Pinterest → Facebook</label>
                <span class="hint">
                    Poste les articles du CSV Pinterest sur ta Page Facebook, schedulés à la même heure.
                    Nécessite <strong>FACEBOOK_PAGE_ID</strong> et <strong>FACEBOOK_ACCESS_TOKEN</strong>.
                </span>
            </div>
            <label class="toggle">
                <input type="checkbox" name="FACEBOOK_CROSSPOST_ACTIVE"
                       <?= (defined('FACEBOOK_CROSSPOST_ACTIVE') && FACEBOOK_CROSSPOST_ACTIVE) ? 'checked' : '' ?>>
                <span class="slider"></span>
            </label>
        </div>
    </div>
</div>

<!-- ── Prompts ── -->
<div class="section">
    <div class="section-header collapsed" onclick="toggleSection(this)">
        <span class="icon">✍️</span><h2>Prompts de génération</h2><span class="chevron">▾</span>
    </div>
    <div class="section-body hidden">
        <div class="prompt-tabs">
            <button type="button" class="prompt-tab active" onclick="switchPrompt(this,'post')">POST_PROMPT</button>
            <button type="button" class="prompt-tab" onclick="switchPrompt(this,'rewrite')">REWRITE_POST_PROMPT</button>
            <button type="button" class="prompt-tab" onclick="switchPrompt(this,'img1')">IMG 1</button>
            <button type="button" class="prompt-tab" onclick="switchPrompt(this,'img2')">IMG 2</button>
            <button type="button" class="prompt-tab" onclick="switchPrompt(this,'img3')">IMG 3</button>
            <button type="button" class="prompt-tab" onclick="switchPrompt(this,'pinterest-csv')">📌 Pinterest CSV</button>
        </div>
        <div id="prompt-post" class="prompt-panel active">
            <div class="field">
                <?php lbl('POST_PROMPT', 'Prompt création article', 'POST_PROMPT') ?>
                <textarea name="POST_PROMPT" rows="25"><?= htmlspecialchars(POST_PROMPT) ?></textarea>
            </div>
        </div>
        <div id="prompt-rewrite" class="prompt-panel">
            <div class="field">
                <?php lbl('REWRITE_POST_PROMPT', 'Prompt réécriture', 'REWRITE_POST_PROMPT') ?>
                <textarea name="REWRITE_POST_PROMPT" rows="25"><?= htmlspecialchars(REWRITE_POST_PROMPT) ?></textarea>
            </div>
        </div>
        <div id="prompt-img1" class="prompt-panel">
            <div class="field">
                <?php lbl('IMG_PROMPT_1', 'Prompt image 1', 'utilise {title} comme placeholder — vide = prompt intégré aléatoire') ?>
                <textarea name="IMG_PROMPT_1" rows="8" placeholder="Ex: Extreme close-up of {title} on a rustic plate..."><?= htmlspecialchars(IMG_PROMPT_1) ?></textarea>
            </div>
        </div>
        <div id="prompt-img2" class="prompt-panel">
            <div class="field">
                <?php lbl('IMG_PROMPT_2', 'Prompt image 2', 'utilise {title} comme placeholder — vide = prompt intégré aléatoire') ?>
                <textarea name="IMG_PROMPT_2" rows="8" placeholder="Ex: Eye-level side shot of {title}..."><?= htmlspecialchars(IMG_PROMPT_2) ?></textarea>
            </div>
        </div>
        <div id="prompt-img3" class="prompt-panel">
            <div class="field">
                <?php lbl('IMG_PROMPT_3', 'Prompt image 3', 'utilise {title} comme placeholder — vide = prompt intégré aléatoire') ?>
                <textarea name="IMG_PROMPT_3" rows="8" placeholder="Ex: Macro close-up of {title}..."><?= htmlspecialchars(IMG_PROMPT_3) ?></textarea>
            </div>
        </div>
        <div id="prompt-pinterest-csv" class="prompt-panel">
            <div class="field">
                <?php lbl('PINTEREST_CSV_PROMPT', 'Prompt — Pinterest CSV Keyword Generator', 'Placeholders: {MONTH}, {SEASON}, {INGREDIENTS}, {TRENDS}, {COUNT}') ?>
                <textarea name="PINTEREST_CSV_PROMPT" rows="22"><?= htmlspecialchars(defined('PINTEREST_CSV_PROMPT') ? PINTEREST_CSV_PROMPT : '') ?></textarea>
            </div>
        </div>
    </div>
</div>

<!-- ── Template ── -->
<!-- ── Facebook Reels ── -->
<div class="section">
    <div class="section-header collapsed" onclick="toggleSection(this)">
        <span class="icon">🎬</span><h2>Facebook Reels</h2><span class="chevron">▾</span>
    </div>
    <div class="section-body hidden">
        <div class="field">
            <?php lbl('FACEBOOK_PAGE_URL', 'Page Facebook URL', 'Lien vers votre page Facebook') ?>
            <input type="text" name="FACEBOOK_PAGE_URL" value="<?= htmlspecialchars(defined('FACEBOOK_PAGE_URL') ? FACEBOOK_PAGE_URL : '') ?>" placeholder="https://www.facebook.com/monpage">
        </div>
        <div class="row2">
            <div class="field">
                <?php lbl('FACEBOOK_CTA_TEXT', 'Texte CTA (Frame 5)', 'Appel à l\'action sur la dernière frame') ?>
                <input type="text" name="FACEBOOK_CTA_TEXT" value="<?= htmlspecialchars(defined('FACEBOOK_CTA_TEXT') ? FACEBOOK_CTA_TEXT : 'Get the full recipe at') ?>">
            </div>
            <div class="field">
                <?php lbl('FACEBOOK_FRAME_DURATION', 'Durée par frame (sec)', 'Durée d\'affichage de chaque image dans la vidéo') ?>
                <input type="number" name="FACEBOOK_FRAME_DURATION" value="<?= (int)(defined('FACEBOOK_FRAME_DURATION') ? FACEBOOK_FRAME_DURATION : 4) ?>" min="1" max="15">
            </div>
        </div>
        <div class="field">
            <?php lbl('FACEBOOK_HASHTAGS', 'Hashtags par défaut', 'Affichés sur la frame CTA') ?>
            <input type="text" name="FACEBOOK_HASHTAGS" value="<?= htmlspecialchars(defined('FACEBOOK_HASHTAGS') ? FACEBOOK_HASHTAGS : '') ?>" placeholder="#recipes #food #homecooking">
        </div>
        <div class="field">
            <?php lbl('FACEBOOK_FFMPEG_PATH', 'Chemin FFmpeg', 'Chemin vers l\'exécutable ffmpeg (ex: C:/ffmpeg/bin/ffmpeg.exe). Laisser "ffmpeg" si dans le PATH.') ?>
            <input type="text" name="FACEBOOK_FFMPEG_PATH" value="<?= htmlspecialchars(defined('FACEBOOK_FFMPEG_PATH') ? FACEBOOK_FFMPEG_PATH : 'ffmpeg') ?>" placeholder="ffmpeg">
        </div>
        <div class="row2">
            <div class="field">
                <?php lbl('FACEBOOK_DAILY_COUNT', 'Posts par jour', 'Nombre d\'articles postés automatiquement chaque jour (5 ou 10)') ?>
                <input type="number" name="FACEBOOK_DAILY_COUNT" value="<?= (int)(defined('FACEBOOK_DAILY_COUNT') ? FACEBOOK_DAILY_COUNT : 5) ?>" min="1" max="20">
            </div>
            <div class="field">
                <?php lbl('FACEBOOK_POST_TYPE', 'Type de post', 'photo = image programmée dans l\'agenda | video = Reel généré avec FFmpeg') ?>
                <select name="FACEBOOK_POST_TYPE">
                    <option value="photo" <?= (_cfg('FACEBOOK_POST_TYPE','photo') === 'photo') ? 'selected' : '' ?>>📷 Photo (agenda Meta)</option>
                    <option value="video" <?= (_cfg('FACEBOOK_POST_TYPE','photo') === 'video') ? 'selected' : '' ?>>🎬 Vidéo / Reel (FFmpeg)</option>
                </select>
            </div>
        </div>
        <div class="row2">
            <div class="field">
                <?php lbl('FACEBOOK_POST_HOUR_START', 'Heure début posting', 'Ex: 16 pour 16h00') ?>
                <input type="number" name="FACEBOOK_POST_HOUR_START" value="<?= (int)(defined('FACEBOOK_POST_HOUR_START') ? FACEBOOK_POST_HOUR_START : 16) ?>" min="0" max="23">
            </div>
            <div class="field">
                <?php lbl('FACEBOOK_POST_HOUR_END', 'Heure fin posting', 'Ex: 4 pour 04h00 (lendemain si < début)') ?>
                <input type="number" name="FACEBOOK_POST_HOUR_END" value="<?= (int)(defined('FACEBOOK_POST_HOUR_END') ? FACEBOOK_POST_HOUR_END : 4) ?>" min="0" max="23">
            </div>
        </div>
        <hr style="border:none;border-top:1px solid #f1f5f9;margin:20px 0">
        <strong style="font-size:13px;color:#1e293b">📤 Publication automatique</strong>

        <!-- App credentials -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:12px 0">
            <div class="field" style="margin:0">
                <?php lbl('FACEBOOK_APP_ID', 'App ID') ?>
                <input type="text" name="FACEBOOK_APP_ID" id="fb_app_id"
                    value="<?= htmlspecialchars(_cfg('FACEBOOK_APP_ID', '')) ?>"
                    placeholder="1234567890"
                    style="font-family:monospace">
            </div>
            <div class="field" style="margin:0">
                <?php lbl('FACEBOOK_APP_SECRET', 'App Secret') ?>
                <input type="password" name="FACEBOOK_APP_SECRET" id="fb_app_secret"
                    value=""
                    placeholder="<?= _cfg('FACEBOOK_APP_SECRET', '') ? '••••••• (inchangé)' : 'abc123...' ?>"
                    style="font-family:monospace">
            </div>
        </div>

        <!-- OAuth Connect -->
        <div style="background:#f0f4ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px;margin-bottom:14px">
            <strong style="font-size:12px;color:#1877F2">🔗 Connexion Facebook (OAuth)</strong>
            <small style="display:block;color:#64748b;font-size:11px;margin:6px 0 10px">
                Sauvegarde App ID + App Secret ci-dessus → clique le bouton → connecte ton compte FB → token permanent sauvegardé automatiquement.
            </small>
            <a href="fb-oauth-callback.php"
               style="display:inline-block;padding:9px 18px;background:#1877F2;color:#fff;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none">
                Connecter Facebook
            </a>
            <?php if (_cfg('FACEBOOK_ACCESS_TOKEN', '')): ?>
            <span style="margin-left:10px;font-size:12px;color:#16a34a">✅ Déjà connecté</span>
            <?php endif; ?>
        </div>

        <!-- Page ID + Token -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
            <div class="field" style="margin:0">
                <?php lbl('FACEBOOK_PAGE_ID', 'Page ID') ?>
                <input type="text" name="FACEBOOK_PAGE_ID" id="fb_page_id"
                    value="<?= htmlspecialchars(_cfg('FACEBOOK_PAGE_ID', '')) ?>"
                    placeholder="123456789012345"
                    style="font-family:monospace">
            </div>
            <div class="field" style="margin:0">
                <?php lbl('FACEBOOK_ACCESS_TOKEN', 'Page Access Token') ?>
                <input type="password" name="FACEBOOK_ACCESS_TOKEN" id="fb_page_token"
                    value=""
                    placeholder="<?= _cfg('FACEBOOK_ACCESS_TOKEN', '') ? '••••••• (inchangé)' : 'EAABxx...' ?>"
                    style="font-family:monospace;font-size:11px">
            </div>
        </div>
        <?php if (_cfg('FACEBOOK_ACCESS_TOKEN', '')): ?>
        <small style="color:#16a34a">✅ Token configuré</small>
        <?php else: ?>
        <small style="color:#f59e0b">⚠️ Token non configuré</small>
        <?php endif; ?>


        <p style="margin-top:12px;font-size:0.82em;color:#6b7280">
            Générez les reels depuis <a href="index-facebook-tools.php" style="color:#1877F2">index-facebook-tools.php</a>
        </p>
    </div>
</div>


<div class="section">
    <div class="section-header collapsed" onclick="toggleSection(this)">
        <span class="icon">🖼️</span><h2>Template Pinterest</h2><span class="chevron">▾</span>
    </div>
    <div class="section-body hidden">

        <!-- Preset Selector -->
        <div class="field" style="margin-bottom:24px">
            <div class="lbl-row"><label>🎨 Design Preset <small>clique pour charger, puis Sauvegarder</small></label></div>
            <div class="tpl-cards">
            <?php
            foreach (TEMPLATE_PRESETS as $key => $preset):
                // Extra layouts (recipe_card, overlay_list) sont des templates additionnels, pas des choix — on les cache du sélecteur
                if (!isset($preset['TEMPLATE_CANVAS_HEIGHT'])) continue;
                $col  = htmlspecialchars($preset['preview']);
                $svgH = 70; $svgW = 35;
                {
                // Build proportional SVG layout diagram
                $sc   = $svgH / $preset['TEMPLATE_CANVAS_HEIGHT'];
                $by   = round($preset['TEMPLATE_BANNER_Y'] * $sc);
                $bh   = round($preset['TEMPLATE_BANNER_HEIGHT'] * $sc);
                $isLight = in_array($preset['preview'], ['#FAFAF8']);
                $imgFill = $isLight ? '#e2e8f0' : '#aaaaaa44';
                $svgDiagram = '<svg width="' . $svgW . '" height="' . $svgH . '" xmlns="http://www.w3.org/2000/svg">'
                    // Canvas background (photo zone)
                    . '<rect width="' . $svgW . '" height="' . $svgH . '" fill="#c8c8c8" rx="3"/>'
                    // Subtle photo texture lines
                    . '<rect width="' . $svgW . '" height="' . $svgH . '" fill="url(#pg' . $key . ')" rx="3"/>'
                    // Banner zone
                    . '<rect x="0" y="' . $by . '" width="' . $svgW . '" height="' . $bh . '" fill="' . $col . '" opacity="0.92"/>'
                    // Text line indicator
                    . '<rect x="4" y="' . ($by + (int)($bh/2) - 2) . '" width="' . ($svgW - 8) . '" height="3" fill="white" opacity="0.7" rx="1"/>'
                    . '</svg>';
                }
            ?>
                <div class="tpl-card <?= ACTIVE_TEMPLATE === $key ? 'active' : '' ?>" onclick="applyPreset('<?= $key ?>')" data-key="<?= $key ?>">
                    <div class="tpl-diagram"><?= $svgDiagram ?></div>
                    <div>
                        <span><?= htmlspecialchars($preset['name']) ?></span>
                        <small style="display:block;color:#9ca3af;font-size:11px;font-weight:400;margin-top:2px"><?= htmlspecialchars($preset['desc'] ?? '') ?></small>
                        <small style="display:block;margin-top:4px;font-size:10px;color:<?= htmlspecialchars($preset['preview']) === '#FAFAF8' ? '#555' : htmlspecialchars($preset['preview']) ?>;font-weight:700;letter-spacing:.5px">
                            <?= strtoupper($preset['layout'] ?? '') ?>
                        </small>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
            <input type="hidden" name="ACTIVE_TEMPLATE" id="active_template" value="<?= htmlspecialchars(ACTIVE_TEMPLATE) ?>">
        </div>

        <div class="field-group" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">

            <div class="field">
                <?php lbl('TEMPLATE_CANVAS_HEIGHT','Hauteur canvas','px') ?>
                <input type="number" name="TEMPLATE_CANVAS_HEIGHT" value="<?= _cfg('TEMPLATE_CANVAS_HEIGHT',2000) ?>" min="1000" max="3000" step="50">
                <small style="color:#888">Largeur fixe: 1000px</small>
            </div>

            <div class="field">
                <?php lbl('TEMPLATE_IMG1_HEIGHT','Hauteur image 1','px') ?>
                <input type="number" name="TEMPLATE_IMG1_HEIGHT" value="<?= _cfg('TEMPLATE_IMG1_HEIGHT',1120) ?>" min="500" max="2000" step="10">
            </div>

            <div class="field">
                <?php lbl('TEMPLATE_IMG2_Y','Image 2 — Y (position)','px depuis le haut') ?>
                <input type="number" name="TEMPLATE_IMG2_Y" value="<?= _cfg('TEMPLATE_IMG2_Y',1000) ?>" min="0" max="2500" step="10">
            </div>

            <div class="field">
                <?php lbl('TEMPLATE_IMG2_HEIGHT','Image 2 — Hauteur','px') ?>
                <input type="number" name="TEMPLATE_IMG2_HEIGHT" value="<?= _cfg('TEMPLATE_IMG2_HEIGHT',1000) ?>" min="0" max="2000" step="10">
            </div>

            <div class="field">
                <?php lbl('TEMPLATE_BANNER_Y','Banner Y (position)','px depuis le haut') ?>
                <input type="number" name="TEMPLATE_BANNER_Y" value="<?= _cfg('TEMPLATE_BANNER_Y',850) ?>" min="0" max="2000" step="10">
            </div>

            <div class="field">
                <?php lbl('TEMPLATE_BANNER_HEIGHT','Banner hauteur','px') ?>
                <input type="number" name="TEMPLATE_BANNER_HEIGHT" value="<?= _cfg('TEMPLATE_BANNER_HEIGHT',270) ?>" min="50" max="600" step="10">
            </div>

            <div class="field">
                <?php lbl('TEMPLATE_BANNER_COLOR','Couleur banner') ?>
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="color" id="clr_banner" value="<?= substr(_cfg('TEMPLATE_BANNER_COLOR','#93043dff'),0,7) ?>"
                        oninput="document.querySelector('[name=TEMPLATE_BANNER_COLOR]').value=this.value+'ff'">
                    <input type="text" name="TEMPLATE_BANNER_COLOR" value="<?= htmlspecialchars(_cfg('TEMPLATE_BANNER_COLOR','#93043dff')) ?>"
                        style="width:110px;font-family:monospace;">
                </div>
            </div>

            <div class="field">
                <?php lbl('TEMPLATE_TEXT_COLOR','Couleur texte') ?>
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="color" id="clr_text" value="<?= substr(_cfg('TEMPLATE_TEXT_COLOR','#ffffffff'),0,7) ?>"
                        oninput="document.querySelector('[name=TEMPLATE_TEXT_COLOR]').value=this.value+'ff'">
                    <input type="text" name="TEMPLATE_TEXT_COLOR" value="<?= htmlspecialchars(_cfg('TEMPLATE_TEXT_COLOR','#ffffffff')) ?>"
                        style="width:110px;font-family:monospace;">
                </div>
            </div>

            <div class="field">
                <?php lbl('TEMPLATE_FONT_SIZE','Taille police','px') ?>
                <input type="number" name="TEMPLATE_FONT_SIZE" value="<?= _cfg('TEMPLATE_FONT_SIZE',95) ?>" min="20" max="200" step="1">
            </div>

            <div class="field">
                <?php lbl('TEMPLATE_FONT_FAMILY','Font family') ?>
                <input type="text" name="TEMPLATE_FONT_FAMILY" value="<?= htmlspecialchars(_cfg('TEMPLATE_FONT_FAMILY','"Akaya Kanadaka", system-ui')) ?>">
            </div>

        </div>

        <div class="field" style="margin-top:12px;">
            <?php lbl('TEMPLATE_FONT_URL','Font URL (Google Fonts)') ?>
            <input type="text" name="TEMPLATE_FONT_URL" value="<?= htmlspecialchars(_cfg('TEMPLATE_FONT_URL','https://fonts.googleapis.com/css2?family=Akaya+Kanadaka&display=swap')) ?>">
        </div>

        <div class="field" style="margin-top:16px;">
            <?php lbl('TEMPLATE_BG_COLOR','Couleur fond canvas') ?>
            <div style="display:flex;gap:8px;align-items:center;">
                <input type="color" id="clr_bg" value="<?= substr(_cfg('TEMPLATE_BG_COLOR','#F5E6D3'),0,7) ?>"
                    oninput="document.querySelector('[name=TEMPLATE_BG_COLOR]').value=this.value">
                <input type="text" name="TEMPLATE_BG_COLOR" value="<?= htmlspecialchars(_cfg('TEMPLATE_BG_COLOR','#F5E6D3')) ?>"
                    style="width:110px;font-family:monospace;">
                <small style="color:#9ca3af">Dark Editorial → #1a1a1a · White Card → #FAFAF8</small>
            </div>
        </div>

        <div class="toggle-row" style="margin-top:16px">
            <div class="info">
                <label>Lignes décoratives (flanquant le titre)</label>
                <small>Recommended: ON pour Classic & Editorial, OFF pour White Card</small>
            </div>
            <label class="toggle">
                <input type="checkbox" name="TEMPLATE_DECOR_LINES" id="decorLinesCheck" <?= _cfg('TEMPLATE_DECOR_LINES', true) ? 'checked' : '' ?>>
                <span class="slider"></span>
            </label>
        </div>

        <!-- ── Engagement text ── -->
        <hr style="border:none;border-top:1px solid #f1f5f9;margin:20px 0">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
            <div>
                <strong style="font-size:13px;color:#1e293b">📣 Phrase d'engagement</strong>
                <small style="display:block;color:#94a3b8;font-size:11px;margin-top:2px">Texte affiché sous le banner — ex: "Save this post"</small>
            </div>
            <label class="toggle">
                <input type="checkbox" name="TEMPLATE_ENGAGEMENT_ENABLED" <?= _cfg('TEMPLATE_ENGAGEMENT_ENABLED', true) ? 'checked' : '' ?>>
                <span class="slider"></span>
            </label>
        </div>

        <div id="engagementFields">

        <div class="field">
            <?php lbl('TEMPLATE_ENGAGEMENT_TEXT', 'Texte') ?>
            <input type="text" name="TEMPLATE_ENGAGEMENT_TEXT"
                value="<?= htmlspecialchars(_cfg('TEMPLATE_ENGAGEMENT_TEXT', 'Save this post')) ?>"
                placeholder="Save this post">
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:14px">
            <div class="field" style="margin:0">
                <?php lbl('TEMPLATE_ENGAGEMENT_STYLE', 'Style') ?>
                <select name="TEMPLATE_ENGAGEMENT_STYLE">
                    <?php foreach (['pill'=>'Pill (arrondi)','lines'=>'Lignes','plain'=>'Plain'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= _cfg('TEMPLATE_ENGAGEMENT_STYLE','pill')===$v?'selected':'' ?>><?= $l ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="field" style="margin:0">
                <?php lbl('TEMPLATE_ENGAGEMENT_FONT_SIZE', 'Taille police', 'px') ?>
                <input type="number" name="TEMPLATE_ENGAGEMENT_FONT_SIZE"
                    value="<?= _cfg('TEMPLATE_ENGAGEMENT_FONT_SIZE', 28) ?>" min="14" max="80" step="1">
            </div>
            <div class="field" style="margin:0">
                <?php lbl('TEMPLATE_ENGAGEMENT_GAP', 'Distance banner', 'px') ?>
                <input type="number" name="TEMPLATE_ENGAGEMENT_GAP"
                    value="<?= _cfg('TEMPLATE_ENGAGEMENT_GAP', 42) ?>" min="10" max="200" step="5">
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
            <div class="field" style="margin:0">
                <?php lbl('TEMPLATE_ENGAGEMENT_COLOR', 'Couleur texte') ?>
                <div style="display:flex;gap:8px;align-items:center">
                    <input type="color" value="<?= substr(_cfg('TEMPLATE_ENGAGEMENT_COLOR','#ffffff'),0,7) ?>"
                        oninput="document.querySelector('[name=TEMPLATE_ENGAGEMENT_COLOR]').value=this.value">
                    <input type="text" name="TEMPLATE_ENGAGEMENT_COLOR"
                        value="<?= htmlspecialchars(_cfg('TEMPLATE_ENGAGEMENT_COLOR','#ffffff')) ?>"
                        style="width:90px;font-family:monospace">
                </div>
            </div>
            <div class="field" style="margin:0">
                <?php lbl('TEMPLATE_ENGAGEMENT_BG_COLOR', 'Couleur pill', 'vide = banner') ?>
                <div style="display:flex;gap:8px;align-items:center">
                    <input type="color" value="<?= substr(_cfg('TEMPLATE_ENGAGEMENT_BG_COLOR','#93043d') ?: '#93043d',0,7) ?>"
                        oninput="document.querySelector('[name=TEMPLATE_ENGAGEMENT_BG_COLOR]').value=this.value">
                    <input type="text" name="TEMPLATE_ENGAGEMENT_BG_COLOR"
                        value="<?= htmlspecialchars(_cfg('TEMPLATE_ENGAGEMENT_BG_COLOR','')) ?>"
                        placeholder="auto"
                        style="width:90px;font-family:monospace">
                </div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:14px">
            <div class="field" style="margin:0">
                <?php lbl('TEMPLATE_ENGAGEMENT_BG_ALPHA', 'Transparence pill', '0=opaque · 127=invisible') ?>
                <input type="number" name="TEMPLATE_ENGAGEMENT_BG_ALPHA"
                    value="<?= _cfg('TEMPLATE_ENGAGEMENT_BG_ALPHA', 60) ?>" min="0" max="127" step="5">
            </div>
            <div class="field" style="margin:0">
                <?php lbl('TEMPLATE_ENGAGEMENT_RADIUS', 'Arrondi coins', '50+ = pill') ?>
                <input type="number" name="TEMPLATE_ENGAGEMENT_RADIUS"
                    value="<?= _cfg('TEMPLATE_ENGAGEMENT_RADIUS', 50) ?>" min="0" max="100" step="5">
            </div>
            <div class="field" style="margin:0">
                <?php lbl('TEMPLATE_ENGAGEMENT_LETTER_SPACING', 'Letter spacing', 'espaces entre lettres') ?>
                <input type="number" name="TEMPLATE_ENGAGEMENT_LETTER_SPACING"
                    value="<?= _cfg('TEMPLATE_ENGAGEMENT_LETTER_SPACING', 3) ?>" min="0" max="8" step="1">
            </div>
        </div>

        <div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:6px">
            <div class="toggle-row" style="min-width:160px">
                <div class="info"><label>Majuscules</label></div>
                <label class="toggle">
                    <input type="checkbox" name="TEMPLATE_ENGAGEMENT_UPPERCASE" <?= _cfg('TEMPLATE_ENGAGEMENT_UPPERCASE', true) ? 'checked' : '' ?>>
                    <span class="slider"></span>
                </label>
            </div>
            <div class="toggle-row" style="min-width:200px">
                <div class="info">
                    <label>Lignes décoratives flanquantes</label>
                </div>
                <label class="toggle">
                    <input type="checkbox" name="TEMPLATE_ENGAGEMENT_LINES" <?= _cfg('TEMPLATE_ENGAGEMENT_LINES', true) ? 'checked' : '' ?>>
                    <span class="slider"></span>
                </label>
            </div>
        </div>

        <div class="field" style="margin-top:12px" id="engLineAlphaField">
            <?php lbl('TEMPLATE_ENGAGEMENT_LINE_ALPHA', 'Opacité lignes décoratives', '0=opaque · 127=invisible') ?>
            <input type="number" name="TEMPLATE_ENGAGEMENT_LINE_ALPHA"
                value="<?= _cfg('TEMPLATE_ENGAGEMENT_LINE_ALPHA', 55) ?>" min="0" max="127" step="5">
        </div>

        </div><!-- /engagementFields -->

    </div>
</div>

<!-- ── Templates additionnels (Recipe Card + Overlay List) ── -->
<div class="section">
    <div class="section-title" onclick="toggleSection(this)">
        🃏 Templates additionnels
        <small style="font-weight:400;opacity:.7;margin-left:8px">Recipe Card &amp; Overlay List — couleurs propres à chaque template</small>
    </div>
    <div class="section-body hidden">

        <?php
        $extraLayouts = [
            'recipe_card'  => ['label' => 'Recipe Card', 'desc' => 'Photo circulaire + ingrédients', 'bgKey' => 'BG_COLOR',      'bgDefault' => '#FFF8F0', 'bgLabel' => 'Fond'],
            'overlay_list' => ['label' => 'Overlay List','desc' => 'Photo plein écran + overlay',    'bgKey' => 'OVERLAY_COLOR', 'bgDefault' => '#120800', 'bgLabel' => 'Fond overlay'],
        ];
        foreach ($extraLayouts as $tplKey => $meta):
            $preset = TEMPLATE_PRESETS[$tplKey] ?? [];
        ?>
        <div style="margin-bottom:28px">
            <strong style="font-size:13px;color:#1e293b"><?= $meta['label'] ?></strong>
            <small style="display:block;color:#94a3b8;font-size:11px;margin-bottom:12px"><?= $meta['desc'] ?></small>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">

                <!-- Fond / overlay -->
                <div class="field" style="margin:0">
                    <?php lbl($tplKey . '_' . $meta['bgKey'], $meta['bgLabel']) ?>
                    <div style="display:flex;gap:8px;align-items:center">
                        <input type="color"
                            value="<?= substr(_cfg($tplKey . '_' . $meta['bgKey'], $preset[$meta['bgKey']] ?? $meta['bgDefault']), 0, 7) ?>"
                            oninput="document.querySelector('[name=<?= $tplKey ?>_<?= $meta['bgKey'] ?>]').value=this.value">
                        <input type="text" name="<?= $tplKey ?>_<?= $meta['bgKey'] ?>"
                            value="<?= htmlspecialchars(_cfg($tplKey . '_' . $meta['bgKey'], '')) ?>"
                            placeholder="<?= $meta['bgDefault'] ?>"
                            style="width:100px;font-family:monospace">
                    </div>
                </div>

                <!-- Couleur texte -->
                <div class="field" style="margin:0">
                    <?php lbl($tplKey . '_TITLE_COLOR', 'Couleur texte') ?>
                    <div style="display:flex;gap:8px;align-items:center">
                        <?php $tcDefault = $preset['TITLE_COLOR'] ?? '#2C1810'; ?>
                        <input type="color"
                            value="<?= substr(_cfg($tplKey . '_TITLE_COLOR', $tcDefault), 0, 7) ?>"
                            oninput="document.querySelector('[name=<?= $tplKey ?>_TITLE_COLOR]').value=this.value">
                        <input type="text" name="<?= $tplKey ?>_TITLE_COLOR"
                            value="<?= htmlspecialchars(_cfg($tplKey . '_TITLE_COLOR', '')) ?>"
                            placeholder="<?= $tcDefault ?>"
                            style="width:100px;font-family:monospace">
                    </div>
                </div>

                <!-- Couleur accent -->
                <div class="field" style="margin:0">
                    <?php lbl($tplKey . '_LABEL_COLOR', 'Couleur accent') ?>
                    <div style="display:flex;gap:8px;align-items:center">
                        <?php $acDefault = $preset['LABEL_COLOR'] ?? $preset['ACCENT_COLOR'] ?? '#8B4513'; ?>
                        <input type="color"
                            value="<?= substr(_cfg($tplKey . '_LABEL_COLOR', $acDefault), 0, 7) ?>"
                            oninput="document.querySelector('[name=<?= $tplKey ?>_LABEL_COLOR]').value=this.value">
                        <input type="text" name="<?= $tplKey ?>_LABEL_COLOR"
                            value="<?= htmlspecialchars(_cfg($tplKey . '_LABEL_COLOR', '')) ?>"
                            placeholder="<?= $acDefault ?>"
                            style="width:100px;font-family:monospace">
                    </div>
                </div>

            </div>

            <!-- Link toggle -->
            <label style="display:inline-flex;align-items:center;gap:.5rem;margin-top:10px;cursor:pointer;font-size:.88rem;color:#374151">
                <input type="checkbox" name="<?= $tplKey ?>_LINK_ACTIVE"
                    <?= _cfg($tplKey . '_LINK_ACTIVE', false) ? 'checked' : '' ?>
                    style="width:15px;height:15px;accent-color:#2563eb">
                <span>🔗 Link actif dans le CSV Pinterest</span>
            </label>
        </div>
        <?php endforeach; ?>

    </div>
</div>

<!-- ── AdSense ── -->
<?php
$adsConfig = file_exists(__DIR__ . '/ads-config.json')
    ? json_decode(file_get_contents(__DIR__ . '/ads-config.json'), true)
    : ['publisherId'=>'','enabled'=>true,'injectionDelay'=>300,'placements'=>[]];
?>
<div class="section">
    <div class="section-header collapsed" onclick="toggleSection(this)">
        <span class="icon">📢</span><h2>AdSense</h2><span class="chevron">▾</span>
    </div>
    <div class="section-body hidden">
        <div class="row2" style="margin-bottom:18px">
            <div class="field" style="margin-bottom:0">
                <div class="lbl-row"><label>Publisher ID</label></div>
                <input type="text" id="ads_publisherId" value="<?= htmlspecialchars($adsConfig['publisherId'] ?? '') ?>" placeholder="ca-pub-XXXXXXXXXXXXXXXX">
            </div>
            <div class="field" style="margin-bottom:0">
                <div class="lbl-row"><label>Injection Delay <small>ms</small></label></div>
                <input type="number" id="ads_delay" value="<?= (int)($adsConfig['injectionDelay'] ?? 300) ?>" min="0" max="5000">
            </div>
        </div>
        <div class="toggle-row" style="margin-bottom:18px">
            <div class="info"><label>Ads activées</label></div>
            <label class="toggle">
                <input type="checkbox" id="ads_enabled" <?= !empty($adsConfig['enabled']) ? 'checked' : '' ?>>
                <span class="slider"></span>
            </label>
        </div>

        <div style="font-size:13px;font-weight:600;color:#374151;margin-bottom:10px">Placements</div>
        <div id="ads-placements"></div>
        <button type="button" class="btn-add" onclick="addPlacement()">+ Ajouter un placement</button>

        <div style="margin-top:16px">
            <button type="button" onclick="saveAds(this)"
                style="background:#f59e0b;color:#fff;border:none;border-radius:8px;padding:9px 22px;font-size:14px;font-weight:600;cursor:pointer">
                💾 Sauvegarder AdSense
            </button>
        </div>
    </div>
</div>

<!-- ── Sticky Save ── -->
<div class="sticky-bar">
    <a href="posts-liste.php" class="btn-back">← Retour</a>
    <button type="submit" class="btn-save">💾 Sauvegarder</button>
    <?php if (file_exists($configFile)): ?>
    <button type="button" onclick="resetAllConfig()" style="background:none;border:none;color:#ef4444;font-size:13px;cursor:pointer;margin-left:auto">
        Supprimer tous les overrides
    </button>
    <?php endif; ?>
</div>

</form>
</div>

<script>
// Injecter les champs pf_* (profils Pinterest) dans le formulaire principal au submit
document.getElementById('form-save-config').addEventListener('submit', function() {
    var pf = document.getElementById('pf-container');
    if (!pf) return;
    pf.querySelectorAll('input[name], select[name]').forEach(function(el) {
        if (!document.getElementById('form-save-config').querySelector('[name="' + el.name + '"]')) {
            var h = document.createElement('input');
            h.type = 'hidden'; h.name = el.name; h.value = el.value;
            document.getElementById('form-save-config').appendChild(h);
        }
    });
});

function toggleSection(header) {
    header.classList.toggle('collapsed');
    header.nextElementSibling.classList.toggle('hidden');
}
function switchPrompt(tab, id) {
    document.querySelectorAll('.prompt-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.prompt-panel').forEach(p => p.classList.remove('active'));
    tab.classList.add('active');
    document.getElementById('prompt-' + id).classList.add('active');
}
function addSatellite() {
    const list = document.getElementById('satellites-list');
    if (!list) { console.error('satellites-list not found'); return; }
    const div = document.createElement('div');
    div.className = 'satellite-item';
    div.innerHTML = `
        <button type="button" class="remove-sat" onclick="this.closest('.satellite-item').remove()">×</button>
        <div class="sat-grid">
            <div class="field" style="margin-bottom:0">
                <label style="font-size:13px;font-weight:500;color:#374151">Path local</label>
                <input type="text" class="sat-path" placeholder="../NomSatellite">
            </div>
            <div class="field" style="margin-bottom:0">
                <label style="font-size:13px;font-weight:500;color:#374151">URL</label>
                <input type="text" class="sat-url" placeholder="http://localhost/...">
            </div>
        </div>`;
    list.appendChild(div);
    div.scrollIntoView({behavior:'smooth', block:'nearest'});
    const inp = div.querySelector('input');
    if (inp) inp.focus();
}

async function saveSatellites() {
    const btn = document.getElementById('btn-save-satellites');
    const msg = document.getElementById('sat-save-msg');
    const items = [...document.querySelectorAll('#satellites-list .satellite-item')];
    const satellites = items.map(item => ({
        path: item.querySelector('.sat-path')?.value?.trim() ?? '',
        url:  item.querySelector('.sat-url')?.value?.trim()  ?? '',
    })).filter(s => s.path && s.url);

    btn.disabled = true;
    btn.textContent = '⏳ Sauvegarde...';
    msg.style.display = 'none';
    try {
        const res = await fetch('config-api.php?action=save_satellites', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(satellites),
        });
        const json = await res.json();
        msg.style.display = 'block';
        if (json.success) {
            msg.style.background = '#dcfce7'; msg.style.color = '#166534';
            msg.textContent = '✅ ' + json.count + ' satellite(s) sauvegardé(s)';
        } else {
            msg.style.background = '#fee2e2'; msg.style.color = '#dc2626';
            msg.textContent = '❌ ' + (json.message || 'Erreur inconnue');
        }
    } catch(e) {
        msg.style.display = 'block';
        msg.style.background = '#fee2e2'; msg.style.color = '#dc2626';
        msg.textContent = '❌ Erreur réseau: ' + e.message;
    } finally {
        btn.disabled = false;
        btn.textContent = '💾 Sauvegarder satellites';
    }
}

// Reset a single field to config.php default
function resetField(key) {
    const hid   = document.getElementById('hid_'   + key);
    const badge = document.getElementById('badge_' + key);
    const rbtn  = document.getElementById('rbtn_'  + key);
    if (!hid) return;
    hid.value = '1';
    // Find the nearest input/select/textarea sibling
    const field = hid.closest('.field') || hid.closest('.lbl-row')?.parentElement;
    if (field) field.classList.add('field-reset');
    if (badge) { badge.textContent = '→ défaut config.php'; badge.className = 'badge-ovr reset'; }
    if (rbtn)  rbtn.style.display = 'none';
}
function resetFlip() {
    const hid = document.getElementById('hid_FLIP');
    if (hid) hid.value = '1';
    const badge = document.getElementById('badge_FLIP');
    if (badge) { badge.textContent = '→ défaut config.php'; badge.className = 'badge-ovr reset'; }
    document.getElementById('flipCheck').disabled = true;
}

function toggleSeedsField() {
    const val = document.getElementById('keyword_source_select').value;
    document.getElementById('suggest_seeds_field').style.display
        = (val === 'google_suggest') ? '' : 'none';
    const importBlock = document.getElementById('pinterest_import_block');
    if (importBlock) importBlock.style.display
        = (val === 'pinterest_import') ? '' : 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    const uploadInput = document.getElementById('pinterest_trends_upload');
    if (!uploadInput) return;
    uploadInput.addEventListener('change', function() {
        const file = this.files[0];
        if (!file) return;
        const status = document.getElementById('pinterest_upload_status');
        status.style.color = '#64748b';
        status.textContent = '⏳ Upload en cours...';
        const fd = new FormData();
        fd.append('action', 'upload_pinterest_trends');
        fd.append('trends_csv', file);
        fetch('config-api.php', {method: 'POST', body: fd})
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    status.style.color = '#16a34a';
                    status.textContent = '✅ ' + d.count + ' keywords importés — rechargement...';
                    setTimeout(() => location.reload(), 1200);
                } else {
                    status.style.color = '#dc2626';
                    status.textContent = '❌ ' + (d.error || 'Erreur inconnue');
                }
            })
            .catch(() => {
                status.style.color = '#dc2626';
                status.textContent = '❌ Erreur réseau';
            });
    });
});

document.querySelector('[name=GENERATION_API]').addEventListener('change', function() {
    const el = document.getElementById('anthropic-fields');
    el.style.opacity = this.value === 'anthropic' ? '1' : '.4';
    el.style.pointerEvents = this.value === 'anthropic' ? '' : 'none';
});

// ── Ads placements ────────────────────────────────────────────────────────────
const ADS_PLACEMENTS = <?= json_encode($adsConfig['placements'] ?? [], JSON_UNESCAPED_SLASHES) ?>;

function renderPlacements() {
    const list = document.getElementById('ads-placements');
    list.innerHTML = '';
    ADS_PLACEMENTS.forEach((p, i) => renderPlacement(p, i));
}

function renderPlacement(p, i) {
    const list   = document.getElementById('ads-placements');
    const isMulti = Array.isArray(p.slots);
    const div    = document.createElement('div');
    div.className = 'satellite-item';
    div.dataset.idx = i;
    div.innerHTML = `
        <button type="button" class="remove-sat" onclick="removePlacement(${i})">×</button>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:10px">
            <div class="field" style="margin-bottom:0">
                <label style="font-size:12px;font-weight:500;color:#374151">Selector</label>
                <input type="text" class="p-selector" value="${p.selector||''}" placeholder=".my-class">
            </div>
            <div class="field" style="margin-bottom:0">
                <label style="font-size:12px;font-weight:500;color:#374151">Position</label>
                <select class="p-position">
                    ${['after','before','inside-top','inside-bottom'].map(v=>`<option${p.position===v?' selected':''}>${v}</option>`).join('')}
                </select>
            </div>
            <div class="field" style="margin-bottom:0">
                <label style="font-size:12px;font-weight:500;color:#374151">Format</label>
                <select class="p-format">
                    ${['auto','in-article','horizontal'].map(v=>`<option${p.format===v?' selected':''}>${v}</option>`).join('')}
                </select>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;margin-bottom:10px">
            <div class="field" style="margin-bottom:0">
                <label style="font-size:12px;font-weight:500;color:#374151">Pages</label>
                <select class="p-pages">
                    ${['all','post','spa'].map(v=>`<option${p.pages===v?' selected':''}>${v}</option>`).join('')}
                </select>
            </div>
            <div class="field" style="margin-bottom:0">
                <label style="font-size:12px;font-weight:500;color:#374151">everyNth</label>
                <input type="number" class="p-everynth" value="${p.everyNth||''}" min="1" placeholder="—">
            </div>
            <div class="field" style="margin-bottom:0">
                <label style="font-size:12px;font-weight:500;color:#374151">maxAds</label>
                <input type="number" class="p-maxads" value="${p.maxAds||''}" min="1" placeholder="—">
            </div>
            <div class="field" style="margin-bottom:0">
                <label style="font-size:12px;font-weight:500;color:#374151">className</label>
                <input type="text" class="p-classname" value="${p.className||''}">
            </div>
        </div>
        <div class="field" style="margin-bottom:0">
            <label style="font-size:12px;font-weight:500;color:#374151">
                Slot(s)
                <label style="font-size:11px;font-weight:400;margin-left:8px">
                    <input type="checkbox" class="p-multi-chk" ${isMulti?'checked':''} onchange="toggleMultiSlot(this)">
                    multiple slots
                </label>
            </label>
            <div class="p-slot-single" style="${isMulti?'display:none':''}">
                <input type="text" class="p-slot" value="${isMulti?'':(p.slot||'')}" placeholder="slot ID">
            </div>
            <div class="p-slot-multi" style="${isMulti?'':'display:none'}">
                <textarea class="p-slots" rows="3" placeholder="un slot par ligne">${isMulti?(p.slots||[]).join('\n'):''}</textarea>
                <div class="hint">Un slot ID par ligne → correspond à chaque position (everyNth)</div>
            </div>
        </div>`;
    list.appendChild(div);
}

function toggleMultiSlot(chk) {
    const item = chk.closest('.satellite-item');
    item.querySelector('.p-slot-single').style.display = chk.checked ? 'none' : '';
    item.querySelector('.p-slot-multi').style.display  = chk.checked ? '' : 'none';
}

function removePlacement(i) {
    ADS_PLACEMENTS.splice(i, 1);
    renderPlacements();
}

function addPlacement() {
    ADS_PLACEMENTS.push({slot:'',selector:'',position:'after',format:'auto',pages:'all',className:''});
    renderPlacements();
    document.getElementById('ads-placements').lastElementChild?.scrollIntoView({behavior:'smooth',block:'center'});
}

function collectPlacements() {
    return [...document.querySelectorAll('#ads-placements .satellite-item')].map(item => {
        const isMulti = item.querySelector('.p-multi-chk').checked;
        const p = {
            selector: item.querySelector('.p-selector').value.trim(),
            position: item.querySelector('.p-position').value,
            format:   item.querySelector('.p-format').value,
            pages:    item.querySelector('.p-pages').value,
        };
        if (isMulti) {
            p.slots = item.querySelector('.p-slots').value.split('\n').map(s=>s.trim()).filter(Boolean);
        } else {
            p.slot = item.querySelector('.p-slot').value.trim();
        }
        const everyNth = parseInt(item.querySelector('.p-everynth').value);
        const maxAds   = parseInt(item.querySelector('.p-maxads').value);
        const cls      = item.querySelector('.p-classname').value.trim();
        if (everyNth) p.everyNth = everyNth;
        if (maxAds)   p.maxAds   = maxAds;
        if (cls)      p.className = cls;
        return p;
    });
}

function saveAds(btn) {
    const config = {
        publisherId:    document.getElementById('ads_publisherId').value.trim(),
        enabled:        document.getElementById('ads_enabled').checked,
        injectionDelay: parseInt(document.getElementById('ads_delay').value) || 300,
        placements:     collectPlacements(),
    };
    btn.disabled = true;
    btn.textContent = '⏳ Sauvegarde...';
    fetch('config-api.php?action=save_ads', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(config)
    })
    .then(r => r.json())
    .then(d => {
        btn.textContent = d.success ? '✅ Sauvegardé' : '❌ ' + d.message;
        setTimeout(() => { btn.disabled = false; btn.textContent = '💾 Sauvegarder AdSense'; }, 2000);
    })
    .catch(() => { btn.disabled = false; btn.textContent = '❌ Erreur réseau'; });
}

// Init placements on load
document.addEventListener('DOMContentLoaded', renderPlacements);

// ── Template Presets ──────────────────────────────────────────────────────────
const TEMPLATE_PRESETS = <?= json_encode(TEMPLATE_PRESETS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function applyPreset(key) {
    const p = TEMPLATE_PRESETS[key];
    if (!p) return;

    // Active card highlight
    document.querySelectorAll('.tpl-card').forEach(c => c.classList.remove('active'));
    const card = document.querySelector('.tpl-card[data-key="' + key + '"]');
    if (card) card.classList.add('active');
    document.getElementById('active_template').value = key;

    // Numeric fields
    document.querySelector('[name=TEMPLATE_CANVAS_HEIGHT]').value = p.TEMPLATE_CANVAS_HEIGHT;
    document.querySelector('[name=TEMPLATE_IMG1_HEIGHT]').value   = p.TEMPLATE_IMG1_HEIGHT;
    document.querySelector('[name=TEMPLATE_BANNER_Y]').value      = p.TEMPLATE_BANNER_Y;
    document.querySelector('[name=TEMPLATE_BANNER_HEIGHT]').value = p.TEMPLATE_BANNER_HEIGHT;
    document.querySelector('[name=TEMPLATE_IMG2_Y]').value        = p.TEMPLATE_IMG2_Y;
    document.querySelector('[name=TEMPLATE_IMG2_HEIGHT]').value   = p.TEMPLATE_IMG2_HEIGHT;
    document.querySelector('[name=TEMPLATE_FONT_SIZE]').value     = p.TEMPLATE_FONT_SIZE;

    // Font
    document.querySelector('[name=TEMPLATE_FONT_FAMILY]').value = p.TEMPLATE_FONT_FAMILY;
    document.querySelector('[name=TEMPLATE_FONT_URL]').value    = p.TEMPLATE_FONT_URL;

    // Banner color
    document.querySelector('[name=TEMPLATE_BANNER_COLOR]').value = p.TEMPLATE_BANNER_COLOR;
    document.getElementById('clr_banner').value = p.TEMPLATE_BANNER_COLOR.substring(0, 7);

    // Text color
    document.querySelector('[name=TEMPLATE_TEXT_COLOR]').value = p.TEMPLATE_TEXT_COLOR;
    document.getElementById('clr_text').value = p.TEMPLATE_TEXT_COLOR.substring(0, 7);

    // Background color
    const bgColor = p.TEMPLATE_BG_COLOR || '#F5E6D3';
    document.querySelector('[name=TEMPLATE_BG_COLOR]').value = bgColor;
    document.getElementById('clr_bg').value = bgColor.substring(0, 7);

    // Decor lines toggle
    document.getElementById('decorLinesCheck').checked = !!p.TEMPLATE_DECOR_LINES;
}

function resetAllConfig() {
    if (!confirm('Supprimer site-config.json ? Tous les params reviendront aux défauts de config.php.')) return;
    fetch('config-api.php?action=reset', { method: 'POST' })
        .then(r => r.json())
        .then(d => { if (d.success) location.reload(); else alert(d.message); });
}

// CSS theme card selection
document.querySelectorAll('.css-theme-card input[type=radio]').forEach(radio => {
    radio.addEventListener('change', () => {
        document.querySelectorAll('.css-theme-card').forEach(c => c.classList.remove('active'));
        radio.closest('.css-theme-card').classList.add('active');
    });
});

// CSS upload (AJAX)
document.getElementById('cssUploadBtn').addEventListener('click', async () => {
    const input = document.getElementById('cssUploadInput');
    const msg   = document.getElementById('cssUploadMsg');
    if (!input.files.length) { msg.innerHTML = '<span style="color:#dc2626">Choisir un fichier .css d\'abord.</span>'; return; }
    const fd = new FormData();
    fd.append('custom_css_file', input.files[0]);
    fd.append('upload_css', '1');
    msg.innerHTML = '<span style="color:#6b7280">Upload en cours…</span>';
    try {
        const res  = await fetch('', { method: 'POST', body: fd });
        const text = await res.text();
        // Server redirects on success — detect by checking Location header or response URL
        if (res.redirected || res.url.includes('css_uploaded')) {
            const url = new URL(res.url);
            const uploaded = url.searchParams.get('css_uploaded') || input.files[0].name;
            msg.innerHTML = '<span style="color:#16a34a">✔ ' + uploaded + ' appliqué</span>';
            // Reload the page after 1s to refresh theme card list
            setTimeout(() => location.reload(), 1200);
        } else if (text.includes('Erreur') || text.includes('invalide') || text.includes('Impossible')) {
            const m = text.match(/Erreur[^<]*|invalide[^<]*|Impossible[^<]*/i);
            msg.innerHTML = '<span style="color:#dc2626">' + (m ? m[0] : 'Erreur upload') + '</span>';
        } else {
            msg.innerHTML = '<span style="color:#16a34a">✔ Appliqué</span>';
            setTimeout(() => location.reload(), 1200);
        }
    } catch(e) {
        msg.innerHTML = '<span style="color:#dc2626">Erreur réseau : ' + e.message + '</span>';
    }
});
</script>

<!-- ── Tâches Windows ── -->
<div class="section" id="section-tasks" style="margin-top:2rem">
    <div class="section-header" onclick="toggleSection(this)">
        <span class="icon">⏰</span><h2 id="tasks-title">Tâches planifiées</h2><span class="chevron">▾</span>
    </div>
    <div class="section-body">
        <div id="tasks-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;margin-bottom:1rem">
            <div style="color:#6b7280;padding:1rem">Chargement…</div>
        </div>
        <div id="tasks-msg" style="font-size:.85rem;min-height:1.2em"></div>
    </div>
</div>

<script>
const TASK_STATUS_BADGE = {
    not_created: '<span style="background:#e5e7eb;color:#374151;padding:2px 8px;border-radius:999px;font-size:.75rem">Non créée</span>',
    ready:       '<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:999px;font-size:.75rem">Active</span>',
    disabled:    '<span style="background:#fef9c3;color:#854d0e;padding:2px 8px;border-radius:999px;font-size:.75rem">Désactivée</span>',
    running:     '<span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:999px;font-size:.75rem">En cours</span>',
};

function taskMsg(html, color) {
    document.getElementById('tasks-msg').innerHTML = '<span style="color:'+color+'">'+html+'</span>';
}

async function taskAction(action, taskKey, extra) {
    taskMsg('⏳ ' + action + '…', '#6b7280');
    const fd = new FormData();
    fd.append('action', action);
    fd.append('task', taskKey);
    if (extra) Object.entries(extra).forEach(([k,v]) => fd.append(k, v));
    try {
        const res  = await fetch('tasks-api.php', {method: 'POST', body: fd});
        const text = await res.text();
        let data;
        try { data = JSON.parse(text); } catch(e) {
            taskMsg('❌ Réponse non-JSON: ' + text.substring(0, 120), '#dc2626');
            return;
        }
        if (data.success) {
            taskMsg('✔ OK', '#16a34a');
            loadTasks();
        } else {
            taskMsg('❌ ' + (data.error || 'Erreur') + (data.output ? ' — ' + data.output.substring(0,100) : ''), '#dc2626');
        }
    } catch(e) { taskMsg('❌ ' + e.message, '#dc2626'); }
}

function mkBtn(label, bg, handler) {
    const b = document.createElement('button');
    b.type = 'button';
    b.textContent = label;
    b.style.cssText = 'flex:1;padding:4px 8px;font-size:.78rem;background:'+bg+';color:#fff;border:none;border-radius:5px;cursor:pointer';
    b.onclick = handler;
    return b;
}

async function loadTasks() {
    try {
        const res  = await fetch('tasks-api.php?action=status');
        const data = await res.json();
        if (!data.success) {
            document.getElementById('tasks-grid').innerHTML = '<span style="color:#dc2626">Erreur chargement</span>';
            return;
        }

        // Update section title based on OS
        const isLinux = data.os === 'linux';
        document.getElementById('tasks-title').innerHTML = isLinux
            ? 'Tâches planifiées <span style="background:#dbeafe;color:#1e40af;font-size:.7rem;padding:2px 8px;border-radius:999px;font-weight:normal;margin-left:6px">🐧 Linux Cron</span>'
            : 'Tâches planifiées <span style="background:#e5e7eb;color:#374151;font-size:.7rem;padding:2px 8px;border-radius:999px;font-weight:normal;margin-left:6px">🪟 Windows Task Scheduler</span>';

        const grid = document.getElementById('tasks-grid');
        grid.innerHTML = '';
        Object.values(data.tasks).forEach(t => {
            const card = document.createElement('div');
            card.style.cssText = 'background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:1rem;display:flex;flex-direction:column;gap:.6rem';

            // ── Carte spéciale : tâche Windows uniquement sur Linux ──
            if (t.windows_only) {
                card.style.background = '#fafafa';
                card.style.borderStyle = 'dashed';
                card.innerHTML =
                    '<div style="display:flex;justify-content:space-between;align-items:center">' +
                        '<strong style="font-size:.9rem;color:#374151">📌 ' + t.label + '</strong>' +
                        '<span style="background:#f3f4f6;color:#9ca3af;padding:2px 8px;border-radius:999px;font-size:.72rem">🪟 Windows uniquement</span>' +
                    '</div>' +
                    '<div style="font-size:.77rem;color:#6b7280;line-height:1.6">' +
                        'Génère <code style="background:#f1f5f9;padding:1px 5px;border-radius:4px">publish-pinterest-generated.bat</code> ' +
                        'depuis les profils VPS, puis télécharge-le sur ton PC Windows.' +
                    '</div>' +
                    '<div style="display:flex;flex-wrap:wrap;gap:.4rem" id="win-btns-' + t.key + '"></div>' +
                    '<div id="win-msg-' + t.key + '" style="font-size:.75rem;min-height:1em"></div>' +
                    '<a href="csv-api.php?action=list&apikey=pinext-2025" target="_blank" ' +
                        'style="font-size:.73rem;color:#2563eb;text-decoration:none">📄 CSVs disponibles →</a>';
                grid.appendChild(card);

                // Bouton Générer
                const btnGen = document.createElement('button');
                btnGen.type = 'button';
                btnGen.textContent = '🔄 Générer .bat';
                btnGen.style.cssText = 'padding:4px 10px;font-size:.78rem;background:#2563eb;color:#fff;border:none;border-radius:5px;cursor:pointer';
                btnGen.onclick = async () => {
                    const msg = document.getElementById('win-msg-' + t.key);
                    msg.innerHTML = '<span style="color:#6b7280">⏳ Génération…</span>';
                    btnGen.disabled = true;
                    try {
                        const fd = new FormData();
                        fd.append('action', 'generate');
                        fd.append('task', t.key);
                        const r = await fetch('tasks-api.php', {method:'POST', body:fd});
                        const d = await r.json();
                        if (d.success) {
                            msg.innerHTML = '<span style="color:#16a34a">✅ ' + d.output + '</span>';
                            document.getElementById('dl-bat-' + t.key).style.display = 'inline-block';
                        } else {
                            msg.innerHTML = '<span style="color:#dc2626">❌ ' + (d.error || 'Erreur') + '</span>';
                        }
                    } catch(e) { msg.innerHTML = '<span style="color:#dc2626">❌ ' + e.message + '</span>'; }
                    btnGen.disabled = false;
                };
                document.getElementById('win-btns-' + t.key).appendChild(btnGen);

                // Bouton Télécharger .bat
                const btnBat = document.createElement('a');
                btnBat.id   = 'dl-bat-' + t.key;
                btnBat.href = 'tasks-api.php?action=download&file=bat';
                btnBat.download = 'publish-pinterest-generated.bat';
                btnBat.textContent = '⬇ publish-pinterest.bat';
                btnBat.style.cssText = 'display:none;padding:4px 10px;font-size:.78rem;background:#16a34a;color:#fff;border-radius:5px;text-decoration:none';
                document.getElementById('win-btns-' + t.key).appendChild(btnBat);

                // Bouton Télécharger profiles.json
                const btnPf = document.createElement('a');
                btnPf.href = 'tasks-api.php?action=download&file=profiles';
                btnPf.download = 'profiles.json';
                btnPf.textContent = '⬇ profiles.json';
                btnPf.style.cssText = 'padding:4px 10px;font-size:.78rem;background:#7c3aed;color:#fff;border-radius:5px;text-decoration:none';
                document.getElementById('win-btns-' + t.key).appendChild(btnPf);

                return;
            }

            // ── Carte normale ──
            const badge      = TASK_STATUS_BADGE[t.status] || TASK_STATUS_BADGE.not_created;
            const exists     = t.exists;
            const timeVal    = t.start_time || '02:00';
            const scriptFile = isLinux ? t.sh : t.bat;

            card.innerHTML =
                '<div style="display:flex;justify-content:space-between;align-items:center">' +
                    '<strong style="font-size:.9rem">' + t.label + '</strong>' + badge +
                '</div>' +
                '<div style="font-size:.72rem;color:#9ca3af;font-family:monospace">' + (scriptFile || '') + '</div>' +
                (exists ? '<div style="font-size:.75rem;color:#6b7280">Prochaine: ' + (t.next_run || (isLinux ? 'voir crontab' : '—')) + '</div>' : '') +
                '<div style="display:flex;align-items:center;gap:.5rem">' +
                    '<label style="font-size:.78rem;color:#374151">Heure:</label>' +
                    '<input type="time" id="time-' + t.key + '" value="' + timeVal + '" style="font-size:.8rem;padding:2px 4px;border:1px solid #d1d5db;border-radius:4px">' +
                '</div>' +
                '<div style="display:flex;flex-wrap:wrap;gap:.4rem" id="btns-' + t.key + '"></div>';
            grid.appendChild(card);

            const btns = document.getElementById('btns-' + t.key);
            if (!exists) {
                btns.appendChild(mkBtn('➕ Créer', '#2563eb',
                    () => taskAction('create', t.key, {time: document.getElementById('time-' + t.key).value})));
            } else {
                btns.appendChild(mkBtn('🗑 Supprimer', '#dc2626', () => taskAction('delete',  t.key)));
                if (t.status === 'disabled') {
                    btns.appendChild(mkBtn('✅ Activer',    '#16a34a', () => taskAction('enable',  t.key)));
                } else {
                    btns.appendChild(mkBtn('⏸ Désactiver', '#d97706', () => taskAction('disable', t.key)));
                }
                btns.appendChild(mkBtn('▶ Lancer', '#7c3aed', () => taskAction('run', t.key)));
            }
        });
    } catch(e) {
        document.getElementById('tasks-grid').innerHTML = '<span style="color:#dc2626">Erreur: ' + e.message + '</span>';
    }
}

loadTasks();
</script>

<!-- ── ads.txt + Pinterest Claim ──────────────────────────────────────────── -->
<div style="max-width:900px;margin:2rem auto;padding:0 1rem">
<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="upload_verify_files" value="1">
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:1.5rem">
  <h3 style="margin:0 0 1.2rem;font-size:1rem;font-weight:600">📄 Fichiers de vérification</h3>

  <?php if ($uploadFileMsg): ?>
  <div style="margin-bottom:1rem;font-size:.82rem;display:flex;flex-direction:column;gap:.3rem">
    <?= $uploadFileMsg ?>
  </div>
  <?php endif; ?>

  <div style="display:flex;gap:1.5rem;flex-wrap:wrap">

    <!-- ads.txt -->
    <div style="flex:1;min-width:220px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:1rem">
      <div style="font-size:.82rem;font-weight:700;color:#374151;margin-bottom:.3rem">📢 ads.txt</div>
      <div style="font-size:.72rem;color:#6b7280;margin-bottom:.7rem">Déposé à la racine du site. Seuls les fichiers <code>.txt</code> sont acceptés. Tout code PHP/script est automatiquement supprimé.</div>
      <?php if (file_exists(__DIR__ . '/ads.txt')): ?>
        <div style="font-size:.72rem;color:#16a34a;margin-bottom:.5rem">✅ ads.txt présent (<?= number_format(filesize(__DIR__ . '/ads.txt') / 1024, 1) ?> Ko)</div>
      <?php else: ?>
        <div style="font-size:.72rem;color:#9ca3af;margin-bottom:.5rem">○ Aucun ads.txt</div>
      <?php endif; ?>
      <input type="file" name="ads_txt_file" accept=".txt,text/plain" style="font-size:.78rem;width:100%">
    </div>

    <!-- Pinterest HTML claim -->
    <div style="flex:1;min-width:220px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:1rem">
      <div style="font-size:.82rem;font-weight:700;color:#374151;margin-bottom:.3rem">📌 Pinterest — Claim HTML</div>
      <div style="font-size:.72rem;color:#6b7280;margin-bottom:.7rem">Fichier de vérification Pinterest. Nom obligatoire : <code>pinterest-XXXXX.html</code>. Tout code PHP est rejeté.</div>
      <?php
        $pinFiles = glob(__DIR__ . '/pinterest-*.html') ?: [];
        if ($pinFiles):
          foreach ($pinFiles as $pf):
      ?>
        <div style="font-size:.72rem;color:#16a34a;margin-bottom:.3rem">✅ <?= htmlspecialchars(basename($pf)) ?></div>
      <?php endforeach; else: ?>
        <div style="font-size:.72rem;color:#9ca3af;margin-bottom:.5rem">○ Aucun fichier Pinterest</div>
      <?php endif; ?>
      <input type="file" name="pinterest_html_file" accept=".html,text/html" style="font-size:.78rem;width:100%">
    </div>

  </div>

  <div style="margin-top:1rem">
    <button type="submit" style="padding:.45rem 1.2rem;background:#2563eb;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:.82rem;font-weight:600">
      ⬆ Uploader
    </button>
    <span style="font-size:.72rem;color:#9ca3af;margin-left:.8rem">Accès réservé aux utilisateurs connectés — fichiers validés côté serveur</span>
  </div>
</div>
</form>
</div>

<!-- ── Logo / Favicon / Author + Nav Labels ────────────────────────────────── -->
<div style="max-width:900px;margin:2rem auto;padding:0 1rem">
<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="save_identity" value="1">
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:1.5rem;display:flex;flex-direction:column;gap:1.5rem">
  <h3 style="margin:0;font-size:1rem;font-weight:600">🎨 Identité visuelle &amp; Navigation</h3>

  <?php $iS = 'display:flex;gap:1.5rem;flex-wrap:wrap;align-items:flex-start'; $iL = 'font-size:.78rem;color:#6b7280;margin-bottom:.3rem;font-weight:500'; ?>

  <!-- Images -->
  <div style="<?= $iS ?>">

    <!-- Logo -->
    <div style="flex:1;min-width:180px">
      <div style="<?= $iL ?>">Logo du site</div>
      <?php if (file_exists(__DIR__ . '/' . $_logoFile)): ?>
        <img src="<?= htmlspecialchars($_logoFile) ?>?v=<?= time() ?>" style="height:40px;margin-bottom:.5rem;display:block;background:#f3f4f6;padding:4px;border-radius:4px">
      <?php endif; ?>
      <input type="file" name="site_logo" accept=".svg,.png,.jpg,.webp" style="font-size:.78rem">
      <div style="font-size:.7rem;color:#9ca3af;margin-top:.2rem">SVG, PNG, JPG, WEBP</div>
    </div>

    <!-- Favicon -->
    <div style="flex:1;min-width:180px">
      <div style="<?= $iL ?>">Favicon</div>
      <?php if (file_exists(__DIR__ . '/assets/favicons/favicon-48x48.png')): ?>
        <img src="assets/favicons/favicon-48x48.png?v=<?= time() ?>" style="height:32px;margin-bottom:.5rem;display:block">
      <?php endif; ?>
      <input type="file" name="site_favicon" accept=".png,.svg,.ico,.jpg,.webp" style="font-size:.78rem">
      <div style="font-size:.7rem;color:#9ca3af;margin-top:.2rem">PNG, SVG, ICO</div>
    </div>

    <!-- Author image -->
    <div style="flex:1;min-width:180px">
      <div style="<?= $iL ?>">Photo auteur</div>
      <?php if (file_exists(__DIR__ . '/' . $_authorImg)): ?>
        <img src="<?= htmlspecialchars($_authorImg) ?>?v=<?= time() ?>" style="height:50px;width:50px;object-fit:cover;border-radius:50%;margin-bottom:.5rem;display:block">
      <?php endif; ?>
      <input type="file" name="author_image" accept=".jpg,.jpeg,.png,.webp" style="font-size:.78rem">
      <div style="font-size:.7rem;color:#9ca3af;margin-top:.2rem">JPG, PNG, WEBP</div>
    </div>

    <!-- Author name + description -->
    <div style="flex:2;min-width:220px;display:flex;flex-direction:column;gap:.6rem">
      <div>
        <div style="<?= $iL ?>">Nom auteur</div>
        <input type="text" name="author_name"
          value="<?= htmlspecialchars($_authorName) ?>"
          placeholder="ex: Sarah Mitchell"
          style="width:100%">
      </div>
      <div>
        <div style="<?= $iL ?>">Description auteur</div>
        <input type="text" name="author_description"
          value="<?= htmlspecialchars($_authorDesc) ?>"
          placeholder="ex: Food blogger passionnée de cuisine méditerranéenne"
          style="width:100%">
      </div>
    </div>

  </div>

  <!-- Header nav -->
  <div>
    <div style="<?= $iL ?>">Menu header (4 liens)</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.5rem">
      <?php foreach (['posts' => 'nav_h_posts', 'posts-category' => 'nav_h_category', 'about' => 'nav_h_about', 'contact' => 'nav_h_contact'] as $page => $fname): ?>
        <div>
          <div style="font-size:.7rem;color:#9ca3af;margin-bottom:.2rem">?page=<?= $page ?></div>
          <input type="text" name="<?= $fname ?>" value="<?= htmlspecialchars($_navH[$page] ?? '') ?>"
            style="width:100%;padding:.3rem .5rem;border:1px solid #d1d5db;border-radius:6px;font-size:.82rem;box-sizing:border-box">
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Footer nav -->
  <div>
    <div style="<?= $iL ?>">Menu footer (10 liens)</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:.5rem">
      <?php
      $footerMap = ['Home'=>'nav_f_home','Writers'=>'nav_f_writers','Topics'=>'nav_f_topics','Keywords'=>'nav_f_keywords','Favorites'=>'nav_f_favorites','Discover'=>'nav_f_discover','Posts'=>'nav_f_posts','Recipes'=>'nav_f_recipes','About Us'=>'nav_f_about','Privacy'=>'nav_f_privacy'];
      foreach ($footerMap as $alt => $fname): ?>
        <div>
          <div style="font-size:.7rem;color:#9ca3af;margin-bottom:.2rem"><?= htmlspecialchars($alt) ?></div>
          <input type="text" name="<?= $fname ?>" value="<?= htmlspecialchars($_navF[$alt] ?? $alt) ?>"
            style="width:100%;padding:.3rem .5rem;border:1px solid #d1d5db;border-radius:6px;font-size:.82rem;box-sizing:border-box">
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div>
    <button type="submit" style="padding:.5rem 1.4rem;background:#2563eb;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:.88rem;font-weight:500">
      💾 Enregistrer
    </button>
  </div>

</div>
</form>
</div>

<!-- ── Profiles Pinterest (publish-pinterest.bat) ──────────────────────────── -->
<div style="max-width:900px;margin:2rem auto;padding:0 1rem">
<div id="pf-container">
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:1.5rem">
  <h3 style="margin:0 0 1rem;font-size:1rem;font-weight:600;display:flex;align-items:center;gap:.5rem">
    📌 Profiles Pinterest — publish-pinterest.bat
  </h3>

  <!-- Navigateur -->
  <?php
  $pfBrowserPaths = [
      'edge'      => 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
      'chrome'    => 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
      'adspower'  => '',   // chemin variable — affiché dans champ custom
  ];
  // Détecter quel type correspond au chemin sauvegardé
  $pfBrowserType = 'edge';
  if (stripos($_pfBrowser, 'chrome.exe') !== false && stripos($_pfBrowser, 'adspower') === false) $pfBrowserType = 'chrome';
  elseif (stripos($_pfBrowser, 'SunBrowser') !== false || stripos($_pfBrowser, 'adspower') !== false) $pfBrowserType = 'adspower';
  ?>
  <div style="margin-bottom:1rem">
    <label style="font-size:.75rem;color:#6b7280;display:block;margin-bottom:.25rem">Navigateur</label>
    <div style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap">
      <select id="pf_browser_select" onchange="pfBrowserSelect(this)"
        style="padding:.4rem .6rem;border:1px solid #d1d5db;border-radius:6px;font-size:.83rem;min-width:180px">
        <option value="edge"     <?= $pfBrowserType==='edge'     ? 'selected':'' ?>>🔵 Microsoft Edge</option>
        <option value="chrome"   <?= $pfBrowserType==='chrome'   ? 'selected':'' ?>>🟢 Google Chrome</option>
        <option value="adspower" <?= $pfBrowserType==='adspower' ? 'selected':'' ?>>🔴 AdsPower (SunBrowser)</option>
      </select>
      <input type="text" id="pf_browser_path"
        value="<?= htmlspecialchars($_pfBrowser) ?>"
        style="flex:1;min-width:280px;padding:.4rem .6rem;border:1px solid #d1d5db;border-radius:6px;font-size:.78rem;font-family:monospace;<?= $pfBrowserType!=='adspower' ? 'display:none' : '' ?>"
        placeholder="C:\Users\...\SunBrowser.exe">
    </div>
    <div style="font-size:.7rem;color:#9ca3af;margin-top:.3rem" id="pf_browser_hint"></div>
  </div>
  <input type="hidden" name="pf_browser"      id="pf_browser_hidden" value="<?= htmlspecialchars($_pfBrowser) ?>">
  <input type="hidden" name="pf_browser_type" id="pf_browser_type"   value="<?= htmlspecialchars($_profilesData['browser_type'] ?? 'edge') ?>">
  <script>
  var PF_PATHS = {
    edge:     'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    chrome:   'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    adspower: <?= json_encode($_pfBrowserType === 'adspower' ? $_pfBrowser : '') ?>
  };
  function pfBrowserSelect(sel) {
    var v = sel.value;
    var pathInput   = document.getElementById('pf_browser_path');
    var hiddenInput = document.getElementById('pf_browser_hidden');
    var typeInput   = document.getElementById('pf_browser_type');
    var hint        = document.getElementById('pf_browser_hint');
    typeInput.value = v;
    if (v === 'adspower') {
      pathInput.style.display = '';
      pathInput.value = PF_PATHS.adspower || '';
      hiddenInput.value = pathInput.value;
      hint.textContent = 'Coller le chemin SunBrowser.exe depuis AdsPower';
    } else {
      pathInput.style.display = 'none';
      hiddenInput.value = PF_PATHS[v];
      hint.textContent = PF_PATHS[v];
    }
  }
  document.getElementById('pf_browser_path').addEventListener('input', function() {
    document.getElementById('pf_browser_hidden').value = this.value;
    PF_PATHS.adspower = this.value;
  });
  // Init hint
  (function(){ pfBrowserSelect(document.getElementById('pf_browser_select')); })();
  </script>

  <!-- Timings -->
  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;margin-bottom:1.2rem">
    <div>
      <label style="font-size:.72rem;color:#6b7280;display:block;margin-bottom:.2rem">Délai entre profils (s)</label>
      <input type="number" name="pf_delay" value="<?= (int)$_pfDelay ?>" min="0" max="120"
        style="width:100%;padding:.35rem .5rem;border:1px solid #d1d5db;border-radius:6px;font-size:.82rem">
    </div>
    <div>
      <label style="font-size:.72rem;color:#6b7280;display:block;margin-bottom:.2rem">Attente signal max (s)</label>
      <input type="number" name="pf_maxwait" value="<?= (int)$_pfMaxWait ?>" min="10" max="300"
        style="width:100%;padding:.35rem .5rem;border:1px solid #d1d5db;border-radius:6px;font-size:.82rem">
    </div>
    <div>
      <label style="font-size:.72rem;color:#6b7280;display:block;margin-bottom:.2rem">Délai après upload (s)</label>
      <input type="number" name="pf_postdel" value="<?= (int)$_pfPostDel ?>" min="0" max="120"
        style="width:100%;padding:.35rem .5rem;border:1px solid #d1d5db;border-radius:6px;font-size:.82rem">
    </div>
  </div>

  <!-- Profil de CE site uniquement -->
  <div style="font-size:.75rem;color:#6b7280;font-weight:600;margin-bottom:.5rem">Profil navigateur — ce site</div>
  <div style="font-size:.7rem;color:#9ca3af;margin-bottom:.8rem">
    💡 Chaque site a son propre profil. Les autres sites gardent leur config indépendante.<br>
    <strong>Label</strong> = nom du compte Pinterest &nbsp;|&nbsp;
    <strong>Profile Edge</strong> = dossier (<code>edge://version/</code>) &nbsp;|&nbsp;
    <strong>AdsPower ID</strong> = user_id (ex: k1497wj4) &nbsp;|&nbsp;
    <strong>URL Pinterest</strong> = lien bulk CSV (défaut: bulk-create-pins)
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr 110px 1fr;gap:.5rem;margin-bottom:.35rem;padding:0 .25rem">
    <span style="font-size:.7rem;color:#9ca3af;font-weight:600">Label (compte Pinterest)</span>
    <span style="font-size:.7rem;color:#9ca3af;font-weight:600">Profile Edge</span>
    <span style="font-size:.7rem;color:#9ca3af;font-weight:600">AdsPower ID</span>
    <span style="font-size:.7rem;color:#9ca3af;font-weight:600">URL Pinterest (bulk CSV)</span>
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr 110px 1fr;gap:.5rem">
    <input type="text" name="pf_label" value="<?= htmlspecialchars($_siteProfile['label'] ?? '') ?>"
      placeholder="MonCompte"
      style="padding:.35rem .5rem;border:1px solid #d1d5db;border-radius:6px;font-size:.82rem">
    <input type="text" name="pf_profile" value="<?= htmlspecialchars($_siteProfile['profile'] ?? '') ?>"
      placeholder="Profile 5"
      style="padding:.35rem .5rem;border:1px solid #d1d5db;border-radius:6px;font-size:.82rem;font-family:monospace">
    <input type="text" name="pf_adspower_id" value="<?= htmlspecialchars($_siteProfile['adspower_id'] ?? '') ?>"
      placeholder="k1497wj4"
      style="padding:.35rem .5rem;border:1px solid #d1d5db;border-radius:6px;font-size:.82rem;font-family:monospace">
    <input type="text" name="pf_pin_url" value="<?= htmlspecialchars($_siteProfile['pin_url'] ?? '') ?>"
      placeholder="https://www.pinterest.com/settings/bulk-create-pins/"
      style="padding:.35rem .5rem;border:1px solid #d1d5db;border-radius:6px;font-size:.82rem">
  </div>

  <?php if (isset($_GET['pf_saved'])): ?>
  <div style="margin-top:.8rem;padding:.5rem .8rem;background:#dcfce7;border-radius:6px;font-size:.82rem;color:#166534">
    ✅ Profil sauvegardé dans site-config.json
  </div>
  <?php endif; ?>
  <?php if (!empty($_GET['pf_error'])): ?>
  <div style="margin-top:.8rem;padding:.5rem .8rem;background:#fee2e2;border-radius:6px;font-size:.82rem;color:#dc2626">
    ❌ <?= htmlspecialchars($_GET['pf_error']) ?>
  </div>
  <?php endif; ?>

</div>
</div>
</div>

<script>
function pfAddRow() {
    var list = document.getElementById('pf-list');
    var row  = document.createElement('div');
    row.className = 'pf-row';
    row.style.cssText = 'display:grid;grid-template-columns:1fr 1fr 110px 1fr auto;gap:.5rem;margin-bottom:.4rem;align-items:center';
    var s  = 'padding:.35rem .5rem;border:1px solid #d1d5db;border-radius:6px;font-size:.82rem';
    var sm = s + ';font-family:monospace';
    row.innerHTML =
        '<input type="text" name="pf_label[]"        placeholder="Pinrecipes"               style="' + s  + '">' +
        '<input type="text" name="pf_profile[]"      placeholder="Profile 5"                style="' + sm + '">' +
        '<input type="text" name="pf_adspower_id[]"  placeholder="k1497wj4"                 style="' + sm + '">' +
        '<input type="text" name="pf_pin_url[]"      placeholder="https://www.pinterest.com/" style="' + s + '">' +
        '<button type="button" onclick="this.parentElement.remove()" style="padding:.3rem .55rem;background:#fee2e2;color:#dc2626;border:none;border-radius:6px;cursor:pointer;font-weight:700">✕</button>';
    list.appendChild(row);
    row.querySelector('input').focus();
}
</script>

<!-- ── Pinterest Boards Manager ───────────────────────────────────────────── -->
<div style="max-width:900px;margin:2rem auto;padding:0 1rem" id="pb-panel">
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:1.5rem">
  <h3 style="margin:0 0 1rem;font-size:1rem;font-weight:600;display:flex;align-items:center;gap:.5rem">
    📌 Pinterest Boards Manager
    <span id="pb-count-badge" style="font-size:.72rem;color:#6b7280;font-weight:400;margin-left:.5rem"></span>
  </h3>
  <p style="font-size:.78rem;color:#6b7280;margin-bottom:1rem">
    L'IA réutilise un board existant si le contenu correspond. Elle n'invente un nouveau nom que si aucun board ne convient.
    Cliquer <strong>Rebuild from Posts</strong> pour importer les boards depuis tous les articles existants.
  </p>

  <!-- Actions bar -->
  <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:1.2rem;align-items:center">
    <button type="button" onclick="pbRebuild()" id="pb-rebuild-btn"
      style="padding:.4rem 1rem;background:#059669;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:.8rem;font-weight:600">
      🔄 Rebuild from Posts
    </button>
    <button type="button" onclick="pbSave()" id="pb-save-btn"
      style="padding:.4rem 1rem;background:#2563eb;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:.8rem;font-weight:600">
      💾 Save Changes
    </button>
    <span id="pb-status" style="font-size:.78rem;color:#6b7280"></span>
    <span style="flex:1"></span>
    <span style="font-size:.72rem;color:#9ca3af" id="pb-updated"></span>
  </div>

  <!-- Three columns -->
  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem" id="pb-grid">
    <?php foreach (['classic' => 'Template 1 — Classic', 'header' => 'Template 2 — Header', 'cinematic' => 'Template 3 — Cinematic'] as $pbKey => $pbLabel): ?>
    <div>
      <div style="font-size:.75rem;font-weight:600;color:#374151;margin-bottom:.4rem">
        <?= htmlspecialchars($pbLabel) ?>
        <span id="pb-col-count-<?= $pbKey ?>" style="font-weight:400;color:#9ca3af"></span>
      </div>
      <div style="display:flex;gap:.4rem;margin-bottom:.5rem">
        <input type="text" id="pb-new-<?= $pbKey ?>" placeholder="Nouveau board..."
          style="flex:1;padding:.3rem .5rem;border:1px solid #d1d5db;border-radius:5px;font-size:.78rem"
          onkeydown="if(event.key==='Enter'){pbAddBoard('<?= $pbKey ?>');event.preventDefault()}">
        <button type="button" onclick="pbAddBoard('<?= $pbKey ?>')"
          style="padding:.3rem .6rem;background:#e5e7eb;border:none;border-radius:5px;cursor:pointer;font-size:.78rem;font-weight:700">+</button>
      </div>
      <div id="pb-list-<?= $pbKey ?>"
        style="display:flex;flex-direction:column;gap:.3rem;max-height:300px;overflow-y:auto;padding-right:.2rem">
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
</div>

<script>
(function() {
var _pbBoards = {classic:[], header:[], cinematic:[]};
var _pbUsage  = {classic:{}, header:{}, cinematic:{}};

async function pbLoad() {
    try {
        var r = await fetch('config-api.php?action=get_boards');
        var d = await r.json();
        if (!d.success) return;
        _pbBoards = d.boards || {classic:[],header:[],cinematic:[]};
        _pbUsage  = d.usage  || {classic:{},header:{},cinematic:{}};
        pbRender();
        var total = (_pbBoards.classic||[]).length + (_pbBoards.header||[]).length + (_pbBoards.cinematic||[]).length;
        document.getElementById('pb-count-badge').textContent = '(' + total + ' boards)';
        if (d.updated_at) {
            document.getElementById('pb-updated').textContent = 'Mis à jour: ' + new Date(d.updated_at).toLocaleString('fr-FR');
        }
    } catch(e) { console.error('pbLoad', e); }
}

function pbEsc(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function pbRender() {
    ['classic','header','cinematic'].forEach(function(key) {
        var list    = document.getElementById('pb-list-' + key);
        var countEl = document.getElementById('pb-col-count-' + key);
        if (!list) return;
        var boards = _pbBoards[key] || [];
        if (countEl) countEl.textContent = ' (' + boards.length + '/50)';
        list.innerHTML = '';
        boards.forEach(function(b, idx) {
            var norm = b.toLowerCase();
            var uses = (_pbUsage[key] && _pbUsage[key][norm]) || 0;
            var row  = document.createElement('div');
            row.style.cssText = 'display:flex;align-items:center;gap:.4rem;padding:.25rem .4rem;background:#f9fafb;border-radius:4px;border:1px solid #e5e7eb';
            row.innerHTML =
                '<span style="flex:1;font-size:.78rem;color:#374151">' + pbEsc(b) + '</span>' +
                (uses > 0 ? '<span style="font-size:.68rem;background:#dbeafe;color:#1d4ed8;border-radius:10px;padding:.1rem .35rem">' + uses + ' posts</span>' : '') +
                '<button type="button" onclick="_pbRemove(\'' + key + '\',' + idx + ')" style="background:none;border:none;cursor:pointer;color:#9ca3af;font-size:.85rem;padding:0;line-height:1" title="Supprimer">✕</button>';
            list.appendChild(row);
        });
    });
}

window._pbRemove = function(key, idx) {
    _pbBoards[key] = (_pbBoards[key] || []).filter(function(_,i){ return i !== idx; });
    pbRender();
};

window.pbAddBoard = function(key) {
    var inp = document.getElementById('pb-new-' + key);
    var val = inp.value.trim();
    if (!val) return;
    if ((_pbBoards[key]||[]).length >= 50) { alert('Maximum 50 boards par template atteint.'); return; }
    var lower = val.toLowerCase();
    var exists = (_pbBoards[key]||[]).some(function(b){ return b.toLowerCase() === lower; });
    if (exists) { alert('Ce board existe déjà.'); inp.value=''; return; }
    _pbBoards[key] = (_pbBoards[key]||[]).concat([val]);
    inp.value = '';
    pbRender();
};

window.pbSave = async function() {
    var btn = document.getElementById('pb-save-btn');
    var st  = document.getElementById('pb-status');
    btn.disabled = true;
    st.textContent = '⏳ Sauvegarde...';
    try {
        var r = await fetch('config-api.php?action=save_boards', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({boards: _pbBoards})
        });
        var d = await r.json();
        if (d.success) {
            _pbBoards = d.boards;
            pbRender();
            st.textContent = '✅ Sauvegardé';
            if (d.updated_at) document.getElementById('pb-updated').textContent = 'Mis à jour: ' + new Date(d.updated_at).toLocaleString('fr-FR');
            var total = (_pbBoards.classic||[]).length + (_pbBoards.header||[]).length + (_pbBoards.cinematic||[]).length;
            document.getElementById('pb-count-badge').textContent = '(' + total + ' boards)';
        } else {
            st.textContent = '❌ ' + (d.message || 'Erreur');
        }
    } catch(e) { st.textContent = '❌ Erreur réseau'; }
    finally {
        btn.disabled = false;
        setTimeout(function(){ if (st.textContent.startsWith('✅')) st.textContent=''; }, 3000);
    }
};

window.pbRebuild = async function() {
    if (!confirm('Reconstruire la liste depuis tous les post.json existants ? La liste actuelle sera remplacée (max 50 par template).')) return;
    var btn = document.getElementById('pb-rebuild-btn');
    var st  = document.getElementById('pb-status');
    btn.disabled = true;
    st.textContent = '⏳ Scan des posts...';
    try {
        var r = await fetch('config-api.php?action=rebuild_boards_from_posts', {method:'POST'});
        var d = await r.json();
        if (d.success) {
            _pbBoards = d.boards;
            pbRender();
            var total = d.counts.classic + d.counts.header + d.counts.cinematic;
            st.textContent = '✅ ' + d.posts_scanned + ' posts scannés — ' + total + ' boards trouvés';
            document.getElementById('pb-count-badge').textContent = '(' + total + ' boards)';
        } else {
            st.textContent = '❌ ' + (d.message || 'Erreur');
        }
    } catch(e) { st.textContent = '❌ Erreur réseau'; }
    finally { btn.disabled = false; }
};

pbLoad();
})();
</script>

<!-- ── Identifiants / Sécurité ──────────────────────────────────────────────── -->
<div style="max-width:900px;margin:2rem auto;padding:0 1rem">
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:1.5rem">
  <h3 style="margin:0 0 1rem;font-size:1rem;font-weight:600;display:flex;align-items:center;gap:.5rem">
    🔒 Identifiants — Admin &amp; Utilisateur
  </h3>
  <p style="font-size:.8rem;color:#6b7280;margin-bottom:1.2rem">
    Laisser vide = conserver la valeur actuelle. Le mot de passe est stocké hashé (bcrypt) — irrécupérable.
  </p>

  <?php
  $hasAdminEmail = !empty($_ovr['ADMIN_EMAIL'] ?? (defined('ADMIN_EMAIL') ? ADMIN_EMAIL : ''));
  $hasUserEmail  = !empty($_ovr['USER_EMAIL']  ?? (defined('USER_EMAIL')  ? USER_EMAIL  : ''));
  ?>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem">

    <!-- Admin -->
    <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:1rem">
      <div style="font-size:.78rem;font-weight:700;color:#374151;margin-bottom:.8rem;display:flex;align-items:center;gap:.4rem">
        👑 Compte Admin
        <?php if ($hasAdminEmail): ?>
          <span style="font-size:.7rem;background:#dcfce7;color:#16a34a;padding:2px 7px;border-radius:10px">configuré</span>
        <?php else: ?>
          <span style="font-size:.7rem;background:#fef3c7;color:#92400e;padding:2px 7px;border-radius:10px">défaut</span>
        <?php endif; ?>
      </div>
      <div style="margin-bottom:.6rem">
        <label style="font-size:.72rem;color:#6b7280;display:block;margin-bottom:.25rem">Email</label>
        <input type="email" name="new_admin_email" form="form-save-config"
          value=""
          placeholder="<?= htmlspecialchars(defined('ADMIN_EMAIL') ? ADMIN_EMAIL : 'admin@exemple.com') ?>"
          style="width:100%;padding:.4rem .6rem;border:1px solid #d1d5db;border-radius:6px;font-size:.82rem">
      </div>
      <div>
        <label style="font-size:.72rem;color:#6b7280;display:block;margin-bottom:.25rem">Nouveau mot de passe</label>
        <input type="password" name="new_admin_password" form="form-save-config"
          value="" placeholder="••••••• (inchangé)"
          style="width:100%;padding:.4rem .6rem;border:1px solid #d1d5db;border-radius:6px;font-size:.82rem">
      </div>
    </div>

    <!-- User -->
    <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:1rem">
      <div style="font-size:.78rem;font-weight:700;color:#374151;margin-bottom:.8rem;display:flex;align-items:center;gap:.4rem">
        👤 Compte Utilisateur
        <?php if ($hasUserEmail): ?>
          <span style="font-size:.7rem;background:#dcfce7;color:#16a34a;padding:2px 7px;border-radius:10px">configuré</span>
        <?php else: ?>
          <span style="font-size:.7rem;background:#fef3c7;color:#92400e;padding:2px 7px;border-radius:10px">défaut</span>
        <?php endif; ?>
      </div>
      <div style="margin-bottom:.6rem">
        <label style="font-size:.72rem;color:#6b7280;display:block;margin-bottom:.25rem">Email</label>
        <input type="email" name="new_user_email" form="form-save-config"
          value=""
          placeholder="<?= htmlspecialchars(defined('USER_EMAIL') ? USER_EMAIL : 'user@exemple.com') ?>"
          style="width:100%;padding:.4rem .6rem;border:1px solid #d1d5db;border-radius:6px;font-size:.82rem">
      </div>
      <div>
        <label style="font-size:.72rem;color:#6b7280;display:block;margin-bottom:.25rem">Nouveau mot de passe</label>
        <input type="password" name="new_user_password" form="form-save-config"
          value="" placeholder="••••••• (inchangé)"
          style="width:100%;padding:.4rem .6rem;border:1px solid #d1d5db;border-radius:6px;font-size:.82rem">
      </div>
    </div>

  </div>

  <div style="margin-top:1rem">
    <button type="submit" form="form-save-config"
      style="padding:.45rem 1.3rem;background:#dc2626;color:#fff;border:none;border-radius:7px;cursor:pointer;font-size:.82rem;font-weight:600">
      💾 Sauvegarder les identifiants
    </button>
    <span style="font-size:.72rem;color:#9ca3af;margin-left:.8rem">Sauvegardé dans site-config.json (chiffré)</span>
  </div>
</div>
</div>

<!-- ── Git Initialisation ───────────────────────────────────────────────────── -->
<div style="max-width:900px;margin:2rem auto;padding:0 1rem">
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:1.5rem">
  <h3 style="margin:0 0 1rem;font-size:1rem;font-weight:600;display:flex;align-items:center;gap:.5rem">
    🔧 Git — Initialisation serveur
  </h3>

  <!-- Status -->
  <div id="git-status-box" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:1rem;font-size:.78rem;font-family:monospace;margin-bottom:1rem;min-height:60px;white-space:pre-wrap">
    Chargement...
  </div>

  <!-- Boutons -->
  <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem;align-items:center">
    <?php $btnStyle = 'padding:.4rem .9rem;border:none;border-radius:6px;cursor:pointer;font-size:.8rem;font-weight:500' ?>
    <button onclick="runTestInitPush()" style="<?= $btnStyle ?>;background:#16a34a;color:#fff">🚀 Init &amp; Test Push</button>
    <span style="width:1px;height:20px;background:#e5e7eb;display:inline-block"></span>
    <button onclick="gitAction('fix_app_perms')" style="<?= $btnStyle ?>;background:#dc2626;color:#fff">🔧 Fix app perms</button>
    <button onclick="gitAction('status')"     style="<?= $btnStyle ?>;background:#e5e7eb;color:#374151">🔄 Rafraîchir</button>
    <button onclick="gitAction('fix_perms')"  style="<?= $btnStyle ?>;background:#d97706;color:#fff">🔑 Fix .git</button>
    <button onclick="gitAction('pull')"       style="<?= $btnStyle ?>;background:#16a34a;color:#fff">⬇ git pull</button>
    <button onclick="gitAction('test_push')"  style="<?= $btnStyle ?>;background:#7c3aed;color:#fff">🧪 Test auth</button>
    <span id="git-cmd-status" style="font-size:.78rem;color:#6b7280;margin-left:.2rem"></span>
  </div>

  <!-- Output -->
  <div id="git-output-box" style="display:none;background:#1e293b;color:#e2e8f0;border-radius:8px;padding:1rem;font-size:.78rem;font-family:monospace;white-space:pre-wrap;max-height:260px;overflow-y:auto"></div>
</div>
</div>

<script>
async function runTestInitPush() {
    const repo = prompt('Nom du repo GitHub (ex: mon-repo):');
    if (!repo || !repo.trim()) return;
    const outBox = document.getElementById('git-output-box');
    const stBox  = document.getElementById('git-status-box');
    const status = document.getElementById('git-cmd-status');
    stBox.textContent = '⏳ Init & push en cours...';
    outBox.style.display = 'none';
    status.textContent = '';
    stBox.textContent = '⏳ Init & push en cours...';
    outBox.style.display = 'none';
    status.textContent = '';
    const fd = new FormData();
    fd.append('action',    'test_init_push');
    fd.append('repo_name', repo.trim());
    fd.append('branch',    'main');
    try {
        const r = await fetch('git-init-api.php', { method: 'POST', body: fd });
        const d = await r.json();
        outBox.textContent = d.output || '—';
        outBox.style.display = 'block';
        stBox.textContent = d.success ? '✅ Git opérationnel — push réussi' : '❌ Échec — voir output';
        status.textContent = d.success ? '✅' : '❌';
        status.style.color = d.success ? '#16a34a' : '#dc2626';
        if (d.success) setTimeout(() => gitAction('status'), 600);
    } catch(e) {
        stBox.textContent = '❌ Erreur: ' + e.message;
    }
}

async function runGitCommands() {
    const ta      = document.getElementById('git-commands-textarea');
    const outBox  = document.getElementById('git-output-box');
    const stBox   = document.getElementById('git-status-box');
    const status  = document.getElementById('git-cmd-status');
    const commands = ta.value.trim();
    if (!commands) { alert('Entrez des commandes git'); return; }
    localStorage.setItem('git_commands', commands);
    stBox.textContent = '⏳ Exécution en cours...';
    outBox.style.display = 'none';
    status.textContent = '';
    const fd = new FormData();
    fd.append('action', 'run_commands');
    fd.append('commands', commands);
    try {
        const r = await fetch('git-init-api.php', { method: 'POST', body: fd });
        const d = await r.json();
        outBox.textContent = d.output || '—';
        outBox.style.display = 'block';
        status.textContent = d.success ? '✅ Terminé' : '⚠️ Vérifiez la sortie';
        status.style.color = d.success ? '#16a34a' : '#d97706';
        setTimeout(() => gitAction('status'), 500);
    } catch(e) {
        stBox.textContent = '❌ Erreur: ' + e.message;
        status.textContent = '❌ Échec';
        status.style.color = '#dc2626';
    }
}

async function gitAction(action) {
    const outBox = document.getElementById('git-output-box');
    const stBox  = document.getElementById('git-status-box');
    const status = document.getElementById('git-cmd-status');
    if (action !== 'status') { outBox.style.display = 'none'; }
    stBox.textContent = '⏳ En cours...';
    try {
        const fd = new FormData();
        fd.append('action', action);
        const r = await fetch('git-init-api.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (action === 'status') {
            stBox.textContent =
                '📁 Repo:      ' + d.repo_dir + '\n' +
                '🔗 Remote:    ' + d.remote + '\n' +
                '🌿 Branch:    ' + d.branch + ' (config: ' + d.branch_cfg + ')\n' +
                '⚙️  GIT_MODE:  ' + d.git_mode + '\n' +
                '👤 Git owner: ' + d.git_owner + ' | PHP user: ' + d.php_user + ' | Perms OK: ' + (d.perms_ok ? '✅' : '❌') + '\n' +
                '📋 Status:    ' + (d.dirty || '✅ clean') + '\n' +
                (d.is_git ? '' : '\n⚠️  PAS un dépôt git — utiliser le textarea ci-dessus');
        } else if (action === 'fix_app_perms' && d.needs_root) {
            stBox.textContent = '⚠️ Fichiers owned par root — commande SSH requise (une seule fois)';
            outBox.innerHTML =
                '<div style="margin-bottom:.6rem;color:#fbbf24;font-weight:600">❌ www-data ne peut pas chown des fichiers root</div>' +
                '<div style="margin-bottom:.4rem;font-size:.75rem;color:#94a3b8">Connectez-vous en SSH à votre VPS et exécutez :</div>' +
                '<div style="display:flex;align-items:center;gap:.5rem;background:#0f172a;border:1px solid #334155;border-radius:6px;padding:.5rem .75rem;margin-bottom:.5rem">' +
                  '<code id="fix-cmd-text" style="flex:1;color:#4ade80;font-size:.82rem;user-select:all">' + d.fix_cmd + '</code>' +
                  '<button onclick="navigator.clipboard.writeText(' + JSON.stringify(d.fix_cmd) + ').then(()=>{this.textContent=\'✅\';setTimeout(()=>this.textContent=\'📋\',1500)})" ' +
                    'style="background:#334155;color:#e2e8f0;border:none;border-radius:4px;padding:.25rem .5rem;cursor:pointer;font-size:.75rem;white-space:nowrap">📋 Copier</button>' +
                '</div>' +
                '<div style="font-size:.73rem;color:#64748b">Après exécution, rechargez la page et réessayez de sauvegarder.</div>';
            outBox.style.display = 'block';
        } else {
            stBox.textContent = d.success ? '✅ ' + action + ' OK' : '❌ ' + action + ' failed';
            outBox.textContent = d.output || '—';
            outBox.style.display = 'block';
            status.textContent = '';
            if (d.success) setTimeout(() => gitAction('status'), 500);
        }
    } catch(e) {
        stBox.textContent = '❌ Erreur: ' + e.message;
    }
}
gitAction('status');
</script>

</body>
</html>

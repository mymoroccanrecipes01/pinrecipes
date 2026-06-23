<?php
if (file_exists(__DIR__ . '/config.php')) require_once __DIR__ . '/config.php';
if (file_exists(__DIR__ . '/rating-helpers.php')) require_once __DIR__ . '/rating-helpers.php';

/**
 * Single Post HTML Generator
 * Usage:
 *   - List posts: generate-single-post.php
 *   - Generate by slug: generate-single-post.php?slug=cozy-comforting-beef-stew-for-cold-winter-nights
 *   - Generate and auto-save: generate-single-post.php?slug=cozy-comforting-beef-stew-for-cold-winter-nights&save=1
 */

class PostHTMLGenerator {
    private $post;
    private $outputPath;
    private $author;
    private $labels;
    private $metaStats;
    private $postsDir;

    public function __construct($jsonPath) {
        if (!file_exists($jsonPath)) {
            throw new Exception("Post JSON file not found: {$jsonPath}");
        }

        $jsonContent = file_get_contents($jsonPath);
        $this->post = json_decode($jsonContent, true);

        if (!$this->post) {
            throw new Exception("Failed to parse JSON file");
        }

        // Dossier posts/ (pour lire index.json — internal linking "Related Recipes")
        $this->postsDir = dirname(dirname($jsonPath));

        // Load niche config
        $defaultLabels = [
            'why_this_works'  => 'Why This Works',
            'ingredients'     => "What You'll Need",
            'instructions'    => 'How To Do It',
            'pro_tips'        => 'Pro Tips',
            'common_mistakes' => 'Common Mistakes to Avoid',
            'variations'      => 'Variations',
            'nutrition'       => 'Nutrition',
            'storage'         => 'Storage & Tips',
            'faq'             => 'FAQ',
            'conclusion'      => 'Final Thoughts',
            'introduction'    => 'Introduction',
        ];
        $this->labels = array_merge($defaultLabels, defined('POST_SECTION_LABELS') ? POST_SECTION_LABELS : []);

        $defaultStats = [
            ['field' => 'prep_time',  'label' => 'Prep Time',  'suffix' => 'min'],
            ['field' => 'cook_time',  'label' => 'Time',       'suffix' => 'min'],
            ['field' => 'total_time', 'label' => 'Total Time', 'suffix' => 'min'],
            ['field' => 'servings',   'label' => 'Servings',   'suffix' => ''],
            ['field' => 'duration',   'label' => 'Duration',   'suffix' => ''],
            ['field' => 'difficulty', 'label' => 'Difficulty', 'suffix' => ''],
            ['field' => 'budget',     'label' => 'Budget',     'suffix' => ''],
            ['field' => 'level',      'label' => 'Level',      'suffix' => ''],
        ];
        $this->metaStats = defined('POST_META_STATS') ? POST_META_STATS : $defaultStats;

        // Load author data
        $this->author = $this->loadAuthorData();
    }

    private function loadAuthorData() {
        $authorId = $this->post['author_id'] ?? null;
        if (!$authorId) {
            return null;
        }

        $authorsPath = 'authors/authors.json';
        if (!file_exists($authorsPath)) {
            return null;
        }

        $authorsContent = file_get_contents($authorsPath);
        $authors = json_decode($authorsContent, true);

        if (!$authors || !is_array($authors)) {
            return null;
        }

        foreach ($authors as $author) {
            if (isset($author['id']) && $author['id'] === $authorId) {
                return $author;
            }
        }

        return null;
    }

    public function getHTML() {
        return $this->buildHTML();
    }

    public function saveFile($outputPath) {
        $html = $this->getHTML();
        $dir = dirname($outputPath);
        
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (file_put_contents($outputPath, $html)) {
            return true;
        }
        throw new Exception("Could not write to {$outputPath}");
    }

    /**
     * Adjust relative URLs in HTML extracted from base.html to use $rootPath prefix.
     * Skips absolute URLs (http/https), anchors (#), and absolute paths (/).
     */
    private function adjustPaths(string $html, string $rootPath): string {
        // href and src attributes that are relative (not starting with http, #, /, or data:)
        return preg_replace_callback(
            '/(href|src)="(?!https?:\/\/|#|\/|data:)([^"]*)"/i',
            function($m) use ($rootPath) {
                return $m[1] . '="' . $rootPath . $m[2] . '"';
            },
            $html
        );
    }

    private function extractFromBase(string $tag): string {
        $basePath = __DIR__ . '/base.html';
        if (!file_exists($basePath)) return '';
        $html = file_get_contents($basePath);
        // Extract <header>...</header> or <footer>...</footer>
        if (preg_match('/<' . $tag . '[\s>].*?<\/' . $tag . '>/si', $html, $m)) {
            return $m[0];
        }
        return '';
    }

    private function extractCssLink(): string {
        $basePath = __DIR__ . '/base.html';
        if (!file_exists($basePath)) return 'style.css';
        $html = file_get_contents($basePath);
        // Find <link rel="stylesheet" href="...css">
        if (preg_match('/<link[^>]+rel=["\']stylesheet["\'][^>]+href=["\']([^"\']+\.css)["\'][^>]*>/i', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/<link[^>]+href=["\']([^"\']+\.css)["\'][^>]+rel=["\']stylesheet["\'][^>]*>/i', $html, $m)) {
            return $m[1];
        }
        return 'style.css';
    }

    private function getHeader($rootPath = '../../') {
        $header = $this->extractFromBase('header');
        if ($header) {
            return $this->adjustPaths($header, $rootPath);
        }
        // Fallback (should not happen if base.html exists)
        return '<header class="header"><div class="container"><div class="logo"><a href="' . $rootPath . '">Home</a></div></div></header>';
    }

    private function getFooter($rootPath = '../../') {
        $footer = $this->extractFromBase('footer');
        if ($footer) {
            $footer = $this->adjustPaths($footer, $rootPath);
        } else {
            $footer = '<footer class="footer"></footer>';
        }

        // Append config.js + social link fix script (needed for dynamic config)
        $script = <<<JS

    <script src="{$rootPath}config.js"></script>
    <script>
        var pi = document.getElementById('pinterest-config'); if (pi && globalThis.PinterestURL) pi.href = globalThis.PinterestURL;
        var ml = document.getElementById('mail-config'); if (ml && globalThis.email) ml.href = 'mailto:' + globalThis.email;
        var cr = document.getElementsByClassName('copyright')[0]; if (cr && globalThis.copyright) cr.innerHTML = globalThis.copyright;
    </script>
JS;
        return $footer . $script;
    }

    /**
     * Rating effectif du post (seed + votes visiteurs), avec fallback déterministe.
     * @return array{value: float, count: int}
     */
    private function getRating(): array {
        $slug = $this->post['slug'] ?? '';
        if (function_exists('rating_compute')) {
            $ratingsPath = ($this->outputPath ? dirname($this->outputPath) : (__DIR__ . '/posts/' . $slug)) . '/ratings.json';
            return rating_compute($slug, $ratingsPath);
        }
        return $this->post['rating'] ?? ['value' => 4.7, 'count' => 20];
    }

    /**
     * Internal linking SEO — sélectionne des posts liés (même catégorie d'abord,
     * puis complète avec les plus récents). Lit posts/index.json (trié newest-first
     * par _rebuild_posts_index). Retourne au plus INTERNAL_LINKING_COUNT posts.
     */
    private function getRelatedPosts(): array {
        $count = defined('INTERNAL_LINKING_COUNT') ? (int)INTERNAL_LINKING_COUNT : 6;
        if ($count < 1) return [];

        // Source : posts/index.json (rapide). Fallback robuste : scan direct de posts/*/post.json
        // (si index.json absent ou pas encore régénéré → les liens apparaissent quand même).
        $all = [];
        $indexPath = $this->postsDir . '/index.json';
        if (is_file($indexPath)) {
            $idx = json_decode(file_get_contents($indexPath), true);
            $all = $idx['posts'] ?? [];
        }
        if (empty($all)) {
            foreach (glob($this->postsDir . '/*/post.json') ?: [] as $jp) {
                $d = json_decode(file_get_contents($jp), true);
                if (!$d || empty($d['title'])) continue;
                $s   = basename(dirname($jp));
                $img = '';
                foreach ($d['images'] ?? [] as $im) {
                    if (in_array($im['type'] ?? '', ['template', 'recipe_card', 'overlay_list'], true)) continue;
                    $fn = $im['fileName'] ?? basename($im['filePath'] ?? '');
                    if ($fn) { $img = 'posts/' . $s . '/images/' . $fn; break; }
                }
                $all[] = [
                    'slug' => $s, 'title' => $d['title'], 'image' => $img,
                    'category_id' => $d['category_id'] ?? null,
                    'isOnline'    => (bool)($d['isOnline'] ?? false),
                    'createdAt'   => $d['createdAt'] ?? ($d['CreateAt'] ?? ''),
                ];
            }
            usort($all, fn($a, $b) => strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? '')));
        }
        if (empty($all)) return [];

        $currentSlug = $this->post['slug'] ?? '';
        $currentCat  = $this->post['category_id'] ?? null;

        // isOnline = PRÉFÉRENCE (pas un filtre dur) → les liens apparaissent toujours s'il y a
        // d'autres posts. category_id comparé en chaîne (tolère int 5 vs "5").
        $sameOnline = $otherOnline = $sameOff = $otherOff = [];
        foreach ($all as $p) {
            if (($p['slug'] ?? '') === $currentSlug) continue; // exclure le post courant
            $isSame = ($currentCat !== null && (string)($p['category_id'] ?? '') === (string)$currentCat);
            $online = !empty($p['isOnline']);
            if      ($isSame && $online) $sameOnline[]  = $p;
            elseif  ($online)            $otherOnline[] = $p;
            elseif  ($isSame)            $sameOff[]     = $p;
            else                         $otherOff[]    = $p;
        }
        // Préférence : même cat online > autre online > même cat offline > autre offline
        $ordered = array_merge($sameOnline, $otherOnline, $sameOff, $otherOff);
        return array_slice($ordered, 0, $count);
    }

    /**
     * Section "Related Recipes" — liens internes vers posts liés, URLs canoniques
     * /posts/{slug}/, anchor text = titre (descriptif → SEO).
     */
    private function buildRelatedRecipes(string $rootPath): string {
        $related = $this->getRelatedPosts();
        if (empty($related)) return '';

        $label = htmlspecialchars($this->labels['related'] ?? 'You Might Also Like');
        $cards = '';
        foreach ($related as $p) {
            $slug = $p['slug'] ?? '';
            if ($slug === '') continue;
            $title   = htmlspecialchars($p['title'] ?? $slug, ENT_QUOTES);
            $url     = $rootPath . 'posts/' . $slug . '/';
            $imgHtml = '';
            if (!empty($p['image'])) {
                $imgSrc  = $rootPath . htmlspecialchars($p['image'], ENT_QUOTES);
                $imgHtml = "<img src=\"{$imgSrc}\" alt=\"{$title}\" loading=\"lazy\" class=\"related-thumb\">";
            }
            $cards .= <<<CARD
                <li class="related-item">
                    <a href="{$url}" class="related-link">
                        {$imgHtml}
                        <span class="related-title">{$title}</span>
                    </a>
                </li>

CARD;
        }
        if ($cards === '') return '';

        return <<<HTML
        <section class="story-section related-recipes" id="related-recipes">
            <style>
            .related-recipes .related-grid{list-style:none;padding:0;margin:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:16px}
            .related-recipes .related-item{margin:0}
            .related-recipes .related-link{display:flex;flex-direction:column;text-decoration:none;color:inherit;border-radius:10px;overflow:hidden;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.08);transition:transform .15s ease,box-shadow .15s ease;height:100%}
            .related-recipes .related-link:hover{transform:translateY(-3px);box-shadow:0 6px 16px rgba(0,0,0,.14)}
            .related-recipes .related-thumb{width:100%;aspect-ratio:1/1;object-fit:cover;display:block}
            .related-recipes .related-title{padding:10px 12px;font-size:.92rem;font-weight:600;line-height:1.3}
            </style>
            <h2 class="story-headline">{$label}</h2>
            <ul class="related-grid">
{$cards}            </ul>
        </section>

HTML;
    }

    private function buildSchemaJsonLD(): string {
        $p       = $this->post;
        $slug    = $p['slug'] ?? '';
        $domain  = 'https://' . (defined('HOST_NAME') ? HOST_NAME : 'localhost');
        $pageUrl = $domain . '/posts/' . $slug . '/';
        $rating  = $this->getRating();
        $aggregateRating = [
            '@type'       => 'AggregateRating',
            'ratingValue' => $rating['value'],
            'reviewCount' => $rating['count'],
            'bestRating'  => 5,
            'worstRating' => 1,
        ];

        // Première image non-template — fallback sur la première image template si aucune raw
        $imageUrl = '';
        $templateFallback = '';
        foreach ($p['images'] ?? [] as $img) {
            if (empty($img['filePath'])) continue;
            $isTpl = in_array($img['type'] ?? '', ['template', 'recipe_card', 'overlay_list']);
            $url   = $domain . '/' . ltrim($img['filePath'], '/');
            if (!$isTpl && empty($imageUrl)) {
                $imageUrl = $url;
            } elseif ($isTpl && empty($templateFallback)) {
                $templateFallback = $url;
            }
            if ($imageUrl) break;
        }
        if (!$imageUrl) $imageUrl = $templateFallback;

        $authorName    = $this->author['name'] ?? 'Chef';
        $datePublished = isset($p['createdAt']) ? date('Y-m-d', strtotime($p['createdAt'])) : null;
        $dateModified  = isset($p['updatedAt']) ? date('Y-m-d', strtotime($p['updatedAt'])) : $datePublished;
        $siteName      = defined('HOST_NAME') ? HOST_NAME : 'localhost';

        $schemas = [];

        // 1. Article (toutes niches)
        $schemas[] = array_filter([
            '@context'      => 'https://schema.org',
            '@type'         => 'Article',
            'headline'      => $p['title'] ?? '',
            'description'   => $p['description'] ?? '',
            'image'         => $imageUrl ? [$imageUrl] : null,
            'author'        => ['@type' => 'Person', 'name' => $authorName],
            'publisher'     => ['@type' => 'Organization', 'name' => $siteName],
            'datePublished' => $datePublished,
            'dateModified'  => $dateModified,
            'url'           => $pageUrl,
        ]);

        // 2. Recipe (seulement si champs recette présents)
        $hasRecipeFields = isset($p['prep_time']) || isset($p['cook_time']) ||
            (!empty($p['ingredients']) && is_array($p['ingredients']) &&
             !empty($p['instructions']) && is_array($p['instructions']) && isset($p['instructions'][0]['step']));

        if ($hasRecipeFields) {
            $prepTime  = isset($p['prep_time'])  ? 'PT' . (int)$p['prep_time']  . 'M' : null;
            $cookTime  = isset($p['cook_time'])  ? 'PT' . (int)$p['cook_time']  . 'M' : null;
            $totalTime = isset($p['total_time']) ? 'PT' . (int)$p['total_time'] . 'M' : null;

            // recipeYield — éviter "4 servings servings" si la valeur contient déjà "serving"
            $recipeYield = null;
            if (!empty($p['servings'])) {
                $srv = (string)$p['servings'];
                $recipeYield = stripos($srv, 'serving') !== false ? $srv : $srv . ' servings';
            }

            // Keywords — toujours string (pas array), sans # (Google rejette array + # en schema)
            $keywordsStr = null;
            $rawKw = $p['hashtags'] ?? null;
            if ($rawKw) {
                $kwText = is_array($rawKw) ? implode(' ', $rawKw) : (string)$rawKw;
                $parts  = array_filter(array_map(
                    fn($t) => trim(ltrim(trim($t), '#')),
                    preg_split('/[\s,]+/', $kwText)
                ), fn($t) => strlen($t) >= 2);
                $keywordsStr = $parts ? implode(', ', array_values($parts)) : null;
            }

            $instructions = [];
            foreach ($p['instructions'] ?? [] as $i => $step) {
                $text = strip_tags($step['instruction'] ?? '');
                if ($text) {
                    $instructions[] = array_filter([
                        '@type' => 'HowToStep',
                        'name'  => $step['title'] ?? ('Step ' . ($step['step'] ?? ($i + 1))),
                        'text'  => $text,
                        'url'   => $pageUrl . '#step-' . ($step['step'] ?? ($i + 1)),
                    ]);
                }
            }

            $nutrition = null;
            if (!empty($p['nutrition'])) {
                $n = $p['nutrition'];
                // Ajouter les unités si la valeur est numérique (Schema.org attend du texte)
                $toGrams    = fn($v) => $v === null ? null : (is_numeric($v) ? $v . ' g'   : (string)$v);
                $toMg       = fn($v) => $v === null ? null : (is_numeric($v) ? $v . ' mg'  : (string)$v);
                $toCal      = fn($v) => $v === null ? null : (is_numeric($v) ? $v . ' calories' : (string)$v);
                $nutrition  = array_filter([
                    '@type'               => 'NutritionInformation',
                    'calories'            => $toCal($n['calories_per_serving'] ?? null),
                    'proteinContent'      => $toGrams($n['protein_grams']      ?? null),
                    'carbohydrateContent' => $toGrams($n['carbohydrates_grams'] ?? null),
                    'fatContent'          => $toGrams($n['fat_grams']           ?? null),
                    'fiberContent'        => $toGrams($n['fiber_grams']         ?? null),
                    'sodiumContent'       => $toMg($n['sodium_mg']             ?? null),
                ]);
            }

            // Video pin — VideoObject si reel MP4 existe (local ou flag has_reel dans post.json)
            $videoObject = null;
            $reelFile = $slug . '_reel.mp4';
            $reelPath = __DIR__ . '/posts/' . $slug . '/images/' . $reelFile;
            $hasReel  = file_exists($reelPath) || !empty($p['has_reel']);
            if ($hasReel && $imageUrl) {
                $videoObject = array_filter([
                    '@type'        => 'VideoObject',
                    'name'         => $p['title'] ?? '',
                    'description'  => $p['description'] ?? '',
                    'thumbnailUrl' => $imageUrl,
                    'uploadDate'   => $datePublished,
                    'contentUrl'   => $domain . '/posts/' . $slug . '/images/' . $reelFile,
                ]);
            }

            $schemas[] = array_filter([
                '@context'           => 'https://schema.org',
                '@type'              => 'Recipe',
                'name'               => $p['title'] ?? '',
                'description'        => $p['description'] ?? '',
                'image'              => $imageUrl ? [$imageUrl] : null,
                'author'             => ['@type' => 'Person', 'name' => $authorName],
                'datePublished'      => $datePublished,
                'prepTime'           => $prepTime,
                'cookTime'           => $cookTime,
                'totalTime'          => $totalTime,
                'recipeYield'        => $recipeYield,
                'recipeCuisine'      => $p['cuisine']       ?? null,
                'recipeCategory'     => $p['category_name'] ?? ($p['category'] ?? null),
                'recipeIngredient'   => array_values(array_filter($p['ingredients'] ?? [])) ?: null,
                'recipeInstructions' => $instructions ?: null,
                'nutrition'          => $nutrition ?: null,
                'keywords'           => $keywordsStr,
                'video'              => $videoObject ?: null,
                'aggregateRating'    => $aggregateRating,
            ]);
        }

        // 3. BreadcrumbList (toutes niches)
        $schemas[] = [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home',  'item' => $domain . '/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Posts', 'item' => $domain . '/posts/'],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $p['title'] ?? ''],
            ],
        ];

        // 4. FAQPage — rich snippet Google (Q&A expandable dans les SERP)
        if (!empty($p['faq']) && is_array($p['faq'])) {
            $faqEntities = [];
            foreach ($p['faq'] as $item) {
                $q = trim(strip_tags($item['question'] ?? ''));
                $a = trim(strip_tags($item['answer'] ?? ''));
                if ($q && $a) {
                    $faqEntities[] = [
                        '@type'          => 'Question',
                        'name'           => $q,
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a],
                    ];
                }
            }
            if ($faqEntities) {
                $schemas[] = [
                    '@context'   => 'https://schema.org',
                    '@type'      => 'FAQPage',
                    'mainEntity' => $faqEntities,
                ];
            }
        }

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
        return implode("\n    ", array_map(
            fn($s) => '<script type="application/ld+json">' . json_encode($s, $flags) . '</script>',
            $schemas
        ));
    }

    private function buildHTML() {
        $title       = htmlspecialchars($this->post['title'] ?? '');
        $description = htmlspecialchars($this->post['description'] ?? '');
        $rootPath    = '../../';

        $slug         = $this->post['slug'] ?? '';
        $domain       = 'https://' . (defined('HOST_NAME') ? HOST_NAME : 'localhost');
        $canonicalUrl = $domain . '/posts/' . $slug . '/';
        $siteName     = htmlspecialchars(defined('HOMEPAGE_TITLE') ? HOMEPAGE_TITLE : 'Recipes');
        $ogImage      = '';
        foreach ($this->post['images'] ?? [] as $img) {
            if (empty($img['type']) && !empty($img['filePath'])) {
                $ogImage = htmlspecialchars($domain . '/' . $img['filePath']);
                break;
            }
        }
        $ogImageMeta      = $ogImage ? "<meta property=\"og:image\" content=\"{$ogImage}\">" : '';
        $twitterImageMeta = $ogImage ? "<meta name=\"twitter:image\" content=\"{$ogImage}\">" : '';
        $schemaJsonLD     = $this->buildSchemaJsonLD();

        $year     = date('Y');
        $gaId     = defined('GA_MEASUREMENT_ID') ? GA_MEASUREMENT_ID : '';
        $gaScript = $gaId
            ? "<!-- Google tag (gtag.js) -->\n"
            . "    <script async src=\"https://www.googletagmanager.com/gtag/js?id={$gaId}\"></script>\n"
            . "    <script>\n"
            . "      window.dataLayer = window.dataLayer || [];\n"
            . "      function gtag(){dataLayer.push(arguments);}\n"
            . "      gtag('js', new Date());\n"
            . "      gtag('config', '{$gaId}');\n"
            . "    </script>"
            : '';

        $_layout = defined('POST_LAYOUT') ? POST_LAYOUT : [
            'breadcrumb', 'header', 'rating', 'image_1', 'description', 'introduction',
            'story', 'why_this_works', 'image_2', 'pro_tips',
            'common_mistakes', 'variations', 'nutrition', 'storage',
            'faq', 'ingredients', 'image_3', 'instructions', 'conclusion',
        ];
        // Rétro-compat : si un layout existant ne contient pas 'rating', l'insérer après 'header'
        if (!in_array('rating', $_layout, true)) {
            $_hpos = array_search('header', $_layout, true);
            if ($_hpos !== false) array_splice($_layout, $_hpos + 1, 0, 'rating');
            else array_unshift($_layout, 'rating');
        }
        $bodyBlocks = '';
        foreach ($_layout as $_blk) {
            $bodyBlocks .= $this->buildBlock($_blk);
        }

        // Internal linking SEO — "Related Recipes" en fin d'article
        if (defined('INTERNAL_LINKING_ACTIVE') && INTERNAL_LINKING_ACTIVE) {
            $bodyBlocks .= $this->buildRelatedRecipes($rootPath);
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} ({$year}) - {$siteName}</title>
    <meta name="description" content="{$description}">
    <link rel="canonical" href="{$canonicalUrl}">
    <meta name="robots" content="index, follow">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://pagead2.googlesyndication.com">
    <link rel="preconnect" href="https://www.googletagmanager.com">

    <meta property="og:type" content="article">
    <meta property="og:title" content="{$title}">
    <meta property="og:description" content="{$description}">
    <meta property="og:url" content="{$canonicalUrl}">
    <meta property="og:site_name" content="{$siteName}">
    {$ogImageMeta}

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{$title}">
    <meta name="twitter:description" content="{$description}">
    {$twitterImageMeta}

    <meta name="pinterest-rich-pin" content="true">
    {$schemaJsonLD}

    <link rel="icon" type="image/x-icon" href="{$rootPath}assets/favicons/icon.svg">
    <link rel="stylesheet" href="{$rootPath}{$this->extractCssLink()}">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">

    {$gaScript}
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('consent', 'default', {
        'ad_storage': 'granted', 'ad_user_data': 'granted',
        'ad_personalization': 'granted', 'analytics_storage': 'granted'
      });
    </script>
    <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-3666818985097490" crossorigin="anonymous"></script>
    <script src="{$rootPath}ads.js" defer></script>
</head>
<body>
    {$this->getHeader($rootPath)}
    <div class="container">
        <div id="post-content">
            <section class="post-detail">
                {$bodyBlocks}
            </section>
        </div>
    </div>
    {$this->getFooter($rootPath)}

    <a href="#" class="back-to-top" id="backToTop">↑</a>
    <script>
        const backToTopBtn = document.getElementById('backToTop');
        window.addEventListener('scroll', () => {
            backToTopBtn.classList.toggle('show', window.pageYOffset > 300);
        });
        backToTopBtn.addEventListener('click', (e) => {
            e.preventDefault();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                const href = this.getAttribute('href');
                if (href !== '#' && document.querySelector(href)) {
                    e.preventDefault();
                    document.querySelector(href).scrollIntoView({ behavior: 'smooth' });
                }
            });
        });
    </script>
    <script src="{$rootPath}post-interactive.js" defer></script>
</body>
</html>
HTML;
    }

    private function buildBreadcrumb(): string {
        $rootPath = '../../';
        $title    = htmlspecialchars($this->post['title'] ?? '');
        return '<nav class="breadcrumb">'
             . '<a href="' . $rootPath . 'index.html">Home</a> / '
             . '<a href="' . $rootPath . '?page=posts">Posts</a> / '
             . '<span>' . $title . '</span>'
             . '</nav>';
    }

    private function buildHeader(): string {
        $rootPath    = '../../';
        $title       = htmlspecialchars($this->post['title'] ?? '');
        $authorName  = 'Chef';
        $authorImage = '';
        if ($this->author) {
            $authorName  = htmlspecialchars($this->author['name'] ?? 'Chef');
            $authorImage = htmlspecialchars($this->author['imagePath'] ?? '');
        }
        $date      = $this->formatDate($this->post['createdAt'] ?? '');
        $avatar    = $authorImage
            ? '<img src="' . $rootPath . $authorImage . '" alt="' . $authorName . '" class="author-avatar">'
            : '';
        $metaTags  = $this->buildMetaTags();

        return '<h1 class="post-title">' . $title . '</h1>'
             . '<div class="author-info">' . $avatar
             . '<div><div class="author-name">' . $authorName . '</div>'
             . '<div class="post-date">Published on ' . $date . '</div>'
             . '</div></div>'
             . '<div class="post-stats">' . $metaTags . '</div>';
    }

    /**
     * Widget d'évaluation : étoiles cliquables + moyenne. Le clic POST vers rate-post.php.
     * Affiche le rating effectif (seed + votes) pour cohérence avec le schema AggregateRating.
     */
    private function buildRatingWidget(): string {
        $slug   = htmlspecialchars($this->post['slug'] ?? '');
        $rating = $this->getRating();
        $value  = (float)$rating['value'];
        $count  = (int)$rating['count'];

        // Étoiles pleines/demi/vides selon la valeur
        $stars = '';
        for ($i = 1; $i <= 5; $i++) {
            $cls = $value >= $i ? 'full' : ($value >= $i - 0.5 ? 'half' : 'empty');
            $stars .= '<button type="button" class="star ' . $cls . '" data-stars="' . $i . '" aria-label="Rate ' . $i . ' stars">★</button>';
        }

        return '<div class="post-rating" data-slug="' . $slug . '">'
             . '<div class="post-rating-stars">' . $stars . '</div>'
             . '<span class="post-rating-meta"><b class="prv">' . number_format($value, 1) . '</b> '
             . '(<span class="prc">' . $count . '</span> ratings)</span>'
             . '<span class="post-rating-thanks" style="display:none">✓ Thanks for rating!</span>'
             . '</div>'
             . '<style>'
             . '.post-rating{display:flex;align-items:center;gap:10px;margin:10px 0 4px;flex-wrap:wrap}'
             . '.post-rating-stars{display:inline-flex;gap:2px}'
             . '.post-rating .star{font-size:22px;line-height:1;background:none;border:none;cursor:pointer;padding:0;color:#d1d5db;transition:color .15s}'
             . '.post-rating .star.full{color:#f59e0b}.post-rating .star.half{color:#fbbf24}'
             . '.post-rating .star:hover,.post-rating .star.hov{color:#f59e0b}'
             . '.post-rating-meta{color:#6b7280;font-size:14px}'
             . '.post-rating-thanks{color:#16a34a;font-size:14px;font-weight:600}'
             . '</style>'
             . '<script>(function(){'
             . 'var w=document.currentScript.previousElementSibling;while(w&&!w.classList.contains("post-rating"))w=w.previousElementSibling;'
             . 'if(!w)return;var slug=w.getAttribute("data-slug");var stars=w.querySelectorAll(".star");var done=false;'
             . 'stars.forEach(function(s){'
             . 's.addEventListener("mouseenter",function(){var n=+s.getAttribute("data-stars");stars.forEach(function(x,i){x.classList.toggle("hov",i<n)})});'
             . 's.addEventListener("mouseleave",function(){stars.forEach(function(x){x.classList.remove("hov")})});'
             . 's.addEventListener("click",function(){if(done)return;done=true;var n=+s.getAttribute("data-stars");'
             . 'var fd=new FormData();fd.append("slug",slug);fd.append("stars",n);'
             . 'fetch("'.($this->ratingEndpointPath()).'",{method:"POST",body:fd}).then(function(r){return r.json()}).then(function(d){'
             . 'if(d&&d.success){w.querySelector(".prv").textContent=(+d.value).toFixed(1);w.querySelector(".prc").textContent=d.count;'
             . 'w.querySelector(".post-rating-thanks").style.display="";'
             . 'stars.forEach(function(x,i){x.classList.toggle("full",i<Math.round(d.value));x.classList.remove("hov")})}})'
             . '.catch(function(){});});'
             . '});'
             . '})();</script>';
    }

    /** Chemin relatif vers l'endpoint de vote depuis la page du post (posts/{slug}/). */
    private function ratingEndpointPath(): string {
        return '../../rate-post.php';
    }

    private function buildDescription(): string {
        $description = htmlspecialchars($this->post['description'] ?? '');
        if (!$description) return '';
        return '<p class="post-description">' . $description . '</p>';
    }

    private function buildMetaTags() {
        $html = '';
        foreach ($this->metaStats as $stat) {
            $value = $this->post[$stat['field']] ?? null;
            if (!$value) continue;
            $label  = htmlspecialchars($stat['label']);
            $suffix = !empty($stat['suffix']) ? ' ' . htmlspecialchars($stat['suffix']) : '';
            $html .= "                <div class=\"stat\"><span>" . htmlspecialchars((string)$value) . "{$suffix} {$label}</span></div>\n";
        }
        return $html;
    }

    private function buildHashtags() {
        $hashtags = $this->post['hashtags'] ?? '';
        if (!$hashtags) return '';

        $tags = array_filter(array_map('trim', explode(' ', $hashtags)));
        if (empty($tags)) return '';

        $html = '<div class="hashtags">';
        foreach ($tags as $tag) {
            $tagEscaped = htmlspecialchars($tag);
            $html .= "<a href=\"#{$tagEscaped}\">{$tagEscaped}</a>";
        }
        $html .= '</div>';

        return $html;
    }

    private function buildImage() {
        if (empty($this->post['images'])) return '';
        $mainImage = $this->post['images'][0];
        $mainPath  = htmlspecialchars($mainImage['filePath'] ?? 'images/placeholder.jpg');
        $mainAlt   = htmlspecialchars($mainImage['alt_text'] ?? 'Post');
        return <<<HTML
        <div class="post-image-container">
            <img src="/{$mainPath}" alt="{$mainAlt}" class="post-main-image" fetchpriority="high">
        </div>
HTML;
    }

    private function buildMainImage(): string {
        if (empty($this->post['images'])) return '';
        $mainImage = $this->post['images'][0];
        $mainPath  = htmlspecialchars($mainImage['filePath'] ?? '');
        $mainAlt   = htmlspecialchars($mainImage['alt_text'] ?? 'Post');
        if (!$mainPath) return '';

        $slug    = $this->post['slug'] ?? '';
        $domain  = 'https://' . (defined('HOST_NAME') ? HOST_NAME : 'localhost');
        $pinUrl  = 'https://pinterest.com/pin/create/button/?url=' . urlencode($domain . '/posts/' . $slug . '/')
                 . '&media=' . urlencode($domain . '/' . ($mainImage['filePath'] ?? ''))
                 . '&description=' . urlencode($this->post['title'] ?? '');

        return <<<HTML
        <div class="post-image-container">
            <img src="/{$mainPath}" alt="{$mainAlt}" class="post-main-image" fetchpriority="high">
            <button class="pinterest-pin-btn" onclick="window.open('{$pinUrl}','_blank','width=750,height=550')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.373 0 0 5.373 0 12c0 5.084 3.163 9.426 7.627 11.174-.105-.949-.2-2.405.042-3.441.218-.937 1.407-5.965 1.407-5.965s-.359-.719-.359-1.782c0-1.668.967-2.914 2.171-2.914 1.023 0 1.518.769 1.518 1.69 0 1.029-.655 2.568-.994 3.995-.283 1.194.599 2.169 1.777 2.169 2.133 0 3.772-2.249 3.772-5.495 0-2.873-2.064-4.882-5.012-4.882-3.414 0-5.418 2.561-5.418 5.207 0 1.031.397 2.138.893 2.738a.36.36 0 0 1 .083.345l-.333 1.36c-.053.22-.174.267-.402.161-1.499-.698-2.436-2.889-2.436-4.649 0-3.785 2.75-7.262 7.929-7.262 4.163 0 7.398 2.967 7.398 6.931 0 4.136-2.607 7.464-6.227 7.464-1.216 0-2.359-.632-2.75-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146C9.57 23.812 10.763 24 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0z"/></svg>
                Save
            </button>
        </div>
HTML;
    }

    private function getImageByIndex($index) {
        if (empty($this->post['images']) || !isset($this->post['images'][$index])) {
            return '';
        }

        $image = $this->post['images'][$index];
        $filePath = htmlspecialchars($image['filePath'] ?? '');
        $alt = htmlspecialchars($image['alt_text'] ?? 'Post step ' . ($index + 1));

        if (!$filePath) {
            return '';
        }

        return <<<HTML
        <div class="content-image-container">
            <img src="/{$filePath}" alt="{$alt}" class="content-image" loading="lazy">
        </div>
HTML;
    }

    private function buildTableOfContents() {
        $sectionMap = [
            'why_this_post_works'      => ['id' => 'why-this-post-works', 'key' => 'why_this_works'],
            'pro_tips'                 => ['id' => 'pro-tips',            'key' => 'pro_tips'],
            'common_mistakes_to_avoid' => ['id' => 'common-mistakes',     'key' => 'common_mistakes'],
            'variations'               => ['id' => 'variations',          'key' => 'variations'],
            'nutrition'                => ['id' => 'nutrition',           'key' => 'nutrition'],
            'storage_and_reheating'    => ['id' => 'storage',            'key' => 'storage'],
            'faq'                      => ['id' => 'faq',                 'key' => 'faq'],
            'ingredients'              => ['id' => 'ingredients',         'key' => 'ingredients'],
            'instructions'             => ['id' => 'instructions',        'key' => 'instructions'],
        ];

        $items = '';
        foreach ($sectionMap as $dataKey => $info) {
            $label = $this->labels[$info['key']] ?? '';
            if (empty($label)) continue;
            if (empty($this->post[$dataKey])) continue;
            $id = $info['id'];
            $label = htmlspecialchars($label);
            $items .= "                <li><a href=\"#{$id}\">{$label}</a></li>\n";
        }

        if (!$items) return '';

        return <<<HTML
        <div class="toc">
            <h2 class="story-headline">Quick Navigation</h2>
            <ul>
{$items}            </ul>
        </div>
HTML;
    }

    private function buildWhyThisPostWorks() {
        if (empty($this->post['why_this_post_works'])) return '';
        $label = htmlspecialchars($this->labels['why_this_works'] ?? 'Why This Works');
        if (!$label) return '';

        $why = $this->post['why_this_post_works'];
        if (is_array($why)) {
            $content = $why['content'] ?? '';
            if (!empty($why['section'])) $content = '<strong>' . htmlspecialchars($why['section']) . '</strong>' . $content;
        } else {
            $content = $why;
        }

        return <<<HTML
        <section class="story-section" id="why-this-post-works">
            <h2 class="story-headline">{$label}</h2>
            <div class="why-works">
                {$content}
            </div>
        </section>
HTML;
    }

    private function buildIngredients() {
        if (empty($this->post['ingredients'])) return '';
        $label = htmlspecialchars($this->labels['ingredients'] ?? "What You'll Need");
        if (!$label) return '';

        $yield = htmlspecialchars($this->post['yield'] ?? '');
        $ingredientsHtml = '';
        foreach ($this->post['ingredients'] as $ingredient) {
            $ingredientsHtml .= "                <li>" . htmlspecialchars($ingredient) . "</li>\n";
        }
        $yieldHtml = $yield ? "<p>{$yield}</p>" : '';

        return <<<HTML
        <section class="story-section" id="ingredients">
            <h2 class="story-headline">{$label}</h2>
            {$yieldHtml}
            <ul class="ingredients-list">
{$ingredientsHtml}            </ul>
        </section>
HTML;
    }

    private function buildInstructions() {
        if (empty($this->post['instructions'])) return '';
        $label = htmlspecialchars($this->labels['instructions'] ?? 'How To Do It');
        if (!$label) return '';

        $instructionsHtml = '';
        $step = 1;

        foreach ($this->post['instructions'] as $inst) {
            $instruction = htmlspecialchars($inst['instruction'] ?? '');
            $timing = htmlspecialchars($inst['timing'] ?? '');
            $cue = htmlspecialchars($inst['doneness_cue'] ?? '');

            $instructionsHtml .= <<<HTML
            <div class="instruction-step">
                <div class="instruction-step-number">Step {$step}</div>
                <div class="instruction-text">{$instruction}</div>
                <div class="instruction-timing">
                    <div class="timing-item"><span class="timing-label">⏱ Time:</span> <span>{$timing}</span></div>
                    <div class="timing-item"><span class="timing-label">✓ Doneness:</span> <span>{$cue}</span></div>
                </div>
            </div>

HTML;
            $step++;
        }

        return <<<HTML
        <section class="story-section" id="instructions">
            <h2 class="story-headline">{$label}</h2>
{$instructionsHtml}        </section>
HTML;
    }

    private function buildProTips() {
        if (empty($this->post['pro_tips'])) {
            return '';
        }

        $tipsHtml = '';

        foreach ($this->post['pro_tips'] as $tip) {
            $tipTitle = htmlspecialchars($tip['tip'] ?? '');
            $tipReason = htmlspecialchars($tip['reason'] ?? '');

            $tipsHtml .= <<<HTML
            <div class="tip-card">
                <div class="tip-title">{$tipTitle}</div>
                <div class="tip-reason">{$tipReason}</div>
            </div>

HTML;
        }

        $label = htmlspecialchars($this->labels['pro_tips'] ?? 'Pro Tips');
        if (!$label) return '';

        return <<<HTML
        <section class="story-section" id="pro-tips">
            <h2 class="story-headline">{$label}</h2>
{$tipsHtml}        </section>
HTML;
    }

    private function buildCommonMistakes() {
        if (empty($this->post['common_mistakes_to_avoid'])) {
            return '';
        }

        $mistakesHtml = '';

        foreach ($this->post['common_mistakes_to_avoid'] as $mistake) {
            $title = htmlspecialchars($mistake['mistake'] ?? '');
            $why = htmlspecialchars($mistake['why_it_happens'] ?? '');
            $fix = htmlspecialchars($mistake['fix'] ?? '');

            $mistakesHtml .= <<<HTML
            <div class="mistake-card">
                <div class="mistake-title"> {$title}</div>
                <p><strong>Why it happens</strong> {$why}</p>
                <p><strong>Fix</strong> {$fix}</p>
            </div>

HTML;
        }

        $label = htmlspecialchars($this->labels['common_mistakes'] ?? 'Common Mistakes to Avoid');
        if (!$label) return '';

        return <<<HTML
        <section class="story-section" id="common-mistakes">
            <h2 class="story-headline">{$label}</h2>
{$mistakesHtml}        </section>
HTML;
    }

    private function buildVariations() {
        if (empty($this->post['variations'])) {
            return '';
        }

        $variationsHtml = '';

        foreach ($this->post['variations'] as $var) {
            $name = htmlspecialchars($var['name'] ?? '');
            $desc = htmlspecialchars($var['description'] ?? '');
            $note = !empty($var['technical_note']) ? '<div class="technical-note">Note: ' . htmlspecialchars($var['technical_note']) . '</div>' : '';

            $variationsHtml .= <<<HTML
            <div class="variation-card">
                <h4>{$name}</h4>
                <p>{$desc}</p>
                {$note}
            </div>

HTML;
        }

        $label = htmlspecialchars($this->labels['variations'] ?? 'Variations');
        if (!$label) return '';

        return <<<HTML
        <section class="story-section" id="variations">
            <h2 class="story-headline">{$label}</h2>
{$variationsHtml}        </section>
HTML;
    }

    private function buildNutrition() {
        if (empty($this->post['nutrition'])) {
            return '';
        }

        $n = $this->post['nutrition'];
        $note = !empty($n['note']) ? '<div class="nutrition-note">' . htmlspecialchars($n['note']) . '</div>' : '';

        $label = htmlspecialchars($this->labels['nutrition'] ?? 'Nutrition');
        if (!$label) return '';

        $perServingLabel = htmlspecialchars($this->labels['nutrition_per_serving'] ?? 'Per Serving:');

        // Build items dynamically from nutrition keys (skip meta keys)
        $skipKeys = ['note', 'servings', 'serving_size', 'per_serving'];
        $itemsHtml = '';
        foreach ($n as $key => $value) {
            if (in_array($key, $skipKeys) || empty($value)) continue;
            $keyLabel = ucwords(str_replace(['_', 'grams', 'mg', 'per serving'], [' ', 'g', '', ''], $key));
            $keyLabel = trim(preg_replace('/\s+/', ' ', $keyLabel));
            $safeValue = htmlspecialchars($value);
            $safeLabel = htmlspecialchars($keyLabel);
            $itemsHtml .= <<<HTML

                <div class="nutrition-item">
                    <div class="nutrition-value">{$safeValue}</div>
                    <div class="nutrition-label">{$safeLabel}</div>
                </div>
HTML;
        }

        return <<<HTML
        <section class="story-section" id="nutrition">
            <h2 class="story-headline">{$label}</h2>
            <p><strong>{$perServingLabel}</strong></p>
            <div class="nutrition-grid">{$itemsHtml}
            </div>
            {$note}
        </section>
HTML;
    }

    private function buildStorage() {
        if (empty($this->post['storage_and_reheating'])) {
            return '';
        }

        $s = $this->post['storage_and_reheating'];
        $storageHtml = '';

        if (!empty($s['room_temperature'] ?? $s['room_temperature_storage'] ?? '')) {
            $text = htmlspecialchars($s['room_temperature'] ?? $s['room_temperature_storage']);
            $storageHtml .= <<<HTML
            <div class="storage-item">
                <div class="storage-title"> Room Temperature</div>
                <p>{$text}</p>
            </div>

HTML;
        }

        if (!empty($s['refrigerator'] ?? $s['refrigerator_storage'] ?? '')) {
            $text = htmlspecialchars($s['refrigerator'] ?? $s['refrigerator_storage']);
            $storageHtml .= <<<HTML
            <div class="storage-item">
                <div class="storage-title">Refrigerator</div>
                <p>{$text}</p>
            </div>

HTML;
        }

        if (!empty($s['freezer'] ?? $s['freezer_storage'] ?? '')) {
            $text = htmlspecialchars($s['freezer'] ?? $s['freezer_storage']);
            $storageHtml .= <<<HTML
            <div class="storage-item">
                <div class="storage-title">Freezer</div>
                <p>{$text}</p>
            </div>

HTML;
        }

        if (!empty($s['reheating'] ?? $s['reheating_method'] ?? '')) {
            $text = htmlspecialchars($s['reheating'] ?? $s['reheating_method']);
            $storageHtml .= <<<HTML
            <div class="storage-item">
                <div class="storage-title">Reheating</div>
                <p>{$text}</p>
            </div>

HTML;
        }

        if (!empty($s['make_ahead_strategy'] ?? $s['make_ahead_tip'] ?? '')) {
            $text = htmlspecialchars($s['make_ahead_strategy'] ?? $s['make_ahead_tip']);
            $storageHtml .= <<<HTML
            <div class="storage-item">
                <div class="storage-title">Make Ahead</div>
                <p>{$text}</p>
            </div>

HTML;
        }

        $label = htmlspecialchars($this->labels['storage'] ?? 'Storage & Tips');
        if (!$label) return '';

        return <<<HTML
        <section class="story-section" id="storage">
            <h2 class="story-headline">{$label}</h2>
{$storageHtml}        </section>
HTML;
    }

    private function buildFAQ() {
        if (empty($this->post['faq'])) {
            return '';
        }

        $faqHtml = '';

        foreach ($this->post['faq'] as $q) {
            $question = htmlspecialchars($q['question'] ?? '');
            $answer = htmlspecialchars($q['answer'] ?? '');

            $faqHtml .= <<<HTML
            <div class="faq-item">
                <div class="faq-question"> {$question}</div>
                <div class="faq-answer">{$answer}</div>
            </div>

HTML;
        }

        $label = htmlspecialchars($this->labels['faq'] ?? 'FAQ');
        if (!$label) return '';

        return <<<HTML
        <section class="story-section" id="faq">
            <h2 class="story-headline">{$label}</h2>
{$faqHtml}        </section>
HTML;
    }

    private function buildConclusion() {
        if (empty($this->post['conclusion'])) {
            return '';
        }

        $conclusion = htmlspecialchars($this->post['conclusion']);

        $label = htmlspecialchars($this->labels['conclusion'] ?? 'Final Thoughts');
        if (!$label) return '';

        return <<<HTML
        <section class="story-section">
            <h2 class="story-headline">{$label}</h2>
            <div class="conclusion">
                <p>{$conclusion}</p>
            </div>
        </section>
HTML;
    }

    private function buildIntroduction(): string {
        $text = $this->post['introduction'] ?? '';
        if (!$text) return '';
        $label = htmlspecialchars($this->labels['introduction'] ?? 'Introduction');
        return '<section class="story-section" id="introduction"><h2 class="story-headline">' . $label . '</h2><p>' . htmlspecialchars((string)$text) . '</p></section>';
    }

    private function buildTips(): string {
        $tips = $this->post['tips'] ?? '';
        if (!$tips) return '';
        if (is_array($tips)) $tips = implode(' ', $tips);
        return '<section class="story-section" id="tips"><p>' . htmlspecialchars((string)$tips) . '</p></section>';
    }

    private function buildStory(): string {
        // Uses 'story' field if present, otherwise falls back to 'introduction' only when no 'introduction' block exists in the layout
        $text = $this->post['story'] ?? '';
        if (!$text) return '';
        $label = htmlspecialchars($this->labels['story'] ?? 'Story');
        return '<section class="story-section" id="story"><h2 class="story-headline">' . $label . '</h2><p>' . htmlspecialchars((string)$text) . '</p></section>';
    }

    /**
     * Pub AdSense in-article rendue server-side à la position EXACTE du bloc ad_N
     * dans POST_LAYOUT. Slot = POST_AD_SLOTS[(N-1) % count] (cyclé). publisherId +
     * enabled lus depuis ads-config.json. La lib adsbygoogle.js est déjà chargée dans <head>.
     */
    private function buildAdUnit(int $n): string {
        static $client = null, $slots = null, $enabled = null;
        if ($client === null) {
            $cfgFile = __DIR__ . '/ads-config.json';
            $acfg    = is_file($cfgFile) ? (json_decode(file_get_contents($cfgFile), true) ?: []) : [];
            $enabled = $acfg['enabled'] ?? true;
            $client  = $acfg['publisherId'] ?? 'ca-pub-3666818985097490';
            $slots   = (defined('POST_AD_SLOTS') && is_array(POST_AD_SLOTS) && POST_AD_SLOTS)
                ? array_values(POST_AD_SLOTS) : ['4055138220', '8684648378'];
        }
        if (!$enabled || empty($slots)) return '';
        $slot = $slots[($n - 1) % count($slots)];
        return <<<HTML
        <div class="ad-container ad-in-post">
            <ins class="adsbygoogle" style="display:block;text-align:center" data-ad-client="{$client}" data-ad-slot="{$slot}" data-ad-format="fluid" data-ad-layout="in-article"></ins>
            <script>(adsbygoogle = window.adsbygoogle || []).push({});</script>
        </div>

HTML;
    }

    private function buildBlock(string $block): string {
        if (preg_match('/^image_(\d+)$/', $block, $m)) {
            $idx = (int)$m[1] - 1;
            return $idx === 0 ? $this->buildImage() : $this->getImageByIndex($idx);
        }
        if (preg_match('/^ad_(\d+)$/', $block, $m)) {
            return $this->buildAdUnit((int)$m[1]); // pub rendue à la position EXACTE du bloc
        }
        $map = [
            'breadcrumb'      => 'buildBreadcrumb',
            'header'          => 'buildHeader',
            'rating'          => 'buildRatingWidget',
            'description'     => 'buildDescription',
            'main_image'      => 'buildMainImage',
            'toc'             => 'buildTableOfContents',
            'why_this_works'  => 'buildWhyThisPostWorks',
            'ingredients'     => 'buildIngredients',
            'instructions'    => 'buildInstructions',
            'pro_tips'        => 'buildProTips',
            'common_mistakes' => 'buildCommonMistakes',
            'variations'      => 'buildVariations',
            'nutrition'       => 'buildNutrition',
            'storage'         => 'buildStorage',
            'faq'             => 'buildFAQ',
            'conclusion'      => 'buildConclusion',
            'introduction'    => 'buildIntroduction',
            'story'           => 'buildStory',
            'tips'            => 'buildTips',
        ];
        if (isset($map[$block])) {
            $method = $map[$block];
            return $this->$method();
        }
        return '';
    }

    private function formatDate($dateString) {
        if (!$dateString) return date('F j, Y');

        try {
            $date = new DateTime($dateString);
            return $date->format('F j, Y');
        } catch (Exception $e) {
            return date('F j, Y');
        }
    }
}

// Router — ignoré si le fichier est inclus uniquement pour la classe (POST_HTML_FUNCTIONS_ONLY)
if (!defined('POST_HTML_FUNCTIONS_ONLY')):

// Handle GET requests
$slug = isset($_GET['slug']) ? $_GET['slug'] : null;
$save = isset($_GET['save']) ? $_GET['save'] : false;

try {
    if ($slug) {
        // Build JSON path from slug
        $jsonPath = "posts/{$slug}/post.json";

        // Check if file exists
        if (!file_exists($jsonPath)) {
            throw new Exception("Post not found for slug: {$slug}");
        }

        if ($save) {
            // PostHTMLGenerator respecte POST_LAYOUT + schema Recipe + ratings
            $outputPath  = "posts/{$slug}/index.html";
            $ok = (new PostHTMLGenerator($jsonPath))->saveFile($outputPath);

            header('Content-Type: application/json');
            echo json_encode([
                'success' => (bool)$ok,
                'message' => $ok ? 'HTML file saved successfully!' : 'Failed to generate HTML',
                'output'  => $outputPath,
                'slug'    => $slug,
                'json'    => $jsonPath
            ]);
        } else {
            // Preview only — use PostHTMLGenerator (no file write)
            $generator = new PostHTMLGenerator($jsonPath);
            header('Content-Type: text/html; charset=utf-8');
            echo $generator->getHTML();
        }
        exit;
    }
    else {
        // List interface
        $page = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Post HTML Generator</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, sans-serif;
            background: linear-gradient(135deg, #8b6f47 0%, #a0826d 100%);
            min-height: 100vh;
            padding: 40px 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        .header {
            text-align: center;
            color: white;
            margin-bottom: 40px;
        }
        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        .header p {
            font-size: 1.1em;
            opacity: 0.9;
        }
        .card {
            background: white;
            border-radius: 12px;
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .card h2 {
            color: #8b6f47;
            margin-bottom: 20px;
            font-size: 1.8em;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            color: #333;
            font-weight: 600;
            margin-bottom: 8px;
        }
        input[type="text"],
        select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e8d4c4;
            border-radius: 6px;
            font-size: 1em;
            font-family: 'Courier New', monospace;
        }
        input[type="text"]:focus,
        select:focus {
            outline: none;
            border-color: #8b6f47;
            box-shadow: 0 0 0 3px rgba(139, 111, 71, 0.1);
        }
        .button-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        button,
        .button {
            padding: 12px 30px;
            border: none;
            border-radius: 6px;
            font-size: 1em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        .button-primary {
            background: #8b6f47;
            color: white;
        }
        .button-primary:hover {
            background: #a0826d;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(139, 111, 71, 0.3);
        }
        .button-secondary {
            background: #f0ebe5;
            color: #8b6f47;
        }
        .button-secondary:hover {
            background: #e8dfd5;
        }
        .info-box {
            background: #faf8f5;
            border-left: 4px solid #8b6f47;
            padding: 20px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .info-box h3 {
            color: #8b6f47;
            margin-bottom: 10px;
        }
        .info-box p {
            color: #666;
            margin-bottom: 8px;
            font-size: 0.95em;
        }
        .code {
            background: #2c2c2c;
            color: #00ff00;
            padding: 15px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 0.9em;
            margin: 10px 0;
            overflow-x: auto;
        }
        .response {
            background: #f0fef0;
            border-left: 4px solid #2d5f3f;
            padding: 15px;
            border-radius: 6px;
            margin-top: 15px;
            display: none;
        }
        .response.success {
            background: #f0fef0;
            border-left-color: #2d5f3f;
            color: #2d5f3f;
        }
        .response.error {
            background: #fff5f5;
            border-left-color: #c9534f;
            color: #c9534f;
        }
        .response.show {
            display: block;
        }
        @media (max-width: 768px) {
            .header h1 { font-size: 1.8em; }
            .card { padding: 20px; }
            .button-group { flex-direction: column; }
            button, .button { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1> Post HTML Generator</h1>
            <p>Generate beautiful HTML post pages from JSON in seconds</p>
        </div>

        <div class="card">
            <h2 class="story-headline"> Generate Post HTML</h2>

            <div class="info-box">
                <h2 class="story-headline">How to use:</h2>
                <p>1. Enter the post slug (folder name)</p>
                <p>2. Choose to view or save</p>
                <p>3. Click the button to generate</p>
            </div>

            <form id="generatorForm">
                <div class="form-group">
                    <label for="postSlug">Post Slug:</label>
                    <input type="text" id="postSlug" name="slug" placeholder="e.g., cozy-comforting-beef-stew-for-cold-winter-nights" required>
                </div>

                <div class="form-group">
                    <label for="saveOption">Action:</label>
                    <select id="saveOption" name="save" required>
                        <option value="0">View in Browser</option>
                        <option value="1">Save to posts/{slug}/index.html</option>
                    </select>
                </div>

                <div class="button-group">
                    <button type="submit" class="button button-primary">Generate HTML</button>
                    <a href="?" class="button button-secondary">Clear Form</a>
                </div>
            </form>

            <div id="response" class="response"></div>
        </div>

        <div class="card">
            <h2 class="story-headline"> API Documentation</h2>

            <h2 class="story-headline" style="color: #8b6f47; margin-top: 20px;">View in Browser</h2>
            <p style="color: #666; margin-bottom: 10px;">Display the generated HTML in the browser:</p>
            <div class="code">generate-single-post.php?slug=cozy-comforting-beef-stew-for-cold-winter-nights</div>

            <h2 class="story-headline" style="color: #8b6f47; margin-top: 20px;">Save to Server</h2>
            <p style="color: #666; margin-bottom: 10px;">Save HTML to posts/{slug}/index.html:</p>
            <div class="code">generate-single-post.php?slug=cozy-comforting-beef-stew-for-cold-winter-nights&save=1</div>

            <h2 class="story-headline" style="color: #8b6f47; margin-top: 20px;">What happens?</h2>
            <div style="margin-top: 15px;">
                <p style="color: #666; margin-bottom: 10px;">• Reads from: posts/{slug}/post.json</p>
                <p style="color: #666; margin-bottom: 10px;">• Saves to: posts/{slug}/index.html</p>
                <p style="color: #666; margin-bottom: 10px;">• Images are preserved with their paths</p>
            </div>
        </div>

        <div class="card">
            <h2 class="story-headline"> Examples</h2>
            <div class="info-box">
                <h2 class="story-headline">Example 1: View Post</h2>
                <p>Select "View in Browser" and enter slug:</p>
                <code style="display: block; background: #f5f5f5; padding: 10px; margin: 10px 0; border-radius: 4px; font-family: monospace;">cozy-comforting-beef-stew-for-cold-winter-nights</code>
            </div>

            <div class="info-box">
                <h2 class="story-headline">Example 2: Save Post HTML</h2>
                <p>Select "Save to posts/{slug}/index.html" and enter slug:</p>
                <code style="display: block; background: #f5f5f5; padding: 10px; margin: 10px 0; border-radius: 4px; font-family: monospace;">cozy-classic-moist-banana-bread-post-for-fall-gatherings</code>
                <p style="margin-top: 10px; color: #666;">This will create index.html in the post folder automatically!</p>
            </div>

            <div class="info-box">
                <h2 class="story-headline">Example 3: Direct URL</h2>
                <p>You can also use direct URLs in browser or scripts:</p>
                <code style="display: block; background: #f5f5f5; padding: 10px; margin: 10px 0; border-radius: 4px; font-family: monospace;">http://localhost/SitePinterset/LummyPosts/generate-single-post.php?slug=your-post-slug&save=1</code>
            </div>
        </div>
    </div>

    <script>
        const form = document.getElementById('generatorForm');
        const response = document.getElementById('response');

        // Handle form submission
        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const slug = document.getElementById('postSlug').value.trim();
            const save = document.getElementById('saveOption').value;

            if (!slug) {
                showResponse('Please enter a post slug', 'error');
                return;
            }

            let url = `?slug=${encodeURIComponent(slug)}`;
            if (save === '1') {
                url += '&save=1';
            }

            try {
                if (save === '0') {
                    // View in browser
                    window.open(url, '_blank');
                    showResponse('Opening post in new tab...', 'success');
                } else {
                    // Save to server
                    const res = await fetch(url);
                    const data = await res.json();
                    if (data.success) {
                        showResponse('✓ ' + data.message + '<br>Output: ' + data.output, 'success');
                    } else {
                        showResponse('Error: ' + (data.error || data.message), 'error');
                    }
                }
            } catch (err) {
                showResponse('Error: ' + err.message, 'error');
            }
        });

        function showResponse(message, type) {
            response.innerHTML = message;
            response.className = 'response ' + type + ' show';
            setTimeout(() => {
                response.classList.remove('show');
            }, 5000);
        }
    </script>
</body>
</html>
HTML;
        header('Content-Type: text/html; charset=utf-8');
        echo $page;
    }
} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

endif; // POST_HTML_FUNCTIONS_ONLY
?>
<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

ob_start();

require_once 'config.php';
require_once __DIR__ . '/auth.php';
auth_check();


if ($_SERVER['REQUEST_METHOD'] === 'GET' && 
    isset($_GET['action']) && 
    $_GET['action'] === 'posts_index' &&
    isset($_GET['from_iframe'])) {
    
    // Notifier le parent que posts_index est terminé
    echo '<!DOCTYPE html>
    <html>
    <head><title>Index terminé</title></head>
    <body>
    <h2>✅ Index des posts généré avec succès!</h2>
    <p>Passage à la source suivante...</p>
    <script>
        // Notifier le parent que tout est vraiment terminé
        if (window.parent && window.parent !== window) {
            console.log("📨 Envoi message index_completed au parent");
            window.parent.postMessage({
                type: "post_status",
                status: "index_completed",
                message: "Index généré, prêt pour source suivante"
            }, "*");
        }
    </script>
    </body>
    </html>';
    exit;
}


$API_URL = 'http://127.0.0.1' . dirname($_SERVER['PHP_SELF']) . '/posts-api.php';
$postsDir = './posts';
$message = '';
$messageType = '';
$generatedpost = null;

// ===== FONCTIONS UTILITAIRES =====

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

function generatepostsIndex($postsDir = './post') {
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
function generateSitemaps($postsDir = './post') {
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
    
    // === SITEMAP POSTS (sitemap-posts.xml) ===
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
            $html .= '<li>' . htmlspecialchars(is_array($instruction) ? ($instruction['text'] ?? $instruction['description'] ?? implode(' ', $instruction)) : $instruction) . '</li>';
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

function generatePinterestRSSFeed($postsDir = './post') {
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
        if (!empty($post['image_path'])) {
            $mainImage = $protocol . '://' . $host . '/' . ltrim($post['images'][array_key_last($post['images'])]['filePath'], './');
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
    
    $rssPaths = [
        './rss.xml',
        './pinterest-rss.xml',
        $postsDir . '/rss.xml'
    ];
    
    $savedCount = 0;
    foreach ($rssPaths as $path) {
        if (file_put_contents($path, $rssXml) !== false) {
            $savedCount++;
        }
    }
    
    return [
        'success' => $savedCount > 0,
        'postsCount' => count($validposts),
        'filesCreated' => $savedCount,
        'paths' => array_filter($rssPaths, function($path) {
            return file_exists($path);
        })
    ];
}

function generateCategoryRSSFeed($categoryId, $categoryName, $postsDir = './post') {
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
            'message' => 'Aucun post en ligne trouvée pour cette catégorie',
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

function generateAllCategoryRSSFeeds($categoriesDir = './categories', $postsDir = './post') {
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

function generatePinVariations($title, $description) {
    $openaiApiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
    if (empty($openaiApiKey)) return null;

    $prompt = "Given this post post:\nTitle: $title\nDescription: $description\n\nGenerate 3 different Pinterest pin title and description variations. Each must be unique, engaging, and SEO-friendly for Pinterest.\n\nReturn ONLY a valid JSON array with exactly 3 objects. Each object must have:\n- 'title': short catchy title (max 100 characters)\n- 'description': engaging description with hashtags (max 500 characters)\n\nNo markdown, no code blocks, no extra text. Just the JSON array.";

    $data = [
        "model" => OPENAI_CONTENT_MODEL,
        "messages" => [
            ["role" => "system", "content" => "You are an expert Pinterest content creator. Return valid JSON array only, no markdown."],
            ["role" => "user", "content" => $prompt]
        ],
        "temperature" => 0.85,
        "max_tokens" => 900
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $openaiApiKey],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) return null;

    $result = json_decode($response, true);
    $content = $result['choices'][0]['message']['content'] ?? '';
    $content = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
    $content = preg_replace('/\s*```$/i', '', $content);
    $variations = json_decode(trim($content), true);

    if (!is_array($variations) || count($variations) < 3) return null;
    return array_slice($variations, 0, 3);
}

function savepostWithImages($postData, $imagesData) {
    global $postsDir;

    $slug = $postData['slug'];
    $postDir = $postsDir . '/' . $slug;
    
    if (!is_dir($postDir)) {
        if (!mkdir($postDir, 0755, true)) {
            return ['success' => false, 'error' => 'Impossible de créer le dossier: ' . $postDir];
        }
    }
    
    $structuredContent = prepareStructuredContent($postData, $slug, $imagesData);

    // Start with ALL AI-generated fields, then override system fields
    $post = $postData;
    $post['id']                 = 'post_' . time() . '_' . rand(100, 999);
    $post['slug']               = $slug;
    $post['isOnline']           = $postData['isOnline'] ?? false;
    $post['category_id']        = $postData['category_id'] ?? '';
    $post['author_id']          = $postData['author_id'] ?? 'author_001';
    $post['prep_time']          = (int)($postData['prep_time'] ?? 0);
    $post['cook_time']          = (int)($postData['cook_time'] ?? 0);
    $post['total_time']         = (int)($postData['total_time'] ?? ($post['prep_time'] + $post['cook_time']));
    $post['servings']           = (int)($postData['servings'] ?? 1);
    $post['structured_content'] = $structuredContent;
    $post['images']             = $imagesData;
    $post['image']              = !empty($imagesData) ? $imagesData[0]['fileName'] : '';
    $post['image_path']         = !empty($imagesData) ? $imagesData[0]['filePath'] : '';
    $post['image_dir']          = $slug . '/images';
    $post['generated_from_text'] = true;
    $post['has_rich_structure'] = true;
    $post['createdAt']          = date('Y-m-d\TH:i:sP');
    $post['updatedAt']          = date('Y-m-d\TH:i:sP');

    $pinVariations = generatePinVariations($post['title'], $post['description'] ?? '');
    if ($pinVariations) {
        $post['pin_variations'] = $pinVariations;
    }

    $postFile = $postDir . '/post.json';
    $jsonContent = json_encode($post, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    if (file_put_contents($postFile, $jsonContent) === false) {
        return ['success' => false, 'error' => 'Impossible d\'écrire le fichier JSON'];
    }
    
    // ✅ GÉNÉRATIONS AUTOMATIQUES APRÈS SAUVEGARDE
    $indexResult = generatepostsIndex($postsDir);
    $sitemapsResult = generateSitemaps($postsDir);
    $rssResult = generatePinterestRSSFeed($postsDir);
    $categoryRssResult = generateAllCategoryRSSFeeds('./categories', $postsDir);

    // HTML génération gérée par generate-post.php après la redirection
    $htmlGenerated = false;
    $htmlPath = $postDir . '/index.html';

    return [
        'success' => true,
        'post' => $post,
        'slug' => $slug,
        'file_path' => $postFile,
        'html_generated' => $htmlGenerated,
        'html_path' => $htmlPath,
        'index_generated' => $indexResult['success'],
        'sitemaps_generated' => $sitemapsResult['sitemap'] && $sitemapsResult['sitemap_posts'],
        'rss_generated' => $rssResult['success'],
        'category_rss_generated' => $categoryRssResult['success']
    ];
}


// ===== TRAITEMENT AJAX POST =====

// Action: save_webp_local
if ($_SERVER['REQUEST_METHOD'] === 'POST' && 
    isset($_POST['action']) && 
    $_POST['action'] === 'save_webp_local') {
    
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        error_log("Action save_webp_local reçue. Slug: " . ($_POST['postslug'] ?? 'MISSING'));
        
        $imageData = $_POST['imageData'] ?? '';
        $fileName = $_POST['fileName'] ?? '';
        $postslug = $_POST['postslug'] ?? '';
        
        if (empty($imageData) || empty($fileName) || empty($postslug)) {
            throw new Exception('Paramètres manquants');
        }
        
        if (strpos($imageData, 'data:image/webp;base64,') === 0) {
            $imageData = substr($imageData, strlen('data:image/webp;base64,'));
        }
        
        $decodedImage = base64_decode($imageData);
        
        if ($decodedImage === false) {
            throw new Exception("Erreur de décodage base64");
        }
        
        $fileName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $fileName) . '.webp';
        $postslug = preg_replace('/[^a-zA-Z0-9_-]/', '-', $postslug);
        
        $postDir = $postsDir . '/' . $postslug;
        $imagesDir = $postDir . '/images';
        
        error_log("Création dossiers: " . $postDir . " et " . $imagesDir);
        
        if (!is_dir($postDir)) {
            mkdir($postDir, 0755, true);
        }
        
        if (!is_dir($imagesDir)) {
            mkdir($imagesDir, 0755, true);
        }
        
        $filePath = $imagesDir . '/' . $fileName;
        
        if (file_put_contents($filePath, $decodedImage) !== false) {
            $fileSize = filesize($filePath);
            $fileSizeKB = round($fileSize / 1024, 1);
            
            error_log("Image sauvegardée avec succès: " . $filePath . " (" . $fileSizeKB . " KB)");
            
            echo json_encode([
                'success' => true,
                'fileName' => $fileName,
                'filePath' => "posts/{$postslug}/images/{$fileName}",
                'relativePath' => "{$postslug}/images/{$fileName}",
                'size' => $fileSizeKB . ' KB'
            ], JSON_UNESCAPED_SLASHES);
        } else {
            throw new Exception("Impossible d'écrire le fichier");
        }


        
    } catch (Exception $e) {
        error_log("Erreur save_webp_local: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// Action: save_final_post_local
if ($_SERVER['REQUEST_METHOD'] === 'POST' && 
    isset($_POST['action']) && 
    $_POST['action'] === 'save_final_post_local') {
    
    // Nettoyer tout output buffer précédent
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $postJson = $_POST['post_data'] ?? '';
        $imagesJson = $_POST['images_data'] ?? '';
        
        if (empty($postJson)) {
            throw new Exception('Données de post manquantes');
        }
        
        $postData = json_decode($postJson, true);
        $imagesData = json_decode($imagesJson, true) ?: [];
        
        if (!$postData) {
            throw new Exception('Format de post invalide');
        }
        
        $saveResult = savepostWithImages($postData, $imagesData);
        
        if ($saveResult['success']) {
            // 🔄 Lancer le pipeline complet (templates + satellites + isOnline) en fire & forget
            $savedSlug = $saveResult['slug'];
            $pipelineUrl = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['REQUEST_URI'] ?? '/') . '/auto-daily-csv.php';
            $ch = curl_init($pipelineUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query(['force_slug' => $savedSlug]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5, // fire & forget — auto-daily-csv.php continue seul (set_time_limit 0)
            ]);
            curl_exec($ch);
            curl_close($ch);

            // ✅ Retourner UNIQUEMENT du JSON
            echo json_encode([
                'success' => true,
                'message' => 'Post sauvegardé avec succès',
                'slug' => $saveResult['slug'],
                'file_path' => $saveResult['file_path'],
                'index_generated' => $saveResult['index_generated'] ?? false,
                'sitemaps_generated' => $saveResult['sitemaps_generated'] ?? false,
                'rss_generated' => $saveResult['rss_generated'] ?? false
            ], JSON_UNESCAPED_SLASHES);
        } else {
            throw new Exception($saveResult['error']);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    
    exit; // ✅ Important : arrêter l'exécution ici
}

// ===== TRAITEMENT FORMULAIRE STANDARD =====

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_from_text') {
    $sourceText = trim($_POST['source_text'] ?? '');

    if (empty($sourceText)) {
        $message = "Veuillez fournir un texte source.";
        $messageType = 'error';
    } else {
        $result   = null;
        $maxTries = 3;
        for ($attempt = 1; $attempt <= $maxTries; $attempt++) {
            $ch = curl_init($API_URL . '?action=analyze');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode(['source_text' => $sourceText]),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
            $result = json_decode($response, true);
            // Retry only on JSON generation errors (transient AI issue)
            $errMsg = $result['error'] ?? '';
            if (!empty($result['success']) || (strpos($errMsg, 'Invalid JSON') === false && strpos($errMsg, 'Syntax error') === false)) {
                break;
            }
        }

        if ($result['success']) {
            $generatedpost = $result['data'];
            // CSV title override (if user selected a row from CSV)
            $csvTitle = trim($_POST['csv_title'] ?? '');
            if ($csvTitle !== '') {
                $generatedpost['title'] = $csvTitle;
            }
            $generatedpost['slug'] = generateUniquepostslug($generatedpost['title']);
            $generatedpost['uniqueSlug'] = $generatedpost['slug'];
            // board_name: CSV value takes priority, fallback to AI-generated
            $csvBoard = trim($_POST['board_name'] ?? '');
            if (!empty($csvBoard)) {
                $generatedpost['board_name'] = $csvBoard;
            }
            // else: keep AI-generated board_name from $generatedpost['board_name']

            $message = "Post analysé avec succès !";
            $messageType = 'success';
        } else {
            $message = "Erreur : " . ($result['error'] ?? 'Erreur inconnue');
            $messageType = 'error';
        }
    }
}

// Config template (direct — évite appel HTTP sans session)
$baseUrl = defined('BASE_URL') ? BASE_URL : '';
$templateEndpoint = $baseUrl . 'generate_template.php';

// Catégories (direct — évite appel HTTP sans session)
$categories = [];
$_catDir = __DIR__ . '/categories';
if (is_dir($_catDir)) {
    foreach (glob($_catDir . '/*/category.json') ?: [] as $_catFile) {
        $_cat = json_decode(file_get_contents($_catFile), true);
        if ($_cat && isset($_cat['id'])) $categories[] = $_cat;
    }
}

// Vérifier OpenAI directement (évite l'appel HTTP qui échoue si auth requise)
$openaiConfigured = defined('OPENAI_API_KEY') && !empty(OPENAI_API_KEY);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Générateur de Posts - Client</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 { font-size: 2.5rem; margin-bottom: 10px; }
        .header p { opacity: 0.9; font-size: 1.1rem; }
        .main-content { padding: 40px; }
        .api-status {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            text-align: center;
        }
        .status-active { color: #28a745; font-weight: bold; }
        .status-inactive { color: #dc3545; font-weight: bold; }
        .form-section {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
        }
        .form-section h3 { color: #333; margin-bottom: 20px; font-size: 1.3rem; }
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
        }
        .form-control:focus {
            outline: none;
            border-color: #ff6b6b;
        }
        textarea.form-control {
            min-height: 200px;
            resize: vertical;
        }
        .images-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }
        .image-box {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 20px;
            border: 2px dashed #ddd;
            transition: all 0.3s ease;
        }
        .image-box.processed {
            border-color: #28a745;
            background: #d4edda;
        }
        .image-preview {
            text-align: center;
            margin: 15px 0;
            display: none;
        }
        .image-preview canvas {
            max-width: 100%;
            max-height: 200px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .image-status {
            font-size: 14px;
            margin-top: 10px;
            padding: 8px;
            border-radius: 5px;
            text-align: center;
        }
        .status-processing { background: #d1ecf1; color: #0c5460; }
        .status-success { background: #d4edda; color: #155724; }
        .status-error { background: #f8d7da; color: #721c24; }
        /* CSV Loader */
        .csv-loader { margin-bottom: 20px; }
        .csv-rows-list { max-height: 260px; overflow-y: auto; border: 2px solid #e0e0e0; border-radius: 8px; margin-top: 10px; }
        .csv-row { display: flex; align-items: center; gap: 10px; padding: 10px 14px; cursor: pointer; border-bottom: 1px solid #f0f0f0; transition: background 0.15s; }
        .csv-row:hover { background: #fff3cd; }
        .csv-row.selected { background: #d4edda; border-left: 4px solid #28a745; }
        .csv-row .csv-num { font-size: 11px; color: #999; min-width: 22px; }
        .csv-row .csv-title { flex: 1; font-size: 13px; font-weight: 500; color: #333; }
        .csv-row .csv-board { font-size: 11px; color: #E60023; background: #fff0f0; padding: 2px 7px; border-radius: 10px; white-space: nowrap; }
        .csv-selected-info { margin-top: 8px; font-size: 13px; color: #155724; background: #d4edda; padding: 6px 12px; border-radius: 6px; display: none; }
        .btn {
            padding: 15px 30px;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            font-weight: 600;
            font-size: 16px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            margin: 5px;
        }
        .btn-primary {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
            color: white;
        }
        .btn-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
        }
        .btn-process {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: white;
            width: 100%;
            margin: 10px 0;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 600;
        }
        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .alert-error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        .post-preview {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin: 20px 0;
            border: 2px solid #e9ecef;
        }
        .post-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📝 Générateur de Posts - Client</h1>
            <p>Analysez le texte avec l'IA, ajoutez vos 3 images, tout va dans un dossier dédié</p>
        </div>

        <div class="main-content">
            <?php if ($message): ?>
                <div class="alert alert-<?= $messageType ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <div class="api-status">
                <div>OpenAI GPT-4 (Analyse texte)</div>
                <div class="<?= $openaiConfigured ? 'status-active' : 'status-inactive' ?>">
                    <?= $openaiConfigured ? '✅ Configurée' : '❌ Requise dans config.php' ?>
                </div>
            </div>

            <?php if ($openaiConfigured): ?>
                <?php if (!$generatedpost): ?>
                    <div class="form-section">
                        <h3>Analyser et convertir un texte</h3>
                        
                        <!-- CSV Loader -->
                        <div class="csv-loader">
                            <label style="font-weight:600;color:#333;display:block;margin-bottom:8px;">📄 Charger titres depuis CSV (optionnel)</label>
                            <input type="file" id="csvFileInput" accept=".csv" class="form-control" style="padding:8px;">
                            <div id="csvRowsList" class="csv-rows-list" style="display:none;"></div>
                            <div id="csvSelectedInfo" class="csv-selected-info"></div>
                        </div>

                        <form method="post">
                            <input type="hidden" name="action" value="generate_from_text">
                            <input type="hidden" name="board_name" id="boardNameHidden" value="">
                            <input type="hidden" name="csv_title" id="csvTitleHidden" value="">

                            <div class="form-group">
                                <label for="source_text">Texte source du post *</label>
                                <textarea name="source_text" id="source_text" class="form-control" required
                                          placeholder="Collez ici le texte d'un post..."></textarea>
                            </div>

                            <div style="text-align: center; margin-top: 30px;">
                                <button type="submit" class="btn btn-primary">
                                    🔍 Analyser le texte avec l'IA
                                </button>
                            </div>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="post-preview">
                        <h2>Prompt Image :</h2>
                        <p style="text-align: center; color: #E60023; margin-bottom: 10px;"><?= htmlspecialchars($generatedpost['promptIMG']) ?></p>
                        <h3 id="post-title"><?= htmlspecialchars($generatedpost['title']) ?></h3>
                        <p style="color: #666; margin-bottom: 20px;"><?= htmlspecialchars($generatedpost['description']) ?></p>
                        
                        <div class="post-meta">
                            <div><strong>⏱️ Préparation:</strong> <?= $generatedpost['prep_time'] ?> min</div>
                            <div><strong>🔥 Cuisson:</strong> <?= $generatedpost['cook_time'] ?> min</div>
                            <div><strong>👥 Portions:</strong> <?= $generatedpost['servings'] ?></div>
                            <div><strong>📊 Difficulté:</strong> <?= ucfirst($generatedpost['difficulty']) ?></div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>📸 Ajouter 3 images à votre post</h3>
                        <div class="images-section">
                            <div class="image-box" id="imageBox1">
                                <div class="form-group">
                                    <label for="imageUrl1">🖼️ Image 1 - Principale :</label>
                                    <input type="url" id="imageUrl1" class="form-control" value="<?= $baseUrl ?>tmpIMG/image_1.webp" placeholder="https://example.com/image1.jpg">
                                </div>
                                <button type="button" id="btn-process1" class="btn btn-process" onclick="processImage(1)">📥 Traiter Image 1</button>
                                <div class="image-preview" id="preview1">
                                    <canvas id="canvas1"></canvas>
                                </div>
                                <div class="image-status" id="status1"></div>
                            </div>

                            <div class="image-box" id="imageBox2">
                                <div class="form-group">
                                    <label for="imageUrl2">🖼️ Image 2 - Étapes :</label>
                                    <input type="url" id="imageUrl2" class="form-control" value="<?= $baseUrl ?>tmpIMG/image_2.webp" placeholder="https://example.com/image2.jpg">
                                </div>
                                <button type="button" id="btn-process2" class="btn btn-process" onclick="processImage(2)">📥 Traiter Image 2</button>
                                <div class="image-preview" id="preview2">
                                    <canvas id="canvas2"></canvas>
                                </div>
                                <div class="image-status" id="status2"></div>
                            </div>

                            <div class="image-box" id="imageBox3">
                                <div class="form-group">
                                    <label for="imageUrl3">🖼️ Image 3 - Résultat :</label>
                                    <input type="url" id="imageUrl3" class="form-control" value="<?= $baseUrl ?>tmpIMG/image_3.webp" placeholder="https://example.com/image3.jpg">
                                </div>
                                <button type="button" id="btn-process3" class="btn btn-process" onclick="processImage(3)">📥 Traiter Image 3</button>
                                <div class="image-preview" id="preview3">
                                    <canvas id="canvas3"></canvas>
                                </div>
                                <div class="image-status" id="status3"></div>
                            </div>
                        </div>

                        <div style="text-align: center; margin-top: 30px;">
                            <button id="savepost" type="button" class="btn btn-success" onclick="saveCompletepost()">
                                💾 Sauvegarder le Post Complet
                            </button>
                            <button type="button" class="btn btn-primary" onclick="resetGeneration()">
                                🔄 Nouveau Post
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="alert alert-error">
                    ⚠️ Clé API OpenAI requise dans config.php
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const baseURL = window.location.href.split("posts-client.php")[0];  
        console.log("Base URL:", baseURL);
        
        const generatedpostData = <?= $generatedpost ? json_encode($generatedpost) : 'null' ?>;
        const templateEndpoint = '<?= $templateEndpoint ?>';
        
        const imageStates = {
            1: { processed: false, fileName: null, filePath: null, originalUrl: null },
            2: { processed: false, fileName: null, filePath: null, originalUrl: null },
            3: { processed: false, fileName: null, filePath: null, originalUrl: null }
        };

        function setImageStatus(imageNumber, message, type) {
            const statusEl = document.getElementById(`status${imageNumber}`);
            statusEl.textContent = message;
            statusEl.className = `image-status status-${type}`;
        }

        async function processImage(imageNumber) {
            console.log('Processing image:', imageNumber);
            if (!generatedpostData) {
                alert('Veuillez d\'abord générer un post avec l\'IA');
                return;
            }

            setImageStatus(imageNumber, '⏳ Traitement en cours...', 'processing');

            try {
                let img;
                
                // Gérer l'Image 4 (upload) différemment des autres (URL)
                if (imageNumber === 4) {
                    if (!uploadedFiles[4]) {
                        setImageStatus(imageNumber, '❌ Veuillez sélectionner un fichier', 'error');
                        return;
                    }
                    img = await loadImageFromFile(uploadedFiles[4]);
                } else {
                    const imageUrl = document.getElementById(`imageUrl${imageNumber}`).value.trim();
                    if (!imageUrl) {
                        setImageStatus(imageNumber, '❌ Veuillez entrer une URL', 'error');
                        return;
                    }
                    img = await loadImageFromUrl(imageUrl);
                }
                
                const webpData = await convertToWebP(img, imageNumber);
                const postslug = generatedpostData.uniqueSlug;
                const fileName = `${postslug}_image_${imageNumber}`;
                
                const result = await saveImageToLocal(webpData, fileName, postslug);
                
                imageStates[imageNumber] = {
                    processed: true,
                    fileName: result.fileName,
                    filePath: result.filePath,
                    relativePath: result.relativePath,
                    originalUrl: imageNumber === 4 ? uploadedFiles[4].name : document.getElementById(`imageUrl${imageNumber}`).value
                };
                
                document.getElementById(`imageBox${imageNumber}`).classList.add('processed');
                setImageStatus(imageNumber, `✅ Sauvegardé: ${result.fileName}`, 'success');
                
            } catch (error) {
                setImageStatus(imageNumber, `❌ Erreur: ${error.message}`, 'error');
            }
        }

        async function saveCompletepost() {
            if (!generatedpostData) {
                alert('Aucun post généré');
                return;
            }

            // Ensure board_name is always up-to-date from JS state
            if (window._csvBoardName) {
                generatedpostData.board_name = window._csvBoardName;
            }

            const processedImages = Object.values(imageStates)
                .filter(state => state.processed)
                .map((state, index) => ({
                    fileName: state.fileName,
                    filePath: state.filePath,
                    relativePath: state.relativePath,
                    originalUrl: state.originalUrl,
                    order: index + 1,
                    type: state.type
                }));

            if (processedImages.length === 0) {
                alert('❌ Veuillez traiter au moins une image avant de sauvegarder');
                return;
            }

            document.getElementById('savepost').disabled = true;
            document.getElementById('savepost').textContent = '💾 Sauvegarde en cours...';

            if (window.parent && window.parent !== window) {
                window.parent.postMessage({
                    type: 'post_status',
                    status: 'saving',
                    message: 'Sauvegarde en cours...'
                }, '*');
            }

            try {
                // ✅ ÉTAPE 1: Générer le template Pinterest (image 4)
                if (processedImages.length >= 2) {
                    console.log('📸 Génération du template Pinterest...');
                    
                    const image1Path = baseURL + processedImages[0].filePath;
                    const image2Path = baseURL + processedImages[1].filePath;
                    
                    const templateFormData = new FormData();
                    templateFormData.append('image1', image1Path);
                    templateFormData.append('image2', image2Path);
                    templateFormData.append('title', generatedpostData.title);
                    templateFormData.append('uniqueSlug', generatedpostData.slug);
                    templateFormData.append('folder', 'posts');
                    
                    try {
                        const templateResponse = await fetch(templateEndpoint, {
                            method: 'POST',
                            body: templateFormData
                        });

                        if (templateResponse.ok) {
                            const templateResult = await templateResponse.json();
                            console.log('✅ Template généré:', templateResult);

                            processedImages.push({
                                fileName: templateResult.filename,
                                filePath: templateResult.path,
                                relativePath: templateResult.pathrelative,
                                originalUrl: templateResult.url,
                                order: processedImages.length + 1,
                                type: 'template'
                            });
                        } else {
                            console.warn('⚠️ Template non généré, continue sans template');
                        }
                    } catch (templateError) {
                        console.warn('⚠️ Erreur template:', templateError);
                    }
                }

                // ✅ ÉTAPE 2: Sauvegarder le post complet
                const formData = new FormData();
                formData.append('action', 'save_final_post_local');
                formData.append('post_data', JSON.stringify(generatedpostData));
                formData.append('images_data', JSON.stringify(processedImages));

                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP Error: ${response.status}`);
                }

                const responseText = await response.text();
                console.log('Response Text:', responseText);
                
                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (parseError) {
                    console.error('JSON Parse Error:', parseError);
                    throw new Error('Réponse serveur invalide');
                    location.href = 'posts-client.php';
                }
                
                if (result.success) {
                    console.log('✅ Post sauvegardé avec succès');

                    if (window.parent && window.parent !== window) {
                        window.parent.postMessage({
                            type: 'post_status',
                            status: 'completed',
                            message: 'Sauvegarde terminée',
                            slug: result.slug || generatedpostData.slug
                        }, '*');
                    }

                    // alert('✅ Post sauvegardé avec succès !');
                    setTimeout(() => location.href = 'generate-post.php?slug=' + encodeURIComponent(result.slug || generatedpostData.slug), 1000);
                } else {
                    location.href = 'posts-client.php';
                    throw new Error(result.error || 'Erreur inconnue');
                }
                
            } catch (error) {
                console.error('Erreur complète:', error);
                
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage({
                        type: 'post_status',
                        status: 'error',
                        message: 'Erreur: ' + error.message
                    }, '*');
                }
                
                // alert('❌ Erreur: ' + error.message);
                document.getElementById('savepost').disabled = false;
                document.getElementById('savepost').textContent = '💾 Sauvegarder le Post Complet';
            }
        }

        function resetGeneration() {
            if (confirm('Voulez-vous recommencer avec un nouveau post ?')) {
                location.reload();
            }
        }

        function loadImageFromUrl(url) {
            return new Promise((resolve, reject) => {
                const img = new Image();
                img.crossOrigin = 'anonymous';
                
                img.onload = () => {
                    console.log('✅ Image chargée:', url);
                    resolve(img);
                };
                
                img.onerror = (e) => {
                    console.error('❌ Erreur chargement image:', url);
                    reject(new Error('Impossible de charger l\'image'));
                };
                
                img.src = url;
            });
        }

        function convertToWebP(img, imageNumber) {
            return new Promise((resolve, reject) => {
                try {
                    const canvas = document.getElementById(`canvas${imageNumber}`);
                    const ctx = canvas.getContext('2d');
                    
                    canvas.width = img.width;
                    canvas.height = img.height;
                    ctx.drawImage(img, 0, 0);
                    
                    document.getElementById(`preview${imageNumber}`).style.display = 'block';
                    
                    canvas.toBlob((blob) => {
                        if (!blob) {
                            reject(new Error('Erreur conversion WebP'));
                            return;
                        }
                        
                        const reader = new FileReader();
                        reader.onload = () => resolve(reader.result);
                        reader.onerror = () => reject(new Error('Erreur lecture blob'));
                        reader.readAsDataURL(blob);
                        
                    }, 'image/webp', 0.9);
                    
                } catch (error) {
                    console.error('Erreur convertToWebP:', error);
                    reject(error);
                }
            });
        }
        

        async function saveImageToLocal(webpData, fileName, postslug) {
            try {
                const formData = new FormData();
                formData.append('action', 'save_webp_local');
                formData.append('imageData', webpData);
                formData.append('fileName', fileName);
                formData.append('postslug', postslug);
                
                console.log('📤 Envoi image:', fileName);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP Error: ${response.status}`);
                }

                const responseText = await response.text();
                
                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (parseError) {
                    console.error('❌ JSON Parse Error:', parseError);
                    throw new Error('Réponse serveur invalide');
                    location.href = 'posts-client.php';
                }
                
                if (result.success) {
                    console.log('✅ Image sauvegardée:', result);
                    return result;
                } else {
                    throw new Error(result.error || 'Erreur inconnue');
                }
                
            } catch (error) {
                console.error('❌ Erreur saveImageToLocal:', error);
                throw new Error('Erreur serveur: ' + error.message);
            }
        }

    </script>

    <script>
    // ── CSV Loader ──────────────────────────────────────────────────────────────
    window._csvBoardName = '';

    function parseCsvLine(line) {
        const result = [];
        let current = '';
        let inQuotes = false;
        for (let i = 0; i < line.length; i++) {
            const ch = line[i];
            if (ch === '"') {
                if (inQuotes && line[i + 1] === '"') { current += '"'; i++; }
                else { inQuotes = !inQuotes; }
            } else if (ch === ',' && !inQuotes) {
                result.push(current.trim());
                current = '';
            } else {
                current += ch;
            }
        }
        result.push(current.trim());
        return result;
    }

    const csvInput = document.getElementById('csvFileInput');
    if (csvInput) {
        csvInput.addEventListener('change', function (e) {
            const file = e.target.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = function (ev) {
                const lines = ev.target.result.split(/\r?\n/).filter(l => l.trim());
                const list = document.getElementById('csvRowsList');
                list.innerHTML = '';
                list.style.display = 'block';
                // skip header row
                lines.slice(1).forEach(function (line) {
                    const cols = parseCsvLine(line);
                    if (cols.length < 4) return;
                    const keyword  = cols[0] || '';
                    const board    = cols[1] || '';
                    const num      = cols[2] || '';
                    const title    = cols[3] || '';
                    if (!title) return;

                    const row = document.createElement('div');
                    row.className = 'csv-row';
                    row.innerHTML = `<span class="csv-num">#${num}</span><span class="csv-title">${title}</span><span class="csv-board">${board}</span>`;
                    row.addEventListener('click', function () {
                        document.querySelectorAll('.csv-row').forEach(r => r.classList.remove('selected'));
                        row.classList.add('selected');
                        // Store in hidden fields
                        const bh = document.getElementById('boardNameHidden');
                        const ct = document.getElementById('csvTitleHidden');
                        if (bh) bh.value = board;
                        if (ct) ct.value = title;
                        window._csvBoardName = board;
                        // Info banner
                        const info = document.getElementById('csvSelectedInfo');
                        if (info) {
                            info.textContent = `✅ Sélectionné : "${title}" → Board: ${board}`;
                            info.style.display = 'block';
                        }
                    });
                    list.appendChild(row);
                });
            };
            reader.readAsText(file);
        });
    }
    </script>

    <script>
document.addEventListener('DOMContentLoaded', function() {
    try {
        const sourceText = document.getElementById('source_text');
        const categorySelect = document.getElementById('category_id');
        
        // Configuration
        const AUTO_CLICK_CONFIG = {
            enableAutoProcess: true,
            enableAutoSave: true,
            delayBetweenImages: 2000,
            delayBeforeSave: 12000
        };
        
        if (sourceText && categorySelect) {
            // ✅ FORMULAIRE MODE
            console.log('✅ Mode formulaire actif');
            
        } else {
            // ✅ POST GÉNÉRÉ MODE
            console.log('📸 Mode traitement automatique actif');
            
            if (!AUTO_CLICK_CONFIG.enableAutoProcess) {
                console.log('⚠️ Auto-processing désactivé dans la config');
                return;
            }
            
            // Notifier le parent qu'on démarre
            if (window.parent && window.parent !== window) {
                window.parent.postMessage({
                    type: 'post_status',
                    status: 'processing',
                    message: 'Traitement des images démarré'
                }, '*');
            }
            
            // Vérifier si les boutons existent
            const buttons = [
                document.querySelector('#btn-process1'),
                document.querySelector('#btn-process2'),
                document.querySelector('#btn-process3')
            ];
            
            const allButtonsExist = buttons.every(btn => btn !== null);
            
            if (!allButtonsExist) {
                console.log('⚠️ Certains buttons manquent');
                return;
            }
            
            const postTitle = document.getElementById("post-title");
            
            if (!postTitle) {
                console.log('⚠️ Titre de post introuvable');
                return;
            }
            
            const formData = new URLSearchParams();
            formData.append('title', postTitle.innerText);

            console.log('🚀 Envoi du titre vers genimg.php:', postTitle.innerText);

            // Traitement images — démarre que genimg.php réussisse ou échoue
            const startImageProcessing = () => {
                setTimeout(() => {
                    console.log(`🚀 Lancement traitement ${buttons.length} images`);
                    buttons.forEach((button, idx) => {
                        setTimeout(() => {
                            button.click();
                            console.log(`✅ Image ${idx + 1}/${buttons.length} lancée`);
                        }, idx * AUTO_CLICK_CONFIG.delayBetweenImages);
                    });

                    if (AUTO_CLICK_CONFIG.enableAutoSave) {
                        const maxWaitMs = 180000;
                        const startWait = Date.now();
                        const checkAndSave = setInterval(() => {
                            const processed = Object.values(imageStates).filter(s => s.processed).length;
                            const elapsed = Date.now() - startWait;
                            if (processed >= 3 || (processed >= 1 && elapsed > maxWaitMs)) {
                                clearInterval(checkAndSave);
                                const saveButton = document.getElementById('savepost');
                                if (saveButton && !saveButton.disabled) {
                                    console.log(`💾 Auto-save: ${processed} images traitées`);
                                    saveButton.click();
                                }
                            } else if (elapsed > maxWaitMs) {
                                clearInterval(checkAndSave);
                                console.warn('⚠️ Timeout: aucune image traitée');
                            }
                        }, 2000);
                    }
                }, 500);
            };

            fetch('genimg.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            })
            .then(response => response.text())
            .then(response => {
                console.log('✅ genimg.php OK:', response);
                startImageProcessing();
            })
            .catch(error => {
                console.warn('⚠️ genimg.php échoué, traitement quand même:', error);
                startImageProcessing();
            });
        }
        
    } catch (error) {
        console.error('❌ Erreur:', error);
    }
});
</script>
</body>
</html>
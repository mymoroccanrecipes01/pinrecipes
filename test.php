<?php
require_once 'config.php';
function generatePinterestRSSFeed($postsDir = './posts') {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = HOST_NAME;
    $basePage = '/base.html';
    $siteUrl = $protocol . '://' . $host;
    
    $rssConfig = [
        'title' => 'Delicious Posts Feed - Pinterest',
        'description' => 'Fresh posts and cooking inspiration for Pinterest discovery',
        'link' => $siteUrl . $basePage,
        'language' => 'en-US',
        'copyright' => '© ' . date('Y') . ' Post Collection',
        'managingEditor' => '',
        'webMaster' => '',
        'category' => 'Food & Cooking',
        'generator' => 'Post RSS Generator v2.0',
        'ttl' => 1440,
        'maxItems' => 50
    ];
    
    $validPosts = [];
    
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
                    $validPosts[] = $postData;
                }
            }
        }
    }
    
    usort($validPosts, function($a, $b) {
        return $b['lastModified'] - $a['lastModified'];
    });
    
    $validPosts = array_slice($validPosts, 0, $rssConfig['maxItems']);
    
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
    
    foreach ($validPosts as $post) {
        $postUrl = $siteUrl . '/posts/' . $post['slug'] . '/';
        $pubDate = date('r', $post['lastModified']);
      
        $mainImage = '';
        if (!empty($post['images']) && isset($post['images'][0]['filePath'])) {
            $mainImage = $protocol . '://' . $host . '/' . ltrim($post['images'][0]['filePath'], './');
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
        
        $tags = extractPostTags($post);
        if (!empty($tags)) {
            $description .= ' | #' . implode(' #', array_slice($tags, 0, 5));
        }
        
        $contentHtml = buildPostContentHTML($post, $mainImage, $tags);
        
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
    
    // FIX: Vérifier les permissions avant d'essayer d'écrire
    $rssPaths = [
        './rss.xml',
        './pinterest-rss.xml',
        $postsDir . '/rss.xml'
    ];
    
    $savedCount = 0;
    $errors = [];
    
    foreach ($rssPaths as $path) {
        // Vérifier si le dossier parent existe et est accessible en écriture
        $directory = dirname($path);
        
        if (!is_dir($directory)) {
            $errors[$path] = "Directory doesn't exist: $directory";
            continue;
        }
        
        if (!is_writable($directory)) {
            $errors[$path] = "Directory not writable: $directory";
            continue;
        }
        
        // Tenter d'écrire le fichier
        if (@file_put_contents($path, $rssXml) !== false) {
            @chmod($path, 0644); // Assurer les bonnes permissions
            $savedCount++;
        } else {
            $errors[$path] = "Failed to write file (check permissions)";
        }
    }
    
    
    var_dump( [
        'success' => $savedCount > 0,
        'postsCount' => count($validPosts),
        'filesCreated' => $savedCount,
        'paths' => array_filter($rssPaths, function($path) {
            return file_exists($path);
        }),
        'errors' => $errors // Ajouter les erreurs pour debug
    ]);
}

function extractPostTags($post) {
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

function buildPostContentHTML($post, $mainImage, $tags) {
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
            $html .= '<li>' . htmlspecialchars($instruction) . '</li>';
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



generatePinterestRSSFeed();
?>

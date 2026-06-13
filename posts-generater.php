<?php

require_once 'config.php';
$_cliBypass = (($_SERVER['HTTP_X_CLI_SECRET'] ?? '') !== '' && ($_SERVER['HTTP_X_CLI_SECRET'] ?? '') === CLI_SECRET)
           || ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1'
           || ($_SERVER['REMOTE_ADDR'] ?? '') === '::1';
if (!$_cliBypass) {
    require_once __DIR__ . '/auth.php';
    auth_check();
}

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


// Traiter l'action si c'est un GET
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'posts_index') {
    $categoriesDir = './posts';
    
    // Créer le dossier s'il n'existe pas
    if (!is_dir($categoriesDir)) {
        mkdir($categoriesDir, 0755, true);
    }
    
    $validFolders = [];
    
    // Scanner le dossier posts
    $handle = opendir($categoriesDir);
    if ($handle) {
        while (($item = readdir($handle)) !== false) {
            if ($item === '.' || $item === '..') continue;
            
            $itemPath = $categoriesDir . '/' . $item;
            if (is_dir($itemPath) && file_exists($itemPath . '/post.json')) {
                $validFolders[] = $item;
            }
        }
        closedir($handle);
    }
    
    // Tri alphabétique
    sort($validFolders);
    
    // Variables pour les messages de retour
    $messages = [];

    // Créer index.json AVEC les métadonnées des posts (format riche : folders + posts).
    // IMPORTANT : ne PAS écrire un index folders-only — sinon le front-end retombe sur
    // le legacy path (1 fetch par post = 900 requêtes → page bloquée). _rebuild_posts_index
    // génère aussi index-home.json (léger) pour un chargement instantané du home.
    if (function_exists('_rebuild_posts_index')) {
        try {
            _rebuild_posts_index(__DIR__);
            $messages[] = "index.json (riche) + index-home.json créés - " . count($validFolders) . " posts";
        } catch (Throwable $e) {
            $messages[] = "Erreur _rebuild_posts_index: " . $e->getMessage();
        }
    } else {
        $messages[] = "Erreur: _rebuild_posts_index introuvable (config.php non chargé)";
    }
    
    // === CONFIGURATION DU SITE ===
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = HOST_NAME;
    $basePage = '/base.html';
    $siteUrl = $protocol . '://' . $host . $basePage;
    $currentDate = date('Y-m-d');
    
    // === GÉNÉRATION DU SITEMAP PRINCIPAL (sitemap.xml) ===
    
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
    
    // Ajouter chaque post au sitemap principal
    foreach ($validFolders as $folder) {
        $postJsonPath = $categoriesDir . '/' . $folder . '/post.json';
        
        if (file_exists($postJsonPath)) {
            $fileModTime = filemtime($postJsonPath);
            $lastmod = date('Y-m-d', $fileModTime);
            
            $sitemapXml .= '  <url>' . PHP_EOL;
            $sitemapXml .= '    <loc>' . $siteUrl . '?page=post-detail&amp;' . htmlspecialchars($folder, ENT_XML1, 'UTF-8') . '</loc>' . PHP_EOL;
            $sitemapXml .= '    <lastmod>' . $lastmod . '</lastmod>' . PHP_EOL;
            $sitemapXml .= '    <changefreq>monthly</changefreq>' . PHP_EOL;
            $sitemapXml .= '    <priority>0.8</priority>' . PHP_EOL;
            $sitemapXml .= '  </url>' . PHP_EOL;
        }
    }
    
    $sitemapXml .= '</urlset>' . PHP_EOL;
    
    // Écrire le sitemap principal
    $sitemapPath = './sitemap.xml';
    if (file_put_contents($sitemapPath, $sitemapXml) !== false) {
        $messages[] = "sitemap.xml créé avec succès - " . (count($validFolders) + 2) . " URLs générées";
    } else {
        $messages[] = "Erreur lors de la création du sitemap.xml";
    }
    
    // === GÉNÉRATION DU SITEMAP RECETTES (sitemap-posts.xml) ===
    
    $postsSitemapXml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
    $postsSitemapXml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;
    
    foreach ($validFolders as $folder) {
        $postJsonPath = $categoriesDir . '/' . $folder . '/post.json';
        
        if (file_exists($postJsonPath)) {
            $fileModTime = filemtime($postJsonPath);
            $lastmod = date('Y-m-d', $fileModTime);
            
            $postsSitemapXml .= '  <url>' . PHP_EOL;
            $postsSitemapXml .= '    <loc>' . $siteUrl . '?page=post-detail&amp;' . htmlspecialchars($folder, ENT_XML1, 'UTF-8') . '</loc>' . PHP_EOL;
            $postsSitemapXml .= '    <lastmod>' . $lastmod . '</lastmod>' . PHP_EOL;
            $postsSitemapXml .= '    <changefreq>monthly</changefreq>' . PHP_EOL;
            $postsSitemapXml .= '    <priority>0.8</priority>' . PHP_EOL;
            $postsSitemapXml .= '  </url>' . PHP_EOL;
        }
    }
    
    $postsSitemapXml .= '</urlset>' . PHP_EOL;
    
    // Écrire le sitemap des posts
    $postsSitemapPath = $categoriesDir . '/sitemap-posts.xml';
    if (file_put_contents($postsSitemapPath, $postsSitemapXml) !== false) {
        $messages[] = "sitemap-posts.xml créé avec succès dans le dossier posts - " . count($validFolders) . " posts";
    } else {
        $messages[] = "Erreur lors de la création du sitemap-posts.xml";
    }
    
       // AJOUTER CES LIGNES AVANT echo implode :
    $rssResult = generatePinterestRSSFeed($categoriesDir);
    
    if ($rssResult['success']) {
        $messages[] = "📌 RSS Pinterest généré - " . $rssResult['postsCount'] . " posts";
        $messages[] = "📁 Fichiers: " . implode(', ', array_map('basename', $rssResult['paths']));
    } else {
        $messages[] = "⚠️ Erreur génération RSS";
    }
    
    echo implode('<br>', $messages);
    header('location: posts-generater.php?status=done');
}
    
if (isset($_GET['status']) && $_GET['status'] === 'done') {
    echo "<p style='color: green;  background: #f0f0f0; padding: 10px; position: absolute; margin: 0% 1%; font-weight: bold;'>✅ Opération terminée. Vous pouvez fermer cette fenêtre.</p>";
    sleep(3); // attendre 5 secondes
    header('location: posts-generater.php');
    exit;
}








// Générateur de posts depuis texte source avec structure riche et images WebP
// Images organisées par dossier de post individuel

$postsDir = './posts';
$categoriesDir = './categories';
$response = ['success' => false, 'message' => ''];

// Traitement AJAX pour sauvegarder l'image convertie en WebP
// Traitement AJAX pour sauvegarder l'image convertie en WebP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_webp' && !empty($_POST['imageData']) && !empty($_POST['fileName']) && !empty($_POST['postSlug'])) {
    try {
        error_log("Action save_webp reçue. Slug: " . $_POST['postSlug'] . ", FileName: " . $_POST['fileName']);
        
        $imageData = $_POST['imageData'];
        
        if (strpos($imageData, 'data:image/webp;base64,') === 0) {
            $imageData = substr($imageData, strlen('data:image/webp;base64,'));
        }
        
        $decodedImage = base64_decode($imageData);
        
        if ($decodedImage === false) {
            throw new Exception("Erreur de décodage base64");
        }
        
        $fileName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $_POST['fileName']) . '.webp';
        $postSlug = preg_replace('/[^a-zA-Z0-9_-]/', '-', $_POST['postSlug']);
        
        // UTILISER DIRECTEMENT LE SLUG RECU - PAS DE MODIFICATION
        $postDir = $postsDir . '/' . $postSlug;
        $imagesDir = $postDir . '/images';
        
        error_log("Création dossiers: " . $postDir . " et " . $imagesDir);
        
        if (!is_dir($postDir)) {
            if (!mkdir($postDir, 0755, true)) {
                throw new Exception("Impossible de créer le dossier post: " . $postDir);
            }
            error_log("Dossier post créé: " . $postDir);
        }
        
        if (!is_dir($imagesDir)) {
            if (!mkdir($imagesDir, 0755, true)) {
                throw new Exception("Impossible de créer le dossier images: " . $imagesDir);
            }
            error_log("Dossier images créé: " . $imagesDir);
        }
        
        $filePath = $imagesDir . '/' . $fileName;
        
        error_log("Tentative sauvegarde image: " . $filePath);
        
        if (file_put_contents($filePath, $decodedImage) !== false) {
            $fileSize = filesize($filePath);
            $fileSizeKB = round($fileSize / 1024, 1);
            
            error_log("Image sauvegardée avec succès: " . $filePath . " (" . $fileSizeKB . " KB)");
            
            $response['success'] = true;
            $response['message'] = "✅ Image sauvegardée : {$fileName} ({$fileSizeKB} KB)";
            $response['fileName'] = $fileName;
            $response['filePath'] = "posts/{$postSlug}/images/{$fileName}";
            $response['relativePath'] = "{$postSlug}/images/{$fileName}";
        } else {
            throw new Exception("Impossible d'écrire le fichier: " . $filePath);
        }
        
    } catch (Exception $e) {
        error_log("Erreur save_webp: " . $e->getMessage());
        $response['message'] = "❌ Erreur : " . $e->getMessage();
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Prompt pour analyser et structurer le texte source

// Fonction pour charger les catégories
function loadCategories() {
    global $categoriesDir;
    $categories = [];
    
    if (!is_dir($categoriesDir)) {
        return $categories;
    }
    
    $categoryFolders = array_filter(glob($categoriesDir . '/*'), 'is_dir');
    
    foreach ($categoryFolders as $folder) {
        $jsonFile = $folder . '/category.json';
        if (file_exists($jsonFile)) {
            $categoryData = json_decode(file_get_contents($jsonFile), true);
            if ($categoryData && isset($categoryData['id'])) {
                $categories[] = $categoryData;
            }
        }
    }
    
    return $categories;
}


// Fonction pour récupérer le premier auteur actif
function getFirstActiveAuthor() {
    $authorsFile = './authors/authors.json';
    
    if (!file_exists($authorsFile)) {
        return null;
    }
    
    $authorsData = json_decode(file_get_contents($authorsFile), true);
    
    if (!$authorsData || !is_array($authorsData)) {
        return null;
    }
    
    // Trouver le premier auteur actif
    foreach ($authorsData as $author) {
        if (isset($author['active']) && $author['active'] === true) {
            return $author['id'];
        }
    }
    
    return null;
}

// Fonction pour analyser le texte et générer une post avec OpenAI
function generatePostFromText($sourceText, $categoryHint = '') {
    $openaiApiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
    
    if (empty($openaiApiKey)) {
        return ['error' => 'Clé API OpenAI requise pour analyser le texte'];
    }
    
    // Décoder les catégories disponibles
    $categoriesArray = json_decode($categoryHint, true);
    $categoriesList = '';
    if ($categoriesArray && is_array($categoriesArray)) {
        foreach ($categoriesArray as $key => $catId) {
            $categoriesList .= "- " . $key . " (ID: " . $catId . ")\n";
        }
    }
    
    // Créer le prompt complet
    $fullPrompt = POST_PROMPT;
    $fullPrompt .=  "\n\n---\n\n";
    $fullPrompt .= "ÉTAPE SUPPLÉMENTAIRE - Sélection de catégorie:\n";
    $fullPrompt .= "Parmi ces catégories disponibles, choisis LA PLUS APPROPRIÉE:\n\n";
    $fullPrompt .= $categoriesList;
    $fullPrompt .= "\nAjoute un champ 'category_id' dans ton JSON avec l'ID exact de la catégorie choisie.\n\n";
    $fullPrompt .= "Source Text:\n{$sourceText}";
    
    $data = [
        "model" => OPENAI_CONTENT_MODEL,
        "messages" => [
            [
                "role" => "system",
                "content" => "You are a professional content analyzer. You MUST return ONLY valid JSON with no markdown, no explanations, no text before or after. The JSON must include all required fields including 'category_id'."
            ],
            [
                "role" => "user",
                "content" => $fullPrompt
            ]
        ],
        "max_tokens" => OPENAI_CONTENT_MAX_TOKENS,
        "temperature" => 0.3
    ];
    
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $openaiApiKey
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMsg = $errorData['error']['message'] ?? "Code HTTP $httpCode";
        return ['error' => "API OpenAI: $errorMsg"];
    }
    
    $responseData = json_decode($response, true);
    
    if (!$responseData || !isset($responseData['choices'][0]['message']['content'])) {
        return ['error' => 'Réponse API invalide'];
    }
    
    $postText = trim($responseData['choices'][0]['message']['content']);
    
    // Nettoyer le texte - enlever markdown et espaces
    $postText = preg_replace('/```json\s*/', '', $postText);
    $postText = preg_replace('/```\s*$/', '', $postText);
    $postText = preg_replace('/^```/', '', $postText);
    $postText = trim($postText);
    
    // Logger pour debug
    error_log("Réponse nettoyée de l'IA: " . substr($postText, 0, 500));
    
    $postData = json_decode($postText, true);
    
    if (!$postData) {
        // Essayer de trouver le JSON dans le texte
        if (preg_match('/\{[\s\S]*\}/', $postText, $matches)) {
            $postData = json_decode($matches[0], true);
        }
        
        if (!$postData) {
            return ['error' => 'JSON invalide: ' . json_last_error_msg() . '. Début du contenu: ' . substr($postText, 0, 300)];
        }
    }
    
    // Vérifier les champs essentiels
    if (!isset($postData['title'])) {
        return ['error' => 'La post générée ne contient pas de titre. Champs présents: ' . implode(', ', array_keys($postData))];
    }
    
    // AJOUTER L'ID DE L'AUTEUR ACTIF
    $firstActiveAuthor = getFirstActiveAuthor();
    if ($firstActiveAuthor) {
        $postData['author_id'] = $firstActiveAuthor;
    }
    
    return $postData;
}

// CORRECTION 2: Dans le traitement POST (autour de la ligne 800-830)
// Remplacer cette section :




// Fonctions utilitaires
function createSlug($name) {
    $slug = strtolower($name);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug;
}

function generateUniquePostSlug($name) {
    global $postsDir;
    
    $baseSlug = createSlug($name);
    $slug = $baseSlug;
    $counter = 1;
    
    // Check if folder already exists (not just JSON file)
    while (is_dir($postsDir . '/' . $slug)) {
        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }
    
    return $slug;
}

// Charger les catégories
$categories = loadCategories();
$message = '';
$messageType = '';
$generatedPost = null;

// Traitement de la génération depuis texte
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_from_text') {
    $sourceText = trim($_POST['source_text'] ?? '');
    // $categoryId = $_POST['category_id'] ?? '';
    
    if (empty($sourceText)) {
        $message = "Veuillez fournir un texte source pour analyser.";
        $messageType = 'error';
    }else {
        // Trouver le nom de la catégorie pour aider l'IA
    //     $categoryName = '';
    //     foreach ($categories as $category) {
    //         if ($category['id'] === $categoryId) {
    //             $categoryName = $category['name'];
    //             break;
    //         }
    // }
        $categoriesIndexPath = 'categories/index.json';
        $categoriesData = json_decode(file_get_contents($categoriesIndexPath), true);
        $categorysName =  json_encode($categoriesData['folders']);
        // Générer la post depuis le texte
        $postData = generatePostFromText($sourceText, $categorysName);
        
        if (isset($postData['error'])) {
            $message = "Erreur d'analyse: " . $postData['error'];
            $messageType = 'error';
        } else {
            $generatedPost = $postData;
            $categoryId = $postData['category_id'] ?? $_POST['category_id'] ?? '';
            $generatedPost['category_id'] = $categoryId;
            // ADD THIS LINE: Generate unique slug immediately
            $generatedPost['uniqueSlug'] = generateUniquePostSlug($postData['title']);
            $message = "Post analysée avec succès ! Ajoutez maintenant vos images et sauvegardez.";
            $messageType = 'success';
        }
    }
}

// Traitement de la sauvegarde finale avec images
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_final_post') {
    $postJson = $_POST['post_data'] ?? '';
    $imagesJson = $_POST['images_data'] ?? '';
    
    if (empty($postJson)) {
        $message = "Données de post manquantes.";
        $messageType = 'error';
    } else {
        $postData = json_decode($postJson, true);
        $imagesData = json_decode($imagesJson, true) ?: [];
        
        if (!$postData) {
            $message = "Format de post invalide.";
            $messageType = 'error';
        } else {
            $saveResult = saveTextBasedPostWithImages($postData, $postData['category_id'], $imagesData);
            
            if ($saveResult['success']) {
                $imageInfo = count($imagesData) > 0 ? " avec " . count($imagesData) . " image(s)" : " sans image";
                $message = "Post '{$postData['title']}' sauvegardée avec succès dans le dossier '{$saveResult['slug']}'$imageInfo !";
                $messageType = 'success';
                $generatedPost = null; // Reset après sauvegarde
            } else {
                $message = "Erreur lors de la sauvegarde: " . $saveResult['error'];
                $messageType = 'error';
            }
        }
    }
}

$openaiConfigured = defined('OPENAI_API_KEY') && !empty(OPENAI_API_KEY);

// Lister les posts existantes
$existingPosts = [];
if (is_dir($postsDir)) {
    // Lire tous les dossiers f posts directory
    $postDirs = array_filter(glob($postsDir . '/*'), 'is_dir');
    
    foreach ($postDirs as $postDir) {
        $folderName = basename($postDir);
        $jsonFile = $postDir . '/' . $folderName . '.json';
        
        if (file_exists($jsonFile)) {
            $post = json_decode(file_get_contents($jsonFile), true);
            if ($post) {
                $post['folder'] = $folderName;
                
                // Compter les images dans le sous-dossier images
                $imagesDir = $postDir . '/images';
                $post['images_count'] = is_dir($imagesDir) ? count(glob($imagesDir . '/*.webp')) : 0;
                
                $existingPosts[] = $post;
            }
        }
    }
}

?>

<?php
// Fonction pour générer automatiquement le RSS Feed Pinterest
function generatePinterestRSSFeed($postsDir = './posts') {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = HOST_NAME;
    $basePage = '/base.html';
    $siteUrl = $protocol . '://' . $host;
    
    // Configuration RSS
    $rssConfig = [
        'title' => 'Delicious Posts Feed - Pinterest',
        'description' => 'Fresh posts and cooking inspiration for Pinterest discovery',
        'link' => $siteUrl.$basePage,
        'language' => 'en-US',
        'copyright' => '© ' . date('Y') . ' Post Collection',
        'managingEditor' => '',
        'webMaster' => '',
        'category' => 'Food & Cooking',
        'generator' => 'Post RSS Generator v2.0',
        'ttl' => 1440, // 24 heures
        'maxItems' => 50
    ];
    
    // Scanner les dossiers de posts
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
    
    // Trier par date de modification (plus récentes d'abord)
    usort($validPosts, function($a, $b) {
        return $b['lastModified'] - $a['lastModified'];
    });
    
    // Limiter au nombre max
    $validPosts = array_slice($validPosts, 0, $rssConfig['maxItems']);
    
    // Construire le XML RSS
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
    
    // Image du channel
    $rssXml .= '    <image>' . PHP_EOL;
    $rssXml .= '      <url>' . $rssConfig['link'] . '/images/logo.png</url>' . PHP_EOL;
    $rssXml .= '      <title>' . htmlspecialchars($rssConfig['title'], ENT_XML1, 'UTF-8') . '</title>' . PHP_EOL;
    $rssXml .= '      <link>' . htmlspecialchars($rssConfig['link'], ENT_XML1, 'UTF-8') . '</link>' . PHP_EOL;
    $rssXml .= '      <width>144</width>' . PHP_EOL;
    $rssXml .= '      <height>144</height>' . PHP_EOL;
    $rssXml .= '    </image>' . PHP_EOL;
    
    // Ajouter chaque post comme item
    foreach ($validPosts as $post) {
        $postUrl = $siteUrl . '?page=post-detail&post=' . $post['slug'];
        $pubDate = date('r', $post['lastModified']);
        
        // Image principale
        $mainImage = '';
        if (!empty($post['image_path'])) {
            $mainImage = $protocol . '://' . $host . '/' . ltrim($post['images'][array_key_last($post['images'])]['filePath'], './');
        } elseif (!empty($post['images']) && isset($post['images'][array_key_first($post['images'])]['filePath'])) {
            $mainImage = $protocol . '://' . $host . '/' . ltrim($post['images'][array_key_first($post['images'])]['filePath'], './');
        }
        
        // Description enrichie pour Pinterest
        $description = $post['description'] ?? 'Delicious post to try';
        $promptIMG = $post['promptIMG'] ?? '';
        
        $timeInfo = [];
        if (!empty($post['prep_time'])) $timeInfo[] = "Prep: {$post['prep_time']}min";
        if (!empty($post['cook_time'])) $timeInfo[] = "Cook: {$post['cook_time']}min";
        if (!empty($post['servings'])) $timeInfo[] = "Serves: {$post['servings']}";
        
        if (!empty($timeInfo)) {
            $description .= ' | ⏱️ ' . implode(' | ', $timeInfo);
        }
        
        // Tags Pinterest
        $tags = extractPostTags($post);
        if (!empty($tags)) {
            $description .= ' | #' . implode(' #', array_slice($tags, 0, 5));
        }
        
        // Contenu HTML enrichi
        $contentHtml = buildPostContentHTML($post, $mainImage, $tags);
        
        // Item RSS
        $rssXml .= '    <item>' . PHP_EOL;
        $rssXml .= '      <title>' . htmlspecialchars($post['title'], ENT_XML1, 'UTF-8') . '</title>' . PHP_EOL;
        $rssXml .= '      <link>' . htmlspecialchars($rssConfig['link'] . '?page=post-detail&post=' . $post['slug'], ENT_XML1, 'UTF-8') . '</link>' . PHP_EOL;
        $rssXml .= '      <description>' . htmlspecialchars($description, ENT_XML1, 'UTF-8') . '</description>' . PHP_EOL;
        $rssXml .= '      <pubDate>' . $pubDate . '</pubDate>' . PHP_EOL;
        $rssXml .= '      <guid isPermaLink="true">' . htmlspecialchars($rssConfig['link'] . '?page=post-detail&post=' . $post['slug'], ENT_XML1, 'UTF-8') . '</guid>' . PHP_EOL;
        $rssXml .= '      <category>' . htmlspecialchars($post['category_id'] ?? 'posts', ENT_XML1, 'UTF-8') . '</category>' . PHP_EOL;
        $rssXml .= '      <dc:creator>' . htmlspecialchars($post['author_id'] ?? 'House Chef', ENT_XML1, 'UTF-8') . '</dc:creator>' . PHP_EOL;
        
        // Contenu enrichi
        $rssXml .= '      <content:encoded><![CDATA[' . $contentHtml . ']]></content:encoded>' . PHP_EOL;
        
        // Media content pour Pinterest
        if (!empty($mainImage)) {
            $rssXml .= '      <media:content url="' . htmlspecialchars($mainImage, ENT_XML1, 'UTF-8') . '" medium="image" type="image/webp">' . PHP_EOL;
            $rssXml .= '        <media:title>' . htmlspecialchars($post['title'], ENT_XML1, 'UTF-8') . '</media:title>' . PHP_EOL;
            $rssXml .= '        <media:description>' . htmlspecialchars($post['description'] ?? '', ENT_XML1, 'UTF-8') . '</media:description>' . PHP_EOL;
            $rssXml .= '      </media:content>' . PHP_EOL;
        }
        
        // Meta tags Pinterest
        $rssXml .= '      <media:group>' . PHP_EOL;
        $rssXml .= '        <media:category>post</media:category>' . PHP_EOL;
        $rssXml .= '        <media:keywords>' . implode(', ', $tags) . '</media:keywords>' . PHP_EOL;
        $rssXml .= '      </media:group>' . PHP_EOL;
        
        $rssXml .= '    </item>' . PHP_EOL;
    }
    
    $rssXml .= '  </channel>' . PHP_EOL;
    $rssXml .= '</rss>' . PHP_EOL;
    
    // Sauvegarder le RSS
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
        'postsCount' => count($validPosts),
        'filesCreated' => $savedCount,
        'paths' => array_filter($rssPaths, function($path) {
            return file_exists($path);
        })
    ];
}

// Fonction pour générer un RSS par catégorie
function generateCategoryRSSFeed($categoryId, $categoryName, $postsDir = './posts') {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = HOST_NAME;
    $basePage = '/base.html';
    $siteUrl = $protocol . '://' . $host;
    
    $categoriesIndexPath = 'categories/index.json';
    $categoriesData = json_decode(file_get_contents($categoriesIndexPath), true);

    // Rechercher la clé correspondante
    $categoryName = array_search($categoryId, $categoriesData['folders']);

    // Configuration RSS spécifique à la catégorie
    $rssConfig = [
        'title' => $categoryName . ' Posts - RSS Feed',
        'description' => 'Fresh ' . strtolower($categoryName) . ' posts and cooking inspiration',
        'link' => $siteUrl . $basePage,
        'language' => SITE_LANGUAGE,
        'category' => $categoryName,
        'maxItems' => 50,
        'copyright' => '© ' . date('Y') ." ". HOST_NAME,
        'managingEditor' => SITE_MANAGER,
        'webMaster' => SITE_WEBMASTER,
        'generator' => 'Posts - RSS Feed '.HOST_NAME,
        'ttl' => 60
    ];
    
    // Scanner UNIQUEMENT les posts de cette catégorie ET qui sont ONLINE
    $categoryPosts = [];
    
    if (is_dir($postsDir)) {
        $postDirs = array_filter(glob($postsDir . '/*'), 'is_dir');
        
        foreach ($postDirs as $postDir) {
            $folderName = basename($postDir);
            $postFile = $postDir . '/post.json';
            
            if (file_exists($postFile)) {
                $postData = json_decode(file_get_contents($postFile), true);
                
                // VÉRIFIER: 1) catégorie correcte, 2) isOnline = true
                if ($postData && 
                    isset($postData['title']) && 
                    isset($postData['category_id']) && 
                    $postData['category_id'] === $categoryId &&
                    isset($postData['isOnline']) &&
                    $postData['isOnline'] === true) {  // ← CONDITION AJOUTÉE ICI
                    
                    $postData['folder'] = $folderName;
                    $postData['lastModified'] = filemtime($postFile);
                    $categoryPosts[] = $postData;
                }
            }
        }
    }
    
    // Si aucune post online, retourner
    if (empty($categoryPosts)) {
        return [
            'success' => false,
            'message' => 'Aucune post en ligne trouvée pour cette catégorie',
            'postsCount' => 0
        ];
    }
    
    // Trier par date (les plus récentes en premier)
    usort($categoryPosts, function($a, $b) {
        return $b['lastModified'] - $a['lastModified'];
    });
    
    // Limiter au nombre maximum
    $categoryPosts = array_slice($categoryPosts, 0, $rssConfig['maxItems']);
    
    // Construire le XML RSS
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
    
    // Image du channel
    $rssXml .= '    <image>' . PHP_EOL;
    $rssXml .= '      <url>' . $rssConfig['link'] . '/images/logo.png</url>' . PHP_EOL;
    $rssXml .= '      <title>' . htmlspecialchars($rssConfig['title'], ENT_XML1, 'UTF-8') . '</title>' . PHP_EOL;
    $rssXml .= '      <link>' . htmlspecialchars($rssConfig['link'], ENT_XML1, 'UTF-8') . '</link>' . PHP_EOL;
    $rssXml .= '      <width>144</width>' . PHP_EOL;
    $rssXml .= '      <height>144</height>' . PHP_EOL;
    $rssXml .= '    </image>' . PHP_EOL;

    // Ajouter les posts ONLINE uniquement
    foreach ($categoryPosts as $post) {
        $postUrl = $siteUrl . '?page=post-detail&post=' . $post['slug'];
        $pubDate = date('r', $post['lastModified']);
        
        // Image principale (dernière image ou première)
        $mainImage = '';
        if (!empty($post['images'])) {
            // Essayer la dernière image d'abord
            if (isset($post['images'][array_key_last($post['images'])]['filePath'])) {
                $mainImage = $protocol . '://' . $host . '/' . ltrim($post['images'][array_key_last($post['images'])]['filePath'], './');
            } 
            // Sinon prendre la première
            elseif (isset($post['images'][array_key_first($post['images'])]['filePath'])) {
                $mainImage = $protocol . '://' . $host . '/' . ltrim($post['images'][array_key_first($post['images'])]['filePath'], './');
            }
        }
        // Fallback sur image_path si elle existe
        elseif (!empty($post['image_path'])) {
            $mainImage = $protocol . '://' . $host . '/' . ltrim($post['image_path'], './');
        }
        
        $description = $post['description'] ?? 'Delicious post';
        $tags = extractPostTags($post);
        $contentHtml = buildPostContentHTML($post, $mainImage, $tags);
        
        $rssXml .= '    <item>' . PHP_EOL;
        $rssXml .= '      <title>' . htmlspecialchars($post['title'], ENT_XML1, 'UTF-8') . '</title>' . PHP_EOL;
        $rssXml .= '      <link>' . htmlspecialchars($rssConfig['link'] . '?page=post-detail&post=' . $post['slug'], ENT_XML1, 'UTF-8') . '</link>' . PHP_EOL;
        $rssXml .= '      <description>' . htmlspecialchars($description, ENT_XML1, 'UTF-8') . '</description>' . PHP_EOL;
        $rssXml .= '      <pubDate>' . $pubDate . '</pubDate>' . PHP_EOL;
        $rssXml .= '      <guid isPermaLink="true">' . htmlspecialchars($rssConfig['link'] . '?page=post-detail&post=' . $post['slug'], ENT_XML1, 'UTF-8') . '</guid>' . PHP_EOL;
        $rssXml .= '      <category>' . htmlspecialchars($post['category_id'] ?? 'posts', ENT_XML1, 'UTF-8') . '</category>' . PHP_EOL;
        $rssXml .= '      <dc:creator>' . htmlspecialchars($post['author_id'] ?? 'House Chef', ENT_XML1, 'UTF-8') . '</dc:creator>' . PHP_EOL;
        
        // Contenu enrichi
        $rssXml .= '      <content:encoded><![CDATA[' . $contentHtml . ']]></content:encoded>' . PHP_EOL;
        
        // Media content pour Pinterest
        if (!empty($mainImage)) {
            $rssXml .= '      <media:content url="' . htmlspecialchars($mainImage, ENT_XML1, 'UTF-8') . '" medium="image" type="image/webp">' . PHP_EOL;
            $rssXml .= '        <media:title>' . htmlspecialchars($post['title'], ENT_XML1, 'UTF-8') . '</media:title>' . PHP_EOL;
            $rssXml .= '        <media:description>' . htmlspecialchars($post['description'] ?? '', ENT_XML1, 'UTF-8') . '</media:description>' . PHP_EOL;
            $rssXml .= '      </media:content>' . PHP_EOL;
        }
        
        // Meta tags Pinterest
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
        'postsCount' => count($categoryPosts),
        'categoryName' => $categoryName,
        'categoryId' => $categoryId
    ];
}

// Fonction pour générer tous les RSS par catégories
function generateAllCategoryRSSFeeds($categoriesDir = './categories', $postsDir = './posts') {
    $results = [];
    
    if (!is_dir($categoriesDir)) {
        return ['success' => false, 'message' => 'Dossier categories introuvable'];
    }
    
    // Scanner les dossiers de catégories
    $categoryFolders = array_filter(glob($categoriesDir . '/*'), 'is_dir');
    
    foreach ($categoryFolders as $categoryFolder) {
        $categoryJsonFile = $categoryFolder . '/category.json';
        
        if (file_exists($categoryJsonFile)) {
            $categoryData = json_decode(file_get_contents($categoryJsonFile), true);
            
            if ($categoryData && isset($categoryData['id']) && isset($categoryData['name'])) {
                // Générer le RSS pour cette catégorie
                $rssResult = generateCategoryRSSFeed(
                    $categoryData['id'], 
                    $categoryData['name'], 
                    $postsDir
                );
                
                if ($rssResult['success']) {
                    // Sauvegarder le RSS dans le dossier de la catégorie
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

// Fonction pour extraire les tags d'une post
function extractPostTags($post) {
    $tags = [];
    
    // Tags de catégorie
    if (!empty($post['category_id'])) {
        $tags[] = str_replace(['-', '_', ' '], '', $post['category_id']);
    }
    
    // Tags de difficulté
    if (!empty($post['difficulty'])) {
        $tags[] = $post['difficulty'] . 'post';
    }
    
    // Tags de temps
    $totalTime = ($post['total_time'] ?? 0) ?: (($post['prep_time'] ?? 0) + ($post['cook_time'] ?? 0));
    if ($totalTime <= 30) {
        $tags[] = 'quickpost';
        $tags[] = '30minutemeals';
    } elseif ($totalTime <= 60) {
        $tags[] = '1hourmeals';
    }
    
    // Tags génériques
    $tags = array_merge($tags, ['post', 'cooking', 'foodie', 'homemade', 'delicious']);
    
    // Extraire des ingrédients (3 premiers mots significatifs)
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

// Fonction pour construire le contenu HTML enrichi
function buildPostContentHTML($post, $mainImage, $tags) {
    $html = '<div class="post-content" style="font-family: Arial, sans-serif; max-width: 600px;">';
    
    // Image principale
    if (!empty($mainImage)) {
        $html .= '<img src="' . htmlspecialchars($mainImage) . '" alt="' . htmlspecialchars($post['title']) . '" style="width:100%;max-width:600px;height:auto;border-radius:8px;margin-bottom:20px;">';
    }
    
    // Meta infos
    $html .= '<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin-bottom:20px;">';
    $html .= '<p style="margin:5px 0;"><strong>⏱️ Prep Time:</strong> ' . ($post['prep_time'] ?? 'N/A') . ' minutes</p>';
    $html .= '<p style="margin:5px 0;"><strong>🍳 Cook Time:</strong> ' . ($post['cook_time'] ?? 'N/A') . ' minutes</p>';
    $html .= '<p style="margin:5px 0;"><strong>⏰ Total Time:</strong> ' . ($post['total_time'] ?? 'N/A') . ' minutes</p>';
    $html .= '<p style="margin:5px 0;"><strong>🍽️ Servings:</strong> ' . ($post['servings'] ?? 'N/A') . '</p>';
    $html .= '<p style="margin:5px 0;"><strong>📊 Difficulty:</strong> ' . ucfirst($post['difficulty'] ?? 'medium') . '</p>';
    $html .= '</div>';
    
    // Ingrédients
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
    
    // Instructions
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
    
    // Tags Pinterest
    $html .= '<div style="background:#fff3f3;padding:15px;border-radius:8px;border-left:4px solid #E60023;">';
    $html .= '<p><strong>📌 Pinterest Tags:</strong> #' . implode(' #', $tags) . '</p>';
    $html .= '</div>';
    
    // CTA
    $html .= '<div style="text-align:center;margin-top:30px;padding:20px;background:#E60023;color:white;border-radius:8px;">';
    $html .= '<p style="margin:0;"><strong>📌 Save this post to Pinterest!</strong></p>';
    $html .= '<p style="margin:5px 0 0 0;">Perfect for meal planning and sharing with friends</p>';
    $html .= '</div>';
    
    $html .= '</div>';
    
    return $html;
}

// MODIFIER LA PARTIE posts_index POUR INCLURE LA GÉNÉRATION RSS
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'posts_index') {
    // $categoriesDir = './posts';
    
    // // === GÉNÉRATION AUTOMATIQUE DU RSS PINTEREST ===
    //     $rssResult = generatePinterestRSSFeed($categoriesDir);
    
    // if ($rssResult['success']) {
    //     $messages[] = "📌 RSS Global généré - " . $rssResult['postsCount'] . " posts";
    // }
    
    // === NOUVEAU: GÉNÉRATION RSS PAR CATÉGORIE ===
    $categoryRssResults = generateAllCategoryRSSFeeds('./categories', './posts');
    
    if ($categoryRssResults['success']) {
        $messages[] = "📂 RSS par catégories générés - " . $categoryRssResults['totalCategories'] . " catégories";
        
        foreach ($categoryRssResults['categories'] as $catRss) {
            $messages[] = "  ✓ {$catRss['category']}: {$catRss['postsCount']} posts → " . basename($catRss['path']);
        }
    } else {
        $messages[] = "⚠️ Aucun RSS catégorie généré";
    }
    
    echo implode('<br>', $messages);
    header('location: posts-generater.php?status=done');
}

// MODIFIER LA FONCTION saveTextBasedPostWithImages POUR GÉNÉRER LE RSS APRÈS SAUVEGARDE
function saveTextBasedPostWithImages($postData, $categoryId, $userImages = []) {
    global $postsDir;
    
    // Utiliser le slug déjà existant
    $slug = $postData['slug'] ?? $postData['uniqueSlug'] ?? generateUniquePostSlug($postData['title']);
    
    // Créer le dossier de la post
    $postDir = $postsDir . '/' . $slug;
    if (!is_dir($postDir)) {
        if (!mkdir($postDir, 0755, true)) {
            return ['success' => false, 'error' => 'Impossible de créer le dossier: ' . $postDir];
        }
    }
    
    // Préparer la structure EXACTE comme l'example
    $post = [
        'id' => 'text_post_' . time() . '_' . rand(100, 999),
        'slug' => $slug,
        'title' => $postData['title'],
        'promptIMG' => $postData['promptIMG'] ?? '',
        'isOnline' => $postData['isOnline'] ?? true,
        'description' => $postData['description'] ?? '',
        'category_id' => $postData['category_id'] ?? $categoryId,
        'author_id' => $postData['author_id'] ?? 'author_001',
        'ingredients' => $postData['ingredients'] ?? [],
        'instructions' => $postData['instructions'] ?? [],
        'prep_time' => (int)($postData['prep_time'] ?? 0),
        'cook_time' => (int)($postData['cook_time'] ?? 0),
        'total_time' => (int)($postData['prep_time'] ?? 0) + (int)($postData['cook_time'] ?? 0),
        'servings' => (int)($postData['servings'] ?? 1),
        'difficulty' => $postData['difficulty'] ?? 'moyen',
        'tips' => $postData['tips'] ?? '',
        'structured_content' => prepareStructuredContent($postData, $slug, $userImages),
        'images' => $userImages,
        'image' => !empty($userImages) ? $userImages[0]['fileName'] : '',
        'image_path' => !empty($userImages) ? $userImages[0]['filePath'] : '',
        'image_dir' => $slug . '/images',
        'generated_from_text' => true,
        'has_rich_structure' => true,
        'createdAt' => date('Y-m-d\TH:i:sP'),
        'updatedAt' => date('Y-m-d\TH:i:sP')
    ];
    
    // Sauvegarder le fichier post.json
    $postFile = $postDir . '/post.json';
    $jsonContent = json_encode($post, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    if (file_put_contents($postFile, $jsonContent) === false) {
        return ['success' => false, 'error' => 'Impossible d\'écrire le fichier JSON'];
    }
    
    // Générer le RSS
    $rssResult = generatePinterestRSSFeed($postsDir);
    
    return [
        'success' => true,
        'post' => $post,
        'slug' => $slug,
        'file_path' => $postFile,
        'rss_generated' => $rssResult['success'] ?? false
    ];
}

// Fonction helper bach nprépariw structured_content avec les images
function prepareStructuredContent($postData, $slug, $userImages) {
    $content = $postData['structured_content'] ?? [];
    
    // Mettre à jour les upload sections avec les vraies images
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
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Générateur de Posts depuis Texte + Images WebP</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

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
            position: relative;
        }
        .header-user {
            position: absolute; top: 12px; right: 15px;
            display: flex; align-items: center; gap: 8px;
        }
        .header-user .role-badge {
            font-size: .78rem; background: rgba(255,255,255,.15); color: #fff;
            padding: 3px 10px; border-radius: 20px; border: 1px solid rgba(255,255,255,.3);
        }
        .header-user a { font-size: .78rem; color: #fca5a5; text-decoration: none; }
        .header-user a:hover { color: #fff; }

        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .header p {
            opacity: 0.9;
            font-size: 1.1rem;
        }

        .main-content {
            padding: 40px;
        }

        .tabs {
            display: flex;
            margin-bottom: 30px;
            border-bottom: 2px solid #eee;
            flex-wrap: wrap;
        }

        .tab {
            padding: 15px 25px;
            background: #f8f9fa;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            border-radius: 10px 10px 0 0;
            margin-right: 5px;
            margin-bottom: 5px;
            transition: all 0.3s ease;
        }

        .tab.active {
            background: linear-gradient(45deg, #4ecdc4, #44a08d);
            color: white;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .api-status {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            text-align: center;
        }

        .status-active {
            color: #28a745;
            font-weight: bold;
        }

        .status-inactive {
            color: #dc3545;
            font-weight: bold;
        }

        .form-section {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
        }

        .form-section h3 {
            color: #333;
            margin-bottom: 20px;
            font-size: 1.3rem;
        }

        .form-group {
            margin-bottom: 20px;
        }

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
            transition: border-color 0.3s ease;
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

        .status-processing {
            background: #d1ecf1;
            color: #0c5460;
        }

        .status-success {
            background: #d4edda;
            color: #155724;
        }

        .status-error {
            background: #f8d7da;
            color: #721c24;
        }

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
            white-space: pre-line;
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

        .post-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .post-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            border: 1px solid #eee;
            transition: transform 0.3s ease;
        }

        .post-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }

        .no-content {
            text-align: center;
            color: #999;
            font-style: italic;
            padding: 40px;
        }

        @media (max-width: 768px) {
            .images-section {
                grid-template-columns: 1fr;
            }
            
            .tabs {
                flex-direction: column;
            }

            .tab {
                margin-right: 0;
            }
        }


        /* Ajouter dans la section <style> */
        input[type="file"].form-control {
            padding: 8px;
            border: 2px dashed #e0e0e0;
            background: #f8f9fa;
        }

        input[type="file"].form-control:focus {
            border-color: #ff6b6b;
            background: white;
        }

    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-user">
                <span class="role-badge">👤 <?= htmlspecialchars($_SESSION['role'] ?? '') ?></span>
                <a href="login.php?logout=1">🚪 Déconnexion</a>
            </div>
            <h1>Générateur depuis Texte + Images WebP</h1>
            <p>Analysez le texte avec l'IA, ajoutez vos 3 images, tout va dans un dossier dédié</p>
        </div>

        <div class="main-content">
            <!-- Onglets -->
            <div class="tabs">
                <button class="tab active" onclick="switchTab('generate')">📝 Générer</button>
            </div>

            <!-- Onglet Génération -->
            <div id="generate" class="tab-content active">
                <?php if ($message): ?>
                    <div class="alert alert-<?= $messageType ?>">
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>

                <!-- Statut API -->
                <div class="api-status">
                    <div>OpenAI GPT-4 (Analyse texte)</div>
                    <div class="<?= $openaiConfigured ? 'status-active' : 'status-inactive' ?>">
                        <?= $openaiConfigured ? '✅ Configurée' : '❌ Requise dans config.php' ?>
                    </div>
                </div>

                <?php if ($openaiConfigured): ?>
                    <?php if (!$generatedPost): ?>
                        <!-- Formulaire de génération -->
                        <div class="form-section">
                            <h3>Analyser et convertir un texte</h3>
                            
                            <form method="post" id="textForm">
                                <input type="hidden" name="action" value="generate_from_text">
                                
                                <div class="form-group">
                                    <label for="source_text">Texte source de la post *</label>
                                    <textarea name="source_text" id="source_text" class="form-control" required
                                              placeholder="Collez ici le texte d'une post (site web, livre, blog...). L'IA analysera automatiquement les ingrédients, instructions et créera une structure riche."></textarea>
                                </div>
                                
                                <div class="form-group">
                                    <label for="category_id">Catégorie *</label>
                                    <select name="category_id" id="category_id" class="form-control" >
                                        <option value="">Sélectionner une catégorie</option>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?= $category['id'] ?>">
                                                <?= htmlspecialchars($category['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div style="text-align: center; margin-top: 30px;">
                                    <button type="submit" class="btn btn-primary">
                                        🔍 Analyser le texte avec l'IA
                                    </button>
                                </div>
                            </form>
                        </div>
                    <?php else: ?>
                        <!-- Post générée + Images -->
                        <div class="post-preview">
                            <h2>Prompt Image : </h2>
                            <p style="text-align: center; color: #E60023; margin-bottom: 10px;"><?= htmlspecialchars($generatedPost['promptIMG']) ?></p>
                            <h3 id="post-title"><?= htmlspecialchars($generatedPost['title']) ?></h3>
                            <p style="color: #666; margin-bottom: 20px;"><?= htmlspecialchars($generatedPost['description']) ?></p>
                            
                            <div class="post-meta">
                                <div><strong>⏱️ Préparation:</strong> <?= $generatedPost['prep_time'] ?> min</div>
                                <div><strong>🔥 Cuisson:</strong> <?= $generatedPost['cook_time'] ?> min</div>
                                <div><strong>👥 Portions:</strong> <?= $generatedPost['servings'] ?></div>
                                <div><strong>📊 Difficulté:</strong> <?= ucfirst($generatedPost['difficulty']) ?></div>
                            </div>
                        </div>

                        <!-- Section des 3 images -->
                        <div class="form-section">
                            <h3>📸 Ajouter 3 images à votre post</h3>
                            <div class="images-section">
                                <div class="image-box" id="imageBox1">
                                    <div class="form-group">
                                        <label for="imageUrl1">🖼️ Image 1 - Principale :</label>
                                        <input type="url" id="imageUrl1" class="form-control" value="<?= BASE_URL. "tmpIMG/image_1.webp" ?>" placeholder="https://example.com/image1.jpg">
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
                                        <input type="url" id="imageUrl2" class="form-control" value="<?= BASE_URL. "tmpIMG/image_2.webp" ?>" placeholder="https://example.com/image2.jpg">
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
                                        <input type="url" id="imageUrl3" class="form-control" value="<?= BASE_URL. "tmpIMG/image_3.webp" ?>" placeholder="https://example.com/image3.jpg">
                                    </div>
                                    <button type="button" id="btn-process3" class="btn btn-process" onclick="processImage(3)">📥 Traiter Image 3</button>
                                    <div class="image-preview" id="preview3">
                                        <canvas id="canvas3"></canvas>
                                    </div>
                                    <div class="image-status" id="status3"></div>
                                </div>

                                <!-- <div class="image-box" id="imageBox4">
                                    <div class="form-group">
                                        <label for="imageFile4">🖼️ Image 4 - Upload Local :</label>
                                        <input type="file" id="imageFile4" class="form-control" accept="image/*" onchange="handleFileUpload(4)">
                                    </div>
                                    <button type="button" class="btn btn-process" onclick="processImage(4)" disabled id="processBtn4">📥 Traiter Image 4</button>
                                    <div class="image-preview" id="preview4">
                                        <canvas id="canvas4"></canvas>
                                    </div>
                                    <div class="image-status" id="status4"></div>
                                </div>         -->
                            </div>

                            <div style="text-align: center; margin-top: 30px;">
                                <button id="savePost" type="button" class="btn btn-success" onclick="saveCompletePost()">
                                    💾 Sauvegarder la Post Complète
                                </button>
                                <button type="button" class="btn btn-primary" onclick="resetGeneration()">
                                    🔄 Nouvelle Post
                                </button>
                            </div>
                        </div>
                   
                    <?php endif; ?>
                <?php else: ?>
                    <div class="alert alert-error">
                        ⚠️ Clé API OpenAI requise. Ajoutez dans config.php :
                        <br><br>
                        define('OPENAI_API_KEY', 'sk-votre-clé-openai');
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <script>

        const baseURL = window.location.href.split("posts-generater.php");  
            console.log("Base URL:", baseURL[0]);
        // État global
        let generatedPostData = <?= $generatedPost ? json_encode($generatedPost) : 'null' ?>;
        const imageStates = {
            1: { processed: false, fileName: null, filePath: null, originalUrl: null },
            2: { processed: false, fileName: null, filePath: null, originalUrl: null },
            3: { processed: false, fileName: null, filePath: null, originalUrl: null }
        };

        // Gestion des onglets
        function switchTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
        }

        function setImageStatus(imageNumber, message, type) {
            const statusEl = document.getElementById(`status${imageNumber}`);
            statusEl.textContent = message;
            statusEl.className = `image-status status-${type}`;
        }

        function generateSlug(title) {
            return title
                .toLowerCase()
                .trim()
                .replace(/[àáâäãåā]/g, 'a')
                .replace(/[èéêëē]/g, 'e')
                .replace(/[ìíîïī]/g, 'i')
                .replace(/[òóôöõøō]/g, 'o')
                .replace(/[ùúûüū]/g, 'u')
                .replace(/[ýÿ]/g, 'y')
                .replace(/[ñ]/g, 'n')
                .replace(/[ç]/g, 'c')
                .replace(/[^a-z0-9 -]/g, '')
                .replace(/\s+/g, '-')
                .replace(/-+/g, '-')
                .replace(/^-|-$/g, '');
        }


        // Ajouter cette variable globale
        let uploadedFiles = {
            4: null
        };

        // Fonction pour gérer l'upload de fichier
        function handleFileUpload(imageNumber) {
            const fileInput = document.getElementById(`imageFile${imageNumber}`);
            const file = fileInput.files[0];
            const processBtn = document.getElementById(`processBtn${imageNumber}`);
            
            if (file) {
                // Vérifier le type de fichier
                if (!file.type.startsWith('image/')) {
                    setImageStatus(imageNumber, '❌ Veuillez sélectionner un fichier image', 'error');
                    processBtn.disabled = true;
                    return;
                }
                
                // Vérifier la taille (max 10MB)
                if (file.size > 10 * 1024 * 1024) {
                    setImageStatus(imageNumber, '❌ Fichier trop volumineux (max 10MB)', 'error');
                    processBtn.disabled = true;
                    return;
                }
                
                uploadedFiles[imageNumber] = file;
                processBtn.disabled = false;
                setImageStatus(imageNumber, `✅ Fichier sélectionné: ${file.name} (${Math.round(file.size/1024)}KB)`, 'success');
            } else {
                uploadedFiles[imageNumber] = null;
                processBtn.disabled = true;
                setImageStatus(imageNumber, '', '');
            }
        }

        // Fonction pour charger l'image depuis un fichier
        function loadImageFromFile(file) {
            return new Promise((resolve, reject) => {
                const img = new Image();
                const url = URL.createObjectURL(file);
                
                img.onload = function() {
                    URL.revokeObjectURL(url); // Nettoyer l'URL temporaire
                    resolve(img);
                };
                
                img.onerror = function() {
                    URL.revokeObjectURL(url);
                    reject(new Error('Impossible de charger le fichier image'));
                };
                
                img.src = url;
            });
        }   

        async function processImage(imageNumber) {
            console.log('Processing image:', imageNumber);
            if (!generatedPostData) {
                alert('Veuillez d\'abord générer une post avec l\'IA');
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
                const postSlug = generatedPostData.uniqueSlug;
                const fileName = `${postSlug}_image_${imageNumber}`;
                
                const result = await saveImageToServer(webpData, fileName, postSlug);
                
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


        async function saveCompletePost() {
    if (!generatedPostData) {
        alert('Aucune post générée');
        return;
    }

    document.getElementById('savePost').disabled = true;
    document.getElementById('savePost').textContent = '💾 Sauvegarde en cours...';

    if (window.parent && window.parent !== window) {
        window.parent.postMessage({
            type: 'post_status',
            status: 'saving',
            message: 'Sauvegarde en cours...'
        }, '*');
    }

    const uniqueSlug = generatedPostData.uniqueSlug;
    
    if (!uniqueSlug) {
        alert('Erreur: slug unique manquant');
        
        if (window.parent && window.parent !== window) {
            window.parent.postMessage({
                type: 'post_status',
                status: 'error',
                message: 'Erreur: slug unique manquant'
            }, '*');
        }
        return;
    }

    const processedImages = Object.values(imageStates)
        .filter(state => state.processed)
        .map((state, index) => ({
            fileName: state.fileName,
            filePath: state.filePath,
            relativePath: state.relativePath,
            originalUrl: state.originalUrl,
            order: index + 1,
            type: index === 0 ? 'main' : (index === 1 ? 'process' : 'final')
        }));

    const postDataWithSlug = {
        ...generatedPostData,
        slug: uniqueSlug
    };

    try {            
        const formData = new FormData();
        const formTemplate = new FormData();
        
        const image1 = processedImages[0].filePath;
        const image2 = processedImages[1].filePath;  
        
        formTemplate.append('image1', baseURL[0] + image1);
        formTemplate.append('image2', baseURL[0] + image2);  
        formTemplate.append('title', generatedPostData.title);
        formTemplate.append('uniqueSlug', generatedPostData.uniqueSlug); 
        
        const urlPinterest = baseURL[0] + 'generate_template.php';                                              
        
        const responseImg = await fetch(urlPinterest, {
            method: 'POST',
            body: formTemplate
        });

        const resultImg = await responseImg.json();
        console.log('✅ Template généré:', resultImg);

        processedImages.push({
            fileName: resultImg.filename,
            filePath: resultImg.path,
            relativePath: resultImg.pathrelative,
            originalUrl: resultImg.url,
            order: processedImages.length + 1,
            type: 'template'
        });

        formData.append('action', 'save_final_post');
        formData.append('post_data', JSON.stringify(postDataWithSlug));
        formData.append('images_data', JSON.stringify(processedImages));

        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.text();
        
        console.log('✅ Post sauvegardée avec succès');
        
        const isInIframe = window.parent && window.parent !== window;
        
        if (isInIframe) {
            // ✅ NOTIFIER LE PARENT QUE LA SAUVEGARDE EST COMPLÈTE
            console.log('📨 Envoi message completed au parent');
            
            window.parent.postMessage({
                type: 'post_status',
                status: 'completed',
                message: 'Sauvegarde terminée, génération index...',
                slug: uniqueSlug
            }, '*');
            
            document.getElementById('savePost').textContent = '✅ Sauvegardé! Index en cours...';
            
            // ✅ REDIRIGER VERS posts_index
            // Le parent attendra le message "index_completed" avant de continuer
            setTimeout(() => {
                console.log('🔄 Redirection vers posts_index');
                location.href = "posts-generater.php?action=posts_index";
            }, 1000);
            
        } else {
            // PAGE NORMALE
            setTimeout(() => {
                location.href = "posts-generater.php?action=posts_index";
            }, 2000);
        }
        
    } catch (error) {
        console.error('Erreur:', error);
        
        if (window.parent && window.parent !== window) {
            window.parent.postMessage({
                type: 'post_status',
                status: 'error',
                message: 'Erreur sauvegarde: ' + error.message
            }, '*');
        }
        
        alert('Erreur lors de la sauvegarde: ' + error.message);
        document.getElementById('savePost').disabled = false;
        document.getElementById('savePost').textContent = '💾 Sauvegarder la Post Complète';
    }
}

        function resetGeneration() {
            if (confirm('Voulez-vous recommencer avec une nouvelle post ?')) {
                location.href="posts-generater.php";
            }
        }

        function loadImageFromUrl(url) {
            return new Promise((resolve, reject) => {
                const img = new Image();
                img.crossOrigin = 'anonymous';
                
                img.onload = function() {
                    resolve(img);
                };
                
                img.onerror = function() {
                    reject(new Error('Impossible de charger l\'image depuis l\'URL'));
                };
                
                img.src = url;
            });
        }

        function convertToWebP(img, imageNumber) {
            return new Promise((resolve, reject) => {
                const canvas = document.getElementById(`canvas${imageNumber}`);
                const ctx = canvas.getContext('2d');
                
                canvas.width = img.width;
                canvas.height = img.height;
                
                ctx.drawImage(img, 0, 0);
                
                document.getElementById(`preview${imageNumber}`).style.display = 'block';
                
                canvas.toBlob(function(blob) {
                    if (!blob) {
                        reject(new Error('Erreur lors de la conversion WebP'));
                        return;
                    }
                    
                    const reader = new FileReader();
                    reader.onload = function() {
                        resolve(reader.result);
                    };
                    reader.onerror = function() {
                        reject(new Error('Erreur lors de la lecture du blob'));
                    };
                    reader.readAsDataURL(blob);
                    
                }, 'image/webp', 0.9);
            });
        }

        async function saveImageToServer(webpData, fileName, postSlug) {
            try {
                const formData = new FormData();
                formData.append('action', 'save_webp');
                formData.append('imageData', webpData);
                formData.append('fileName', fileName);
                formData.append('postSlug', postSlug); // Utiliser le slug exact
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    return result; // Utiliser les valeurs retournées par PHP
                } else {
                    throw new Error(result.message);
                }
                
            } catch (error) {
                throw new Error('Erreur serveur: ' + error.message);
            }
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
            delayBetweenImages: 1000,
            delayBeforeSave: 8000
        };
        
        if (sourceText && categorySelect) {
            // ✅ FORMULAIRE MODE
            console.log('✅ Mode formulaire actif');
            
        } else {
            // ✅ RECETTE GÉNÉRÉE MODE
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

            fetch('genimg.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.text();
            })
            .then(response => {
                console.log('✅ Réponse reçue de genimg.php:', response);
                
                setTimeout(() => {
                    console.log(`🚀 Lancement du traitement automatique de ${buttons.length} images`);
                    
                    // Process chaque image
                    buttons.forEach((button, index) => {
                        setTimeout(() => {
                            button.click();
                            console.log(`✅ Image ${index + 1}/${buttons.length} - Traitement lancé`);
                        }, index * AUTO_CLICK_CONFIG.delayBetweenImages);
                    });
                    
                    // Auto-save après traitement
                    if (AUTO_CLICK_CONFIG.enableAutoSave) {
                        setTimeout(() => {
                            const saveButton = document.getElementById('savePost');
                            if (saveButton && !saveButton.disabled) {
                                console.log('💾 Sauvegarde automatique lancée');
                                saveButton.click();
                                // ✅ La notification "completed" sera envoyée par saveCompletePost()
                            } else {
                                console.log('⚠️ Bouton save non disponible ou désactivé');
                            }
                        }, AUTO_CLICK_CONFIG.delayBeforeSave);
                    }
                    
                }, 500);
            })
            .catch(error => {
                console.error('❌ Erreur AJAX:', error);
            });
        }
        
    } catch (error) {
        console.error('❌ Erreur:', error);
    }
});
</script>

</body>
</html>
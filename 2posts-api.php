<?php
require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}



// Fonctions utilitaires
function getFirstActiveAuthor() {
    $authorsFile = './authors/authors.json';
    
    if (!file_exists($authorsFile)) {
        return null;
    }
    
    $authorsData = json_decode(file_get_contents($authorsFile), true);
    
    if (!$authorsData || !is_array($authorsData)) {
        return null;
    }
    
    foreach ($authorsData as $author) {
        if (isset($author['active']) && $author['active'] === true) {
            return $author['id'];
        }
    }
    
    return null;
}

function generatePostFromText($sourceText, $categoryHint = '') {
    $openaiApiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
    
    if (empty($openaiApiKey)) {
        return ['error' => 'Clé API OpenAI requise'];
    }
    
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
    $postText = preg_replace('/```json\s*/', '', $postText);
    $postText = preg_replace('/```\s*$/', '', $postText);
    $postText = preg_replace('/^```/', '', $postText);
    $postText = trim($postText);
    
    $postData = json_decode($postText, true);
    
    if (!$postData) {
        if (preg_match('/\{[\s\S]*\}/', $postText, $matches)) {
            $postData = json_decode($matches[0], true);
        }
        
        if (!$postData) {
            return ['error' => 'JSON invalide: ' . json_last_error_msg()];
        }
    }
    
    if (!isset($postData['title'])) {
        return ['error' => 'Titre manquant dans le post généré'];
    }
    
    $firstActiveAuthor = getFirstActiveAuthor();
    if ($firstActiveAuthor) {
        $postData['author_id'] = $firstActiveAuthor;
    }
    
    return $postData;
}

function rewritePostFromText($sourceText, $categoryHint = '') {
    $openaiApiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
    
    if (empty($openaiApiKey)) {
        return ['error' => 'Clé API OpenAI requise'];
    }
    
    $categoriesArray = json_decode($categoryHint, true);
    $categoriesList = '';
    if ($categoriesArray && is_array($categoriesArray)) {
        foreach ($categoriesArray as $key => $catId) {
            $categoriesList .= "- " . $key . " (ID: " . $catId . ")\n";
        }
    }
    
    // Créer le prompt complet
    $fullPrompt = REWRITE_POST_PROMPT;
    $fullPrompt .= "\n\n---\n\n";
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
    $postText = preg_replace('/```json\s*/', '', $postText);
    $postText = preg_replace('/```\s*$/', '', $postText);
    $postText = preg_replace('/^```/', '', $postText);
    $postText = trim($postText);
    
    $postData = json_decode($postText, true);
    
    if (!$postData) {
        if (preg_match('/\{[\s\S]*\}/', $postText, $matches)) {
            $postData = json_decode($matches[0], true);
        }
        
        if (!$postData) {
            return ['error' => 'JSON invalide: ' . json_last_error_msg()];
        }
    }
    
    if (!isset($postData['title'])) {
        return ['error' => 'Titre manquant dans le post généré'];
    }
    
    return $postData;
}
function createImageVariations($originalImagePath, $postName, $slug) {
    if (!file_exists($originalImagePath)) {
        echo "❌ Image not found: {$originalImagePath}\n";
        return [];
    }
    
    // Vérifier que c'est un WebP
    $imageType = exif_imagetype($originalImagePath);
    if ($imageType !== IMAGETYPE_WEBP) {
        echo "⚠️  Not a WebP image: {$originalImagePath}\n";
        return [$originalImagePath]; // Return original
    }
    
    // Load image
    $img = imagecreatefromwebp($originalImagePath);
    if (!$img) {
        echo "❌ Failed to load image: {$originalImagePath}\n";
        return [];
    }
    
    $width = imagesx($img);
    $height = imagesy($img);
    $var2 = imagecreatetruecolor($width, $height);

    $zoomFactor = ZOOM; 
    $cropWidth = (int)($width / $zoomFactor);
    $cropHeight = (int)($height / $zoomFactor);

    // Center crop
    $cropX = (int)(($width - $cropWidth) / 2);
    $cropY = (int)(($height - $cropHeight) / 2);

    // Copy & resize with zoom effect
    imagecopyresampled(
        $var2,              // destination
        $img,               // source
        0, 0,               // destination x, y
        $cropX, $cropY,     // source x, y (center)
        $width, $height,    // destination width, height
        $cropWidth, $cropHeight  // source width, height (smaller = zoom)
    );

    // Apply filters
    imagefilter($var2, IMG_FILTER_CONTRAST, -5);
    imagefilter($var2, IMG_FILTER_COLORIZE, 10, 5, -5);
    
    // Save over original
    $success = imagewebp($var2, $originalImagePath, 85);
    
    imagedestroy($var2);
    imagedestroy($img);
    
    if ($success) {
        echo "✅ Image processed: {$originalImagePath}\n";
        return [$originalImagePath];
    } else {
        echo "❌ Failed to save image: {$originalImagePath}\n";
        return [];
    }
}

function createImageVariationsFLIP($originalImagePath, $postName, $slug) {
    if (!file_exists($originalImagePath)) {
        echo "❌ Image not found: {$originalImagePath}\n";
        return [];
    }
    
    // Vérifier que c'est un WebP
    $imageType = exif_imagetype($originalImagePath);
    if ($imageType !== IMAGETYPE_WEBP) {
        echo "⚠️  Not a WebP image: {$originalImagePath}\n";
        return [$originalImagePath]; // Return original
    }
    
    // Load image
    $img = imagecreatefromwebp($originalImagePath);
    if (!$img) {
        echo "❌ Failed to load image: {$originalImagePath}\n";
        return [];
    }
    
    $width = imagesx($img);
    $height = imagesy($img);
    $var2 = imagecreatetruecolor($width, $height);

    $zoomFactor = 0.1; 
    $cropWidth = (int)($width / $zoomFactor);
    $cropHeight = (int)($height / $zoomFactor);

    // Center crop
    $cropX = (int)(($width - $cropWidth) / 2);
    $cropY = (int)(($height - $cropHeight) / 2);

    // Copy & resize with zoom effect
    imagecopyresampled(
        $var2,              // destination
        $img,               // source
        0, 0,               // destination x, y
        $cropX, $cropY,     // source x, y (center)
        $width, $height,    // destination width, height
        $cropWidth, $cropHeight  // source width, height (smaller = zoom)
    );

    // ✨ FLIP HORIZONTAL (mirror effect)
    imageflip($var2, IMG_FLIP_HORIZONTAL);

    // Apply filters
    imagefilter($var2, IMG_FILTER_CONTRAST, -5);
    imagefilter($var2, IMG_FILTER_COLORIZE, 10, 5, -5);
    
    // Save over original
    $success = imagewebp($var2, $originalImagePath, 100);
    
    imagedestroy($var2);
    imagedestroy($img);
    
    if ($success) {
        echo "✅ Image processed (flipped): {$originalImagePath}\n";
        return [$originalImagePath];
    } else {
        echo "❌ Failed to save image: {$originalImagePath}\n";
        return [];
    }
}

function mapUploadsToImages($structuredContent, $imagesData) {
    $uploadIndex = 0;
    $availableImages = array_filter($imagesData, function($img) {
        return isset($img['type']) && in_array($img['type'], ['main', 'process', 'final','template']);
    });
    
    foreach ($structuredContent as &$item) {
        if (isset($item['upload']) && $uploadIndex < count($availableImages)) {
            $imageData = array_values($availableImages)[$uploadIndex];
            $item['upload']['url'] = $imageData['relativePath'];
            $item['upload']['fileName'] = $imageData['fileName'];
            $item['upload']['type'] = $imageData['type'];
            $uploadIndex++;
        }
    }
    
    return $structuredContent;
}

function loadCategories() {
    $categoriesDir = './categories';
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

// ====== ENDPOINTS API (JSON RESPONSE ONLY) ======


function getPost($postPath) {
    if (!file_exists($postPath)) {
        return null;
    }
    return json_decode(file_get_contents($postPath), true);
}




// 1. GET /api?action=categories
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'categories') {
    $categories = loadCategories();
    
    echo json_encode([
        'success' => true,
        'data' => $categories,
        'count' => count($categories)
    ]);
    exit;
}

// 2. POST /api?action=analyze
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'analyze') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['source_text']) || empty(trim($input['source_text']))) {
        echo json_encode([
            'success' => false,
            'error' => 'Le champ source_text est requis'
        ]);
        exit;
    }
    
    $categoriesIndexPath = 'categories/index.json';
    if (file_exists($categoriesIndexPath)) {
        $categoriesData = json_decode(file_get_contents($categoriesIndexPath), true);
        $categoriesName = json_encode($categoriesData['folders']);
    } else {
        $categoriesName = '{}';
    }
    
    $postData = generatePostFromText($input['source_text'], $categoriesName);
    
    if (isset($postData['error'])) {
        echo json_encode([
            'success' => false,
            'error' => $postData['error']
        ]);
        exit;
    }
    
    // Retourner JUSTE les données, pas de sauvegarde
    echo json_encode([
        'success' => true,
        'data' => $postData
    ]);
    exit;
}


if (isset($_GET['action']) && $_GET['action'] === 'rewrite') {
    header('Content-Type: application/json; charset=utf-8');
    
    $slug = $_GET['slug'] ?? '';
    
    if (empty($slug)) {
        echo json_encode([
            'success' => false,
            'error' => 'Slug requis'
        ]);
        exit;
    }
    
    // Paths corrects
    $postDir = __DIR__ . '/posts/' . $slug;
    $imagesDir = $postDir . '/images';
    $postPath = $postDir . '/post.json';
    
    // Vérifications
    if (!file_exists($postDir)) {
        echo json_encode([
            'success' => false,
            'error' => "Dossier post introuvable: {$postDir}"
        ]);
        exit;
    }
    
    if (!file_exists($postPath)) {
        echo json_encode([
            'success' => false,
            'error' => "Fichier post.json introuvable: {$postPath}"
        ]);
        exit;
    }
    
    if (!file_exists($imagesDir)) {
        echo json_encode([
            'success' => false,
            'error' => "Dossier images introuvable: {$imagesDir}"
        ]);
        exit;
    }
    
    echo "📂 Post directory: {$postDir}\n";
    echo "🖼️  Images directory: {$imagesDir}\n\n";
    
    // Charger le post existante
    $post = getPost($postPath);
    if (!$post) {
        echo json_encode([
            'success' => false,
            'error' => 'Erreur lecture post.json'
        ]);
        exit;
    }
    
    echo "📖 Post loaded: " . ($post['title'] ?? 'Sans titre') . "\n\n";
    
    // Préparer les catégories
    $categoriesIndexPath = __DIR__ . '/categories/index.json';
    if (file_exists($categoriesIndexPath)) {
        $categoriesData = json_decode(file_get_contents($categoriesIndexPath), true);
        $categoriesName = json_encode($categoriesData['folders'] ?? []);
    } else {
        $categoriesName = '{}';
    }
    
    // Process images
    echo "🖼️  Processing images...\n";
    $originalImages = $post['images'] ?? [];
    $updatedImages = [];
    $processedCount = 0;
    
    if (is_array($originalImages) && count($originalImages) > 0) {
        foreach ($originalImages as $index => $imageData) {
            if ($index < 3) { // Limit to 3 images
                if (isset($imageData["filePath"]) && !empty($imageData["filePath"])) {
                    // Construire le path absolu
                    $imagePath = $imageData["filePath"];
                    
                    // Si le path commence par /, enlève-le
                    if (strpos($imagePath, '/') === 0) {
                        $imagePath = substr($imagePath, 1);
                    }
                    
                    // Path absolu
                    $fullImagePath = __DIR__ . '/' . $imagePath;
                    
                    // Normaliser les slashes
                    $fullImagePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fullImagePath);
                    
                    echo "\n--- Image " . ($index + 1) . " ---\n";
                    echo "Original path: " . $imageData["filePath"] . "\n";
                    echo "Full path: {$fullImagePath}\n";
                    
                    if (file_exists($fullImagePath)) {
                        // Créer variation (zoom + filters)
                        if(FLIP==true){
                            $variations = createImageVariationsFLIP($fullImagePath, $slug . "_img" . $index, $slug);    
                        }else{
                            $variations = createImageVariations($fullImagePath, $slug . "_img" . $index, $slug);
                        }
                        
                        if (!empty($variations)) {
                            // Le path reste le même (on écrase l'original)
                            echo "✅ Image processed successfully\n";
                            $processedCount++;
                        }
                    } else {
                        echo "⚠️  Image not found, keeping original path\n";
                    }
                }
                
                // Garder l'image dans tous les cas
                $updatedImages[] = $imageData;
            }
        }
    }
    
    echo "\n📊 Images processed: {$processedCount}/" . count($updatedImages) . "\n\n";
    
    // Rewrite avec OpenAI
    echo "🤖 Calling OpenAI API...\n";
    $sourceText = $post['description'] ?? $post['content'] ?? '';
    
    if (empty($sourceText)) {
        echo json_encode([
            'success' => false,
            'error' => 'Pas de contenu à rewriter (description ou content manquant)'
        ]);
        exit;
    }

  

    $newPostData = rewritePostFromText($sourceText, $categoriesName);
    
    if (isset($newPostData['error'])) {
        echo json_encode([
            'success' => false,
            'error' => $newPostData['error']
        ]);
        exit;
    }
    
    echo "✅ Post rewritten by AI\n\n";
    
    // ✅ MAPPER LES UPLOADS AVEC LES VRAIES IMAGES
    if (isset($newPostData['structured_content']) && is_array($newPostData['structured_content'])) {
        echo "🔗 Mapping upload objects to actual images...\n";
        $newPostData['structured_content'] = mapUploadsToImages(
            $newPostData['structured_content'], 
            $updatedImages
        );
        echo "✅ Upload objects mapped successfully\n\n";




    }
    
    // Garder les infos importantes
    $newPostData['images'] = $updatedImages;
    $newPostData['slug'] = $slug;
    
    // Garder ou créer ID
    if (isset($post['id'])) {
        $newPostData['id'] = $post['id'];
    } else {
        $newPostData['id'] = 'text_post_' . time() . '_' . rand(100, 999);
    }
    
    // Garder author_id
    if (isset($post['author_id'])) {
        $newPostData['author_id'] = $post['author_id'];
    } else {
        $newPostData['author_id'] = 'author_001';
    }
    
    // Image principale
    if (!empty($updatedImages)) {
        $newPostData['image'] = $updatedImages[0]['fileName'];
        $newPostData['image_path'] = "posts/".$updatedImages[0]['relativePath'];
        $newPostData['image_dir'] = $slug . '/images';
    }
    
    // Flags
    $newPostData['generated_from_text'] = true;
    $newPostData['has_rich_structure'] = true;
    
    // Garder created_at si existe
    if (isset($post['createdAt'])) {
        $newPostData['createdAt'] = $post['createdAt'];
    } else {
        $newPostData['createdAt'] = date('Y-m-d\TH:i:sP');
    }
    
    // Updated date
    $newPostData['updatedAt'] = date('Y-m-d\TH:i:sP');
    
    // Save JSON
    $jsonOptions = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $jsonContent = json_encode($newPostData, $jsonOptions);
    
    if ($jsonContent === false) {
        echo json_encode([
            'success' => false,
            'error' => 'Erreur encodage JSON: ' . json_last_error_msg()
        ]);
        exit;
    }
    
    if (file_put_contents($postPath, $jsonContent) === false) {
        echo json_encode([
            'success' => false,
            'error' => 'Erreur sauvegarde post.json'
        ]);
        exit;
    }
    
    echo "💾 Post saved successfully\n\n";
    
    // ✅ GÉNÉRATIONS AUTOMATIQUES APRÈS SAUVEGARDE (comme dans savePostWithImages)
    echo "🔄 Generating automatic files...\n";
    
    $postsDir = __DIR__ . '/posts';
    $categoriesDir = __DIR__ . '/categories';
    
    $generationResults = [];
    
    // Generate posts index
    if (function_exists('generatePostsIndex')) {
        $indexResult = generatePostsIndex($postsDir);
        $generationResults['index_generated'] = $indexResult['success'] ?? false;
        echo ($generationResults['index_generated'] ? "✅" : "❌") . " Posts index\n";
    }
    
   
    // Generate sitemaps
    if (function_exists('generateSitemaps')) {
        $sitemapsResult = generateSitemaps($postsDir);
        $generationResults['sitemaps_generated'] = ($sitemapsResult['sitemap'] ?? false) && ($sitemapsResult['sitemap_posts'] ?? false);
        echo ($generationResults['sitemaps_generated'] ? "✅" : "❌") . " Sitemaps\n";
    }
    
    // Generate Pinterest RSS
    if (function_exists('generatePinterestRSSFeed')) {
        $rssResult = generatePinterestRSSFeed($postsDir);
        $generationResults['rss_generated'] = $rssResult['success'] ?? false;
        echo ($generationResults['rss_generated'] ? "✅" : "❌") . " Pinterest RSS\n";
    }
    
    // Generate category RSS feeds
    if (function_exists('generateAllCategoryRSSFeeds')) {
        $categoryRssResult = generateAllCategoryRSSFeeds($categoriesDir, $postsDir);
        $generationResults['category_rss_generated'] = $categoryRssResult['success'] ?? false;
        echo ($generationResults['category_rss_generated'] ? "✅" : "❌") . " Category RSS feeds\n";
    }
    
    // echo "\n";
    
    // // Success response
    // echo json_encode([
    //     'success' => true,
    //     'message' => 'Post rewritée avec succès',
    //     'data' => $newPostData,
    //     'stats' => [
    //         'images_processed' => $processedCount,
    //         'total_images' => count($updatedImages),
    //         'slug' => $slug
    //     ],
    //     'generations' => $generationResults
    // ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
   
        // Template
        $image1Path = BASE_URL . $originalImages[IMG1]["filePath"];
        $image2Path = BASE_URL . $originalImages[IMG2]["filePath"];

        $templateData = [
            'image1' => $image1Path,
            'image2' => $image2Path,
            'title' => $post['title'],
            'uniqueSlug' => $post['slug']
        ];

        try {
            $ch = curl_init(BASE_URL . 'generate_template.php');
            
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $templateData
            ]);
            
            $templateResponse = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            curl_close($ch);
            
            if ($httpCode >= 200 && $httpCode < 300) {
                $templateResult = json_decode($templateResponse, true);
                echo "✅ Template généré: " . print_r($templateResult, true) . "\n";
                
                // Ajouter l'image template directement
                $newPostData['images'][] = [
                    'fileName' => $newPostData['slug'] . '_image_4.webp',
                    'filePath' => 'posts/' . $newPostData['slug'] . '/images/' . $newPostData['slug'] . '_image_4.webp',
                    'relativePath' => $newPostData['slug'] . '/images/' . $newPostData['slug'] . '_image_4.webp',
                    'originalUrl' => BASE_URL . 'posts/' . $newPostData['slug'] . '/images/' . $newPostData['slug'] . '_image_4.webp',  // ✅ BASE_URL au lieu de $baseURL
                    'order' => 4,
                    'type' => 'template'
                ];
                
                // ✅ Sauvegarder dans le bon chemin (posts/slug/post.json)
                $savedPath = __DIR__ . '/posts/' . $newPostData['slug'] . '/post.json';
                file_put_contents(
                    $savedPath,
                    json_encode($newPostData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
                
                echo "✅ Template image added to post JSON\n";
                
            } else {
                throw new Exception("Erreur HTTP: " . $httpCode);
            }
            
        } catch (Exception $e) {
            error_log('❌ Erreur template: ' . $e->getMessage());
            echo "⚠️  Template generation failed but continuing...\n";
        }

        echo "\n✅ Rewrite completed!\n\n";

        header('Location: ' . $_SERVER['HTTP_REFERER']);
        exit;
}


// 3. GET /api?action=check_openai
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'check_openai') {
    $configured = defined('OPENAI_API_KEY') && !empty(OPENAI_API_KEY);
    
    echo json_encode([
        'success' => true,
        'configured' => $configured
    ]);
    exit;
}


// Endpoint par défaut
echo json_encode([
    'success' => false,
    'error' => 'Action non reconnue',
    'available_endpoints' => [
        'GET ?action=categories' => 'Liste des catégories',
        'POST ?action=analyze' => 'Analyser un texte (body: {source_text: "..."})',
        'GET ?action=check_openai' => 'Vérifier si OpenAI est configuré',
        'GET ?action=get_template_config' => 'Configuration template generator'
    ]
]);
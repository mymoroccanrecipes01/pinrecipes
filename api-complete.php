<?php
/**
 * 🚀 API BACKEND COMPLET - Multi-Sources Post Generator
 * VERSION COMPLÈTE: Génère post + télécharge images + sauvegarde + index
 */

// Disable all output except JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Start output buffering to catch any unwanted output
ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Configuration
define('LOG_FILE', __DIR__ . '/processing.log');
define('POST_API_PATH', __DIR__ . '/posts-api.php'); // New API file
define('POST_GENERATOR_PATH', __DIR__ . '/posts-generater.php'); // Old file (fallback)

// Verify API file exists
if (!file_exists(POST_API_PATH)) {
    logMessage("CRITICAL: posts-api.php not found at: " . POST_API_PATH, 'FATAL');
    echo json_encode([
        'success' => false,
        'error' => 'posts-api.php not found. Please ensure it is in the same directory as api-complete.php'
    ]);
    exit;
}

// Debug mode
define('DEBUG_MODE', false); // Set to true for detailed error messages

// Load wrapper for API calls
require_once __DIR__ . '/post-generator-wrapper.php';
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

// Set BASE_URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$baseDir = dirname($_SERVER['SCRIPT_NAME']);
define('BASE_URL', $protocol . $host . $baseDir . '/');
define('POSTS_DIR', __DIR__ . '/posts');

// Logging function
function logMessage($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message\n";
    file_put_contents(LOG_FILE, $logEntry, FILE_APPEND);
    return $logEntry;
}

/**
 * Classe pour gérer le traitement complet d'une post
 */
class PostProcessor {
    private $sourceText;
    private $categoryId;
    private $postData;
    private $slug;
    private $imagesData = [];
    
    public function __construct($sourceText, $categoryId = 1) {
        $this->sourceText = $sourceText;
        $this->categoryId = $categoryId;
    }
    
    /**
     * Processus complet: génération + images + sauvegarde + index
     */
    public function processComplete() {
        try {
            // Étape 1: Générer la post via OpenAI
            logMessage("Étape 1/6: Génération de la post...");
            $generateResult = $this->generatePost();
            if (!$generateResult['success']) {
                return $generateResult;
            }
            
            // Étape 2: Générer les images via AI (genimg.php)
            logMessage("Étape 2/6: Génération des images AI...");
            $aiImagesResult = $this->generateAIImages();
            if (!$aiImagesResult['success']) {
                logMessage("⚠️ Erreur génération AI: " . $aiImagesResult['error'], 'WARNING');
                // Continuer même si AI images échouent
            } else {
                logMessage("✅ Images AI générées: " . implode(', ', $aiImagesResult['images']));
            }
            
            // Étape 3: Télécharger et convertir les images
            logMessage("Étape 3/6: Traitement des images...");
            $imagesResult = $this->processImages();
            if (!$imagesResult['success']) {
                logMessage("⚠️ Erreur images: " . $imagesResult['error'], 'WARNING');
                // Continuer même sans images
            }
            
            // Étape 4: Générer template Pinterest
            logMessage("Étape 4/6: Génération template Pinterest...");
            $templateResult = $this->generatePinterestTemplate();
            if ($templateResult['success']) {
                $this->imagesData[] = $templateResult['data'];
            }
            
            // Étape 5: Sauvegarder la post complète
            logMessage("Étape 5/6: Sauvegarde de la post...");
            $saveResult = $this->savePost();
            if (!$saveResult['success']) {
                return $saveResult;
            }
            
            // Étape 6: Générer index/sitemap/RSS
            logMessage("Étape 6/6: Génération index et feeds...");
            $indexResult = $this->generateIndex();
            
            logMessage("✅ Traitement complet terminé pour: " . $this->slug);
            
            return [
                'success' => true,
                'slug' => $this->slug,
                'title' => $this->postData['title'],
                'images_count' => count($this->imagesData),
                'message' => 'Post créée avec succès'
            ];
            
        } catch (Exception $e) {
            logMessage("❌ Exception: " . $e->getMessage(), 'ERROR');
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Générer la post via OpenAI
     */
    private function generatePost() {
        logMessage("Calling post generator API for text generation...");
        
        try {
            $api = getPostGeneratorAPI();
            $result = $api->generateFromText($this->sourceText, $this->categoryId);
            
            if ($result['success'] && isset($result['data'])) {
                $this->postData = $result['data'];
                
                // Générer slug si pas présent
                if (!isset($this->postData['uniqueSlug']) || empty($this->postData['uniqueSlug'])) {
                    $this->postData['uniqueSlug'] = $this->generateSlug($this->postData['title']);
                }
                
                $this->slug = $this->postData['uniqueSlug'];
                
                logMessage("Post generated successfully: " . $this->postData['title']);
                
                return [
                    'success' => true,
                    'data' => $this->postData
                ];
            } else {
                $error = $result['error'] ?? 'Unknown error';
                logMessage("Post generation failed: " . $error, 'ERROR');
                
                return [
                    'success' => false,
                    'error' => $error
                ];
            }
            
        } catch (Exception $e) {
            logMessage("Exception in generatePost: " . $e->getMessage(), 'ERROR');
            
            return [
                'success' => false,
                'error' => 'Exception: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Générer les images via AI (genimg.php)
     */
    private function generateAIImages() {
        logMessage("Calling genimg.php to generate AI images...");
        
        try {
            // Récupérer le titre et le prompt image
            $title = $this->postData['title'] ?? '';
            $promptImg = $this->postData['promptIMG'] ?? $title;
            
            if (empty($title)) {
                return [
                    'success' => false,
                    'error' => 'No post title available'
                ];
            }
            
            // Construire l'URL de genimg.php
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
            $host = $_SERVER['HTTP_HOST'];
            $baseDir = dirname($_SERVER['SCRIPT_NAME']);
            $genimgUrl = $protocol . $host . $baseDir . '/genimg.php';
            
            // Vérifier si genimg.php existe
            if (!file_exists(__DIR__ . '/genimg.php')) {
                logMessage("genimg.php not found - skipping AI image generation", 'WARNING');
                return [
                    'success' => false,
                    'error' => 'genimg.php not found'
                ];
            }
            
            // Appeler genimg.php via cURL
            $postData = [
                'title' => $title,
                'prompt' => $promptImg
            ];
            
            $ch = curl_init($genimgUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120); // 2 minutes pour génération AI
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                logMessage("cURL error calling genimg.php: " . $curlError, 'ERROR');
                return [
                    'success' => false,
                    'error' => 'cURL error: ' . $curlError
                ];
            }
            
            if ($httpCode !== 200) {
                logMessage("genimg.php returned HTTP " . $httpCode, 'ERROR');
                return [
                    'success' => false,
                    'error' => 'HTTP error: ' . $httpCode
                ];
            }
            
            // Parse response
            $result = json_decode($response, true);
            
            if ($result && isset($result['success']) && $result['success']) {
                logMessage("AI images generated successfully");
                
                // Mettre à jour postData avec les URLs des images générées
                if (isset($result['images']) && is_array($result['images'])) {
                    $this->postData['ai_generated_images'] = $result['images'];
                }
                
                return [
                    'success' => true,
                    'images' => $result['images'] ?? [],
                    'message' => 'AI images generated'
                ];
            } else {
                $error = $result['error'] ?? 'Unknown error from genimg.php';
                logMessage("genimg.php failed: " . $error, 'ERROR');
                
                return [
                    'success' => false,
                    'error' => $error
                ];
            }
            
        } catch (Exception $e) {
            logMessage("Exception in generateAIImages: " . $e->getMessage(), 'ERROR');
            
            return [
                'success' => false,
                'error' => 'Exception: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Télécharger et traiter les images
     */
    private function processImages() {
        // Vérifier si on a des images générées par AI
        $imageUrls = [];
        
        if (isset($this->postData['ai_generated_images']) && !empty($this->postData['ai_generated_images'])) {
            // Utiliser les images générées par AI
            $aiImages = $this->postData['ai_generated_images'];
            logMessage("Using AI generated images: " . count($aiImages) . " images");
            
            foreach ($aiImages as $img) {
                if (is_string($img)) {
                    $imageUrls[] = $img;
                } elseif (isset($img['url'])) {
                    $imageUrls[] = $img['url'];
                }
            }
        } else {
            // URLs par défaut pour les images (tmpIMG)
            logMessage("No AI images found, using default tmpIMG URLs");
            $imageUrls = [
                BASE_URL . 'tmpIMG/image_1.webp',
                BASE_URL . 'tmpIMG/image_2.webp',
                BASE_URL . 'tmpIMG/image_3.webp'
            ];
        }
        
        $processedCount = 0;
        
        foreach ($imageUrls as $index => $url) {
            $imageNumber = $index + 1;
            
            try {
                $result = $this->downloadAndConvertImage($url, $imageNumber);
                if ($result['success']) {
                    $this->imagesData[] = [
                        'fileName' => $result['fileName'],
                        'filePath' => $result['filePath'],
                        'relativePath' => $result['relativePath'],
                        'originalUrl' => $url,
                        'order' => $imageNumber,
                        'type' => $imageNumber === 1 ? 'main' : ($imageNumber === 2 ? 'process' : 'final')
                    ];
                    $processedCount++;
                    logMessage("✅ Image $imageNumber traitée");
                }
            } catch (Exception $e) {
                logMessage("⚠️ Erreur image $imageNumber: " . $e->getMessage(), 'WARNING');
            }
        }
        
        return [
            'success' => $processedCount > 0,
            'processed_count' => $processedCount
        ];
    }
    
    /**
     * Télécharger et convertir une image en WebP
     */
    private function downloadAndConvertImage($url, $imageNumber) {
        // Télécharger l'image
        $imageContent = @file_get_contents($url);
        if ($imageContent === false) {
            throw new Exception("Cannot download image from: $url");
        }
        
        // Créer une image depuis le contenu
        $image = @imagecreatefromstring($imageContent);
        if ($image === false) {
            throw new Exception("Cannot create image from content");
        }
        
        // Créer les dossiers nécessaires
        $postDir = POSTS_DIR . '/' . $this->slug;
        $imagesDir = $postDir . '/images';
        
        if (!is_dir($postDir)) {
            mkdir($postDir, 0755, true);
        }
        if (!is_dir($imagesDir)) {
            mkdir($imagesDir, 0755, true);
        }
        
        // Nom du fichier
        $fileName = 'image_' . $imageNumber . '.webp';
        $filePath = $imagesDir . '/' . $fileName;
        
        // Convertir en WebP et sauvegarder
        if (!imagewebp($image, $filePath, 90)) {
            imagedestroy($image);
            throw new Exception("Cannot convert to WebP");
        }
        
        imagedestroy($image);
        
        return [
            'success' => true,
            'fileName' => $fileName,
            'filePath' => 'posts/' . $this->slug . '/images/' . $fileName,
            'relativePath' => $this->slug . '/images/' . $fileName
        ];
    }
    
    /**
     * Générer template Pinterest
     */
    private function generatePinterestTemplate() {
        if (count($this->imagesData) < 2) {
            return ['success' => false, 'error' => 'Not enough images'];
        }
        
        try {
            // Appeler generate_template.php
            $formData = [
                'image1' => BASE_URL . $this->imagesData[0]['filePath'],
                'image2' => BASE_URL . $this->imagesData[1]['filePath'],
                'title' => $this->postData['title'],
                'uniqueSlug' => $this->slug
            ];
            
            $ch = curl_init(BASE_URL . 'generate_template.php');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($formData));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $result = json_decode($response, true);
                if ($result && isset($result['path'])) {
                    return [
                        'success' => true,
                        'data' => [
                            'fileName' => $result['filename'],
                            'filePath' => $result['path'],
                            'relativePath' => $result['pathrelative'],
                            'originalUrl' => $result['url'],
                            'order' => count($this->imagesData) + 1,
                            'type' => 'template'
                        ]
                    ];
                }
            }
            
            return ['success' => false, 'error' => 'Template generation failed'];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Sauvegarder la post complète
     */
    private function savePost() {
        logMessage("Saving post to database...");
        
        try {
            // Ajouter le slug aux données
            $postWithSlug = array_merge($this->postData, ['slug' => $this->slug]);
            
            $api = getPostGeneratorAPI();
            $result = $api->saveFinalPost($postWithSlug, $this->imagesData);
            
            // Vérifier que le fichier post.json existe
            $postFile = POSTS_DIR . '/' . $this->slug . '/post.json';
            if (file_exists($postFile)) {
                logMessage("Post saved successfully: " . $postFile);
                return ['success' => true];
            } else {
                logMessage("Post file not created: " . $postFile, 'ERROR');
                return ['success' => false, 'error' => 'Post file not created'];
            }
            
        } catch (Exception $e) {
            logMessage("Exception in savePost: " . $e->getMessage(), 'ERROR');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Générer index, sitemap et RSS
     */
    private function generateIndex() {
        logMessage("Generating index, sitemap and RSS feeds...");
        
        try {
            $api = getPostGeneratorAPI();
            $result = $api->generateIndex();
            
            logMessage("Index/sitemap/RSS generated successfully");
            return ['success' => true];
            
        } catch (Exception $e) {
            logMessage("Exception in generateIndex: " . $e->getMessage(), 'ERROR');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Générer un slug unique
     */
    private function generateSlug($title) {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)));
        
        // Vérifier unicité
        $baseSlug = $slug;
        $counter = 1;
        
        while (is_dir(POSTS_DIR . '/' . $slug)) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }
        
        return $slug;
    }
}

/**
 * Session Manager
 */
class SessionManager {
    private $sessionId;
    private $sessionFile;
    
    public function __construct($sessionId) {
        $this->sessionId = $sessionId;
        $this->sessionFile = __DIR__ . "/logs/session_$sessionId.json";
    }
    
    public function init($sources) {
        $data = [
            'session_id' => $this->sessionId,
            'created_at' => time(),
            'status' => 'initialized',
            'total_sources' => count($sources),
            'processed' => 0,
            'sources' => array_map(function($source, $index) {
                return [
                    'index' => $index,
                    'text' => $source,
                    'status' => 'pending',
                    'result' => null,
                    'error' => null,
                    'started_at' => null,
                    'completed_at' => null
                ];
            }, $sources, array_keys($sources))
        ];
        
        file_put_contents($this->sessionFile, json_encode($data, JSON_PRETTY_PRINT));
        logMessage("Session {$this->sessionId} créée avec " . count($sources) . " sources");
        
        return $data;
    }
    
    public function getStatus() {
        if (!file_exists($this->sessionFile)) {
            return ['error' => 'Session not found'];
        }
        return json_decode(file_get_contents($this->sessionFile), true);
    }
    
    public function updateSource($index, $status, $data = []) {
        $sessionData = $this->getStatus();
        
        if (isset($sessionData['sources'][$index])) {
            $sessionData['sources'][$index]['status'] = $status;
            $sessionData['sources'][$index] = array_merge($sessionData['sources'][$index], $data);
            
            if ($status === 'completed' || $status === 'error') {
                $sessionData['processed']++;
                $sessionData['sources'][$index]['completed_at'] = time();
            }
            
            // Check if all done
            $allDone = true;
            foreach ($sessionData['sources'] as $source) {
                if ($source['status'] === 'pending' || $source['status'] === 'processing') {
                    $allDone = false;
                    break;
                }
            }
            
            if ($allDone) {
                $sessionData['status'] = 'completed';
                $sessionData['completed_at'] = time();
            }
            
            file_put_contents($this->sessionFile, json_encode($sessionData, JSON_PRETTY_PRINT));
        }
        
        return $sessionData;
    }
    
    public static function cleanup($maxAge = 3600) {
        $files = glob(__DIR__ . '/session_*.json');
        $now = time();
        
        foreach ($files as $file) {
            if (file_exists($file) && ($now - filemtime($file)) > $maxAge) {
                unlink($file);
                logMessage("Session nettoyée: " . basename($file));
            }
        }
    }
}

/**
 * API Router
 */
$method = $_SERVER['REQUEST_METHOD'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    // Clean any previous output
    if (ob_get_level()) ob_clean();
    
    switch ($action) {
        case 'init':
            // Initialiser session
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            $sources = json_decode($_POST['sources'] ?? '[]', true);
            if (empty($sources)) {
                throw new Exception('No sources provided');
            }
            
            $sessionId = uniqid('session_', true);
            $manager = new SessionManager($sessionId);
            $result = $manager->init($sources);
            
            // Clean output before JSON
            if (ob_get_level()) ob_clean();
            
            echo json_encode([
                'success' => true,
                'session_id' => $sessionId,
                'data' => $result
            ]);
            break;
            
        case 'process':
            // Traiter une source
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            $sessionId = $_POST['session_id'] ?? '';
            $sourceIndex = (int)($_POST['source_index'] ?? -1);
            
            if (empty($sessionId) || $sourceIndex < 0) {
                throw new Exception('Invalid parameters');
            }
            
            $manager = new SessionManager($sessionId);
            $sessionData = $manager->getStatus();
            
            if (!isset($sessionData['sources'][$sourceIndex])) {
                throw new Exception('Source not found');
            }
            
            $source = $sessionData['sources'][$sourceIndex];
            $manager->updateSource($sourceIndex, 'processing', ['started_at' => time()]);
            
            // Traiter la post complète
            $processor = new PostProcessor($source['text'], 1);
            $result = $processor->processComplete();
            
            // Clean output before JSON
            if (ob_get_level()) ob_clean();
            
            if ($result['success']) {
                $manager->updateSource($sourceIndex, 'completed', ['result' => $result]);
                echo json_encode([
                    'success' => true,
                    'data' => $result
                ]);
            } else {
                $manager->updateSource($sourceIndex, 'error', ['error' => $result['error']]);
                echo json_encode([
                    'success' => false,
                    'error' => $result['error']
                ]);
            }
            break;
            
        case 'status':
            // Obtenir statut
            $sessionId = $_GET['session_id'] ?? $_POST['session_id'] ?? '';
            if (empty($sessionId)) {
                throw new Exception('Session ID required');
            }
            
            $manager = new SessionManager($sessionId);
            $status = $manager->getStatus();
            
            // Clean output before JSON
            if (ob_get_level()) ob_clean();
            
            echo json_encode([
                'success' => true,
                'data' => $status
            ]);
            break;
            
        case 'cleanup':
            // Nettoyer sessions
            SessionManager::cleanup();
            
            // Clean output before JSON
            if (ob_get_level()) ob_clean();
            
            echo json_encode([
                'success' => true,
                'message' => 'Cleanup completed'
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    // Clean any output before error
    if (ob_get_level()) ob_clean();
    
    http_response_code(400);
    
    // Log error
    logMessage("Erreur API: " . $e->getMessage(), 'ERROR');
    logMessage("Stack trace: " . $e->getTraceAsString(), 'ERROR');
    
    // Always return JSON
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => DEBUG_MODE ? $e->getTraceAsString() : null
    ]);
}

// Catch any PHP errors and convert to JSON
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Clean all output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        http_response_code(500);
        header('Content-Type: application/json');
        
        $errorMsg = [
            'success' => false,
            'error' => 'PHP Fatal Error: ' . $error['message'],
            'file' => $error['file'],
            'line' => $error['line']
        ];
        
        echo json_encode($errorMsg);
        
        logMessage("PHP Fatal Error: " . $error['message'] . " in " . $error['file'] . ":" . $error['line'], 'FATAL');
    }
});
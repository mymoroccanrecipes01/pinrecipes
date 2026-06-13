<?php
/**
 * 🔌 Post Generator Wrapper
 * Appelle posts-api.php en tant qu'API HTTP
 */

class PostGeneratorAPI {
    private $baseUrl;
    
    public function __construct($baseUrl = null) {
        if ($baseUrl) {
            $this->baseUrl = $baseUrl;
        } else {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
            $host = $_SERVER['HTTP_HOST'];
            $dir = dirname($_SERVER['SCRIPT_NAME']);
            // Use posts-api.php instead of posts-generater.php
            $this->baseUrl = $protocol . $host . $dir . '/posts-generater.php';
        }
    }
    
    /**
     * Générer une post depuis texte
     */
    public function generateFromText($sourceText, $categoryId = 1) {
        $postData = [
            'action' => 'generate_from_text',
            'source_text' => $sourceText,
            'category_id' => $categoryId
        ];
        
        return $this->makeRequest($postData);
    }
    
    /**
     * Sauvegarder une post avec images
     */
    public function saveFinalPost($postData, $imagesData) {
        $postData = [
            'action' => 'save_final_post',
            'post_data' => json_encode($postData),
            'images_data' => json_encode($imagesData)
        ];
        
        return $this->makeRequest($postData);
    }
    
    /**
     * Sauvegarder une image WebP
     */
    public function saveWebP($imageData, $fileName, $postSlug) {
        $postData = [
            'action' => 'save_webp',
            'imageData' => $imageData,
            'fileName' => $fileName,
            'postSlug' => $postSlug
        ];
        
        return $this->makeRequest($postData);
    }
    
    /**
     * Générer l'index des posts
     */
    public function generateIndex() {
        return $this->makeRequest([], 'GET', '?action=posts_index&from_iframe=1');
    }
    
    /**
     * Faire une requête HTTP
     */
    private function makeRequest($postData = [], $method = 'POST', $urlSuffix = '') {
        $url = $this->baseUrl . $urlSuffix;
        
        $ch = curl_init($url);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        }
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Pour dev local
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'error' => 'cURL Error: ' . $error
            ];
        }
        
        if ($httpCode !== 200) {
            return [
                'success' => false,
                'error' => 'HTTP Error: ' . $httpCode,
                'response' => substr($response, 0, 500)
            ];
        }
        
        // Essayer de parser comme JSON
        $json = json_decode($response, true);
        if ($json !== null) {
            return $json;
        }
        
        // Si ce n'est pas du JSON, extraire les variables PHP depuis le HTML
        // Chercher window.generatedPostData ou $generatedPost dans le HTML
        return $this->extractDataFromHTML($response);
    }
    
    /**
     * Extraire les données depuis la réponse
     */
    private function extractDataFromHTML($response) {
        // posts-api.php returns JSON, so this shouldn't be needed
        // But keep for backward compatibility
        
        // Try to parse as JSON first
        $json = json_decode($response, true);
        if ($json !== null) {
            return $json;
        }
        
        // If HTML is returned (shouldn't happen with posts-api.php)
        return [
            'success' => false,
            'error' => 'Expected JSON but received HTML/text',
            'response_preview' => substr($response, 0, 500)
        ];
    }
}

/**
 * Fonction helper pour utiliser facilement
 */
function getPostGeneratorAPI() {
    static $api = null;
    if ($api === null) {
        $api = new PostGeneratorAPI();
    }
    return $api;
}
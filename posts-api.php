<?php
require_once 'config.php';
require_once __DIR__ . '/pinterest-boards-helpers.php';
require_once __DIR__ . '/rating-helpers.php';
// Auth: bypass pour appels serveur local (curl depuis posts-client.php, auto-pipeline, etc.)
$_isLocalCall = ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1'
             || ($_SERVER['REMOTE_ADDR'] ?? '') === '::1'
             || (($_SERVER['HTTP_X_CLI_SECRET'] ?? '') !== '' && ($_SERVER['HTTP_X_CLI_SECRET'] ?? '') === CLI_SECRET);
if (!$_isLocalCall) {
    require_once __DIR__ . '/auth.php';
    auth_check();
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}



function compressWebP($source_file, $output_file, $quality = 80) {
    $image_info = getimagesize($source_file);
    $mime_type = $image_info['mime'];
    
    switch ($mime_type) {
        case 'image/jpeg':
            $source = imagecreatefromjpeg($source_file);
            break;
        case 'image/png':
            $source = imagecreatefrompng($source_file);
            break;
        case 'image/webp':
            $source = imagecreatefromwebp($source_file);
            break;
        default:
            return false;
    }
    
    imagewebp($source, $output_file, $quality);
    
    imagedestroy($source);
    
    return true;
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

/**
 * Generate HTML page for a single post (without regenerating all pages)
 */
function generatepostHtmlPage($postSlug) {
    $postDir = './posts/' . $postSlug;

    // Check if post directory exists
    if (!is_dir($postDir)) {
        return ['success' => false, 'error' => 'post directory not found: ' . $postDir];
    }

    // Check if post.json exists
    $postJsonPath = $postDir . '/post.json';
    if (!file_exists($postJsonPath)) {
        return ['success' => false, 'error' => 'post.json not found'];
    }

    // Load post data
    $post = json_decode(file_get_contents($postJsonPath), true);
    if (!$post) {
        return ['success' => false, 'error' => 'Failed to parse post.json'];
    }

    try {
        // Call generate-single-post.php as a separate process to avoid loading all pages
        $command = 'php ' . escapeshellarg(__DIR__ . '/generate-single-post.php') . ' ' . escapeshellarg($postSlug) . ' 2>&1';
        $output = shell_exec($command);

        // Check if HTML file was created
        $htmlPath = $postDir . '/index.html';
        if (file_exists($htmlPath)) {
            return [
                'success' => true,
                'message' => 'HTML page generated successfully',
                'htmlPath' => $htmlPath,
                'url' => 'http://localhost/SitePinterset/mollykitchendaily-main/posts/' . $postSlug . '/',
                'output' => $output
            ];
        } else {
            return [
                'success' => false,
                'error' => 'HTML file was not created',
                'output' => $output
            ];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Exception: ' . $e->getMessage()];
    }
}

function generatePinVariations($title, $description) {
    set_time_limit(0);
    $openaiApiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
    if (empty($openaiApiKey)) return null;

    $hookPrompt = defined('HOOK_PROMPT') ? HOOK_PROMPT : '';
    $hookPrompt = str_replace(['{TITLE}', '{DESCRIPTION}'], [$title, $description], $hookPrompt);

    $data = [
        "model" => OPENAI_CONTENT_MODEL,
        "messages" => [
            ["role" => "system", "content" => "You are a Pinterest viral content strategist specializing in food/recipe pins. Return valid JSON only, no markdown."],
            ["role" => "user", "content" => "Recipe title: $title\nDescription: $description\n\nGenerate 4 Pinterest pin variations that maximize impressions and saves. Each variation targets a DIFFERENT audience angle to get more reach.\n\nVariation 1 (Original): Keep the recipe title as-is, write a keyword-rich description.\nVariation 2 (Curiosity hook): Rewrite the title using a curiosity gap (secret, surprising fact, common mistake). Max 60 chars.\nVariation 3 (Value/Informative): Rewrite the title with a number or did-you-know hook. Max 60 chars.\nVariation 4 (Emotional/Seasonal): Rewrite the title targeting an emotion, occasion, or season (e.g. cozy, family, holiday). Max 60 chars.\n\nEach variation must have:\n- 'title': pin image text (max 60 chars for variations 2, 3 & 4)\n- 'description': Pinterest pin description with SEO keywords + hashtags (max 500 chars)\n\nReturn ONLY a valid JSON array with exactly 4 objects. No markdown, no extra text."]
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
    if (!is_array($variations) || count($variations) < 4) return null;
    return array_slice($variations, 0, 4);
}

/**
 * Log API token usage and cost to logs/api_usage.log
 */
function computeApiCost($api, $model, $inputTokens, $outputTokens) {
    $pricing = [
        'claude-haiku-4-5-20251001' => ['input' => 0.80,  'output' => 4.00],
        'claude-sonnet-4-6'         => ['input' => 3.00,  'output' => 15.00],
        'gpt-4o-mini'               => ['input' => 0.15,  'output' => 0.60],
        'gpt-4.1-mini'              => ['input' => 0.40,  'output' => 1.60],
        'gpt-5-mini'                => ['input' => 0.25,  'output' => 2.00],
    ];
    $price = $pricing[$model] ?? ['input' => 0, 'output' => 0];
    return ($inputTokens / 1_000_000) * $price['input']
         + ($outputTokens / 1_000_000) * $price['output'];
}

function logApiUsage($api, $model, $inputTokens, $outputTokens, $context = '') {
    $totalCost = computeApiCost($api, $model, $inputTokens, $outputTokens);

    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $logLine = sprintf(
        "[%s] API=%-10s Model=%-30s In=%6d Out=%6d Cost=$%.5f%s\n",
        date('Y-m-d H:i:s'),
        strtoupper($api),
        $model,
        $inputTokens,
        $outputTokens,
        $totalCost,
        $context ? "  [$context]" : ''
    );
    file_put_contents($logDir . '/api_usage.log', $logLine, FILE_APPEND | LOCK_EX);
}

/**
 * Unified API caller — switches between OpenAI and Anthropic based on GENERATION_API config.
 * Returns ['text' => '...'] on success or ['error' => '...'] on failure.
 */
function callGenerationAPI($systemPrompt, $userPrompt, $maxTokens, $temperature) {
    set_time_limit(0); // Remove PHP execution time limit for API calls
    $api = defined('GENERATION_API') ? GENERATION_API : 'openai';

    if ($api === 'anthropic') {
        $apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
        if (empty($apiKey) || strpos($apiKey, 'votre-clé') !== false) {
            return ['error' => 'Clé API Anthropic non configurée dans config.php'];
        }
        $model = defined('ANTHROPIC_MODEL') ? ANTHROPIC_MODEL : 'claude-sonnet-4-6';

        // Respect per-model output token limits
        $modelLimits = [
            'claude-3-haiku-20240307'   => 4096,
            'claude-3-5-haiku-20241022' => 8192,
            'claude-haiku-4-5-20251001' => 8192,
            'claude-3-5-sonnet-20241022'=> 8192,
            'claude-sonnet-4-6'         => 16000,
        ];
        $cappedTokens = isset($modelLimits[$model]) ? min($maxTokens, $modelLimits[$model]) : $maxTokens;

        $data = [
            'model'      => $model,
            'max_tokens' => $cappedTokens,
            'system'     => $systemPrompt,
            'messages'   => [
                ['role' => 'user', 'content' => $userPrompt]
            ]
        ];

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST          => true,
            CURLOPT_POSTFIELDS    => json_encode($data),
            CURLOPT_HTTPHEADER    => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT       => 300
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $err = json_decode($response, true);
            return ['error' => 'Anthropic API Error: ' . ($err['error']['message'] ?? "HTTP $httpCode")];
        }

        $resp = json_decode($response, true);
        if (!$resp || !isset($resp['content'][0]['text'])) {
            return ['error' => 'Réponse Anthropic invalide'];
        }
        $usage = $resp['usage'] ?? [];
        $cost  = computeApiCost('anthropic', $model, $usage['input_tokens'] ?? 0, $usage['output_tokens'] ?? 0);
        logApiUsage('anthropic', $model, $usage['input_tokens'] ?? 0, $usage['output_tokens'] ?? 0);
        $GLOBALS['_pipeline_text_cost'] = ($GLOBALS['_pipeline_text_cost'] ?? 0.0) + $cost;
        return ['text' => $resp['content'][0]['text'], 'cost' => $cost];

    } else {
        // OpenAI (default)
        $apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
        if (empty($apiKey)) {
            return ['error' => 'Clé API OpenAI non configurée dans config.php'];
        }

        $data = [
            'model'           => OPENAI_CONTENT_MODEL,
            'messages'        => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt]
            ],
            'max_tokens'      => $maxTokens,
            'temperature'     => $temperature,
            'response_format' => ['type' => 'json_object']
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST          => true,
            CURLOPT_POSTFIELDS    => json_encode($data),
            CURLOPT_HTTPHEADER    => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT       => 300
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $err = json_decode($response, true);
            return ['error' => 'OpenAI API Error: ' . ($err['error']['message'] ?? "HTTP $httpCode")];
        }

        $resp = json_decode($response, true);
        if (!$resp || !isset($resp['choices'][0]['message']['content'])) {
            return ['error' => 'Réponse OpenAI invalide'];
        }
        $usage = $resp['usage'] ?? [];
        $cost  = computeApiCost('openai', OPENAI_CONTENT_MODEL, $usage['prompt_tokens'] ?? 0, $usage['completion_tokens'] ?? 0);
        logApiUsage('openai', OPENAI_CONTENT_MODEL, $usage['prompt_tokens'] ?? 0, $usage['completion_tokens'] ?? 0);
        $GLOBALS['_pipeline_text_cost'] = ($GLOBALS['_pipeline_text_cost'] ?? 0.0) + $cost;
        return ['text' => $resp['choices'][0]['message']['content'], 'cost' => $cost];
    }
}

/**
 * Generate post with human-like variations for AdSense approval
 */
function generatepostFromText($sourceText, $categoryHint = '') {
    // Prepare categories list
    $categoriesArray = json_decode($categoryHint, true);
    $categoriesList = '';
    if ($categoriesArray && is_array($categoriesArray)) {
        foreach ($categoriesArray as $key => $catId) {
            $categoriesList .= "- " . $key . " (ID: " . $catId . ")\n";
        }
    }
    
    // CRITICAL: Determine post complexity for natural variation
    $complexity = determinepostComplexity($sourceText);
    $structurePattern = getRandomStructurePattern();
    
    // If input looks like a recipe title (short, < 120 chars), force the AI to use it exactly
    $isTitleInput = strlen(trim($sourceText)) < 120 && strpos($sourceText, "\n") === false;

    // Build dynamic prompt with variations
    $fullPrompt = POST_PROMPT;
    $fullPrompt .= "\n\n---\n\n";

    // Inject existing Pinterest boards so AI reuses them instead of inventing new ones
    $boardsSection = buildBoardsPromptSection();
    if ($boardsSection !== '') {
        $fullPrompt .= $boardsSection . "\n\n---\n\n";
    }

    if ($isTitleInput) {
        $fullPrompt .= "MANDATORY RECIPE TITLE: You MUST write the recipe for exactly: \"{$sourceText}\"\n";
        $fullPrompt .= "The 'title' field in your JSON MUST be based on this exact recipe name. Do NOT invent a different recipe.\n\n";
    }
    $fullPrompt .= "CATEGORY SELECTION:\n";
    $fullPrompt .= "Choose the MOST appropriate category from this list:\n\n";
    $fullPrompt .= $categoriesList;
    $fullPrompt .= "\nAdd 'category_id' field in your JSON with the exact category ID.\n\n";
    $fullPrompt .= "Source Text:\n{$sourceText}";
    
    // API call with varied temperature for natural content
    $temperature = getVariedTemperature($complexity);
    $systemPrompt = "You are a food blogger writing authentic post posts. Each post should feel unique and naturally written, never templated or AI-generated. RETURN ONLY VALID JSON with no markdown formatting, no text before or after the JSON object.";

    $apiResult = callGenerationAPI($systemPrompt, $fullPrompt, getMaxTokensForComplexity($complexity), $temperature);
    if (isset($apiResult['error'])) {
        return ['error' => $apiResult['error']];
    }

    $postText = cleanJsonResponse($apiResult['text']);
    $postData = json_decode($postText, true);

    // Fallback: try repaired JSON
    if (!$postData) {
        $repaired = repairTruncatedJson($postText);
        $postData = json_decode($repaired, true);
    }

    if (!$postData) {
        return ['error' => 'Invalid JSON: ' . json_last_error_msg()];
    }

    // Validation
    if (!isset($postData['title'])) {
        return ['error' => 'Missing title in generated post'];
    }

    // Sync any new board names generated by AI into the master list
    if (!empty($postData['pinterest_boards']) && is_array($postData['pinterest_boards'])) {
        syncNewBoardsToMasterList($postData['pinterest_boards']);
    }

    // Add metadata for tracking variations
    $postData['generation_metadata'] = [
        'complexity' => $complexity,
        'structure_pattern' => $structurePattern,
        'temperature' => $temperature,
        'generated_at' => date('Y-m-d H:i:s')
    ];
    
    // Set author
    $firstActiveAuthor = getFirstActiveAuthor();
    if ($firstActiveAuthor) {
        $postData['author_id'] = $firstActiveAuthor;
    }
    
    // Post-process for human touch
    $postData = addHumanImperfections($postData);
    
    return $postData;
}


/**
 * Determine post complexity from source text
 */
function determinepostComplexity($sourceText) {
    $wordCount = str_word_count($sourceText);
    $text = strtolower($sourceText);
    
    // Complex indicators
    $complexIndicators = [
        'traditional', 'authentic', 'from scratch', 'homemade',
        'layered', 'multi-step', 'slow cook', 'overnight',
        'ferment', 'marinate', 'reduction', 'roux'
    ];
    
    // Simple indicators
    $simpleIndicators = [
        'quick', 'easy', '5 ingredient', 'no-bake', 
        'microwave', 'instant', '15 minute', '3-ingredient',
        'simple', 'beginner'
    ];
    
    $complexScore = 0;
    $simpleScore = 0;
    
    foreach ($complexIndicators as $indicator) {
        if (strpos($text, $indicator) !== false) {
            $complexScore++;
        }
    }
    
    foreach ($simpleIndicators as $indicator) {
        if (strpos($text, $indicator) !== false) {
            $simpleScore++;
        }
    }
    
    // Decision logic
    if ($simpleScore > $complexScore || $wordCount < 100) {
        return 'simple'; // 400-600 words target
    } elseif ($complexScore > $simpleScore + 1 || $wordCount > 250) {
        return 'complex'; // 800-1000 words target
    }
    
    return 'medium'; // 600-800 words target
}

/**
 * Get random structure pattern with weighted distribution
 */
function getRandomStructurePattern() {
    $patterns = [
        'simple' => 40,   // 40% chance
        'story' => 30,    // 30% chance
        'detailed' => 30  // 30% chance
    ];
    
    $rand = rand(1, 100);
    $cumulative = 0;
    
    foreach ($patterns as $pattern => $weight) {
        $cumulative += $weight;
        if ($rand <= $cumulative) {
            return $pattern;
        }
    }
    
    return 'simple'; // Fallback
}

/**
 * Get varied temperature based on complexity
 */
function getVariedTemperature($complexity) {
    $temperatures = [
        'simple' => 0.5,   // More creative for simple posts
        'medium' => 0.4,   // Balanced
        'complex' => 0.3   // More precise for complex posts
    ];
    
    return $temperatures[$complexity] ?? 0.4;
}

/**
 * Get max tokens based on complexity
 */
function getMaxTokensForComplexity($complexity) {
    $tokens = [
        'simple'  => 10000,  // prompts légers
        'medium'  => 14000,  // prompts moyens (5500-7500 words)
        'complex' => 16000   // prompts lourds — max modèle
    ];

    return $tokens[$complexity] ?? 14000;
}


/**
 * Clean JSON response from API
 */
function cleanJsonResponse($text) {
    $text = trim($text);

    // Remove markdown code blocks
    $text = preg_replace('/```json\s*/i', '', $text);
    $text = preg_replace('/```\s*$/i', '', $text);
    $text = preg_replace('/^```/i', '', $text);

    // Extract from first { to last }
    $start = strpos($text, '{');
    $end   = strrpos($text, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $text = substr($text, $start, $end - $start + 1);
    } elseif ($start !== false) {
        $text = repairTruncatedJson(substr($text, $start));
    }

    // Fix unescaped control characters inside JSON string values
    $text = fixUnescapedJsonStrings($text);

    return trim($text);
}

/**
 * Fix unescaped newlines, tabs, and bare quotes inside JSON string values.
 * Walks the raw text char-by-char to only touch content inside strings.
 */
function fixUnescapedJsonStrings(string $json): string {
    $out    = '';
    $len    = strlen($json);
    $inStr  = false;
    $escape = false;

    for ($i = 0; $i < $len; $i++) {
        $c = $json[$i];

        if ($escape) {
            $out   .= $c;
            $escape = false;
            continue;
        }

        if ($c === '\\') {
            $out   .= $c;
            $escape = true;
            continue;
        }

        if ($c === '"') {
            $inStr = !$inStr;
            $out  .= $c;
            continue;
        }

        if ($inStr) {
            // Escape bare control characters that break JSON
            if ($c === "\n")      { $out .= '\\n';  continue; }
            if ($c === "\r")      { $out .= '\\r';  continue; }
            if ($c === "\t")      { $out .= '\\t';  continue; }
            if (ord($c) < 0x20)  { $out .= '\\u' . sprintf('%04x', ord($c)); continue; }
        }

        $out .= $c;
    }

    return $out;
}

/**
 * Attempt to close a truncated JSON string by balancing braces/brackets/quotes.
 */
function repairTruncatedJson(string $json): string {
    // Remove trailing incomplete string value or key
    $json = rtrim($json);
    // Strip trailing comma before closing
    $json = preg_replace('/,\s*$/', '', $json);

    // Count open braces and brackets to close them
    $depth   = [];
    $inStr   = false;
    $escape  = false;
    $len     = strlen($json);

    for ($i = 0; $i < $len; $i++) {
        $c = $json[$i];
        if ($escape)           { $escape = false; continue; }
        if ($c === '\\')       { $escape = true;  continue; }
        if ($c === '"')        { $inStr = !$inStr; continue; }
        if ($inStr)            { continue; }
        if ($c === '{')        { $depth[] = '}'; }
        elseif ($c === '[')    { $depth[] = ']'; }
        elseif ($c === '}' || $c === ']') { array_pop($depth); }
    }

    // If we ended mid-string, close it
    if ($inStr) $json .= '"';

    // Strip any trailing partial key/value that would break JSON
    $json = preg_replace('/,?\s*"[^"]*$/', '', $json);
    $json = preg_replace('/,\s*$/', '', $json);

    // Close all open structures
    while (!empty($depth)) {
        $json .= array_pop($depth);
    }

    return $json;
}

/**
 * Add subtle human imperfections to content
 */
function addHumanImperfections($postData) {
    // Randomly vary some minor things
    if (isset($postData['structured_content'])) {
        foreach ($postData['structured_content'] as &$section) {
            if (isset($section['content'])) {
                // Occasionally add casual starting phrases
                if (rand(1, 100) > 75) {
                    $casualStarts = [
                        "Here's the thing - ",
                        "Honestly, ",
                        "Quick note: ",
                        "One thing I love - "
                    ];
                    $randomStart = $casualStarts[array_rand($casualStarts)];
                    
                    // Add to a random paragraph
                    if (strpos($section['content'], '<p>') !== false) {
                        $section['content'] = preg_replace(
                            '/<p>/', 
                            '<p>' . $randomStart, 
                            $section['content'], 
                            1
                        );
                    }
                }
            }
        }
    }
    
    return $postData;
}


function generatepostFromTextRewrite($sourceText, $categoryHint = '', $boardName = '') {
    // Parse categories
    $categoriesArray = json_decode($categoryHint, true);
    $categoriesList = '';
    if ($categoriesArray && is_array($categoriesArray)) {
        foreach ($categoriesArray as $key => $catId) {
            $categoriesList .= "- " . $key . " (ID: " . $catId . ")\n";
        }
    }
    
    // Randomly select voice for variety
    $voices = ['Honest Cook', 'Enthusiastic Friend', 'Practical Expert', 'Storyteller'];
    $selectedVoice = $voices[array_rand($voices)];
    
    // Detect complexity (used for style/temperature only, NOT for limiting word count)
    $wordCount = str_word_count($sourceText);
    if ($wordCount < 100) {
        $complexity = 'simple';
        $targetWords = '1800-2200'; // Always rich — short source text still needs full detail
        $maxTokens = 10000;
    } elseif ($wordCount > 250) {
        $complexity = 'complex';
        $targetWords = '2200-2800';
        $maxTokens = 14000;
    } else {
        $complexity = 'medium';
        $targetWords = '2000-2500';
        $maxTokens = 12000;
    }
    
    // Build prompt with variations
    $fullPrompt = REWRITE_POST_PROMPT;
    
    // Inject voice selection
    $fullPrompt = str_replace(
        'MANDATORY VOICE SELECTION (Pick ONE randomly):',
        "🎯 YOU MUST USE THIS VOICE: **{$selectedVoice}**\n\nStick to this voice throughout the entire post.\n\n",
        $fullPrompt
    );
    
    // Inject word count target
    $fullPrompt = str_replace(
        'TOTAL TARGET: minimum 1400-1800 words',
        "TOTAL TARGET: {$targetWords} words (complexity: {$complexity})",
        $fullPrompt
    );
    
    // Add category selection
    $fullPrompt .= "\n\n---\n\n";

    // Inject existing Pinterest boards so AI reuses them instead of inventing new ones
    $boardsSection = buildBoardsPromptSection();
    if ($boardsSection !== '') {
        $fullPrompt .= $boardsSection . "\n\n---\n\n";
    }

    $fullPrompt .= "CATEGORY SELECTION:\n";
    $fullPrompt .= "Choose the MOST appropriate category:\n\n";
    $fullPrompt .= $categoriesList;
    $fullPrompt .= "\nAdd 'category_id' field with exact ID.\n\n";
    if (!empty($boardName)) {
        $fullPrompt .= "PINTEREST BOARD CONTEXT:\n";
        $fullPrompt .= "This post is for the Pinterest board: \"{$boardName}\"\n";
        $fullPrompt .= "Make sure the title, description, and hashtags align with this board's theme.\n\n";
    }
    $fullPrompt .= "Source Text:\n{$sourceText}";
    
    // Varied temperature for natural variety
    switch ($complexity) {
        case 'simple':
            $temperature = 0.7;
            break;
        case 'complex':
            $temperature = 0.5;
            break;
        default:
            $temperature = 0.6;
            break;
    }
    
    $systemPrompt = "You are a professional food blogger writing authentic, human-like post content. You MUST return ONLY valid JSON with no markdown, no explanations, no text before or after. Write naturally like texting a friend, not like AI.";

    $apiResult = callGenerationAPI($systemPrompt, $fullPrompt, $maxTokens, $temperature);
    if (isset($apiResult['error'])) {
        return ['error' => $apiResult['error']];
    }

    $postText = cleanJsonResponse($apiResult['text']);
    
    $postData = json_decode($postText, true);
    
    // Fallback JSON extraction
    if (!$postData) {
        if (preg_match('/\{[\s\S]*\}/s', $postText, $matches)) {
            $postData = json_decode($matches[0], true);
        }
        
        if (!$postData) {
            return ['error' => 'Invalid JSON: ' . json_last_error_msg()];
        }
    }
    
    if (!isset($postData['title'])) {
        return ['error' => 'Missing title in generated post'];
    }

    // Sync any new board names generated by AI into the master list
    if (!empty($postData['pinterest_boards']) && is_array($postData['pinterest_boards'])) {
        syncNewBoardsToMasterList($postData['pinterest_boards']);
    }

    // Add metadata
    $postData['generation_metadata'] = [
        'voice' => $selectedVoice,
        'complexity' => $complexity,
        'target_words' => $targetWords,
        'temperature' => $temperature,
        'generated_at' => date('Y-m-d H:i:s')
    ];
    
    // Set author
    $firstActiveAuthor = getFirstActiveAuthor();
    if ($firstActiveAuthor) {
        $postData['author_id'] = $firstActiveAuthor;
    }
    
    // Validate against banned words
    $postData = validateAndCleanContent($postData);
    
    return $postData;
}

/**
 * Validate content and remove AI-detection red flags
 */
function validateAndCleanContent($postData) {
    $bannedWords = [
        'indulge', 'decadent', 'delightful', 'delectable', 'divine', 
        'exquisite', 'luscious', 'sumptuous', 'heavenly', 'elegant',
        'showstopper', 'crowd-pleaser'
    ];
    
    $replacements = [
        'indulge' => 'try',
        'decadent' => 'rich',
        'delightful' => 'tasty',
        'showstopper' => 'impressive',
        'crowd-pleaser' => 'popular'
    ];
    
    // Check structured content
    if (isset($postData['structured_content'])) {
        foreach ($postData['structured_content'] as &$section) {
            if (isset($section['content'])) {
                foreach ($bannedWords as $banned) {
                    if (stripos($section['content'], $banned) !== false) {
                        $replacement = $replacements[$banned] ?? 'good';
                        $section['content'] = str_ireplace($banned, $replacement, $section['content']);
                    }
                }
            }
        }
    }
    
    return $postData;
}


/**
 * Validate post content for AdSense compliance
 */
function validatepostForAdSense($postData) {
    $issues = [];
    $warnings = [];
    $score = 100;
    
    // Convert all content to string for checking
    $allContent = json_encode($postData);
    $allContentLower = strtolower($allContent);
    
    // 1. CHECK BANNED WORDS (Critical -10 points each)
    $bannedWords = [
        'indulge', 'delightful', 'delectable', 'divine', 'exquisite',
        'luscious', 'sumptuous', 'heavenly', 'elegant',
        'showstopper', 'crowd-pleaser', 'game-changer', 'elevate'
    ];
    
    foreach ($bannedWords as $banned) {
        if (stripos($allContent, $banned) !== false) {
            $issues[] = "❌ CRITICAL: Found banned word '$banned'";
            $score -= 10;
        }
    }
    
    // 2. CHECK BANNED PHRASES (Critical -15 points each)
    $bannedPhrases = [
        'perfect for',
        'sure to impress',
        'sure to be a hit',
        'elevate your',
        'indulge in this'
    ];
    
    foreach ($bannedPhrases as $phrase) {
        if (stripos($allContentLower, $phrase) !== false) {
            $issues[] = "❌ CRITICAL: Found banned phrase '$phrase'";
            $score -= 15;
        }
    }
    
    // 3. CHECK CASUAL LANGUAGE (-15 if missing)
    $casualWords = ['gonna', 'wanna', 'kinda', 'sorta', 'ok so', 'real talk', 'honestly', 'seriously'];
    $casualCount = 0;
    foreach ($casualWords as $word) {
        $casualCount += substr_count($allContentLower, strtolower($word));
    }
    
    if ($casualCount < 5) {
        $warnings[] = "⚠️ Only $casualCount casual words (need 5+)";
        $score -= 15;
    }
    
    // 4. CHECK FOR QUOTES IN STORY (-10 if missing)
    $hasQuotes = preg_match('/[""\']\w+/', $allContent);
    if (!$hasQuotes) {
        $warnings[] = "⚠️ No direct quotes found in story";
        $score -= 10;
    }
    
    // 5. CHECK FOR FAILURE STORY (-20 if missing)
    $hasFailureStory = stripos($allContent, 'learned the hard way') !== false ||
                       stripos($allContent, 'disaster') !== false ||
                       stripos($allContent, 'first time') !== false;
    
    if (!$hasFailureStory) {
        $issues[] = "❌ CRITICAL: Missing failure story";
        $score -= 20;
    }
    
    // 6. CHECK FOR BRAND MENTIONS (-10 if missing)
    $brands = ['kerrygold', 'kitchenaid', 'bob\'s red mill', 'tillamook', 
               'land o\'lakes', 'organic valley', 'ghirardelli', 'king arthur'];
    $brandCount = 0;
    foreach ($brands as $brand) {
        if (stripos($allContentLower, $brand) !== false) {
            $brandCount++;
        }
    }
    
    if ($brandCount < 2) {
        $warnings[] = "⚠️ Only $brandCount brands mentioned (need 2+)";
        $score -= 10;
    }
    
    // 7. CHECK FOR SPECIFIC DETAILS IN STORY
    $hasSpecificDate = preg_match('/(january|february|march|april|may|june|july|august|september|october|november|december)\s+\d+/i', $allContent) ||
                       preg_match('/\d+\s+(years?|months?|weeks?)\s+ago/i', $allContent);
    
    if (!$hasSpecificDate) {
        $warnings[] = "⚠️ Story lacks specific date";
        $score -= 5;
    }
    
    // Determine status
    $status = 'REJECTED';
    $statusEmoji = '❌';
    
    if ($score >= 90) {
        $status = 'EXCELLENT';
        $statusEmoji = '🎯';
    } elseif ($score >= 80) {
        $status = 'GOOD';
        $statusEmoji = '✅';
    } elseif ($score >= 70) {
        $status = 'ACCEPTABLE';
        $statusEmoji = '⚠️';
    }
    
    return [
        'score' => max(0, $score),
        'status' => $status,
        'emoji' => $statusEmoji,
        'ready_for_adsense' => $score >= 80,
        'issues' => $issues,
        'warnings' => $warnings,
        'casual_count' => $casualCount,
        'brand_count' => $brandCount,
        'has_quotes' => $hasQuotes,
        'has_failure_story' => $hasFailureStory
    ];
}

/**
 * Display validation results
 */
function displayValidationResults($validation) {
    echo "\n";
    echo "═══════════════════════════════════════\n";
    echo "{$validation['emoji']} AdSense Score: {$validation['score']}/100\n";
    echo "Status: {$validation['status']}\n";
    echo "═══════════════════════════════════════\n";
    
    if (!empty($validation['issues'])) {
        echo "\n🚨 CRITICAL ISSUES:\n";
        foreach ($validation['issues'] as $issue) {
            echo "  $issue\n";
        }
    }
    
    if (!empty($validation['warnings'])) {
        echo "\n⚠️ WARNINGS:\n";
        foreach ($validation['warnings'] as $warning) {
            echo "  $warning\n";
        }
    }
    
    echo "\n📊 DETAILS:\n";
    echo "  Casual language: {$validation['casual_count']} instances\n";
    echo "  Brand mentions: {$validation['brand_count']}\n";
    echo "  Has quotes: " . ($validation['has_quotes'] ? '✓' : '✗') . "\n";
    echo "  Has failure story: " . ($validation['has_failure_story'] ? '✓' : '✗') . "\n";
    
    echo "\n";
    if ($validation['ready_for_adsense']) {
        echo "🎯 READY FOR ADSENSE!\n";
    } else {
        echo "❌ FIX ISSUES BEFORE PUBLISHING\n";
    }
    echo "═══════════════════════════════════════\n\n";
}


// function generatepostFromTextRewrite($sourceText, $categoryHint = '') {
//     $openaiApiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
    
//     if (empty($openaiApiKey)) {
//         return ['error' => 'Clé API OpenAI requise'];
//     }
    
//     $categoriesArray = json_decode($categoryHint, true);
//     $categoriesList = '';
//     if ($categoriesArray && is_array($categoriesArray)) {
//         foreach ($categoriesArray as $key => $catId) {
//             $categoriesList .= "- " . $key . " (ID: " . $catId . ")\n";
//         }
//     }
    
//    // Créer le prompt complet
//     $fullPrompt = REWRITE_POST_PROMPT;
//     $fullPrompt .=  "\n\n---\n\n";
//     $fullPrompt .= "ÉTAPE SUPPLÉMENTAIRE - Sélection de catégorie:\n";
//     $fullPrompt .= "Parmi ces catégories disponibles, choisis LA PLUS APPROPRIÉE:\n\n";
//     $fullPrompt .= $categoriesList;
//     $fullPrompt .= "\nAjoute un champ 'category_id' dans ton JSON avec l'ID exact de la catégorie choisie.\n\n";
//     $fullPrompt .= "Source Text:\n{$sourceText}";
    
//     $data = [
//         "model" => "gpt-4o-mini",
//         "messages" => [
//             [
//                 "role" => "system",
//                 "content" => "You are a professional post analyzer. You MUST return ONLY valid JSON with no markdown, no explanations, no text before or after. The JSON must include all required fields including 'category_id'."
//             ],
//             [
//                 "role" => "user",
//                 "content" => $fullPrompt
//             ]
//         ],
//         "max_tokens" => 3000,
//         "temperature" => 0.3
//     ];
    
//     $ch = curl_init('https://api.openai.com/v1/chat/completions');
//     curl_setopt_array($ch, [
//         CURLOPT_POST => true,
//         CURLOPT_POSTFIELDS => json_encode($data),
//         CURLOPT_HTTPHEADER => [
//             'Content-Type: application/json',
//             'Authorization: Bearer ' . $openaiApiKey
//         ],
//         CURLOPT_RETURNTRANSFER => true,
//         CURLOPT_TIMEOUT => 90
//     ]);
    
//     $response = curl_exec($ch);
//     $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
//     curl_close($ch);
    
//     if ($httpCode !== 200) {
//         $errorData = json_decode($response, true);
//         $errorMsg = $errorData['error']['message'] ?? "Code HTTP $httpCode";
//         return ['error' => "API OpenAI: $errorMsg"];
//     }
    
//     $responseData = json_decode($response, true);
    
//     if (!$responseData || !isset($responseData['choices'][0]['message']['content'])) {
//         return ['error' => 'Réponse API invalide'];
//     }
    
//     $postText = trim($responseData['choices'][0]['message']['content']);
//     $postText = preg_replace('/```json\s*/', '', $postText);
//     $postText = preg_replace('/```\s*$/', '', $postText);
//     $postText = preg_replace('/^```/', '', $postText);
//     $postText = trim($postText);
    
//     $postData = json_decode($postText, true);
    
//     if (!$postData) {
//         if (preg_match('/\{[\s\S]*\}/', $postText, $matches)) {
//             $postData = json_decode($matches[0], true);
//         }
        
//         if (!$postData) {
//             return ['error' => 'JSON invalide: ' . json_last_error_msg()];
//         }
//     }
    
//     if (!isset($postData['title'])) {
//         return ['error' => 'Titre manquant dans le post généré'];
//     }
    
//     $firstActiveAuthor = getFirstActiveAuthor();
//     if ($firstActiveAuthor) {
//         $postData['author_id'] = $firstActiveAuthor;
//     }
    
//     return $postData;
// }

// function rewritepostFromText($sourceText, $categoryHint = '') {
//     $openaiApiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
    
//     if (empty($openaiApiKey)) {
//         return ['error' => 'Clé API OpenAI requise'];
//     }
    
//     $categoriesArray = json_decode($categoryHint, true);
//     $categoriesList = '';
//     if ($categoriesArray && is_array($categoriesArray)) {
//         foreach ($categoriesArray as $key => $catId) {
//             $categoriesList .= "- " . $key . " (ID: " . $catId . ")\n";
//         }
//     }
    
//     // Créer le prompt complet
//     $fullPrompt = REWRITE_POST_PROMPT;
//     $fullPrompt .= "\n\n---\n\n";
//     $fullPrompt .= "ÉTAPE SUPPLÉMENTAIRE - Sélection de catégorie:\n";
//     $fullPrompt .= "Parmi ces catégories disponibles, choisis LA PLUS APPROPRIÉE:\n\n";
//     $fullPrompt .= $categoriesList;
//     $fullPrompt .= "\nAjoute un champ 'category_id' dans ton JSON avec l'ID exact de la catégorie choisie.\n\n";
//     $fullPrompt .= "Source Text:\n{$sourceText}";
    
//     $data = [
//         "model" => "gpt-4o-mini",
//         "messages" => [
//             [
//                 "role" => "system",
//                 "content" => "You are a professional post analyzer. You MUST return ONLY valid JSON with no markdown, no explanations, no text before or after. The JSON must include all required fields including 'category_id'."
//             ],
//             [
//                 "role" => "user",
//                 "content" => $fullPrompt
//             ]
//         ],
//         "max_tokens" => 3000,
//         "temperature" => 0.3
//     ];
    
//     $ch = curl_init('https://api.openai.com/v1/chat/completions');
//     curl_setopt_array($ch, [
//         CURLOPT_POST => true,
//         CURLOPT_POSTFIELDS => json_encode($data),
//         CURLOPT_HTTPHEADER => [
//             'Content-Type: application/json',
//             'Authorization: Bearer ' . $openaiApiKey
//         ],
//         CURLOPT_RETURNTRANSFER => true,
//         CURLOPT_TIMEOUT => 90
//     ]);
    
//     $response = curl_exec($ch);
//     $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
//     curl_close($ch);
    
//     if ($httpCode !== 200) {
//         $errorData = json_decode($response, true);
//         $errorMsg = $errorData['error']['message'] ?? "Code HTTP $httpCode";
//         return ['error' => "API OpenAI: $errorMsg"];
//     }
    
//     $responseData = json_decode($response, true);
    
//     if (!$responseData || !isset($responseData['choices'][0]['message']['content'])) {
//         return ['error' => 'Réponse API invalide'];
//     }
    
//     $postText = trim($responseData['choices'][0]['message']['content']);
//     $postText = preg_replace('/```json\s*/', '', $postText);
//     $postText = preg_replace('/```\s*$/', '', $postText);
//     $postText = preg_replace('/^```/', '', $postText);
//     $postText = trim($postText);
    
//     $postData = json_decode($postText, true);
    
//     if (!$postData) {
//         if (preg_match('/\{[\s\S]*\}/', $postText, $matches)) {
//             $postData = json_decode($matches[0], true);
//         }
        
//         if (!$postData) {
//             return ['error' => 'JSON invalide: ' . json_last_error_msg()];
//         }
//     }
    
//     if (!isset($postData['title'])) {
//         return ['error' => 'Titre manquant dans le post généré'];
//     }
    
//     return $postData;
// }

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
    // imagefilter($var2, IMG_FILTER_CONTRAST, -5);
    // imagefilter($var2, IMG_FILTER_COLORIZE, 10, 5, -5);
    
    // Save over original
    $success = imagewebp($var2, $originalImagePath, 85);
    // compressWebP($var2,$originalImagePath,80);
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


function getpost($postPath) {
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
    
    $GLOBALS['_pipeline_text_cost'] = 0.0;
    $postData = generatepostFromText($input['source_text'], $categoriesName);
    $textCost = $GLOBALS['_pipeline_text_cost'] ?? 0.0;

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
        'data'    => $postData,
        'cost'    => $textCost,
    ]);
    exit;
}

// 3. POST /api?action=generate-html - Generate HTML page for a post
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'generate-html') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['slug']) || empty(trim($input['slug']))) {
        echo json_encode([
            'success' => false,
            'error' => 'Le champ slug est requis'
        ]);
        exit;
    }

    $slug = trim($input['slug']);

    // Call the HTML generation function
    $result = generatepostHtmlPage($slug);

    echo json_encode($result);
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
    
    echo "📂 post directory: {$postDir}\n";
    echo "🖼️  Images directory: {$imagesDir}\n\n";
    
    // Charger le post existante
    $post = getpost($postPath);
    if (!$post) {
        echo json_encode([
            'success' => false,
            'error' => 'Erreur lecture post.json'
        ]);
        exit;
    }
    
    echo "📖 post loaded: " . ($post['title'] ?? 'Sans titre') . "\n\n";
    
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
    $sourceText = $post['description'];

    if (empty($sourceText)) {
        echo json_encode([
            'success' => false,
            'error' => 'Pas de contenu à rewriter (description ou content manquant)'
        ]);
        exit;
    }

    // Board name: from GET param first, then from existing post.json
    $boardName = trim($_GET['board_name'] ?? $post['board_name'] ?? '');

    $newpostData = generatepostFromTextRewrite($sourceText, $categoriesName, $boardName);
    if (isset($newpostData['error'])) {
        echo json_encode([
            'success' => false,
            'error' => $newpostData['error']
        ]);
        exit;
    }
    
    echo "✅ post rewritten by AI\n\n";    
    
    // // ✅ MAPPER LES UPLOADS AVEC LES VRAIES IMAGES
    // if (isset($newpostData['structured_content']) && is_array($newpostData['structured_content'])) {
    //     echo "🔗 Mapping upload objects to actual images...\n";
    //     $newpostData['structured_content'] = mapUploadsToImages(
    //         $newpostData['structured_content'], 
    //         $updatedImages
    //     );
    //     echo "✅ Upload objects mapped successfully\n\n";




    // }
    
    // Garder les infos importantes
    $newpostData['images'] = $updatedImages;
    $newpostData['slug'] = $slug;
    
    // Garder ou créer ID
    if (isset($post['id'])) {
        $newpostData['id'] = $post['id'];
    } else {
        $newpostData['id'] = 'text_post_' . time() . '_' . rand(100, 999);
    }
    
    // Garder author_id
    if (isset($post['author_id'])) {
        $newpostData['author_id'] = $post['author_id'];
    } else {
        $newpostData['author_id'] = 'author_001';
    }

    // Garder/mettre à jour board_name (toujours inclus dans post.json)
    // Priority: 1) URL param/CSV, 2) AI-generated from prompt, 3) existing post.json
    $newpostData['board_name'] = $boardName ?: ($newpostData['board_name'] ?: ($post['board_name'] ?? ''));
    
    // Image principale
    if (!empty($updatedImages)) {
        $newpostData['image'] = $updatedImages[0]['fileName'];
        $newpostData['image_path'] = "posts/".$updatedImages[0]['relativePath'];
        $newpostData['image_dir'] = $slug . '/images';
    }
    
    // Flags
    $newpostData['generated_from_text'] = true;
    $newpostData['has_rich_structure'] = true;
    $newpostData['isOnline'] = false;
    
    // Garder created_at si existe
    if (isset($post['createdAt'])) {
        $newpostData['createdAt'] = $post['createdAt'];
    } else {
        $newpostData['createdAt'] = date('Y-m-d\TH:i:sP');
    }
    
    // Updated date
    $newpostData['updatedAt'] = date('Y-m-d\TH:i:sP');

    // Rating seeded (déterministe par slug) — cache pour le schema AggregateRating.
    // Préserver le rating existant si déjà présent (votes visiteurs accumulés).
    if (isset($post['rating']['value'], $post['rating']['count'])) {
        $newpostData['rating'] = $post['rating'];
    } else {
        $newpostData['rating'] = rating_seed($slug);
    }

    // Régénérer pin_variations avec le nouveau titre/description
    $pinVariations = generatePinVariations($newpostData['title'], $newpostData['description'] ?? '');
    if ($pinVariations) {
        $newpostData['pin_variations'] = $pinVariations;
        // Stocker pin_hooks (titres seuls) pour utilisation dans le bouton Pinterest JS
        $newpostData['pin_hooks'] = array_column($pinVariations, 'title');
    } elseif (isset($post['pin_variations'])) {
        $newpostData['pin_variations'] = $post['pin_variations']; // fallback
        $newpostData['pin_hooks'] = $post['pin_hooks'] ?? array_column($post['pin_variations'], 'title');
    }

    // Récupérer les filepaths des images (sans le type "template")
    $imagePaths = [];
    if (isset($newpostData['images']) && is_array($newpostData['images'])) {
        foreach ($newpostData['images'] as $image) {
            // Exclure les images de type "template"
            if (isset($image['relativePath']) && (!isset($image['type']) || $image['type'] !== 'template')) {
                $imagePaths[] = $image['relativePath'];
            }
        }
    }

    // Index pour parcourir les images
    $imageIndex = 0;

    // Parcourir structured_content et remplacer les URLs des uploads
    if (isset($newpostData['structured_content']) && is_array($newpostData['structured_content'])) {
        foreach ($newpostData['structured_content'] as $key => &$content) {
            // Vérifier si c'est un upload
            if (isset($content['upload']) && isset($content['upload']['url'])) {
                // Remplacer l'URL par le filepath de l'image correspondante
                if ($imageIndex < count($imagePaths)) {
                    $newpostData['structured_content'][$key]['upload']['url'] = $imagePaths[$imageIndex];
                    $imageIndex++;
                }
            }
        }
        unset($content); // Libérer la référence
    }


    // Save JSON
    $jsonOptions = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $jsonContent = json_encode($newpostData, $jsonOptions);
    
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
    
    echo "💾 post saved successfully\n\n";
    
    // ✅ GÉNÉRATIONS AUTOMATIQUES APRÈS SAUVEGARDE (comme dans savepostWithImages)
    echo "🔄 Generating automatic files...\n";
    
    $postsDir = __DIR__ . '/posts';
    $categoriesDir = __DIR__ . '/categories';
    
    $generationResults = [];
    
    // // Generate posts index
    // if (function_exists('generatepostsIndex')) {
    //     $indexResult = generatepostsIndex($postsDir);
    //     $generationResults['index_generated'] = $indexResult['success'] ?? false;
    //     echo ($generationResults['index_generated'] ? "✅" : "❌") . " posts index\n";
    // }
    
   
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
    //     'data' => $newpostData,
    //     'stats' => [
    //         'images_processed' => $processedCount,
    //         'total_images' => count($updatedImages),
    //         'slug' => $slug
    //     ],
    //     'generations' => $generationResults
    // ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
   
        echo "\n✅ Rewrite completed!\n\n";

        header('Location: generate-post.php?slug=' . urlencode($slug));
        exit;
    
}


if (isset($_GET['action']) && $_GET['action'] === 'generate_template') {
    ignore_user_abort(true); // continue even if caller disconnects (fire & forget)
    set_time_limit(600);

    $slug = $_GET['uniqueSlug'];

    // Load post.json
    $postJsonPath = __DIR__ . '/posts/' . $slug . '/post.json';
    $post = file_exists($postJsonPath) ? json_decode(file_get_contents($postJsonPath), true) : null;

    // Read source images (non-template) from post.json — skip black/empty images
    $allSourceImages = array_values(array_filter(
        $post['images'] ?? [],
        fn($i) => ($i['type'] ?? '') !== 'template'
    ));

    // Filtrer les images noires/invalides (taille < 10KB = probablement noire)
    $sourceImages = array_values(array_filter($allSourceImages, function($img) {
        $localPath = __DIR__ . '/' . ltrim($img['filePath'] ?? '', '/');
        if (!file_exists($localPath)) return false;
        if (filesize($localPath) < 10240) return false; // < 10KB = image noire/vide
        return true;
    }));

    if (count($sourceImages) === 0) {
        echo json_encode(['success' => false, 'message' => 'No valid source images found for this post.']);
        exit;
    }

    // Si moins de 3 images disponibles, on recycle les existantes pour remplir les slots
    $origCount = count($sourceImages);
    while (count($sourceImages) < 3) {
        $sourceImages[] = $sourceImages[count($sourceImages) % $origCount];
    }

    $image1Path = __DIR__ . '/' . ltrim($sourceImages[0]['filePath'], '/');
    $image2Path = __DIR__ . '/' . ltrim($sourceImages[1]['filePath'], '/');
    $image3Path = __DIR__ . '/' . ltrim($sourceImages[2]['filePath'], '/');

    // ── Supprimer les anciens templates (fichiers + post.json) ───────────────
    if ($post) {
        $post['images'] = deletePostTemplates($slug, $post['images'] ?? [], __DIR__);
        file_put_contents($postJsonPath, json_encode($post, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    // ─────────────────────────────────────────────────────────────────────────

    // Use pin_variations if available, fallback to post title
    $fallbackTitle = $post['title'] ?? $slug;
    $v = (isset($post['pin_variations']) && count($post['pin_variations']) >= 4)
        ? $post['pin_variations']
        : [
            ['title' => $fallbackTitle, 'description' => ''],
            ['title' => $fallbackTitle, 'description' => ''],
            ['title' => $fallbackTitle, 'description' => ''],
            ['title' => $fallbackTitle, 'description' => ''],
        ];

    // Tableau de toutes les combinaisons — all use the active template
    $activeTemplate = ACTIVE_TEMPLATE;
    $templates = [
        [
            'image1'     => $image1Path,
            'image2'     => $image2Path,
            'title'      => $v[0]['title'],
            'uniqueSlug' => $slug,
            'folder'     => 'posts',
            'index'      => 4,
            'template'   => $activeTemplate,
        ],
        [
            'image1'     => $image1Path,
            'image2'     => $image3Path,
            'title'      => $v[1]['title'],
            'uniqueSlug' => $slug,
            'folder'     => 'posts',
            'index'      => 5,
            'template'   => $activeTemplate,
        ],
        [
            'image1'     => $image2Path,
            'image2'     => $image1Path,
            'title'      => $v[2]['title'],
            'uniqueSlug' => $slug,
            'folder'     => 'posts',
            'index'      => 6,
            'template'   => $activeTemplate,
        ],
        [
            'image1'     => $image2Path,
            'image2'     => $image3Path,
            'title'      => $v[3]['title'],
            'uniqueSlug' => $slug,
            'folder'     => 'posts',
            'index'      => 7,
            'template'   => $activeTemplate,
        ],
        // [
        //     'image1' => $image3Path,
        //     'image2' => $image1Path,
        //     'title' => $_GET['title'],
        //     'uniqueSlug' => $_GET['uniqueSlug'],
        //     'bannerColor' => $_GET['bannerColor'] ?? null,
        //     'textColor' => $_GET['textColor'] ?? null,          
        //     'index' => 5
        // ],
        // [
        //     'image1' => $image3Path,
        //     'image2' => $image2Path,
        //     'title' => $_GET['title'],
        //     'uniqueSlug' => $_GET['uniqueSlug'],
        //     'bannerColor' => $_GET['bannerColor'] ?? null,
        //     'textColor' => $_GET['textColor'] ?? null,
        //     'index' => 6
        // ]
    ];

    // ── Extra templates recipe_card (8) + overlay_list (9) — couleurs propres, sans link ─────
    $ingredientsJson = json_encode($post['ingredients'] ?? [], JSON_UNESCAPED_UNICODE);
    $templates[] = [
        'image1'      => $image1Path,
        'title'       => $v[0]['title'],
        'uniqueSlug'  => $slug,
        'folder'      => 'posts',
        'index'       => 8,
        'template'    => 'recipe_card',
        'no_inherit'  => '1',
        'ingredients' => $ingredientsJson,
    ];
    $templates[] = [
        'image1'      => $image1Path,
        'title'       => $v[0]['title'],
        'uniqueSlug'  => $slug,
        'folder'      => 'posts',
        'index'       => 9,
        'template'    => 'overlay_list',
        'no_inherit'  => '1',
        'ingredients' => $ingredientsJson,
    ];
    // ─────────────────────────────────────────────────────────────────────────

    // Génération de tous les templates
    // Déterminer le prochain order
    $maxOrder = 0;
    if ($post && isset($post['images'])) {
        foreach ($post['images'] as $img) {
            if (isset($img['order']) && $img['order'] > $maxOrder) $maxOrder = $img['order'];
        }
    }

    foreach ($templates as $templateData) {
        try {
            $ch = curl_init(BASE_URL . 'generate_pinterest.php');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $templateData,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_NOSIGNAL       => 1,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            ]);
            $templateResponse = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                $templateResult = json_decode($templateResponse, true);
                if ($post && isset($templateResult['success']) && $templateResult['success']) {
                    $maxOrder++;
                    $post['images'][] = [
                        'fileName'     => $templateResult['filename'],
                        'filePath'     => $templateResult['path'],
                        'relativePath' => $templateResult['pathrelative'],
                        'originalUrl'  => $templateResult['url'],
                        'order'        => $maxOrder,
                        'type'         => in_array($templateData['template'] ?? '', ['recipe_card', 'overlay_list'])
                                            ? $templateData['template']
                                            : 'template',
                        'template'     => $templateData['template'] ?? 'classic',
                    ];
                }
            } else {
                throw new Exception("Erreur HTTP: $httpCode curlErr: $curlError");
            }
        } catch (Exception $e) {
            error_log('❌ Erreur template ' . $templateData['index'] . ': ' . $e->getMessage());
        }
    }

    // Sauvegarder post.json avec les nouveaux templates
    if ($post) {
        file_put_contents($postJsonPath, json_encode($post, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    header('Location: posts-liste.php');
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
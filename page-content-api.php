<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$action          = $_GET['action'] ?? ($_POST['action'] ?? '');
$pageContentFile = __DIR__ . '/pages/page-content.json';

// ── GET: return current page-content.json ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' || $action === 'get') {
    if (file_exists($pageContentFile)) {
        echo file_get_contents($pageContentFile);
    } else {
        echo json_encode(['error' => 'page-content.json not found']);
    }
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $action ?: ($input['action'] ?? '');

// ── POST action=save ──────────────────────────────────────────────────────────
if ($action === 'save') {
    $data = $input['data'] ?? null;
    if (!$data || !is_array($data)) {
        echo json_encode(['success' => false, 'message' => 'Données manquantes']);
        exit;
    }
    $data['generated_at'] = date('Y-m-d');
    file_put_contents($pageContentFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    echo json_encode(['success' => true, 'message' => 'Contenu sauvegardé']);
    exit;
}

// ── POST action=generate ─────────────────────────────────────────────────────
if ($action === 'generate') {
    $niche      = NICHE ?: 'general';
    $siteName   = HOMEPAGE_TITLE;
    $tagline    = HOMEPAGE_TAGLINE;
    $apiKey     = OPENAI_API_KEY;
    $model      = OPENAI_CONTENT_MODEL;
    $todayDate  = date('F j, Y');

    $prompt = <<<PROMPT
You are generating website page content for a "{$niche}" niche blog called "{$siteName}" with tagline: "{$tagline}".

Generate content for 4 static pages: home, about, contact, privacy.
The tone should be professional, friendly, and tailored to the {$niche} audience.
Use {{SITE_NAME}} as a placeholder where the site name appears, and {{SITE_HOST}} for the domain.

Return ONLY valid JSON with this exact structure (no markdown, no explanation):
{
  "niche": "{$niche}",
  "home": {
    "hero_tagline": "short tagline phrase (5-8 words, ALL CAPS style)",
    "welcome_text": "2-3 sentence welcome paragraph mentioning {{SITE_NAME}}"
  },
  "about": {
    "hero_subtitle": "one sentence describing the site mission",
    "explore_title": "section title (e.g. What Can You Explore Here?)",
    "explore_intro": "2 sentences about what the site offers",
    "explore_items": [
      {"bold": "Feature:", "text": "description"},
      {"bold": "Feature:", "text": "description"},
      {"bold": "Feature:", "text": "description"},
      {"bold": "Feature:", "text": "description"},
      {"bold": "Feature:", "text": "description"}
    ],
    "founder_section_title": "section title (e.g. Who Am I?)",
    "founder_intro": "2-3 sentences about the founder's passion for this niche",
    "founder_items": [
      {"bold": "Quality:", "text": "description"},
      {"bold": "Quality:", "text": "description"},
      {"bold": "Quality:", "text": "description"},
      {"bold": "Quality:", "text": "description"}
    ],
    "goals_title": "section title (e.g. What Are My Goals?)",
    "goals_intro": "1-2 sentences intro to goals",
    "goals_items": [
      {"bold": "Goal:", "text": "description"},
      {"bold": "Goal:", "text": "description"},
      {"bold": "Goal:", "text": "description"},
      {"bold": "Goal:", "text": "description"},
      {"bold": "Goal:", "text": "description"}
    ],
    "selection_title": "section title (e.g. How Do I Choose Content?)",
    "selection_intro": "1-2 sentences about content quality standards",
    "selection_items": [
      {"bold": "Standard:", "text": "description"},
      {"bold": "Standard:", "text": "description"},
      {"bold": "Standard:", "text": "description"},
      {"bold": "Standard:", "text": "description"},
      {"bold": "Standard:", "text": "description"}
    ],
    "founder_name": "Full Name",
    "founder_role": "Role title",
    "connect_title": "Let's Connect!",
    "connect_text": "1-2 sentences inviting visitors to reach out"
  },
  "contact": {
    "hero_subtitle": "short subtitle relevant to the niche",
    "intro": "2-3 sentences intro with contact@{{SITE_HOST}} email mention, using <strong> tags for the email",
    "faq": [
      {"q": "question about the niche", "a": "answer"},
      {"q": "question about content", "a": "answer"},
      {"q": "question about customization", "a": "answer"},
      {"q": "question about availability", "a": "answer"}
    ]
  },
  "privacy": {
    "last_updated": "{$todayDate}",
    "hero_subtitle": "one sentence about privacy and no personal data collection",
    "welcome_text": "2-3 sentences about privacy commitment for a {$niche} site mentioning {{SITE_NAME}}",
    "welcome_text2": "1-2 sentences about third-party services and {{SITE_HOST}}",
    "conclusion_text": "2 sentences thanking users, mentioning {{SITE_NAME}} and the niche"
  }
}
PROMPT;

    $payload = [
        'model'       => $model,
        'max_tokens'  => 3000,
        'messages'    => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'response_format' => ['type' => 'json_object']
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_TIMEOUT        => 60,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$response || $httpCode !== 200) {
        echo json_encode(['success' => false, 'message' => 'Erreur API OpenAI (HTTP ' . $httpCode . ')', 'raw' => $response]);
        exit;
    }

    $result  = json_decode($response, true);
    $content = $result['choices'][0]['message']['content'] ?? null;
    if (!$content) {
        echo json_encode(['success' => false, 'message' => 'Réponse vide de l\'API']);
        exit;
    }

    $generated = json_decode($content, true);
    if (!$generated) {
        echo json_encode(['success' => false, 'message' => 'JSON invalide retourné par l\'IA', 'raw' => $content]);
        exit;
    }

    $generated['niche']        = $niche;
    $generated['generated_at'] = date('Y-m-d');

    echo json_encode(['success' => true, 'data' => $generated]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Action inconnue: ' . $action]);

<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
set_exception_handler(function($e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'exception', 'message' => $e->getMessage()]);
    exit;
});
set_error_handler(function($severity, $msg, $file, $line) {
    if ($severity & (E_ERROR | E_USER_ERROR)) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'php_error', 'message' => $msg . ' in ' . basename($file) . ':' . $line]);
        exit;
    }
    return false;
});
require_once 'config.php';
require_once __DIR__ . '/auth.php';
auth_check();
$apiKey = OPENAI_API_KEY; 
$postTitle = isset($_POST['title']) ? $_POST['title'] : "";

// 🎯 3 Prompts مختلفين
// $promptIMG1 = "Close-up iPhone photo zoomed in on $postTitle filling most of frame, on a white everyday plate. Shot from directly above in home kitchen with natural afternoon window light. The dish shows detailed textures, colors, and fresh-prepared appearance with small natural imperfections. Only plate edges and small portion of kitchen counter visible. Blurred kitchen background shows hints of coffee maker, utensils, and typical kitchen items. Natural smartphone photography, no filters, authentic home-cooking close-up, real daylight colors, slight natural shadows, genuine homemade meal focus.";

// $promptIMG2 = "Zoomed-in smartphone photo of $postTitle on a blue ceramic plate, plate filling 85% of frame, tight 45-degree angle showing rich layers and detailed texture, glistening surfaces with visible moisture, plate edges barely visible, wooden table just showing, very soft-focus kitchen background, iPhone quality, unedited natural daylight, authentic extreme close-up focusing on dish details and texture";

// $promptIMG3 = "Tight close-up smartphone photo focusing on $postTitle on a black plate, food dominates the frame showing detailed textures, colors, and fresh-cooked appearance. Shot from slightly above with natural indirect kitchen window light. Realistic home-cooking result with natural imperfections clearly visible - authentic texture and presentation. Plate fills most of image, small counter space visible at edges. Kitchen background softly blurred showing hints of appliances, cooking tools, normal kitchen environment. iPhone photography style, natural color temperature, unfiltered, extreme close-up on dish, genuine homemade food moment, lived-in kitchen context.";



// $promptIMG1 = "Authentic food blogger lifestyle photography of $postTitle captured at 45-degree angle. Shot on marble countertop with natural afternoon sunlight streaming from window creating golden hour glow. The dish sits on handmade ceramic plate with visible artisan texture, slight imperfections adding character. Composition includes storytelling elements - vintage brass fork, striped linen napkin casually draped, small bouquet of fresh herbs in background softly out of focus. Rich depth of field showing layers and dimension. Colors are warm, saturated, Instagram-worthy with slight film grain texture. Natural cooking signs visible - steam wisps, glistening juices, fresh-from-kitchen appeal. Professional food blogger quality, trending Pinterest aesthetic, cozy and inviting, 4K resolution.";

// ── Variables aléatoires pour éviter la répétition ──────────────────────────

$plates = [
    "a rustic hand-thrown ceramic bowl with earthy tones",
    "a wide shallow white ceramic plate with thick rim",
    "a deep matte black slate plate",
    "a vintage floral-painted porcelain dish",
    "a modern grey stoneware bowl",
    "a warm terracotta clay dish",
    "a simple off-white linen-textured plate",
    "a dark charcoal ceramic plate with rough edges",
];

$surfaces = [
    "a worn light oak wooden table with natural grain",
    "a white marble countertop with subtle grey veining",
    "a dark walnut wood surface",
    "a rough grey concrete countertop",
    "a bleached linen tablecloth on a farmhouse table",
    "a smooth cream-painted kitchen counter",
];

$lights = [
    "warm golden afternoon sunlight streaming from the left window",
    "soft diffused morning light from a large side window",
    "bright overcast natural daylight from above",
    "warm low-angle late afternoon window light",
    "cool bright north-facing window light",
];

$contexts = [
    "as if a home cook just placed it fresh from the stove",
    "as if a chef just finished plating it in a real kitchen",
    "as if a food blogger captured it moments after cooking",
    "as if a mother just served it for a family meal",
    "as if captured candidly in a real home kitchen",
];

// Shuffle pour que chaque image soit différente
shuffle($plates);
shuffle($surfaces);
shuffle($lights);
shuffle($contexts);

$plate1  = $plates[0];   $plate2  = $plates[1];   $plate3  = $plates[2];
$surf1   = $surfaces[0]; $surf2   = $surfaces[1];  $surf3   = $surfaces[2];
$light1  = $lights[0];   $light2  = $lights[1];    $light3  = $lights[2];
$ctx1    = $contexts[0]; $ctx2    = $contexts[1];   $ctx3    = $contexts[2];

// ── Prompts ──────────────────────────────────────────────────────────────────

// Prompt 1: CLOSE-UP 30° - crisp, glistening, no steam/fog
$promptIMG1 = "Extreme close-up food photography of $postTitle served on $plate1, placed on $surf1, $ctx1. Shot from a low 30-degree angle, dish filling 85% of the frame. $light1 casting soft natural shadows that reveal rich texture and depth. Crystal clear sharp image — no steam, no fog, no haze. Glistening sauce catching the light, caramelized crust, vibrant colors, visible herbs and spices. Background dissolves into warm creamy bokeh. Hyper-realistic food photography, not AI-looking, the kind of photo that stops a user mid-scroll and makes them hungry immediately.";

// Prompt 2: EYE-LEVEL SIDE - layers, depth, cross-section
$promptIMG2 = "Close-up eye-level side-angle food photography of $postTitle served on $plate2, resting on $surf2, $ctx2. Food filling 80% of the frame, shot straight-on to reveal the full depth, layers and cross-section of the dish. $light2 creating subtle highlights on sauces and edges, enhancing volume and realism. Sharp focus across the entire front face of the food, beautiful bokeh blur behind. Rich saturated natural colors, visible herbs, spices, juices. No props, no clutter. Ultra-realistic, mouthwatering, scroll-stopping Pinterest portrait.";

// Prompt 3: MACRO CLOSE-UP - irresistible textures
$promptIMG3 = "Extreme close-up macro food photography of $postTitle served on $plate3, on $surf3, $ctx3. Food filling 80% of frame, captured at a low eye-level angle. $light3 from the side creating gentle highlights and shadows emphasizing depth and realism. Razor-sharp focus on the most delicious-looking section — glistening sauce, visible layers, caramelized edges, fresh herb flecks, moisture droplets. Background blurs into warm creamy bokeh. Ultra-realistic, mouthwatering, fine-dining quality, irresistible close-up that makes the viewer want to reach into the frame.";


// Override with config prompts if defined (use {title} as placeholder)
if (defined('IMG_PROMPT_1') && IMG_PROMPT_1 !== '') {
    $promptIMG1 = str_replace('{title}', $postTitle, IMG_PROMPT_1);
}
if (defined('IMG_PROMPT_2') && IMG_PROMPT_2 !== '') {
    $promptIMG2 = str_replace('{title}', $postTitle, IMG_PROMPT_2);
}
if (defined('IMG_PROMPT_3') && IMG_PROMPT_3 !== '') {
    $promptIMG3 = str_replace('{title}', $postTitle, IMG_PROMPT_3);
}

$prompts = [$promptIMG1, $promptIMG2, $promptIMG3];

$saveDir = __DIR__ . "/tmpIMG";

if (!is_dir($saveDir)) {
    mkdir($saveDir, 0777, true);
}

$resp = "";

// 🎯 Si imageNumber spécifié, générer seulement cette image (retry d'une seule image)
$onlyIndex = null;
if (isset($_POST['imageNumber']) && is_numeric($_POST['imageNumber'])) {
    $n = (int)$_POST['imageNumber'];
    if ($n >= 1 && $n <= 3) {
        $onlyIndex = $n - 1; // 0-based — ne pas toucher aux autres images
    }
} else {
    // Génération complète: vider le dossier
    foreach (glob($saveDir . '/*') as $file) {
        if (is_file($file)) unlink($file);
    }
}

// 🔄 Loop 3la kol prompt
foreach ($prompts as $index => $prompt) {
    if ($onlyIndex !== null && $index !== $onlyIndex) continue;
    
    $data = [
        "model" => OPENAI_IMAGE_MODEL,
        "prompt" => $prompt,
        "quality" => OPENAI_IMAGE_QUALITY,
        "size" => OPENAI_IMAGE_SIZE,
        "n" => 1
    ];

    $ch = curl_init("https://api.openai.com/v1/images/generations");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer $apiKey"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);

    if (isset($result["data"][0]["b64_json"])) {
        $imageBase64 = $result["data"][0]["b64_json"];
        $imageData = base64_decode($imageBase64);

        if ($imageData === false || strlen($imageData) < 100) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'decode_error', 'message' => 'base64_decode failed']);
            exit;
        }

        $outputPath = $saveDir . "/image_" . ($index + 1) . ".webp";

        if (!function_exists('imagewebp')) {
            // GD WebP not available — save as PNG fallback
            file_put_contents($outputPath, $imageData);
        } else {
            $image = imagecreatefromstring($imageData);
            if ($image === false) {
                header('Content-Type: application/json');
                echo json_encode(['error' => 'gd_error', 'message' => 'imagecreatefromstring failed — image data may be invalid']);
                exit;
            }
            imagewebp($image, $outputPath, 100);
            imagedestroy($image);
        }

        // Log image generation
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) mkdir($logDir, 0755, true);
        $logLine = sprintf(
            "[%s] API=OPENAI     Model=%-22s Size=%-12s Quality=%-6s Image=%d Cost=$%.5f  [%s]\n",
            date('Y-m-d H:i:s'),
            OPENAI_IMAGE_MODEL,
            OPENAI_IMAGE_SIZE,
            OPENAI_IMAGE_QUALITY,
            ($index + 1),
            OPENAI_IMAGE_COST,
            $postTitle
        );
        file_put_contents($logDir . '/api_usage.log', $logLine, FILE_APPEND | LOCK_EX);

        $resp .= $outputPath . ";\n";
    } else {
        // Detect quota/billing errors
        $errorMsg = $result["error"]["message"] ?? "";
        $errorCode = $result["error"]["code"] ?? "";
        if (strpos($errorCode, "quota") !== false || strpos($errorMsg, "quota") !== false || strpos($errorMsg, "billing") !== false) {
            header('Content-Type: application/json');
            echo json_encode(["error" => "quota_exceeded", "message" => "OpenAI quota dépassé. Recharge ton compte sur platform.openai.com/billing"]);
            exit;
        }
        header('Content-Type: application/json');
        echo json_encode(["error" => "api_error", "message" => $errorMsg ?: json_encode($result)]);
        exit;
    }
}

echo $resp;
?>
<?php
/**
 * Test script to generate HTML for a post using slug
 * This extracts only the class definition
 */

// Test slug
$slug = $_GET['slug'] ?? '';

// Build JSON path
$jsonPath = "posts/{$slug}/post.json";

echo "Testing HTML generation for slug: {$slug}\n";
echo "JSON path: {$jsonPath}\n";

// Extract only the class from the file
// Load POST_LAYOUT from site-config.json so block order is respected
if (!defined('POST_LAYOUT')) {
    $__sc = json_decode(file_get_contents(__DIR__ . '/site-config.json'), true);
    if (!empty($__sc['POST_LAYOUT']) && is_array($__sc['POST_LAYOUT'])) {
        define('POST_LAYOUT', $__sc['POST_LAYOUT']);
    }
    unset($__sc);
}

$content = file_get_contents('generate-single-post.php');
preg_match('/class PostHTMLGenerator \{.*?^}/ms', $content, $matches);

if (empty($matches[0])) {
    die("Could not extract PostHTMLGenerator class\n");
}

// Execute the class code
eval($matches[0]);

try {
    if (!file_exists($jsonPath)) {
        throw new Exception("Post JSON not found: {$jsonPath}");
    }

    // Create generator
    $generator = new PostHTMLGenerator($jsonPath);

    // Save to index.html
    $outputPath = "posts/{$slug}/index.html";
    $generator->saveFile($outputPath);

    echo "✓ Success! HTML generated at: {$outputPath}\n";
    echo "File size: " . filesize($outputPath) . " bytes\n";
    header('Location: posts-liste.php');
    exit;

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
?>

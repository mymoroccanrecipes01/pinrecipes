<?php
/**
 * Get URL for a post to test in browser
 * Usage: php get-url.php post-slug
 */

if ($argc < 2) {
    echo "Usage: php get-url.php <post-slug>\n\n";
    echo "Examples:\n";
    echo "  php get-url.php 30-minute-creamy-white-chicken-chili\n";
    echo "  php get-url.php best-chili\n";
    echo "  php get-url.php creamy-butternut-squash-soup-for-cozy-nights\n\n";

    echo "Or list all posts:\n";
    echo "  php get-url.php --list\n";
    exit(0);
}

$slug = $argv[1];

// List all posts
if ($slug === '--list' || $slug === '-l') {
    echo "Available posts:\n";
    echo "==================\n\n";

    $dirs = glob('./posts/*', GLOB_ONLYDIR);
    $count = 0;

    foreach ($dirs as $dir) {
        $postSlug = basename($dir);
        $url = "http://localhost/SitePinterset/mollykitchendaily-main/posts/$postSlug/";

        $count++;
        echo "$count. $postSlug\n";
        echo "   $url\n\n";

        if ($count >= 10) {
            echo "... and " . (count($dirs) - 10) . " more posts\n\n";
            echo "Use: php get-url.php <post-slug> to get a specific URL\n";
            break;
        }
    }
    exit(0);
}

// Check if post exists
$postDir = "./posts/$slug";

if (!is_dir($postDir)) {
    echo "❌ Post not found: $slug\n\n";
    echo "Available posts (first 10):\n";
    $dirs = glob('./posts/*', GLOB_ONLYDIR);
    foreach (array_slice($dirs, 0, 10) as $dir) {
        echo "  - " . basename($dir) . "\n";
    }
    echo "\nUse: php get-url.php --list to see all posts\n";
    exit(1);
}

// Generate URLs
$localUrl = "http://localhost/SitePinterset/mollykitchendaily-main/posts/$slug/";
$productionUrl = "https://www.mollykitchendaily.com/posts/$slug/";

echo "✅ Post found: $slug\n";
echo "==========================================\n\n";

echo "LOCAL URL (for testing):\n";
echo "$localUrl\n\n";

echo "PRODUCTION URL:\n";
echo "$productionUrl\n\n";

echo "FILES:\n";
echo "  JSON: $postDir/post.json\n";
echo "  HTML: $postDir/index.html\n";

// Check if files exist
if (file_exists("$postDir/index.html")) {
    $size = filesize("$postDir/index.html");
    $time = date("Y-m-d H:i:s", filemtime("$postDir/index.html"));
    echo "\n  HTML Generated: $time\n";
    echo "  HTML Size: " . number_format($size) . " bytes\n";
} else {
    echo "\n  ⚠️  HTML not generated yet. Run: php generate-single-post.php?slug=<slug>&save=1\n";
}

// Count images
$imagesDir = "$postDir/images";
if (is_dir($imagesDir)) {
    $images = glob("$imagesDir/*.{jpg,jpeg,png,webp}", GLOB_BRACE);
    echo "  Images: " . count($images) . " files\n";
}

echo "\n==========================================\n";
echo "Copy the LOCAL URL above and paste in your browser!\n";

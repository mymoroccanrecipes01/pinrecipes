<?php
/**
 * Script one-shot — convertit les images sources PNG/JPEG des posts en WebP.
 * Allège le repo git + accélère le deploy Cloudflare Pages (PNG ~2.5MB → webp ~300KB).
 *
 * Pour chaque post :
 *   1. convertit posts/{slug}/images/*.png|*.jpg  → .webp (q82), supprime l'original
 *   2. met à jour les références dans post.json (.png → .webp)
 *   3. régénère index.html
 * Puis reconstruit index.json + index-home.json.
 *
 * Usage CLI :  php convert-images-webp.php           (tous les posts)
 *              php convert-images-webp.php --dry      (simulation, n'écrit rien)
 *
 * NOTE : ne touche PAS aux templates webp ni aux reels mp4.
 */

require_once __DIR__ . '/config.php';
if (!defined('POST_HTML_FUNCTIONS_ONLY')) define('POST_HTML_FUNCTIONS_ONLY', true);
require_once __DIR__ . '/generate-single-post.php'; // classe PostHTMLGenerator

$isCli = php_sapi_name() === 'cli';
$dry   = in_array('--dry', $argv ?? [], true);

if (!function_exists('imagewebp')) {
    exit("ERREUR: GD (imagewebp) indisponible. Installe php-gd.\n");
}

$postsDir = __DIR__ . '/posts';
$dirs = is_dir($postsDir) ? glob($postsDir . '/*', GLOB_ONLYDIR) : [];

$converted = 0; $postsUpdated = 0; $bytesSaved = 0; $errors = 0;

foreach ($dirs as $dir) {
    $slug     = basename($dir);
    $jsonPath = $dir . '/post.json';
    $imgDir   = $dir . '/images';
    if (!is_file($jsonPath) || !is_dir($imgDir)) continue;

    // Cibler uniquement les images sources non-webp (png/jpg/jpeg)
    $candidates = array_merge(
        glob($imgDir . '/*.png')  ?: [],
        glob($imgDir . '/*.jpg')  ?: [],
        glob($imgDir . '/*.jpeg') ?: []
    );
    if (empty($candidates)) continue;

    $rawJson = file_get_contents($jsonPath);
    $postTouched = false;

    foreach ($candidates as $src) {
        // Ignorer les reels/frames (ne devraient pas être png mais par sécurité)
        if (preg_match('/_fb_frame_|_reel\.mp4/', basename($src))) continue;

        $webpName = preg_replace('/\.(png|jpe?g)$/i', '.webp', basename($src));
        $webpPath = $imgDir . '/' . $webpName;
        $origSize = filesize($src);

        if ($dry) {
            echo "  [DRY] $slug : " . basename($src) . " → $webpName\n";
            $converted++;
            continue;
        }

        $img = @imagecreatefromstring(file_get_contents($src));
        if ($img === false) { $errors++; echo "  ⚠️  $slug : échec lecture " . basename($src) . "\n"; continue; }

        if (!imagewebp($img, $webpPath, 82)) { imagedestroy($img); $errors++; continue; }
        imagedestroy($img);

        $newSize = filesize($webpPath) ?: 0;
        $bytesSaved += max(0, $origSize - $newSize);

        // Mettre à jour les références dans le JSON brut (nom de fichier unique → remplacement sûr)
        $rawJson = str_replace(basename($src), $webpName, $rawJson);
        $postTouched = true;

        @unlink($src); // supprimer le PNG/JPEG original
        $converted++;
        echo "  ✓ $slug : " . basename($src) . " (" . (int)($origSize/1024) . "KB) → $webpName (" . (int)($newSize/1024) . "KB)\n";
    }

    if ($postTouched && !$dry) {
        file_put_contents($jsonPath, $rawJson);
        // Régénérer le HTML pour pointer vers les .webp
        try { (new PostHTMLGenerator($jsonPath))->saveFile($dir . '/index.html'); }
        catch (Throwable $e) { echo "  ⚠️  $slug : HTML non régénéré — " . $e->getMessage() . "\n"; }
        $postsUpdated++;
    }
}

if (!$dry && $postsUpdated > 0) {
    _rebuild_posts_index(__DIR__);
    echo "\n🔄 index.json + index-home.json reconstruits.\n";
}

echo "\n=== " . ($dry ? "[DRY] " : "") . "Terminé ===\n";
echo "Images converties : $converted\n";
echo "Posts mis à jour   : $postsUpdated\n";
echo "Espace économisé   : " . round($bytesSaved / 1048576, 1) . " MB\n";
echo "Erreurs            : $errors\n";
if ($dry) echo "\n(simulation — relance sans --dry pour appliquer)\n";

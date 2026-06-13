<?php
/**
 * Standalone API endpoint to regenerate all post HTML pages.
 * Called via AJAX from config-ui.php.
 */

// Catch fatal errors via shutdown function before any output
ob_start();
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '[Fatal] ' . $err['message'] . ' in ' . basename($err['file']) . ':' . $err['line']]);
    } else {
        ob_end_flush();
    }
});

header('Content-Type: application/json');

// Auth: same mechanism as config-ui.php
require_once __DIR__ . '/auth.php';
auth_check();
if (empty($_SESSION['role'])) {
    echo json_encode(['success' => false, 'message' => 'Non authentifié']);
    exit;
}

try {
    require_once __DIR__ . '/config.php';

    if (!class_exists('PostHTMLGenerator')) {
        if (!defined('POST_HTML_FUNCTIONS_ONLY')) define('POST_HTML_FUNCTIONS_ONLY', true);
        require __DIR__ . '/generate-single-post.php';
    }

    ob_clean();

    $postsDir  = __DIR__ . '/posts';
    $generated = [];
    $errors    = [];

    $dirs = glob($postsDir . '/*', GLOB_ONLYDIR) ?: [];
    foreach ($dirs as $dir) {
        $slug     = basename($dir);
        $jsonPath = $dir . '/post.json';
        if (!file_exists($jsonPath)) continue;

        ob_start();
        try {
            $ok = (new PostHTMLGenerator($jsonPath))->saveFile($dir . '/index.html');
        } catch (Throwable $e) {
            $ok = false;
        }
        ob_end_clean();

        if ($ok) {
            $generated[] = $slug;
        } else {
            $errors[] = $slug;
        }
    }

    echo json_encode([
        'success' => true,
        'count'   => count($generated),
        'output'  => implode("\n", array_map(fn($s) => "✓ $s", $generated))
                   . (count($errors) ? "\n✗ " . implode("\n✗ ", $errors) : ''),
    ]);

} catch (Throwable $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage() . ' — ' . basename($e->getFile()) . ':' . $e->getLine()]);
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage() . ' — ' . basename($e->getFile()) . ':' . $e->getLine()]);
}

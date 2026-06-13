<?php
// CLI worker — runs in background, no nginx timeout
if (php_sapi_name() !== 'cli') { exit; }

$jobId   = $argv[1] ?? '';
$inFile  = $argv[2] ?? '';
$outFile = $argv[3] ?? '';

if (!$jobId || !file_exists($inFile) || !$outFile) { exit(1); }

$input   = json_decode(file_get_contents($inFile), true);
$baseDir = $input['base_dir'] ?? __DIR__;

chdir($baseDir);
require_once $baseDir . '/config.php';

// Load generation functions from posts-api.php (CLI safe — skip HTTP routing)
define('_WORKER_MODE', true);
require_once $baseDir . '/posts-api.php';

$sourceText = $input['source_text'] ?? '';
$boardName  = $input['board_name']  ?? '';

$categoriesIndexPath = $baseDir . '/categories/index.json';
$categoriesName = '{}';
if (file_exists($categoriesIndexPath)) {
    $cat = json_decode(file_get_contents($categoriesIndexPath), true);
    $categoriesName = json_encode($cat['folders'] ?? []);
}

$GLOBALS['_pipeline_text_cost'] = 0.0;
$postData = generatepostFromText($sourceText, $categoriesName);
$textCost = $GLOBALS['_pipeline_text_cost'] ?? 0.0;

if (isset($postData['error'])) {
    file_put_contents($outFile, json_encode([
        'status' => 'error',
        'error'  => $postData['error'],
    ]));
} else {
    // board_name override
    if (!empty($boardName)) {
        $postData['board_name'] = $boardName;
    }
    file_put_contents($outFile, json_encode([
        'status' => 'done',
        'data'   => $postData,
        'cost'   => $textCost,
    ]));
}

@unlink($inFile);

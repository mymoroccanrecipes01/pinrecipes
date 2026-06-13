<?php
require_once 'config.php';
require_once __DIR__ . '/auth.php';
auth_check();

header('Content-Type: application/json');

$sourceText = trim($_POST['source_text'] ?? '');
$csvTitle   = trim($_POST['csv_title']   ?? '');
$boardName  = trim($_POST['board_name']  ?? '');

if (empty($sourceText)) {
    echo json_encode(['success' => false, 'error' => 'source_text requis']);
    exit;
}

$jobId   = uniqid('job_', true);
$tmpDir  = sys_get_temp_dir();
$inFile  = $tmpDir . '/gen_in_'  . $jobId . '.json';
$outFile = $tmpDir . '/gen_out_' . $jobId . '.json';

// Save input + initial status
file_put_contents($inFile, json_encode([
    'source_text' => $sourceText,
    'csv_title'   => $csvTitle,
    'board_name'  => $boardName,
    'base_dir'    => __DIR__,
]));
file_put_contents($outFile, json_encode(['status' => 'running']));

// Start background worker (Linux)
$phpBin  = PHP_BINARY;
$worker  = __DIR__ . '/posts-worker.php';
$cmd     = escapeshellarg($phpBin) . ' ' . escapeshellarg($worker)
         . ' ' . escapeshellarg($jobId)
         . ' ' . escapeshellarg($inFile)
         . ' ' . escapeshellarg($outFile)
         . ' > /dev/null 2>&1 &';
exec($cmd);

echo json_encode(['success' => true, 'job_id' => $jobId]);

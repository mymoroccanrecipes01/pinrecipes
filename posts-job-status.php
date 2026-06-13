<?php
require_once 'config.php';
require_once __DIR__ . '/auth.php';
auth_check();

header('Content-Type: application/json');

$jobId   = preg_replace('/[^a-zA-Z0-9_.]/', '', $_GET['job_id'] ?? '');
$outFile = sys_get_temp_dir() . '/gen_out_' . $jobId . '.json';

if (empty($jobId) || !file_exists($outFile)) {
    echo json_encode(['status' => 'not_found']);
    exit;
}

$result = json_decode(file_get_contents($outFile), true);
echo json_encode($result ?: ['status' => 'running']);

// Cleanup after delivering done/error result
if (in_array($result['status'] ?? '', ['done', 'error'])) {
    @unlink($outFile);
}

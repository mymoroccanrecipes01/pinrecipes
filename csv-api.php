<?php
// CORS — allow Chrome extension service worker
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// Extension API token — bypasses session auth for local Chrome extension calls
define('CSV_API_TOKEN', 'pinext-2025');
if (($_GET['apikey'] ?? '') !== CSV_API_TOKEN) {
    require_once __DIR__ . '/auth.php';
    auth_check();
}
// Minimal CSV API — no includes, no session, no config
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

$downloadsDir = __DIR__ . '/downloads';
$action = $_GET['action'] ?? '';

// Optional profile sub-directory — sanitize to prevent path traversal
$_profileParam = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['profile'] ?? '');
$activeDir = ($_profileParam !== '' && is_dir($downloadsDir . '/' . $_profileParam))
    ? $downloadsDir . '/' . $_profileParam
    : $downloadsDir;

// Safe file path — only .csv files inside active dir
function csvFile($name) {
    global $activeDir;
    $name = basename((string)$name);
    if ($name === '' || strtolower(substr($name, -4)) !== '.csv') return '';
    $path = $activeDir . '/' . $name;
    return file_exists($path) ? $path : '';
}

ob_end_clean();

// ── List ──────────────────────────────────────────────────────────────────────
if ($action === 'list') {
    header('Content-Type: application/json');
    $files = glob($activeDir . '/pinterest_*.csv') ?: [];
    rsort($files);
    $today = date('Y-m-d');
    $list  = [];
    foreach ($files as $f) {
        $name = basename($f);
        preg_match('/(\d{4}-\d{2}-\d{2})/', $name, $m);
        $date  = isset($m[1]) ? $m[1] : '';
        $label = $date === '' ? $name
               : ($date === $today ? 'Aujourd\'hui'
               : ($date  >  $today ? 'Le ' . $date
               :                     'En retard ' . $date));
        $list[] = [
            'filename' => $name,
            'date'     => $date !== '' ? $date : $name,
            'label'    => $label,
            'rows'     => max(0, count(file($f)) - 1),
        ];
    }
    echo json_encode(['exists' => !empty($list), 'files' => $list, 'date' => $today, 'profile' => $_profileParam ?: null]);
    exit;
}

// ── Download ──────────────────────────────────────────────────────────────────
if ($action === 'download') {
    $file = basename((string)($_GET['file'] ?? ''));
    // Fallback: most recent in active dir
    if ($file === '' || !csvFile($file)) {
        $found = glob($activeDir . '/pinterest_*.csv') ?: [];
        rsort($found);
        $file = $found ? basename($found[0]) : '';
    }
    $path = csvFile($file);
    if (!$path) { http_response_code(404); echo '{"error":"not found"}'; exit; }
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    readfile($path);
    exit;
}

// ── Delete ────────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    header('Content-Type: application/json');
    $path = csvFile($_GET['file'] ?? '');
    if (!$path) { echo '{"success":false,"error":"not found"}'; exit; }
    unlink($path);
    echo '{"success":true}';
    exit;
}

http_response_code(400);
echo '{"error":"unknown action"}';

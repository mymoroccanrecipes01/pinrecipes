<?php
header('Content-Type: application/json');
require_once 'config.php';
require_once __DIR__ . '/auth.php';
auth_check();
set_time_limit(120);

$sessionFile = __DIR__ . '/downloads/last-daily-session.json';

if (!file_exists($sessionFile)) {
    echo json_encode(['success' => false, 'message' => 'Aucune session à annuler']);
    exit;
}

$session = json_decode(file_get_contents($sessionFile), true);
if (!$session || empty($session['articles'])) {
    echo json_encode(['success' => false, 'message' => 'Session invalide']);
    exit;
}

$restored = 0;

foreach ($session['articles'] as $article) {
    $path = $article['path'];
    if (!file_exists($path)) continue;

    $postData = json_decode(file_get_contents($path), true);
    if (!$postData) continue;

    $slug = $article['slug'];
    $orig = $article['original'];

    // Delete templates + restore offline
    $postData['images']   = deletePostTemplates($slug, $postData['images'] ?? [], __DIR__);
    $postData['isOnline'] = false;
    if ($orig['createdAt'] !== null && array_key_exists('createdAt', $postData)) $postData['createdAt'] = $orig['createdAt'];
    if ($orig['CreateAt']  !== null && array_key_exists('CreateAt',  $postData)) $postData['CreateAt']  = $orig['CreateAt'];
    if ($orig['updatedAt'] !== null && array_key_exists('updatedAt', $postData)) $postData['updatedAt'] = $orig['updatedAt'];

    file_put_contents($path, json_encode($postData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    // Sync to satellites
    foreach (SATELLITE_PROJECTS as $satellite) {
        $satPath = realpath(__DIR__ . '/' . $satellite['path']);
        if (!$satPath) continue;
        $satJson = $satPath . '/posts/' . $slug . '/post.json';
        if (!file_exists($satJson)) continue;
        $satPost = json_decode(file_get_contents($satJson), true);
        if (!$satPost) continue;
        $satPost['isOnline'] = false;
        if ($orig['createdAt'] !== null && array_key_exists('createdAt', $satPost)) $satPost['createdAt'] = $orig['createdAt'];
        if ($orig['CreateAt']  !== null && array_key_exists('CreateAt',  $satPost)) $satPost['CreateAt']  = $orig['CreateAt'];
        if ($orig['updatedAt'] !== null && array_key_exists('updatedAt', $satPost)) $satPost['updatedAt'] = $orig['updatedAt'];
        file_put_contents($satJson, json_encode($satPost, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $restored++;
}

// Git push
$pushResult = [];
if (defined('REPO_PATH') && is_dir(REPO_PATH)) {
    $prevDir = getcwd();
    chdir(REPO_PATH);
    exec('git add -A 2>&1');
    exec('git commit -m ' . escapeshellarg('Rollback from web: ' . date('Y-m-d H:i:s')) . ' 2>&1', $commitOut, $commitCode);
    exec('git push origin ' . escapeshellarg(BRANCH) . ' --force 2>&1', $pushOut, $pushCode);
    chdir($prevDir);
    $pushResult['main'] = ($pushCode === 0 || stripos(implode('', $commitOut), 'nothing to commit') !== false)
        ? '✅ push OK' : '❌ push failed';
}

// Remove session so rollback can't be done twice
unlink($sessionFile);

echo json_encode([
    'success'      => true,
    'message'      => "$restored articles remis offline — templates supprimés",
    'restored'     => $restored,
    'push'         => $pushResult,
    'session_date' => $session['date'] ?? '',
]);
